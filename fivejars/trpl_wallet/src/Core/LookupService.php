<?php

namespace Drupal\trpl_wallet\Core;

/**
 * Turns "order number + email" or "membership number + email" into passes.
 *
 * Both lookups need two things the visitor knows. Neither says which of the
 * two was wrong when nothing is found.
 */
class LookupService {

  /**
   * Tickets stay available this long after their entry time.
   */
  private const TICKET_GRACE = 'P1D';

  public function __construct(
    private readonly AcmeClient $acme,
    private readonly ?\DateTimeImmutable $now = NULL,
  ) {}

  /**
   * @return \Drupal\trpl_wallet\Core\PassData[]
   */
  public function ticketsForOrder(string $orderNumber, string $email): array {
    $orderNumber = self::digits($orderNumber);
    $email = self::normalEmail($email);
    if ($orderNumber === '' || $email === '') {
      return [];
    }
    foreach ($this->acme->ordersByEmail($email) as $order) {
      if (self::digits((string) ($order['orderNumber'] ?? '')) !== $orderNumber) {
        continue;
      }
      if (self::normalEmail((string) ($order['email'] ?? '')) !== $email) {
        continue;
      }
      // Search results may omit the tickets; fetch the full order if so.
      if (!self::hasTickets($order) && isset($order['id'])) {
        $order = $this->acme->order($order['id']) ?? $order;
      }
      return $this->ticketsFromOrder($order);
    }
    return [];
  }

  /**
   * @return \Drupal\trpl_wallet\Core\PassData[]
   */
  public function cardsForMembership(string $membershipNumber, string $email): array {
    $membershipNumber = self::digits($membershipNumber);
    $email = self::normalEmail($email);
    if ($membershipNumber === '' || $email === '') {
      return [];
    }
    foreach ($this->acme->membershipsByNumber($membershipNumber) as $membership) {
      if (!self::membershipHasNumber($membership, $membershipNumber)) {
        continue;
      }
      // Only someone holding an active card on this membership may fetch it.
      $emails = [];
      foreach (self::cardholders($membership) as $card) {
        if (self::isActive($card)) {
          $emails[] = self::normalEmail((string) ($card['email'] ?? ''));
        }
      }
      if (!in_array($email, $emails, TRUE)) {
        continue;
      }
      return $this->cardsFromMembership($membership);
    }
    return [];
  }

  /**
   * Loads one pass again from the ids carried in a signed token.
   *
   * @param array<string,int|string> $ref
   */
  public function passFromRef(array $ref): ?PassData {
    if (($ref['k'] ?? '') === PassData::TICKET) {
      $order = $this->acme->order($ref['o'] ?? '');
      foreach ($order ? $this->ticketsFromOrder($order) : [] as $pass) {
        if ((string) $pass->ref['t'] === (string) ($ref['t'] ?? '')) {
          return $pass;
        }
      }
    }
    if (($ref['k'] ?? '') === PassData::MEMBERSHIP) {
      $membership = $this->acme->membership($ref['m'] ?? '');
      foreach ($membership ? $this->cardsFromMembership($membership) : [] as $pass) {
        if ((string) $pass->ref['c'] === (string) ($ref['c'] ?? '')) {
          return $pass;
        }
      }
    }
    return NULL;
  }

  /**
   * @param array<string,mixed> $order
   *
   * @return \Drupal\trpl_wallet\Core\PassData[]
   */
  private function ticketsFromOrder(array $order): array {
    $passes = [];
    $cutoff = $this->now()->sub(new \DateInterval(self::TICKET_GRACE));
    foreach ((array) ($order['orderItems'] ?? []) as $item) {
      $starts = self::date($item['eventStartTime'] ?? NULL);
      if ($starts && $starts < $cutoff) {
        continue;
      }
      foreach ((array) ($item['ticketVos'] ?? []) as $ticket) {
        $uuid = trim((string) ($ticket['uuid'] ?? ''));
        $ticketId = (string) ($ticket['id'] ?? '');
        if ($uuid === '' || $ticketId === '') {
          continue;
        }
        $parts = explode('-', $uuid);
        $passes[] = new PassData(
          kind: PassData::TICKET,
          serial: 'ticket-' . $ticketId,
          barcode: $uuid,
          barcodeLabel: (string) end($parts),
          title: (string) ($item['eventName'] ?? 'Admission'),
          holderName: trim(($order['firstName'] ?? '') . ' ' . ($order['lastName'] ?? '')),
          typeName: (string) ($item['ticketingTypeName'] ?? ''),
          startsAt: $starts,
          // ACME emails show tickets expiring the day after the visit.
          expiresAt: $starts?->modify('+1 day')->setTime(23, 59, 59),
          reference: (string) ($order['orderNumber'] ?? ''),
          ref: ['k' => PassData::TICKET, 'o' => (string) ($order['id'] ?? ''), 't' => $ticketId],
        );
      }
    }
    return $passes;
  }

  /**
   * @param array<string,mixed> $membership
   *
   * @return \Drupal\trpl_wallet\Core\PassData[]
   */
  private function cardsFromMembership(array $membership): array {
    $passes = [];
    $expires = self::date($membership['membershipExpirationDate'] ?? NULL);
    if ($expires && $expires < $this->now()) {
      return [];
    }
    foreach (self::cardholders($membership) as $card) {
      $barcode = trim((string) ($card['barcode'] ?? ''));
      $cardId = (string) ($card['id'] ?? '');
      if ($barcode === '' || $cardId === '' || !self::isActive($card)) {
        continue;
      }
      $name = trim((string) ($card['name'] ?? ''));
      if ($name === '') {
        $name = trim(($card['firstName'] ?? '') . ' ' . ($card['lastName'] ?? ''));
      }
      $passes[] = new PassData(
        kind: PassData::MEMBERSHIP,
        serial: 'card-' . $cardId,
        barcode: $barcode,
        barcodeLabel: $barcode,
        title: (string) ($membership['offeringName'] ?? $membership['category'] ?? 'Member'),
        holderName: $name,
        typeName: 'Member',
        startsAt: self::date($membership['membershipStartDate'] ?? NULL),
        expiresAt: self::date($card['expirationDate'] ?? NULL) ?? $expires,
        reference: (string) ($membership['acmeMembershipNumber'] ?? ''),
        ref: ['k' => PassData::MEMBERSHIP, 'm' => (string) ($membership['id'] ?? ''), 'c' => $cardId],
      );
    }
    return $passes;
  }

  /**
   * @param array<string,mixed> $membership
   *
   * @return array<int,array<string,mixed>>
   */
  private static function cardholders(array $membership): array {
    return array_values(array_filter((array) ($membership['cardholders'] ?? []), 'is_array'));
  }

  /**
   * @param array<string,mixed> $card
   */
  private static function isActive(array $card): bool {
    return strtolower((string) ($card['cardStatus'] ?? 'active')) === 'active';
  }

  /**
   * @param array<string,mixed> $membership
   */
  private static function membershipHasNumber(array $membership, string $number): bool {
    foreach (['acmeMembershipNumber', 'externalMembershipId'] as $field) {
      if (self::digits((string) ($membership[$field] ?? '')) === $number) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string,mixed> $order
   */
  private static function hasTickets(array $order): bool {
    foreach ((array) ($order['orderItems'] ?? []) as $item) {
      if (!empty($item['ticketVos'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private static function digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
  }

  private static function normalEmail(string $value): string {
    $value = strtolower(trim($value));
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
  }

  private static function date(mixed $value): ?\DateTimeImmutable {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    try {
      return new \DateTimeImmutable($value);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  private function now(): \DateTimeImmutable {
    return $this->now ?? new \DateTimeImmutable();
  }

}
