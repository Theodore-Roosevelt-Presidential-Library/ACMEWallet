<?php

namespace Drupal\trpl_wallet\Http;

use Drupal\trpl_wallet\Core\AcmeException;
use Drupal\trpl_wallet\Core\HttpClientInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Lets the core talk to ACME through Drupal's HTTP client.
 */
class GuzzleHttpClient implements HttpClientInterface {

  public function __construct(private readonly ClientInterface $client) {}

  public function get(string $url, array $headers = []): array {
    try {
      $response = $this->client->request('GET', $url, [
        'headers' => $headers,
        'http_errors' => FALSE,
        'timeout' => 10,
        'connect_timeout' => 5,
      ]);
    }
    catch (GuzzleException $e) {
      // Never pass the message on: it can contain the request URL.
      throw new AcmeException('ACME could not be reached');
    }
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
  }

}
