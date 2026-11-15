# Snippets

Copy-and-paste pieces for putting the wallet lookup and the official badges in
front of visitors. The badge artwork and its rules are in
[`assets/badges/NOTICE.md`](../assets/badges/NOTICE.md).

| File | Where it goes |
|------|---------------|
| [`web-embed.html`](web-embed.html) | Any web page. The full lookup form; badges appear after a match. |
| [`web-badges.html`](web-badges.html) | A web page that only needs the two badges linking to the lookup page. |
| [`email-badges.html`](email-badges.html) | The ticket and membership confirmation emails. |

One thing to set before use: **`WALLET_PAGE_URL`** in the badge snippets is
the page on www.trlibrary.com that carries the lookup. It is written as
`https://www.trlibrary.com/wallet` here; change it if the page lands somewhere
else.

Email clients do not show SVG, so the email snippet uses the PNG files.
