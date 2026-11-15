# ACMEWallet

Apple Wallet and Google Wallet passes for Theodore Roosevelt Presidential
Library tickets and memberships, which are sold through ACME Ticketing.

**Site:** https://wallet.labs.trlibrary.com · **Status:** demo mode. The live
lookup is not switched on yet.

## Two halves

| Where | What | Secrets |
|---|---|---|
| This repo's root, published with GitHub Pages | Lookup form (`embed.js`), landing page, [pass preview](preview/) and public images | None, ever |
| [`fivejars/`](fivejars/), installed on www.trlibrary.com | Drupal module that checks the order in ACME, signs each pass and serves it | All of them, in `settings.php` on the host |

GitHub holds code only. No API key, certificate or service account file
belongs in this repository or in its Actions secrets.

## The public site

- `index.html`: landing page with the lookup, in demo mode.
- `embed.js`: the lookup as a drop-in. Usage is in the file header.
- `preview/`: what the passes look like, with editable sample details.
- `assets/`: styles, brand images, and a vendored QR library (MIT).
- `assets/badges/`: the official Add to Google Wallet and Add to Apple Wallet
  artwork. Not MIT; see [`assets/badges/NOTICE.md`](assets/badges/NOTICE.md).
- `snippets/`: copy-and-paste code for web pages and confirmation emails.
- `scripts/build_pass_art.py`: rebuilds pass logos and icons from the
  [brand kit](https://brand.labs.trlibrary.com). Tickets are Night Sky,
  membership cards Dark Forest.

Preview locally with `python3 -m http.server` from the repo root.

**Going live:** once the module is running on www.trlibrary.com, remove
`data-demo="true"` from the embed in `index.html`.

## The Drupal module

Start with [`fivejars/README.md`](fivejars/README.md): routes, install steps,
the security checklist, and the list of ACME details still to verify.

## One-time setup for the site

1. Repo Settings → Pages → Source: **GitHub Actions**.
2. DNS: `CNAME wallet.labs` → `theodore-roosevelt-presidential-library.github.io`.
3. Merge to `main`; the Deploy site workflow publishes it.

## Before any visitor uses this

- [ ] ACME API returns the same barcode strings the emails carry.
- [ ] Apple Developer account, Pass Type ID and certificate.
- [ ] Google Wallet issuer account and service account.
- [ ] A real pass on an iPhone and an Android phone scans at the door.
- [ ] Marketing & Communications signs off on the pass artwork on a real phone.
