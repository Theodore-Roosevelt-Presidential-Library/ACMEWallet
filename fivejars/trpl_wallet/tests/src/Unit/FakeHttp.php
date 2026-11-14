<?php

namespace Drupal\Tests\trpl_wallet\Unit;

use Drupal\trpl_wallet\Core\HttpClientInterface;

/**
 * Stands in for ACME. Answers from the fixture files; records every request.
 */
final class FakeHttp implements HttpClientInterface {

  /** @var array<int,array{url:string, headers:array<string,string>}> */
  public array $requests = [];

  public int $forceStatus = 0;

  public function get(string $url, array $headers = []): array {
    $this->requests[] = ['url' => $url, 'headers' => $headers];
    if ($this->forceStatus) {
      return ['status' => $this->forceStatus, 'body' => ''];
    }
    $order = (string) file_get_contents(__DIR__ . '/../../fixtures/order.json');
    $membership = (string) file_get_contents(__DIR__ . '/../../fixtures/membership.json');
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    if ($path === '/v2/b2c/orders/search') {
      // Search results come back without tickets, as ACME's samples show.
      $summary = json_decode($order, TRUE);
      foreach ($summary['orderItems'] as &$item) {
        unset($item['ticketVos']);
      }
      $match = strtolower($query['email'] ?? '') === 'sample.visitor@example.org';
      return ['status' => 200, 'body' => json_encode($match ? [$summary] : [])];
    }
    if ($path === '/v2/b2c/orders/9000001') {
      return ['status' => 200, 'body' => $order];
    }
    if ($path === '/v2/b2b/memberships') {
      // Deliberately ignores the filter, to prove the caller re-checks.
      return ['status' => 200, 'body' => json_encode(['list' => [json_decode($membership, TRUE)]])];
    }
    if ($path === '/v2/b2b/memberships/8000001') {
      return ['status' => 200, 'body' => $membership];
    }
    return ['status' => 404, 'body' => ''];
  }

}
