<?php

namespace Drupal\trpl_wallet\Core;

/**
 * Builds a "Save to Google Wallet" link for one or more passes.
 *
 * The link is https://pay.google.com/gp/v/save/<JWT>. The JWT is signed with
 * the Google service account key and carries the pass classes and objects
 * inline, so no call to Google's API is needed to issue a pass.
 */
class GoogleWalletLink {

  /**
   * @param array{
   *   issuer_id:string,
   *   service_account_email:string,
   *   private_key:string,
   *   issuer_name:string,
   *   origins?:string[],
   *   logo_url?:string,
   *   hero_image_url?:string,
   *   background_color?:string,
   *   venue_name?:string,
   *   venue_address?:string,
   * } $config
   *   private_key is the PEM from the service account JSON.
   */
  public function __construct(
    private readonly array $config,
    private readonly ?\Closure $clock = NULL,
  ) {}

  /**
   * @param \Drupal\trpl_wallet\Core\PassData[] $passes
   */
  public function saveUrl(array $passes): string {
    return 'https://pay.google.com/gp/v/save/' . $this->jwt($passes);
  }

  /**
   * @param \Drupal\trpl_wallet\Core\PassData[] $passes
   */
  public function jwt(array $passes): string {
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
      'iss' => $this->config['service_account_email'],
      'aud' => 'google',
      'typ' => 'savetowallet',
      'iat' => $this->clock ? ($this->clock)() : time(),
      'origins' => $this->config['origins'] ?? [],
      'payload' => $this->payload($passes),
    ];
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $signingInput = TokenSigner::b64(json_encode($header, $flags)) . '.' . TokenSigner::b64(json_encode($claims, $flags));

    $key = openssl_pkey_get_private($this->config['private_key']);
    if ($key === FALSE) {
      throw new \RuntimeException('Google service account key could not be read.');
    }
    if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
      throw new \RuntimeException('Could not sign the Google Wallet link.');
    }
    return $signingInput . '.' . TokenSigner::b64($signature);
  }

  /**
   * @param \Drupal\trpl_wallet\Core\PassData[] $passes
   *
   * @return array<string,array<int,array<string,mixed>>>
   */
  public function payload(array $passes): array {
    $payload = [];
    $classes = [];
    foreach ($passes as $pass) {
      if ($pass->isTicket()) {
        $class = $this->ticketClass($pass);
        if (!isset($classes[$class['id']])) {
          $classes[$class['id']] = TRUE;
          $payload['eventTicketClasses'][] = $class;
        }
        $payload['eventTicketObjects'][] = $this->ticketObject($pass, $class['id']);
      }
      else {
        $classId = $this->id('membership');
        if (!isset($classes[$classId])) {
          $classes[$classId] = TRUE;
          $payload['genericClasses'][] = ['id' => $classId];
        }
        $payload['genericObjects'][] = $this->membershipObject($pass, $classId);
      }
    }
    return $payload;
  }

  /**
   * One class per event and entry time; Google's reminder uses its start.
   *
   * @return array<string,mixed>
   */
  private function ticketClass(PassData $pass): array {
    $c = $this->config;
    $slot = $pass->startsAt ? $pass->startsAt->format('Ymd\THi') : 'undated';
    $class = [
      'id' => $this->id('event-' . substr(sha1($pass->title), 0, 10) . '-' . $slot),
      'issuerName' => $c['issuer_name'],
      'eventName' => self::text($pass->title),
      'reviewStatus' => 'UNDER_REVIEW',
    ];
    if ($pass->startsAt) {
      $class['dateTime'] = ['start' => $pass->startsAt->format(\DateTimeInterface::ATOM)];
    }
    if (!empty($c['venue_name']) && !empty($c['venue_address'])) {
      $class['venue'] = [
        'name' => self::text($c['venue_name']),
        'address' => self::text($c['venue_address']),
      ];
    }
    if (!empty($c['logo_url'])) {
      $class['logo'] = self::image($c['logo_url'], $c['issuer_name']);
    }
    if (!empty($c['hero_image_url'])) {
      $class['heroImage'] = self::image($c['hero_image_url'], $pass->title);
    }
    if (!empty($c['background_color'])) {
      $class['hexBackgroundColor'] = $c['background_color'];
    }
    return $class;
  }

  /**
   * @return array<string,mixed>
   */
  private function ticketObject(PassData $pass, string $classId): array {
    $object = [
      'id' => $this->id($pass->serial),
      'classId' => $classId,
      'state' => 'ACTIVE',
      'barcode' => [
        'type' => 'QR_CODE',
        'value' => $pass->barcode,
        'alternateText' => $pass->barcodeLabel,
      ],
      'ticketNumber' => $pass->barcodeLabel,
    ];
    if ($pass->holderName !== '') {
      $object['ticketHolderName'] = $pass->holderName;
    }
    if ($pass->typeName !== '') {
      $object['ticketType'] = self::text($pass->typeName);
    }
    if ($pass->expiresAt) {
      $object['validTimeInterval'] = ['end' => ['date' => $pass->expiresAt->format(\DateTimeInterface::ATOM)]];
    }
    return $object;
  }

  /**
   * @return array<string,mixed>
   */
  private function membershipObject(PassData $pass, string $classId): array {
    $c = $this->config;
    $object = [
      'id' => $this->id($pass->serial),
      'classId' => $classId,
      'state' => 'ACTIVE',
      'cardTitle' => self::text($c['issuer_name']),
      'subheader' => self::text($pass->title),
      'header' => self::text($pass->holderName !== '' ? $pass->holderName : 'Member'),
      'barcode' => [
        'type' => 'QR_CODE',
        'value' => $pass->barcode,
        'alternateText' => $pass->barcodeLabel,
      ],
      'textModulesData' => [
        ['id' => 'membership', 'header' => 'Membership number', 'body' => $pass->reference],
      ],
    ];
    if ($pass->expiresAt) {
      $object['textModulesData'][] = [
        'id' => 'expires',
        'header' => 'Valid through',
        'body' => $pass->expiresAt->format('F j, Y'),
      ];
      $object['validTimeInterval'] = ['end' => ['date' => $pass->expiresAt->format(\DateTimeInterface::ATOM)]];
    }
    if (!empty($c['logo_url'])) {
      $object['logo'] = self::image($c['logo_url'], $c['issuer_name']);
    }
    if (!empty($c['hero_image_url'])) {
      $object['heroImage'] = self::image($c['hero_image_url'], $c['issuer_name']);
    }
    if (!empty($c['background_color'])) {
      $object['hexBackgroundColor'] = $c['background_color'];
    }
    return $object;
  }

  /**
   * Google ids are "<issuer id>.<suffix>"; the suffix allows [A-Za-z0-9._-].
   */
  private function id(string $suffix): string {
    return $this->config['issuer_id'] . '.' . preg_replace('/[^A-Za-z0-9._-]/', '_', $suffix);
  }

  /**
   * @return array<string,mixed>
   */
  private static function text(string $value): array {
    return ['defaultValue' => ['language' => 'en-US', 'value' => $value]];
  }

  /**
   * @return array<string,mixed>
   */
  private static function image(string $url, string $description): array {
    return [
      'sourceUri' => ['uri' => $url],
      'contentDescription' => self::text($description),
    ];
  }

}
