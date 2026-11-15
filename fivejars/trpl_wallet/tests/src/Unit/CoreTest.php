<?php

namespace Drupal\Tests\trpl_wallet\Unit;

use Drupal\trpl_wallet\Core\AcmeClient;
use Drupal\trpl_wallet\Core\AcmeException;
use Drupal\trpl_wallet\Core\ApplePassBuilder;
use Drupal\trpl_wallet\Core\GoogleWalletLink;
use Drupal\trpl_wallet\Core\LookupService;
use Drupal\trpl_wallet\Core\PassData;
use Drupal\trpl_wallet\Core\TokenSigner;
use PHPUnit\Framework\TestCase;

/**
 * Runs with fake ACME data and a throwaway certificate. No real secret needed.
 */
final class CoreTest extends TestCase {

  private FakeHttp $http;
  private LookupService $lookup;
  private static string $dir;

  public static function setUpBeforeClass(): void {
    // A self-signed certificate stands in for Apple's, and for the WWDR
    // intermediate. It proves the signing mechanics, not Apple's trust chain.
    self::$dir = sys_get_temp_dir() . '/trpl_wallet_test_' . bin2hex(random_bytes(4));
    mkdir(self::$dir);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Test Pass Type ID'], $key);
    $cert = openssl_csr_sign($csr, NULL, $key, 1);
    openssl_x509_export_to_file($cert, self::$dir . '/cert.pem');
    openssl_x509_export_to_file($cert, self::$dir . '/wwdr.pem');
    openssl_pkey_export_to_file($key, self::$dir . '/key.pem');
  }

  public static function tearDownAfterClass(): void {
    array_map('unlink', glob(self::$dir . '/*') ?: []);
    rmdir(self::$dir);
  }

  protected function setUp(): void {
    $this->http = new FakeHttp();
    $this->lookup = new LookupService(
      new AcmeClient($this->http, 'test-key', '613'),
      new \DateTimeImmutable('2027-07-01T09:00:00-06:00'),
    );
  }

  public function testOrderLookupReturnsUpcomingTicketsOnly(): void {
    $passes = $this->lookup->ticketsForOrder('#100099999', ' SAMPLE.visitor@example.org ');
    $this->assertCount(2, $passes, 'Two upcoming tickets; the past tour is left out.');
    $this->assertSame('at000000-000000000000000000-aaaa1111', $passes[0]->barcode);
    $this->assertSame('aaaa1111', $passes[0]->barcodeLabel);
    $this->assertSame('General Admission', $passes[0]->title);
    $this->assertSame('Member Adult', $passes[0]->typeName);
    $this->assertSame('2027-07-05', $passes[0]->expiresAt->format('Y-m-d'));
    $this->assertSame('test-key', $this->http->requests[0]['headers']['x-acme-api-key']);
  }

  public function testOrderLookupNeedsBothOrderNumberAndEmail(): void {
    $this->assertSame([], $this->lookup->ticketsForOrder('100000000', 'sample.visitor@example.org'));
    $this->assertSame([], $this->lookup->ticketsForOrder('100099999', 'someone.else@example.org'));
    $this->assertSame([], $this->lookup->ticketsForOrder('100099999', 'not-an-email'));
    $this->assertSame([], $this->lookup->ticketsForOrder('', ''));
  }

  public function testMembershipLookupReturnsActiveCards(): void {
    $passes = $this->lookup->cardsForMembership('300000001', 'second.member@example.org');
    $this->assertCount(2, $passes, 'Both active cards; the inactive card is left out.');
    $this->assertSame('300000101', $passes[0]->barcode);
    $this->assertSame('Sample Member', $passes[0]->holderName);
    $this->assertSame('Family', $passes[0]->title);
    $this->assertSame('300000001', $passes[0]->reference);
  }

  public function testMembershipLookupRechecksNumberAndEmail(): void {
    // FakeHttp returns the membership whatever number is asked for.
    $this->assertSame([], $this->lookup->cardsForMembership('399999999', 'sample.member@example.org'));
    $this->assertSame([], $this->lookup->cardsForMembership('300000001', 'stranger@example.org'));
    $this->assertSame([], $this->lookup->cardsForMembership('300000001', 'former.member@example.org'), 'An inactive cardholder cannot fetch the cards.');
  }

  public function testExpiredMembershipGivesNoCards(): void {
    $late = new LookupService(new AcmeClient($this->http, 'k', '613'), new \DateTimeImmutable('2028-02-01'));
    $this->assertSame([], $late->cardsForMembership('300000001', 'sample.member@example.org'));
  }

  public function testPassReloadsFromTokenRef(): void {
    $ticket = $this->lookup->ticketsForOrder('100099999', 'sample.visitor@example.org')[1];
    $again = $this->lookup->passFromRef($ticket->ref);
    $this->assertSame($ticket->barcode, $again->barcode);

    $card = $this->lookup->cardsForMembership('300000001', 'sample.member@example.org')[1];
    $this->assertSame($card->barcode, $this->lookup->passFromRef($card->ref)->barcode);

    $this->assertNull($this->lookup->passFromRef(['k' => 'ticket', 'o' => '9000001', 't' => '1']));
    $this->assertNull($this->lookup->passFromRef(['k' => 'nonsense']));
  }

  public function testSummaryNeverCarriesTheBarcode(): void {
    $ticket = $this->lookup->ticketsForOrder('100099999', 'sample.visitor@example.org')[0];
    $this->assertStringNotContainsString($ticket->barcode, json_encode([$ticket->summary(), $ticket->ref]));
  }

  public function testAcmeErrorsSurfaceAsExceptions(): void {
    $this->http->forceStatus = 429;
    $this->expectException(AcmeException::class);
    $this->lookup->ticketsForOrder('100099999', 'sample.visitor@example.org');
  }

  public function testTokensRoundTripAndRejectTampering(): void {
    $now = 1_800_000_000;
    $signer = new TokenSigner(str_repeat('s', 32), function () use (&$now) { return $now; });
    $token = $signer->sign(['k' => 'ticket', 'o' => '1', 't' => '2'], 900);

    $this->assertSame('2', $signer->verify($token)['t']);
    $this->assertNull($signer->verify($token . 'x'), 'Altered signature.');
    [$body, $sig] = explode('.', $token);
    $forged = TokenSigner::b64(json_encode(['k' => 'ticket', 'o' => '1', 't' => '3', 'exp' => $now + 900]));
    $this->assertNull($signer->verify($forged . '.' . $sig), 'Altered payload.');
    $this->assertNull((new TokenSigner(str_repeat('z', 32)))->verify($token), 'Wrong secret.');
    $this->assertNull($signer->verify('garbage'));

    $now += 901;
    $this->assertNull($signer->verify($token), 'Expired.');
  }

  public function testShortTokenSecretIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    new TokenSigner('too-short');
  }

  public function testApplePassIsAValidSignedArchive(): void {
    $builder = new ApplePassBuilder($this->appleConfig());

    foreach ($this->samplePasses() as $pass) {
      $path = self::$dir . '/' . $pass->kind . '.pkpass';
      file_put_contents($path, $builder->build($pass));

      $zip = new \ZipArchive();
      $this->assertTrue($zip->open($path));
      $json = json_decode($zip->getFromName('pass.json'), TRUE);
      $manifest = json_decode($zip->getFromName('manifest.json'), TRUE);

      $this->assertSame($pass->barcode, $json['barcodes'][0]['message']);
      $this->assertSame('PKBarcodeFormatQR', $json['barcodes'][0]['format']);
      $this->assertSame('pass.org.example.test', $json['passTypeIdentifier']);
      $this->assertArrayHasKey($pass->isTicket() ? 'eventTicket' : 'generic', $json);
      $this->assertArrayHasKey('expirationDate', $json);

      // Every file except the manifest and signature is listed with its hash.
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (in_array($name, ['manifest.json', 'signature'], TRUE)) {
          continue;
        }
        $this->assertSame(sha1($zip->getFromName($name)), $manifest[$name] ?? NULL, $name);
      }
      $this->assertArrayHasKey('icon.png', $manifest);

      // The signature really is a detached PKCS#7 signature of the manifest.
      file_put_contents(self::$dir . '/manifest.json', $zip->getFromName('manifest.json'));
      file_put_contents(self::$dir . '/signature', $zip->getFromName('signature'));
      $zip->close();
      exec(sprintf(
        'openssl smime -verify -inform DER -in %s -content %s -noverify -out /dev/null 2>&1',
        escapeshellarg(self::$dir . '/signature'),
        escapeshellarg(self::$dir . '/manifest.json'),
      ), $output, $code);
      $this->assertSame(0, $code, implode("\n", $output));
    }
  }

  public function testSeveralTicketsBundleIntoOneFile(): void {
    $builder = new ApplePassBuilder($this->appleConfig());
    $tickets = $this->lookup->ticketsForOrder('100099999', 'sample.visitor@example.org');
    $this->assertGreaterThan(1, count($tickets));

    $path = self::$dir . '/tickets.pkpasses';
    file_put_contents($path, $builder->bundle($tickets));
    $zip = new \ZipArchive();
    $this->assertTrue($zip->open($path));
    $this->assertSame(count($tickets), $zip->numFiles);

    // Every entry is a complete signed pass with its own barcode, and all of
    // them carry the same grouping so Wallet stacks them.
    $barcodes = [];
    $groups = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $this->assertStringEndsWith('.pkpass', $zip->getNameIndex($i));
      $inner = self::$dir . '/inner-' . $i . '.pkpass';
      file_put_contents($inner, $zip->getFromIndex($i));
      $pass = new \ZipArchive();
      $this->assertTrue($pass->open($inner));
      $this->assertNotFalse($pass->getFromName('signature'));
      $json = json_decode($pass->getFromName('pass.json'), TRUE);
      $barcodes[] = $json['barcodes'][0]['message'];
      $groups[] = $json['groupingIdentifier'] ?? NULL;
    }
    $this->assertSame(array_map(static fn($t) => $t->barcode, $tickets), $barcodes);
    $this->assertCount(count($tickets), array_unique($barcodes));
    $this->assertSame(['order-100099999'], array_values(array_unique($groups)));
  }

  public function testBundleRefusesOnePassOrMoreThanTen(): void {
    $builder = new ApplePassBuilder($this->appleConfig());
    $ticket = $this->samplePasses()[0];
    foreach ([[$ticket], array_fill(0, 11, $ticket)] as $passes) {
      try {
        $builder->bundle($passes);
        $this->fail('Expected the bundle to be refused.');
      }
      catch (\InvalidArgumentException $e) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testTicketPassCarriesLockScreenRelevance(): void {
    $json = (new ApplePassBuilder($this->appleConfig()))->passJson($this->samplePasses()[0]);
    $this->assertSame('2027-07-04T13:00:00-06:00', $json['relevantDate']);
    $this->assertSame(46.5, $json['locations'][0]['latitude']);
  }

  public function testTicketsAndMembershipsGetTheirOwnBrandColor(): void {
    $builder = new ApplePassBuilder($this->appleConfig());
    [$ticket, $card] = $this->samplePasses();
    $this->assertSame('rgb(9, 42, 77)', $builder->passJson($ticket)['backgroundColor']);
    $this->assertSame('rgb(153, 173, 197)', $builder->passJson($ticket)['labelColor']);
    $this->assertSame('rgb(27, 69, 50)', $builder->passJson($card)['backgroundColor']);
    $this->assertSame('rgb(209, 204, 189)', $builder->passJson($card)['labelColor']);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $payload = (new GoogleWalletLink([
      'issuer_id' => '1', 'service_account_email' => 'a@b', 'private_key' => $pem, 'issuer_name' => 'X',
      'colors' => $this->appleConfig()['colors'],
      'logo_urls' => ['ticket' => 'https://example.org/t.png', 'membership' => 'https://example.org/m.png'],
    ]))->payload([$ticket, $card]);
    $this->assertSame('#092A4D', $payload['eventTicketClasses'][0]['hexBackgroundColor']);
    $this->assertSame('https://example.org/t.png', $payload['eventTicketClasses'][0]['logo']['sourceUri']['uri']);
    $this->assertSame('#1B4532', $payload['genericObjects'][0]['hexBackgroundColor']);
    $this->assertSame('https://example.org/m.png', $payload['genericObjects'][0]['logo']['sourceUri']['uri']);
  }

  public function testGoogleLinkIsAVerifiableJwt(): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $link = new GoogleWalletLink([
      'issuer_id' => '3388000000000000000',
      'service_account_email' => 'wallet@example.iam.gserviceaccount.com',
      'private_key' => $pem,
      'issuer_name' => 'Example Library',
      'origins' => ['https://www.example.org'],
    ], fn() => 1_800_000_000);

    $url = $link->saveUrl($this->samplePasses());
    $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);

    [$h, $c, $s] = explode('.', substr($url, strlen('https://pay.google.com/gp/v/save/')));
    $signature = base64_decode(strtr($s, '-_', '+/'));
    $public = openssl_pkey_get_details($key)['key'];
    $this->assertSame(1, openssl_verify("$h.$c", $signature, $public, OPENSSL_ALGO_SHA256));

    $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), TRUE);
    $this->assertSame('savetowallet', $claims['typ']);
    $this->assertSame('google', $claims['aud']);
    $ticket = $claims['payload']['eventTicketObjects'][0];
    $this->assertSame('at000000-000000000000000000-aaaa1111', $ticket['barcode']['value']);
    $this->assertSame('QR_CODE', $ticket['barcode']['type']);
    $this->assertSame($claims['payload']['eventTicketClasses'][0]['id'], $ticket['classId']);
    $this->assertSame('2027-07-04T13:00:00-06:00', $claims['payload']['eventTicketClasses'][0]['dateTime']['start']);
    $card = $claims['payload']['genericObjects'][0];
    $this->assertSame('300000101', $card['barcode']['value']);
    $this->assertMatchesRegularExpression('/^3388000000000000000\.[A-Za-z0-9._-]+$/', $card['id']);
  }

  public function testTwoTicketsForOneSlotShareAGoogleClass(): void {
    $passes = $this->lookup->ticketsForOrder('100099999', 'sample.visitor@example.org');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $payload = (new GoogleWalletLink([
      'issuer_id' => '1', 'service_account_email' => 'a@b', 'private_key' => $pem, 'issuer_name' => 'X',
    ]))->payload($passes);
    $this->assertCount(1, $payload['eventTicketClasses']);
    $this->assertCount(2, $payload['eventTicketObjects']);
  }

  /**
   * @return \Drupal\trpl_wallet\Core\PassData[]
   */
  private function samplePasses(): array {
    return [
      $this->lookup->ticketsForOrder('100099999', 'sample.visitor@example.org')[0],
      $this->lookup->cardsForMembership('300000001', 'sample.member@example.org')[0],
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private function appleConfig(): array {
    return [
      'pass_type_identifier' => 'pass.org.example.test',
      'team_identifier' => 'TEAM123456',
      'organization_name' => 'Example Library',
      'certificate_path' => self::$dir . '/cert.pem',
      'private_key_path' => self::$dir . '/key.pem',
      'wwdr_certificate_path' => self::$dir . '/wwdr.pem',
      'assets_dir' => __DIR__ . '/../../../pass_assets',
      'latitude' => 46.5,
      'longitude' => -103.5,
      'colors' => [
        'ticket' => ['background' => '#092A4D', 'foreground' => '#FFFFFF', 'label' => '#99ADC5'],
        'membership' => ['background' => '#1B4532', 'foreground' => '#FFFFFF', 'label' => '#D1CCBD'],
      ],
    ];
  }

}
