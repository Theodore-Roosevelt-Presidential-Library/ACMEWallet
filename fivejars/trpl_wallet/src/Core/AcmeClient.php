<?php

namespace Drupal\trpl_wallet\Core;

/**
 * Read-only client for the ACME Ticketing API.
 *
 * Every ACME-specific path, header and field name lives in this class and in
 * LookupService, so anything that turns out to differ in the Library's tenant
 * is fixed in one place. Lines marked VERIFY are taken from ACME's public
 * documentation and have not been run against the live API yet.
 */
class AcmeClient {

  /**
   * Single records already fetched during this request, by path.
   *
   * @var array<string,array<string,mixed>|null>
   */
  private array $fetched = [];

  public function __construct(
    private readonly HttpClientInterface $http,
    private readonly string $apiKey,
    private readonly string $tenantId,
    private readonly string $baseUrl = 'https://api.acmeticketing.com',
  ) {}

  /**
   * Orders placed with an email address.
   *
   * ACME documents search by email or phone only, not by order number, so the
   * caller matches the order number itself.
   *
   * VERIFY: the path. ACME's "B2C Orders" page gives GET /v2/b2c/orders with
   * an email parameter; older notes give /v2/b2c/orders/search. The first is
   * tried, and the second only if the first does not exist.
   *
   * @return array<int,array<string,mixed>>
   */
  public function ordersByEmail(string $email): array {
    $data = $this->getJson('/v2/b2c/orders', ['email' => $email])
      ?? $this->getJson('/v2/b2c/orders/search', ['email' => $email]);
    return $this->asList($data);
  }

  /**
   * One order with its items and tickets.
   *
   * @return array<string,mixed>|null
   */
  public function order(int|string $orderId): ?array {
    $path = '/v2/b2c/orders/' . rawurlencode((string) $orderId);
    return $this->fetched[$path] ??= $this->one($this->getJson($path));
  }

  /**
   * Memberships matching a membership number.
   *
   * VERIFY: the filter parameter name. ACME's public pages document the
   * endpoint and the response, not its filters. LookupService re-checks the
   * number on every result, so a filter that is ignored cannot leak another
   * member's cards; it would only make this call slow.
   *
   * @return array<int,array<string,mixed>>
   */
  public function membershipsByNumber(string $membershipNumber): array {
    $data = $this->getJson('/v2/b2b/memberships', [
      'acmeMembershipNumber' => $membershipNumber,
    ]);
    return $this->asList($data);
  }

  /**
   * One membership by ACME id.
   *
   * VERIFY: path.
   *
   * @return array<string,mixed>|null
   */
  public function membership(int|string $membershipId): ?array {
    $path = '/v2/b2b/memberships/' . rawurlencode((string) $membershipId);
    return $this->fetched[$path] ??= $this->one($this->getJson($path));
  }

  /**
   * @return array<string,mixed>|null
   */
  private function one(mixed $data): ?array {
    return is_array($data) && $data ? $data : NULL;
  }

  /**
   * @param array<string,string> $query
   *
   * @return mixed
   */
  private function getJson(string $path, array $query = []): mixed {
    $url = rtrim($this->baseUrl, '/') . $path;
    if ($query) {
      $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $response = $this->http->get($url, [
      'x-acme-api-key' => $this->apiKey,
      'x-b2c-tenant-id' => $this->tenantId,
      'Accept' => 'application/json',
    ]);
    if ($response['status'] === 404) {
      return NULL;
    }
    if ($response['status'] === 429) {
      throw new AcmeException('ACME rate limit reached', 429);
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
      throw new AcmeException('ACME returned HTTP ' . $response['status'], $response['status']);
    }
    $decoded = json_decode($response['body'], TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new AcmeException('ACME returned a body that is not JSON');
    }
    return $decoded;
  }

  /**
   * ACME list endpoints return either a bare array or {"list": [...]}.
   *
   * @return array<int,array<string,mixed>>
   */
  private function asList(mixed $data): array {
    if (!is_array($data)) {
      return [];
    }
    if (isset($data['list']) && is_array($data['list'])) {
      $data = $data['list'];
    }
    return array_values(array_filter($data, 'is_array'));
  }

}
