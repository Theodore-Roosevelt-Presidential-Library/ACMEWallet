<?php

namespace Drupal\trpl_wallet\Core;

/**
 * Builds a signed Apple Wallet pass (.pkpass) for one ticket or card.
 *
 * A .pkpass is a zip holding pass.json, images, a manifest of SHA-1 hashes,
 * and a detached PKCS#7 signature of that manifest made with the Library's
 * Pass Type ID certificate.
 *
 * Needs the openssl and zip PHP extensions.
 */
class ApplePassBuilder {

  /**
   * @param array{
   *   pass_type_identifier:string,
   *   team_identifier:string,
   *   organization_name:string,
   *   certificate_path:string,
   *   private_key_path:string,
   *   private_key_password?:string,
   *   wwdr_certificate_path:string,
   *   assets_dir:string,
   *   background_color?:string,
   *   foreground_color?:string,
   *   label_color?:string,
   *   latitude?:float|null,
   *   longitude?:float|null,
   *   relevant_text?:string,
   *   terms_url?:string,
   * } $config
   *   assets_dir holds "ticket" and "membership" folders of PNGs.
   */
  public function __construct(private readonly array $config) {}

  /**
   * @return string
   *   The .pkpass file contents.
   */
  public function build(PassData $pass): string {
    $files = $this->images($pass);
    $files['pass.json'] = json_encode(
      $this->passJson($pass),
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    $manifest = [];
    foreach ($files as $name => $contents) {
      $manifest[$name] = sha1($contents);
    }
    $files['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
    $files['signature'] = $this->sign($files['manifest.json']);

    return $this->zip($files);
  }

  /**
   * @return array<string,mixed>
   */
  public function passJson(PassData $pass): array {
    $c = $this->config;
    $json = [
      'formatVersion' => 1,
      'passTypeIdentifier' => $c['pass_type_identifier'],
      'teamIdentifier' => $c['team_identifier'],
      'serialNumber' => $pass->serial,
      'organizationName' => $c['organization_name'],
      'description' => $pass->isTicket()
        ? $c['organization_name'] . ' ticket'
        : $c['organization_name'] . ' membership card',
      'backgroundColor' => $c['background_color'] ?? 'rgb(255, 255, 255)',
      'foregroundColor' => $c['foreground_color'] ?? 'rgb(0, 0, 0)',
      'labelColor' => $c['label_color'] ?? 'rgb(90, 90, 90)',
      'barcodes' => [[
        'format' => 'PKBarcodeFormatQR',
        'message' => $pass->barcode,
        'messageEncoding' => 'iso-8859-1',
        'altText' => $pass->barcodeLabel,
      ]],
    ];

    if ($pass->expiresAt) {
      $json['expirationDate'] = $pass->expiresAt->format(\DateTimeInterface::ATOM);
    }
    if (isset($c['latitude'], $c['longitude'])) {
      $location = ['latitude' => (float) $c['latitude'], 'longitude' => (float) $c['longitude']];
      if (!empty($c['relevant_text'])) {
        $location['relevantText'] = $c['relevant_text'];
      }
      $json['locations'] = [$location];
    }

    $back = [];
    if (!empty($c['terms_url'])) {
      $back[] = ['key' => 'terms', 'label' => 'Terms', 'value' => $c['terms_url']];
    }

    if ($pass->isTicket()) {
      if ($pass->startsAt) {
        // Puts the ticket on the lock screen around the entry time.
        $json['relevantDate'] = $pass->startsAt->format(\DateTimeInterface::ATOM);
      }
      $json['eventTicket'] = [
        'primaryFields' => [
          ['key' => 'event', 'label' => 'ADMISSION', 'value' => $pass->title],
        ],
        'secondaryFields' => $pass->startsAt ? [
          [
            'key' => 'date',
            'label' => 'DATE',
            'value' => $pass->startsAt->format(\DateTimeInterface::ATOM),
            'dateStyle' => 'PKDateStyleMedium',
            'timeStyle' => 'PKDateStyleNone',
          ],
          [
            'key' => 'time',
            'label' => 'ENTRY TIME',
            'value' => $pass->startsAt->format(\DateTimeInterface::ATOM),
            'dateStyle' => 'PKDateStyleNone',
            'timeStyle' => 'PKDateStyleShort',
            'textAlignment' => 'PKTextAlignmentRight',
          ],
        ] : [],
        'auxiliaryFields' => array_values(array_filter([
          $pass->typeName !== '' ? ['key' => 'type', 'label' => 'TICKET', 'value' => $pass->typeName] : NULL,
          $pass->holderName !== '' ? ['key' => 'name', 'label' => 'NAME', 'value' => $pass->holderName] : NULL,
        ])),
        'backFields' => array_merge(
          [['key' => 'order', 'label' => 'Order number', 'value' => $pass->reference]],
          $back,
        ),
      ];
    }
    else {
      $json['generic'] = [
        'primaryFields' => [
          ['key' => 'member', 'label' => 'MEMBER', 'value' => $pass->holderName],
        ],
        'secondaryFields' => [
          ['key' => 'level', 'label' => 'LEVEL', 'value' => $pass->title],
        ],
        'auxiliaryFields' => $pass->expiresAt ? [[
          'key' => 'expires',
          'label' => 'VALID THROUGH',
          'value' => $pass->expiresAt->format(\DateTimeInterface::ATOM),
          'dateStyle' => 'PKDateStyleMedium',
          'timeStyle' => 'PKDateStyleNone',
        ]] : [],
        'backFields' => array_merge(
          [['key' => 'membership', 'label' => 'Membership number', 'value' => $pass->reference]],
          $back,
        ),
      ];
    }
    return $json;
  }

  /**
   * @return array<string,string>
   *   File name => contents for every PNG in this pass kind's asset folder.
   */
  private function images(PassData $pass): array {
    $dir = rtrim($this->config['assets_dir'], '/') . '/' . $pass->kind;
    $files = [];
    foreach (glob($dir . '/*.png') ?: [] as $path) {
      $files[basename($path)] = (string) file_get_contents($path);
    }
    if (!isset($files['icon.png'])) {
      throw new \RuntimeException("Apple passes require icon.png in $dir");
    }
    return $files;
  }

  /**
   * Detached PKCS#7 signature of the manifest, DER encoded.
   */
  private function sign(string $manifest): string {
    $c = $this->config;
    foreach (['certificate_path', 'private_key_path', 'wwdr_certificate_path'] as $key) {
      if (!is_readable($c[$key])) {
        throw new \RuntimeException("Apple signing file is not readable: $key");
      }
    }
    $in = tempnam(sys_get_temp_dir(), 'pkm');
    $out = tempnam(sys_get_temp_dir(), 'pks');
    try {
      file_put_contents($in, $manifest);
      $ok = openssl_pkcs7_sign(
        $in,
        $out,
        'file://' . realpath($c['certificate_path']),
        ['file://' . realpath($c['private_key_path']), $c['private_key_password'] ?? ''],
        [],
        PKCS7_BINARY | PKCS7_DETACHED,
        realpath($c['wwdr_certificate_path']),
      );
      if (!$ok) {
        throw new \RuntimeException('Could not sign the pass: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
      }
      // openssl writes S/MIME; the signature is the base64 part after this header.
      $smime = (string) file_get_contents($out);
      $marker = 'filename="smime.p7s"';
      $start = strpos($smime, $marker);
      if ($start === FALSE) {
        throw new \RuntimeException('Unexpected signature format from OpenSSL.');
      }
      $body = substr($smime, $start + strlen($marker));
      $end = strpos($body, '------');
      $der = base64_decode(trim($end === FALSE ? $body : substr($body, 0, $end)), TRUE);
      if ($der === FALSE || $der === '') {
        throw new \RuntimeException('Could not decode the pass signature.');
      }
      return $der;
    }
    finally {
      @unlink($in);
      @unlink($out);
    }
  }

  /**
   * @param array<string,string> $files
   */
  private function zip(array $files): string {
    $path = tempnam(sys_get_temp_dir(), 'pkz');
    try {
      $zip = new \ZipArchive();
      if ($zip->open($path, \ZipArchive::OVERWRITE) !== TRUE) {
        throw new \RuntimeException('Could not create the pass archive.');
      }
      foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
      }
      $zip->close();
      return (string) file_get_contents($path);
    }
    finally {
      @unlink($path);
    }
  }

}
