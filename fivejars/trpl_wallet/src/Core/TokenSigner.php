<?php

namespace Drupal\trpl_wallet\Core;

/**
 * Short-lived signed tokens, so the site keeps no per-visitor state.
 *
 * A token is "<base64url JSON>.<base64url HMAC-SHA256>". It carries ACME ids
 * and an expiry, never a barcode, name or email.
 */
class TokenSigner {

  public function __construct(
    private readonly string $secret,
    private readonly ?\Closure $clock = NULL,
  ) {
    if (strlen($secret) < 32) {
      throw new \InvalidArgumentException('Token secret must be at least 32 characters.');
    }
  }

  /**
   * @param array<string,mixed> $payload
   */
  public function sign(array $payload, int $ttlSeconds): string {
    $payload['exp'] = $this->time() + $ttlSeconds;
    $body = self::b64(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    return $body . '.' . self::b64(hash_hmac('sha256', $body, $this->secret, TRUE));
  }

  /**
   * @return array<string,mixed>|null
   *   The payload, or NULL when the token is malformed, forged or expired.
   */
  public function verify(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
      return NULL;
    }
    [$body, $signature] = $parts;
    $expected = self::b64(hash_hmac('sha256', $body, $this->secret, TRUE));
    if (!hash_equals($expected, $signature)) {
      return NULL;
    }
    $json = base64_decode(strtr($body, '-_', '+/'), TRUE);
    $payload = $json === FALSE ? NULL : json_decode($json, TRUE);
    if (!is_array($payload) || !isset($payload['exp']) || (int) $payload['exp'] < $this->time()) {
      return NULL;
    }
    return $payload;
  }

  private function time(): int {
    return $this->clock ? ($this->clock)() : time();
  }

  public static function b64(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

}
