<?php

namespace Drupal\trpl_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\trpl_wallet\Core\AcmeException;
use Drupal\trpl_wallet\Core\PassData;
use Drupal\trpl_wallet\WalletFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public endpoints for wallet passes.
 *
 * - POST /wallet/api/lookup      order or membership number + email
 * - GET  /wallet/api/passes?t=   passes behind an emailed link
 * - GET  /wallet/apple/{token}   one signed .pkpass
 * - GET  /wallet/google/{token}  redirect to a Save to Google Wallet link
 *
 * Nothing here is cacheable, nothing is stored, and logs carry no names,
 * emails, order numbers or barcodes.
 */
class WalletController extends ControllerBase {

  public function __construct(
    private readonly WalletFactory $wallet,
    private readonly FloodInterface $flood,
    private readonly MailManagerInterface $mail,
    private readonly LoggerInterface $log,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('trpl_wallet.factory'),
      $container->get('flood'),
      $container->get('plugin.manager.mail'),
      $container->get('logger.factory')->get('trpl_wallet'),
    );
  }

  /**
   * Looks up an order or membership and returns or emails its passes.
   */
  public function lookup(Request $request): Response {
    if ($request->isMethod('OPTIONS')) {
      return $this->finish(new Response('', 204), $request);
    }
    if ($problem = $this->unavailable()) {
      return $this->finish($problem, $request);
    }

    $input = json_decode($request->getContent(), TRUE);
    $kind = is_array($input) ? (string) ($input['kind'] ?? '') : '';
    $number = is_array($input) ? preg_replace('/\D+/', '', (string) ($input['number'] ?? '')) : '';
    $email = is_array($input) ? trim((string) ($input['email'] ?? '')) : '';
    if (!in_array($kind, ['order', 'membership'], TRUE) || $number === '' || strlen($number) > 20
      || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $this->finish(new JsonResponse(['status' => 'invalid'], 400), $request);
    }

    // Rate limits: by visitor, and by the number being tried, so neither one
    // address nor one order number can be hammered.
    $config = $this->wallet->config();
    $window = (int) $config->get('limits.window_seconds');
    $ip = (string) $request->getClientIp();
    $numberKey = $kind . ':' . hash('sha256', $number);
    if (!$this->flood->isAllowed('trpl_wallet.ip', (int) $config->get('limits.per_ip'), $window, $ip)
      || !$this->flood->isAllowed('trpl_wallet.number', (int) $config->get('limits.per_number'), $window, $numberKey)) {
      return $this->finish(new JsonResponse(['status' => 'slow_down'], 429), $request);
    }
    $this->flood->register('trpl_wallet.ip', $window, $ip);
    $this->flood->register('trpl_wallet.number', $window, $numberKey);

    try {
      $lookup = $this->wallet->lookup();
      $passes = $kind === 'order'
        ? $lookup->ticketsForOrder($number, $email)
        : $lookup->cardsForMembership($number, $email);
    }
    catch (AcmeException $e) {
      $this->log->error('ACME lookup failed: @message', ['@message' => $e->getMessage()]);
      return $this->finish(new JsonResponse(['status' => 'unavailable'], 503), $request);
    }

    if ($config->get('delivery_mode') === 'email') {
      // The answer is the same whether or not anything was found, so this
      // endpoint cannot be used to test which orders or emails exist.
      if ($passes) {
        $this->sendLink($email, $passes);
      }
      return $this->finish(new JsonResponse(['status' => 'sent']), $request);
    }

    if (!$passes) {
      return $this->finish(new JsonResponse(['status' => 'not_found']), $request);
    }
    return $this->finish(new JsonResponse(['status' => 'ok'] + $this->describe($passes)), $request);
  }

  /**
   * Lists the passes behind an emailed link.
   */
  public function passes(Request $request): Response {
    if ($request->isMethod('OPTIONS')) {
      return $this->finish(new Response('', 204), $request);
    }
    if ($problem = $this->unavailable()) {
      return $this->finish($problem, $request);
    }
    $passes = $this->passesFromToken((string) $request->query->get('t', ''));
    if (!$passes) {
      return $this->finish(new JsonResponse(['status' => 'expired'], 410), $request);
    }
    return $this->finish(new JsonResponse(['status' => 'ok'] + $this->describe($passes)), $request);
  }

  /**
   * Serves one signed Apple Wallet pass.
   */
  public function apple(string $token, Request $request): Response {
    if ($this->unavailable() || !$this->wallet->appleReady()) {
      return $this->finish(new Response('Wallet passes are not available right now.', 503), $request);
    }
    $passes = $this->passesFromToken($token);
    if (count($passes) !== 1) {
      return $this->finish(new Response('This link has expired. Please look up your order again.', 410), $request);
    }
    try {
      $file = $this->wallet->apple()->build($passes[0]);
    }
    catch (\Throwable $e) {
      $this->log->error('Apple pass could not be built: @message', ['@message' => $e->getMessage()]);
      return $this->finish(new Response('Wallet passes are not available right now.', 503), $request);
    }
    return $this->finish(new Response($file, 200, [
      'Content-Type' => 'application/vnd.apple.pkpass',
      'Content-Disposition' => 'attachment; filename="trlibrary-' . $passes[0]->kind . '.pkpass"',
      'Content-Length' => (string) strlen($file),
    ]), $request);
  }

  /**
   * Redirects to a Save to Google Wallet link for one or more passes.
   */
  public function google(string $token, Request $request): Response {
    if ($this->unavailable() || !$this->wallet->googleReady()) {
      return $this->finish(new Response('Wallet passes are not available right now.', 503), $request);
    }
    $passes = $this->passesFromToken($token);
    if (!$passes) {
      return $this->finish(new Response('This link has expired. Please look up your order again.', 410), $request);
    }
    try {
      $url = $this->wallet->google()->saveUrl($passes);
    }
    catch (\Throwable $e) {
      $this->log->error('Google Wallet link could not be built: @message', ['@message' => $e->getMessage()]);
      return $this->finish(new Response('Wallet passes are not available right now.', 503), $request);
    }
    return $this->finish(new TrustedRedirectResponse($url, 302), $request);
  }

  /**
   * A 503 when the feature is switched off or not configured, else NULL.
   */
  private function unavailable(): ?Response {
    if (!$this->wallet->config()->get('enabled') || $this->wallet->missing()) {
      return new JsonResponse(['status' => 'unavailable'], 503);
    }
    return NULL;
  }

  /**
   * @return \Drupal\trpl_wallet\Core\PassData[]
   */
  private function passesFromToken(string $token): array {
    $payload = $token === '' ? NULL : $this->wallet->tokens()->verify($token);
    if (!$payload || !is_array($payload['r'] ?? NULL)) {
      return [];
    }
    try {
      $lookup = $this->wallet->lookup();
      $passes = [];
      foreach (array_slice($payload['r'], 0, 20) as $ref) {
        if (is_array($ref) && ($pass = $lookup->passFromRef($ref))) {
          $passes[] = $pass;
        }
      }
      return $passes;
    }
    catch (AcmeException $e) {
      $this->log->error('ACME lookup failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * What the browser gets: labels and short-lived links, never barcodes.
   *
   * @param \Drupal\trpl_wallet\Core\PassData[] $passes
   *
   * @return array<string,mixed>
   */
  private function describe(array $passes): array {
    $ttl = (int) $this->wallet->config()->get('limits.pass_token_seconds');
    $tokens = $this->wallet->tokens();
    $apple = $this->wallet->appleReady();
    $google = $this->wallet->googleReady();
    $items = [];
    foreach ($passes as $pass) {
      $token = $tokens->sign(['r' => [$pass->ref]], $ttl);
      $items[] = $pass->summary() + [
        'apple_url' => $apple ? $this->link('trpl_wallet.apple', $token) : NULL,
        'google_url' => $google ? $this->link('trpl_wallet.google', $token) : NULL,
      ];
    }
    $all = $tokens->sign(['r' => array_map(static fn(PassData $p): array => $p->ref, $passes)], $ttl);
    return [
      'passes' => $items,
      'google_all_url' => $google && count($passes) > 1 ? $this->link('trpl_wallet.google', $all) : NULL,
    ];
  }

  /**
   * @param \Drupal\trpl_wallet\Core\PassData[] $passes
   */
  private function sendLink(string $email, array $passes): void {
    $config = $this->wallet->config();
    $token = $this->wallet->tokens()->sign(
      ['r' => array_map(static fn(PassData $p): array => $p->ref, array_slice($passes, 0, 20))],
      (int) $config->get('limits.link_token_seconds'),
    );
    $page = (string) $config->get('pass_page_url');
    $this->mail->mail('trpl_wallet', 'pass_link', $email, $this->languageManager()->getDefaultLanguage()->getId(), [
      'link' => $page . (str_contains($page, '?') ? '&' : '?') . 't=' . $token,
      'is_ticket' => $passes[0]->isTicket(),
      'hours' => max(1, (int) round((int) $config->get('limits.link_token_seconds') / 3600)),
    ]);
  }

  private function link(string $route, string $token): string {
    return Url::fromRoute($route, ['token' => $token], ['absolute' => TRUE])->toString();
  }

  /**
   * Adds the headers every response from this controller must carry.
   */
  private function finish(Response $response, Request $request): Response {
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Robots-Tag', 'noindex');
    $response->headers->set('Referrer-Policy', 'no-referrer');
    $origin = (string) $request->headers->get('Origin', '');
    if ($origin !== '' && in_array($origin, (array) $this->wallet->config()->get('allowed_origins'), TRUE)) {
      $response->headers->set('Access-Control-Allow-Origin', $origin);
      $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
      $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
      $response->headers->set('Access-Control-Max-Age', '600');
      $response->headers->set('Vary', 'Origin');
    }
    return $response;
  }

}
