<?php

namespace Drupal\trpl_wallet\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings that are safe to export. Secrets are never entered here.
 */
class SettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'trpl_wallet_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['trpl_wallet.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('trpl_wallet.settings');
    /** @var \Drupal\trpl_wallet\WalletFactory $wallet */
    $wallet = \Drupal::service('trpl_wallet.factory');

    $missing = $wallet->missing();
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => TRUE,
      'list' => [
        '#theme' => 'item_list',
        '#items' => [
          $missing
            ? $this->t('Missing from settings.php: @keys', ['@keys' => implode(', ', $missing)])
            : $this->t('ACME key and token secret are installed.'),
          $wallet->appleReady()
            ? $this->t('Apple Wallet is ready.')
            : $this->t('Apple Wallet is not ready: check the Pass Type ID, Team ID and the three certificate paths.'),
          $wallet->googleReady()
            ? $this->t('Google Wallet is ready.')
            : $this->t('Google Wallet is not ready: check the Issuer ID and the service account path.'),
        ],
      ],
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Wallet passes are on'),
      '#description' => $this->t('Turn off to disable every wallet route at once.'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['delivery_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('How visitors get their passes'),
      '#options' => [
        'email' => $this->t('Email a one-time link to the address on the order (recommended)'),
        'instant' => $this->t('Show the passes straight away'),
      ],
      '#default_value' => $config->get('delivery_mode'),
    ];
    $form['pass_page_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Pass page'),
      '#description' => $this->t('The page that hosts the wallet embed. Emailed links open it.'),
      '#default_value' => $config->get('pass_page_url'),
      '#required' => TRUE,
    ];
    $form['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sites allowed to call the lookup'),
      '#description' => $this->t('One origin per line, for example https://wallet.labs.trlibrary.com'),
      '#default_value' => implode("\n", (array) $config->get('allowed_origins')),
      '#rows' => 3,
    ];
    $form['organization_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name shown on passes'),
      '#default_value' => $config->get('organization_name'),
      '#required' => TRUE,
    ];

    $form['look'] = [
      '#type' => 'details',
      '#title' => $this->t('Colors and images'),
      '#description' => $this->t('One background color per kind of pass, from the brand palette. Colors are #rrggbb.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach (['ticket' => $this->t('Tickets'), 'membership' => $this->t('Membership cards')] as $kind => $label) {
      $form['look'][$kind] = ['#type' => 'fieldset', '#title' => $label];
      foreach (['background' => $this->t('Background'), 'foreground' => $this->t('Text'), 'label' => $this->t('Labels')] as $key => $title) {
        $form['look'][$kind][$key] = [
          '#type' => 'color',
          '#title' => $title,
          '#default_value' => $config->get("colors.$kind.$key"),
        ];
      }
      $form['look'][$kind]['logo_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Google Wallet logo (square, shown in a circle)'),
        '#default_value' => $config->get("google.logo_urls.$kind"),
      ];
      $form['look'][$kind]['hero_image_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Google Wallet wide photo (optional, 1032 x 336)'),
        '#default_value' => $config->get("google.hero_image_urls.$kind"),
      ];
    }

    $form['apple'] = [
      '#type' => 'details',
      '#title' => $this->t('Apple Wallet'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $apple = [
      'pass_type_identifier' => $this->t('Pass Type ID'),
      'team_identifier' => $this->t('Team ID'),
      'latitude' => $this->t('Entrance latitude'),
      'longitude' => $this->t('Entrance longitude'),
      'relevant_text' => $this->t('Lock screen text near the Library'),
      'terms_url' => $this->t('Terms link on the back of the pass'),
    ];
    foreach ($apple as $key => $title) {
      $form['apple'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => $config->get("apple.$key"),
      ];
    }

    $form['google'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Wallet'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $google = [
      'issuer_id' => $this->t('Issuer ID'),
      'venue_name' => $this->t('Venue name'),
      'venue_address' => $this->t('Venue address'),
    ];
    foreach ($google as $key => $title) {
      $form['google'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => $config->get("google.$key"),
        '#maxlength' => 255,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ($this->origins($form_state) as $origin) {
      if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#', $origin)) {
        $form_state->setErrorByName('allowed_origins', $this->t('@origin is not an https origin.', ['@origin' => $origin]));
      }
    }
    foreach (['latitude', 'longitude'] as $key) {
      $value = trim((string) $form_state->getValue(['apple', $key]));
      if ($value !== '' && !is_numeric($value)) {
        $form_state->setErrorByName("apple][$key", $this->t('Latitude and longitude must be numbers.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('trpl_wallet.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('delivery_mode', $form_state->getValue('delivery_mode'))
      ->set('pass_page_url', trim((string) $form_state->getValue('pass_page_url')))
      ->set('allowed_origins', $this->origins($form_state))
      ->set('organization_name', trim((string) $form_state->getValue('organization_name')));
    foreach (['apple', 'google'] as $group) {
      foreach ((array) $form_state->getValue($group) as $key => $value) {
        $config->set("$group.$key", trim((string) $value));
      }
    }
    foreach ((array) $form_state->getValue('look') as $kind => $values) {
      foreach (['background', 'foreground', 'label'] as $key) {
        $config->set("colors.$kind.$key", strtoupper((string) $values[$key]));
      }
      $config->set("google.logo_urls.$kind", trim((string) $values['logo_url']));
      $config->set("google.hero_image_urls.$kind", trim((string) $values['hero_image_url']));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * @return string[]
   */
  private function origins(FormStateInterface $form_state): array {
    $lines = preg_split('/\R/', (string) $form_state->getValue('allowed_origins')) ?: [];
    return array_values(array_filter(array_map(static fn(string $l): string => rtrim(trim($l), '/'), $lines)));
  }

}
