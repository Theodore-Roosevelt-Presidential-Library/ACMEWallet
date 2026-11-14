# Hand-off for FiveJars: wallet passes on www.trlibrary.com

This folder holds `trpl_wallet`, a Drupal module that lets visitors add their
ACME Ticketing tickets and membership cards to Apple Wallet and Google Wallet.

The public pieces (lookup form, buttons, pass preview) are a static site at
wallet.labs.trlibrary.com, built from the root of this repository. That site
holds no keys. Everything that needs a secret runs in this module on
www.trlibrary.com.

**Status: reference build, not yet run inside Drupal.** The core library is
tested (see "What has been tested"). The Drupal wrapper is linted but has not
been installed on a site. Please treat it as a detailed spec in code and
change whatever your standards call for.

## What the module does

| Route | Method | Purpose |
|---|---|---|
| `/wallet/api/lookup` | POST | Takes `{kind, number, email}`. Checks ACME. Emails a one-time link, or returns the passes. |
| `/wallet/api/passes?t=` | GET | Lists the passes behind an emailed link. |
| `/wallet/apple/{token}` | GET | Returns one signed `.pkpass`. |
| `/wallet/google/{token}` | GET | Redirects to a signed Save to Google Wallet link. |
| `/admin/config/services/trpl-wallet` | | Settings and a readiness check. |

`kind` is `order` (order number + email) or `membership` (Membership ID +
email). Both values must match the ACME record.

Nothing is stored. Tokens are HMAC-signed, expire, and carry only ACME ids.
Each download re-reads ACME, so a refunded ticket or lapsed card stops working.

## Layout

```
trpl_wallet/
  src/Core/          No Drupal dependencies. ACME client, lookup rules,
                     token signer, Apple pass builder, Google link builder.
  src/Controller/    The four public routes.
  src/Form/          Settings form.
  src/Http/          Guzzle adapter for the core.
  src/WalletFactory  Builds core objects from config and settings.php.
  config/            Default config and schema (no secrets).
  pass_assets/       Pass images built from the brand kit, per pass kind.
  tests/             PHPUnit tests for src/Core, with fake ACME data.
  settings.example.php
```

`src/Core` is the part worth keeping as is. The rest is ordinary Drupal glue.

## Install

1. Copy `trpl_wallet/` to `web/modules/custom/trpl_wallet`. PHP 8.1+, with the
   `openssl` and `zip` extensions.
2. Add the block from `settings.example.php` to `settings.php`, reading each
   value from the host's secret store. Put the certificate files outside the
   web root.
3. Enable the module. It installs switched **off**.
4. Fill in `/admin/config/services/trpl-wallet`. The Status box shows what is
   still missing.
5. Test on staging against the ACME sandbox, then switch on.

## Secrets (settings.php only)

| Key | What |
|---|---|
| `acme_api_key` | ACME API key. Ask for read-only. |
| `token_secret` | 32+ random characters. Rotating it expires every outstanding link. |
| `apple_certificate_path`, `apple_private_key_path`, `apple_private_key_password` | The Library's Pass Type ID certificate and key, as PEM. |
| `apple_wwdr_certificate_path` | Apple's WWDR intermediate certificate, as PEM. |
| `google_service_account_path` | Google service account JSON for the Wallet issuer. |

These keys can mint passes that scan at the door. They must not appear in
git, config export, the database, logs or error pages. If you already use the
Key module, swap `WalletFactory::secret()` to read from it.

## Security checklist

- [ ] **No caching.** Routes set `no_cache` and responses send
      `Cache-Control: no-store, private`. Please confirm behind the CDN that
      two different tokens never return the same body.
- [ ] **Rate limits.** Core flood control, per IP and per number. Confirm
      `getClientIp()` sees the real visitor behind the proxy
      (`reverse_proxy` settings), or every visitor shares one limit.
- [ ] **CORS.** Only origins listed in settings get the header.
- [ ] **Email mode is the default.** The response is identical whether or not
      a match was found, and the link goes only to the address on the order.
      "Instant" mode is there for kiosks or testing; order numbers look
      sequential, so do not use it publicly without a reason.
- [ ] **Logs.** The module logs failures without names, emails, numbers,
      tokens or barcodes. Check that web server access logs do not keep the
      `{token}` path segment longer than needed; tokens last 15 minutes.
- [ ] **Kill switch.** Unticking "Wallet passes are on" returns 503 on every
      route.
- [ ] **Mail.** The link email goes through Drupal mail. Confirm it sends from
      an address that passes SPF/DKIM for trlibrary.com.

## ACME details to verify against the live API

Taken from ACME's public documentation and from real confirmation emails.
Every one of these lives in `AcmeClient` or `LookupService`.

| Assumption | Where it came from | Status |
|---|---|---|
| Ticket barcode is `orderItems[].ticketVos[].uuid`, a string starting `at` | API docs; matches the QR in real emails | Format confirmed in emails; field not yet read from API |
| Membership barcode is `cardholders[].barcode`, nine digits per cardholder | Sample response; matches real emails | Format confirmed in emails; field not yet read from API |
| `GET /v2/b2c/orders/search?email=` then `GET /v2/b2c/orders/{id}` | API docs | Not yet run |
| Headers `x-acme-api-key` and `x-b2c-tenant-id` (tenant 613) | API docs | Not yet run |
| `GET /v2/b2b/memberships` accepts a membership-number filter | **Guess.** Docs show the endpoint, not its filters | Must check. Code re-checks the number on each result, so a wrong filter is slow, not unsafe |
| `GET /v2/b2b/memberships/{id}` | **Guess** | Must check |
| Order has `firstName` / `lastName` | **Guess** | Must check; only affects the name shown |
| Visitor-facing "Membership ID" equals `acmeMembershipNumber` | Emails show a nine-digit ID | Must check |
| Cancelled or refunded tickets drop out of the order response | Unknown | Must check; add a status filter if not |

ACME publishes no rate limits and no webhooks that we could find. The module
makes one to three ACME calls per lookup and per download.

## Pass artwork and colors

Artwork comes from the Library's brand kit (brand.labs.trlibrary.com) and is
built by `scripts/build_pass_art.py` at the repo root. Logos are scaled and
placed on a brand background color, never recolored or redrawn.

| Kind | Background | Text | Labels |
|---|---|---|---|
| Ticket | Night Sky `#092A4D` | White | Gray Sky `#99ADC5` |
| Membership card | Dark Forest `#1B4532` | White | Sand `#D1CCBD` |

Colors live in config (`colors.ticket`, `colors.membership`) and on the
settings form.

| File in `pass_assets/<kind>/` | Size (px) | What |
|---|---|---|
| `icon.png`, `@2x`, `@3x` | 29, 58, 87 square | TR Brand Mark on the background color. Apple requires it |
| `logo.png`, `@2x`, `@3x` | 50, 100, 150 tall | Stacked logo, white, transparent. Top left of the Apple pass |
| `strip.png`, `@2x`, `@3x` | 375x98, 750x196, 1125x294 | Optional photo band on Apple tickets. Not supplied; add a cleared photo from the Library's asset library and it is picked up automatically |

Google loads images by URL. The round logos are on the Pages site
(`assets/img/google-logo-ticket.png`, `google-logo-membership.png`) and are
already in the default config. The optional wide photo (1032x336) goes in
`google.hero_image_urls`.

Open with Marketing & Communications: sign-off on logo size on a real phone,
the Dark Forest and Sand pairing, and the TR monogram app icon, which the
brand kit lists as still to come. The TR Brand Mark stands in until then.

## Not in this build

- **Pushed updates.** Apple needs five extra web-service routes, a small table
  of device registrations and an APNs call; Google needs REST calls to patch
  objects. Planned as the next phase. Until then a pass is correct when added
  and each Apple pass carries an expiry.
- **Official "Add to Wallet" badges.** The embed uses plain text buttons.
  Apple's and Google's badge artwork must be downloaded from them and used
  unaltered; drop the files into the Pages site and style `.trw-btn`.
- **Existing libraries.** The signer is about 250 lines with no dependencies.
  If you would rather maintain a library, `pkpass/pkpass` (Apple) and
  `firebase/php-jwt` (Google) replace `ApplePassBuilder::sign()`/`zip()` and
  `GoogleWalletLink::jwt()` cleanly.

## What has been tested

```
cd fivejars/trpl_wallet
composer install
composer test
```

The tests use fake ACME responses and a throwaway self-signed certificate, so
they need no real secret. They cover: lookup rules (both values must match,
past events and inactive cards excluded), token signing and tampering, that
the Apple pass is a well-formed archive whose signature verifies with OpenSSL,
and that the Google link is a JWT whose signature verifies.

They do **not** prove that Apple accepts the pass (that needs the Library's
real certificate and an iPhone), that Google accepts the link (needs the real
issuer), or that a pass scans at the door.
