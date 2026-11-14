<?php

namespace Drupal\trpl_wallet;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Site\Settings;
use Drupal\trpl_wallet\Core\AcmeClient;
use Drupal\trpl_wallet\Core\ApplePassBuilder;
use Drupal\trpl_wallet\Core\GoogleWalletLink;
use Drupal\trpl_wallet\Core\HttpClientInterface;
use Drupal\trpl_wallet\Core\LookupService;
use Drupal\trpl_wallet\Core\TokenSigner;

/**
 * Builds the core objects from Drupal config and from secrets.
 *
 * Config (exportable, no secrets) comes from trpl_wallet.settings.
 * Secrets come only from $settings['trpl_wallet'] in settings.php, which
 * should read them from the host's environment. See settings.example.php.
 */
class WalletFactory {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Settings $settings,
    private readonly HttpClientInterface $http,
    private readonly ModuleExtensionList $modules,
  ) {}

  public function config(): ImmutableConfig {
    return $this->configFactory->get('trpl_wallet.settings');
  }

  /**
   * Names of required secrets that are missing. Empty means ready.
   *
   * @return string[]
   */
  public function missing(): array {
    $missing = [];
    foreach (['acme_api_key', 'token_secret'] as $key) {
      if ($this->secret($key) === '') {
        $missing[] = $key;
      }
    }
    return $missing;
  }

  public function appleReady(): bool {
    $c = $this->config();
    return $c->get('apple.pass_type_identifier') && $c->get('apple.team_identifier')
      && is_readable($this->secret('apple_certificate_path'))
      && is_readable($this->secret('apple_private_key_path'))
      && is_readable($this->secret('apple_wwdr_certificate_path'));
  }

  public function googleReady(): bool {
    return $this->config()->get('google.issuer_id') && is_readable($this->secret('google_service_account_path'));
  }

  public function lookup(): LookupService {
    $c = $this->config();
    return new LookupService(new AcmeClient(
      $this->http,
      $this->secret('acme_api_key'),
      (string) $c->get('acme.tenant_id'),
      (string) $c->get('acme.base_url'),
    ));
  }

  public function tokens(): TokenSigner {
    return new TokenSigner($this->secret('token_secret'));
  }

  public function apple(): ApplePassBuilder {
    $c = $this->config();
    $lat = $c->get('apple.latitude');
    $lon = $c->get('apple.longitude');
    return new ApplePassBuilder([
      'pass_type_identifier' => (string) $c->get('apple.pass_type_identifier'),
      'team_identifier' => (string) $c->get('apple.team_identifier'),
      'organization_name' => (string) $c->get('organization_name'),
      'certificate_path' => $this->secret('apple_certificate_path'),
      'private_key_path' => $this->secret('apple_private_key_path'),
      'private_key_password' => $this->secret('apple_private_key_password'),
      'wwdr_certificate_path' => $this->secret('apple_wwdr_certificate_path'),
      'assets_dir' => $this->secret('pass_assets_dir') ?: $this->modules->getPath('trpl_wallet') . '/pass_assets',
      'background_color' => (string) $c->get('apple.background_color'),
      'foreground_color' => (string) $c->get('apple.foreground_color'),
      'label_color' => (string) $c->get('apple.label_color'),
      'latitude' => is_numeric($lat) ? (float) $lat : NULL,
      'longitude' => is_numeric($lon) ? (float) $lon : NULL,
      'relevant_text' => (string) $c->get('apple.relevant_text'),
      'terms_url' => (string) $c->get('apple.terms_url'),
    ]);
  }

  public function google(): GoogleWalletLink {
    $c = $this->config();
    $account = json_decode((string) file_get_contents($this->secret('google_service_account_path')), TRUE);
    if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
      throw new \RuntimeException('Google service account file is not valid.');
    }
    return new GoogleWalletLink([
      'issuer_id' => (string) $c->get('google.issuer_id'),
      'service_account_email' => $account['client_email'],
      'private_key' => $account['private_key'],
      'issuer_name' => (string) $c->get('organization_name'),
      'origins' => (array) $c->get('allowed_origins'),
      'logo_url' => (string) $c->get('google.logo_url'),
      'hero_image_url' => (string) $c->get('google.hero_image_url'),
      'background_color' => (string) $c->get('google.background_color'),
      'venue_name' => (string) $c->get('google.venue_name'),
      'venue_address' => (string) $c->get('google.venue_address'),
    ]);
  }

  private function secret(string $key): string {
    $secrets = $this->settings->get('trpl_wallet', []);
    return is_array($secrets) ? (string) ($secrets[$key] ?? '') : '';
  }

}
