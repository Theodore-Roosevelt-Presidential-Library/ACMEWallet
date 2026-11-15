# Snippets

Copy-and-paste pieces for putting the wallet lookup and the official badges in
front of visitors. The badge artwork and its rules are in
[`assets/badges/NOTICE.md`](../assets/badges/NOTICE.md).

| File | Where it goes |
|------|---------------|
| [`web-embed.html`](web-embed.html) | Any web page. The full lookup form; badges appear after a match. |
| [`web-badges.html`](web-badges.html) | A web page that only needs the two badges linking to the lookup page. |
| [`email-badges.html`](email-badges.html) | The ticket and membership confirmation emails. |

**`WALLET_PAGE_URL`** in the badge snippets is the page on www.trlibrary.com
that carries the lookup. It is written as `https://www.trlibrary.com/wallet`
here; change it if the page lands somewhere else.

Email clients do not show SVG, so the email snippet uses the PNG files.

## Three ways to link from a confirmation email

ACME sends the confirmation emails, so the link can only carry what ACME's
email editor can merge in. The lookup form reads all three forms below; which
one to use depends on the merge fields ACME offers.

| | Link | Needs from ACME | What the visitor does |
|---|---|---|---|
| 1. Plain | `WALLET_PAGE_URL` | Nothing | Types the order number and email |
| 2. Prefilled | `WALLET_PAGE_URL?order=<order number>` | Order number merge field | Types the email only |
| 3. One-tap | `WALLET_PAGE_URL#order=<order number>&email=<email>` | Order number and email merge fields | Nothing. The passes appear. |

For the membership email, use `membership=<Membership ID>` in place of
`order=<order number>`.

**Why the one-tap link uses `#`.** Everything after `#` in a web address
stays in the visitor's browser. It is never sent to www.trlibrary.com or to a
link-tracking service, so the email address does not land in server logs,
analytics or referrers. The form reads it, looks the order up, and removes it
from the address bar. An email address after a `?` is ignored on purpose.

**One-tap needs instant delivery.** The module's default is to answer a lookup
by emailing a link, which would send a second email to someone who just tapped
a link in the first. For one-tap, set `delivery_mode` to `instant` in the
module settings. The trade-off is in `fivejars/README.md`.

**Not confirmed yet:** which merge fields ACME's email templates support, and
whether ACME rewrites links for click tracking. A tracking redirect keeps the
`#` part in every current browser, but test one real email before relying on
it. If ACME offers no merge fields, the plain link still works.
