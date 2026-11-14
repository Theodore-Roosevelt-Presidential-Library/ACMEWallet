<?php

/**
 * @file
 * Secrets for trpl_wallet. Copy this block into settings.php on the host.
 *
 * Nothing here belongs in git, in config export, or in the Drupal database.
 * Read every value from the host's secret store or environment, and keep the
 * certificate files outside the web root, readable only by the PHP user.
 *
 * Anyone holding these can mint tickets that scan at the door.
 */

$settings['trpl_wallet'] = [
  // ACME API key. Ask ACME for a read-only key for the username-only user.
  'acme_api_key' => getenv('TRPL_WALLET_ACME_API_KEY') ?: '',

  // Signs the short-lived links. At least 32 random characters, for example
  // the output of: openssl rand -base64 48
  'token_secret' => getenv('TRPL_WALLET_TOKEN_SECRET') ?: '',

  // Apple: the Pass Type ID certificate and its private key, as PEM files,
  // plus Apple's WWDR intermediate certificate (G4) as PEM.
  'apple_certificate_path' => '/path/outside/webroot/wallet/pass-certificate.pem',
  'apple_private_key_path' => '/path/outside/webroot/wallet/pass-key.pem',
  'apple_private_key_password' => getenv('TRPL_WALLET_APPLE_KEY_PASSWORD') ?: '',
  'apple_wwdr_certificate_path' => '/path/outside/webroot/wallet/AppleWWDRCAG4.pem',

  // Google: the service account JSON key file for the Wallet issuer.
  'google_service_account_path' => '/path/outside/webroot/wallet/google-service-account.json',

  // Optional: a different folder of pass artwork. Defaults to the module's
  // pass_assets folder.
  // 'pass_assets_dir' => '/path/to/pass_assets',
];
