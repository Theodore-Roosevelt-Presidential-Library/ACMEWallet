# Snippets

Copy-and-paste pieces for putting the wallet lookup and the official badges in
front of visitors. The badge artwork and its rules are in
[`assets/badges/NOTICE.md`](../assets/badges/NOTICE.md).

| File | Where it goes |
|------|---------------|
| [`web-embed.html`](web-embed.html) | Any web page. The full lookup form; badges appear after a match. |
| [`web-badges.html`](web-badges.html) | A web page that only needs the two badges linking to the lookup page. |
| [`email-badges.html`](email-badges.html) | The ticket and membership confirmation emails. |

Two things to set before use:

1. **`WALLET_PAGE_URL`** in the badge snippets is the page on
   www.trlibrary.com that carries the lookup. It is written as
   `https://www.trlibrary.com/wallet` here; change it if the page lands
   somewhere else.
2. **Apple's badge** is not in the repository until the Library accepts
   Apple's agreement and adds the file. Leave the Apple half of a snippet out
   until then.

Email clients do not show SVG, so the email snippet uses the PNG files.
