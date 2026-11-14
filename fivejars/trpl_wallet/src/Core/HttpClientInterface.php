<?php

namespace Drupal\trpl_wallet\Core;

/**
 * The only thing the core needs from the outside world: an HTTP GET.
 *
 * The Drupal module supplies one backed by Guzzle; tests supply a fake.
 */
interface HttpClientInterface {

  /**
   * @param string $url
   * @param array<string,string> $headers
   *
   * @return array{status:int, body:string}
   */
  public function get(string $url, array $headers = []): array;

}
