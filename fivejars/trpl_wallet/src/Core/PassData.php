<?php

namespace Drupal\trpl_wallet\Core;

/**
 * Everything needed to draw one wallet pass, for either wallet.
 *
 * One PassData is one ticket or one membership card.
 */
final class PassData {

  public const TICKET = 'ticket';
  public const MEMBERSHIP = 'membership';

  /**
   * @param string $kind
   *   self::TICKET or self::MEMBERSHIP.
   * @param string $serial
   *   Stable unique id for this pass (never the barcode itself).
   * @param string $barcode
   *   The exact string ACME's scanners read.
   * @param string $barcodeLabel
   *   Short text printed under the QR code.
   * @param string $title
   *   Event name, or membership level.
   * @param string $holderName
   *   Ticket holder or cardholder.
   * @param string $typeName
   *   Ticket type ("Member Adult") or "Member".
   * @param \DateTimeImmutable|null $startsAt
   *   Entry time for tickets; benefits start for memberships.
   * @param \DateTimeImmutable|null $expiresAt
   *   When the pass stops being valid.
   * @param string $reference
   *   Order number or membership number, shown on the back.
   * @param array<string,int|string> $ref
   *   ACME ids used to load this pass again from a signed token.
   */
  public function __construct(
    public readonly string $kind,
    public readonly string $serial,
    public readonly string $barcode,
    public readonly string $barcodeLabel,
    public readonly string $title,
    public readonly string $holderName,
    public readonly string $typeName,
    public readonly ?\DateTimeImmutable $startsAt,
    public readonly ?\DateTimeImmutable $expiresAt,
    public readonly string $reference,
    public readonly array $ref,
  ) {}

  public function isTicket(): bool {
    return $this->kind === self::TICKET;
  }

  /**
   * What the lookup endpoint tells the browser about this pass. No barcode.
   *
   * @return array<string,string|null>
   */
  public function summary(): array {
    return [
      'kind' => $this->kind,
      'title' => $this->title,
      'type' => $this->typeName,
      'holder' => $this->holderName,
      'starts' => $this->startsAt?->format(\DateTimeInterface::ATOM),
      'expires' => $this->expiresAt?->format(\DateTimeInterface::ATOM),
    ];
  }

}
