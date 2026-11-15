# What the wallet endpoints return

The contract between the lookup form (`embed.js`, served from
wallet.labs.trlibrary.com) and the module on www.trlibrary.com. If the module
is rebuilt or changed, these are the responses the form depends on.

The reference implementation is
`trpl_wallet/src/Controller/WalletController.php`; the tests in
`trpl_wallet/tests/` cover the lookup, token and signing logic underneath it.

## Summary

| Route | Method | Returns | Called by |
|---|---|---|---|
| `/wallet/api/lookup` | POST | JSON | The form, when a visitor submits it |
| `/wallet/api/passes?t=<token>` | GET | JSON | The form, when opened from an emailed link |
| `/wallet/apple/<token>` | GET | A signed `.pkpass` file | The visitor's phone, from the Apple badge |
| `/wallet/google/<token>` | GET | A 302 redirect to Google | The visitor's phone, from the Google badge |

Both `/wallet/api/*` routes also answer `OPTIONS` with an empty `204` for the
browser's cross-site check.

## On every response

These headers are required on all four routes, including errors:

```
Cache-Control: no-store, private
X-Robots-Tag: noindex
Referrer-Policy: no-referrer
```

When the request's `Origin` is on the allowed list
(`https://wallet.labs.trlibrary.com` and `https://www.trlibrary.com` by
default), add:

```
Access-Control-Allow-Origin: <that origin, echoed back>
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type
Access-Control-Max-Age: 600
Vary: Origin
```

Never send `Access-Control-Allow-Origin: *`, and never let a page cache or CDN
store these responses. One visitor must not receive another visitor's pass.

## POST /wallet/api/lookup

### Request

```http
POST /wallet/api/lookup
Content-Type: application/json

{ "kind": "order", "number": "100000000", "email": "visitor@example.com" }
```

| Field | Type | Rules |
|---|---|---|
| `kind` | string | `"order"` or `"membership"` |
| `number` | string | Order number or membership ID. Non-digits are stripped; 1 to 20 digits must remain. |
| `email` | string | A valid address, 254 characters at most. Must match the email on the order or on an active cardholder. |

### Responses

| HTTP | Body | When | What the form shows |
|---|---|---|---|
| 200 | `{"status":"sent"}` | Email mode (the default). Returned **whether or not anything matched**, so the endpoint cannot be used to test which orders or emails exist. If there was a match, an email with a one-time link has been sent. | "If that number and email match, a link is on its way." |
| 200 | `{"status":"ok", "passes":[…], "google_all_url":…}` | Instant mode, with a match. See [the pass list](#the-pass-list). | The passes with their badges |
| 200 | `{"status":"not_found"}` | Instant mode, no match, or everything on the order is in the past or inactive. | "No match" message |
| 400 | `{"status":"invalid"}` | Missing or malformed `kind`, `number` or `email`, or a body that is not JSON. | "No match" message |
| 429 | `{"status":"slow_down"}` | Rate limit reached: 10 lookups per IP address or 5 per order number in 15 minutes (configurable). | "Too many tries. Wait about 15 minutes." |
| 503 | `{"status":"unavailable"}` | The feature is switched off, a secret is missing, or ACME did not answer. | "Not available right now. The QR codes in the confirmation email still work." |

The form decides by the `status` string first and the HTTP code second, so
both must be right.

## GET /wallet/api/passes?t=&lt;token&gt;

Used when a visitor opens the emailed link. The page carries `?t=<token>` and
the form passes it straight through.

| HTTP | Body | When |
|---|---|---|
| 200 | `{"status":"ok", "passes":[…], "google_all_url":…}` | The token is valid and at least one pass is still current. |
| 410 | `{"status":"expired"}` | The token is missing, altered, older than 24 hours, or nothing behind it is current any more. |
| 503 | `{"status":"unavailable"}` | Switched off or not configured. |

## The pass list

Returned by both JSON routes when `status` is `ok`.

```json
{
  "status": "ok",
  "passes": [
    {
      "kind": "ticket",
      "title": "General Admission",
      "type": "Adult",
      "holder": "Sample Visitor",
      "starts": "2026-11-20T13:00:00-07:00",
      "expires": "2026-11-21T23:59:59-07:00",
      "apple_url": "https://www.trlibrary.com/wallet/apple/eyJy…",
      "google_url": "https://www.trlibrary.com/wallet/google/eyJy…"
    },
    {
      "kind": "membership",
      "title": "Family",
      "type": "Member",
      "holder": "Sample Member",
      "starts": "2026-01-01T00:00:00-07:00",
      "expires": "2027-12-31T23:59:59-07:00",
      "apple_url": "https://www.trlibrary.com/wallet/apple/eyJy…",
      "google_url": "https://www.trlibrary.com/wallet/google/eyJy…"
    }
  ],
  "google_all_url": "https://www.trlibrary.com/wallet/google/eyJy…"
}
```

One entry per ticket or per membership card. A real response holds one kind
only; both are shown here for reference.

| Field | Type | Notes |
|---|---|---|
| `kind` | string | `"ticket"` or `"membership"` |
| `title` | string | Event name for a ticket; membership level for a card |
| `type` | string | Ticket type ("Adult", "Youth") or "Member" |
| `holder` | string | Ticket holder or cardholder name |
| `starts` | string or null | ISO 8601 with offset. Entry time for a ticket; start of benefits for a card, when ACME has one. |
| `expires` | string or null | ISO 8601 with offset. The end of the day after the visit for a ticket; the membership end date for a card. |
| `apple_url` | string or null | Absolute link to the Apple route. `null` until the Apple certificate is installed. |
| `google_url` | string or null | Absolute link to the Google route. `null` until the Google key is installed. |
| `google_all_url` | string or null | One link that saves every pass to Google Wallet at once. `null` when there is only one pass or Google is not set up. Apple has no equivalent. |

Rules that matter:

- **No barcode, QR payload or card number in JSON, ever.** The browser gets
  labels and links only. The code that scans at the door exists only inside
  the signed pass.
- **Links are short-lived.** The tokens in `apple_url` and `google_url` last
  15 minutes. They carry ACME record ids, signed with HMAC-SHA256; they are
  not the barcode and cannot be turned into one.
- **Past visits and inactive cards are left out**, so a list can be shorter
  than the order.
- **At most 20 passes** per response.
- When a wallet is not set up yet its link is `null` and the form hides that
  badge. If both are `null` the form says passes are not switched on yet.

## GET /wallet/apple/&lt;token&gt;

| HTTP | Response | When |
|---|---|---|
| 200 | The pass file. `Content-Type: application/vnd.apple.pkpass`, `Content-Disposition: attachment; filename="trlibrary-ticket.pkpass"` (or `trlibrary-membership.pkpass`), `Content-Length` set. | The token is valid and names exactly one pass. |
| 410 | Plain text: "This link has expired. Please look up your order again." | Token missing, altered, expired, or naming more than one pass. |
| 503 | Plain text: "Wallet passes are not available right now." | Switched off, certificate missing, or signing failed. |

The content type must be exact. Safari on iPhone opens the Add to Wallet sheet
only for `application/vnd.apple.pkpass`; anything else downloads a file the
visitor cannot use.

## GET /wallet/google/&lt;token&gt;

| HTTP | Response | When |
|---|---|---|
| 302 | `Location: https://pay.google.com/gp/v/save/<signed JWT>` | The token is valid. One pass, or several for `google_all_url`. |
| 410 | Plain text: "This link has expired. Please look up your order again." | Token missing, altered or expired. |
| 503 | Plain text: "Wallet passes are not available right now." | Switched off, key missing, or signing failed. |

The JWT is signed with the service account key (RS256) and carries the pass
classes and objects, so nothing is created at Google until the visitor taps
Save.

## What the module expects from ACME

The other side of the contract, and the part not yet proven against the live
API. The fields the module reads, and the two endpoints that are assumptions,
are listed under "ACME details to verify against the live API" in
[`README.md`](README.md).

## Quick checks with curl

```sh
# Email mode: always "sent", match or not.
curl -si https://www.trlibrary.com/wallet/api/lookup \
  -H 'Content-Type: application/json' \
  -H 'Origin: https://wallet.labs.trlibrary.com' \
  -d '{"kind":"order","number":"100000000","email":"visitor@example.com"}'

# Expect: 200, {"status":"sent"}, Cache-Control: no-store, private,
# and Access-Control-Allow-Origin echoing the Origin above.

# A bad body.
curl -si https://www.trlibrary.com/wallet/api/lookup \
  -H 'Content-Type: application/json' -d '{"kind":"order"}'
# Expect: 400, {"status":"invalid"}

# An altered token.
curl -si 'https://www.trlibrary.com/wallet/api/passes?t=not-a-token'
# Expect: 410, {"status":"expired"}
```
