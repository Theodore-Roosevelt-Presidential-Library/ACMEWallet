/*!
 * TRPL Wallet embed. https://wallet.labs.trlibrary.com
 *
 * Drop-in "Add to Wallet" lookup for ACME tickets and memberships.
 *
 *   <div data-trpl-wallet></div>
 *   <script src="https://wallet.labs.trlibrary.com/embed.js" async></script>
 *
 * Options, as attributes on the div:
 *   data-endpoint  Base of the lookup API. Default https://www.trlibrary.com/wallet/api
 *   data-kind      "order" (default) or "membership": which tab opens first.
 *   data-demo      "true" shows sample passes and never calls the API.
 *
 * This file holds no keys and signs nothing. It only talks to the lookup API
 * on www.trlibrary.com, which answers with labels and short-lived links.
 */
(function () {
  'use strict';

  var DEFAULT_ENDPOINT = 'https://www.trlibrary.com/wallet/api';
  var HOME = 'https://wallet.labs.trlibrary.com';
  // Folder this script was loaded from, so the demo links work on any host.
  var BASE = (document.currentScript && document.currentScript.src)
    ? new URL('.', document.currentScript.src).href
    : HOME + '/';

  var CSS = [
    '.trw{font-family:"Frutiger Next",Arial,Helvetica,sans-serif;color:#25282A;max-width:460px;line-height:1.45;font-size:16px}',
    '.trw *{box-sizing:border-box}',
    '.trw-tabs{display:flex;gap:4px;margin:0 0 16px;border-bottom:2px solid #cfd6df}',
    '.trw-tab{flex:1;padding:10px 8px;border:0;background:none;font:inherit;font-weight:700;color:#5b6166;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px}',
    '.trw-tab[aria-selected="true"]{color:#092A4D;border-bottom-color:#092A4D}',
    '.trw label{display:block;font-weight:700;font-size:14px;margin:12px 0 4px}',
    '.trw input{width:100%;padding:12px;border:1px solid #8a9097;border-radius:4px;font:inherit;background:#fff;color:inherit}',
    '.trw input:focus,.trw button:focus-visible,.trw a:focus-visible{outline:3px solid #99ADC5;outline-offset:2px}',
    '.trw-hint{font-size:13px;color:#5b6166;margin:4px 0 0}',
    '.trw-go{margin-top:16px;width:100%;padding:14px;border:0;border-radius:4px;background:#E7805D;color:#25282A;font:inherit;font-weight:700;cursor:pointer}',
    '.trw-go[disabled]{opacity:.6;cursor:default}',
    '.trw-msg{margin:16px 0 0;padding:12px 14px;border-radius:4px;background:#eef1f5;border-left:4px solid #99ADC5}',
    '.trw-msg.trw-bad{border-left-color:#25282A}',
    '.trw-pass{border:1px solid #cfd6df;border-left:6px solid #092A4D;border-radius:6px;padding:14px;margin:12px 0 0;background:#fff}',
    '.trw-pass.trw-m{border-left-color:#1B4532}',
    '.trw-pass h3{margin:0;font-size:17px;color:#092A4D}',
    '.trw-pass.trw-m h3{color:#1B4532}',
    '.trw-pass p{margin:2px 0 0;font-size:14px;color:#454a4f}',
    '.trw-btns{display:flex;flex-wrap:wrap;align-items:center;gap:0;margin:6px 0 -8px -8px}',
    '.trw-btn{display:inline-block;padding:11px 14px;border-radius:6px;background:#E7805D;color:#25282A;text-decoration:none;font-weight:700;font-size:15px}',
    '.trw-btn.trw-off{background:#8a9097;pointer-events:none}',
    '.trw-badge{display:inline-block;padding:8px;line-height:0;border-radius:8px}',
    '.trw-badge img{display:block;height:48px;width:auto}',
    '.trw-badge:focus-visible{outline:3px solid #092A4D;outline-offset:-4px}',
    '.trw-btns .trw-btn,.trw-all .trw-btn{margin:8px}',
    '.trw-all p{margin:0;font-size:14px;color:#454a4f}',
    '.trw-all .trw-badge{margin-left:-8px}',
    '.trw-all{margin-top:14px}',
    '.trw-again{margin-top:16px;background:none;border:0;padding:0;color:#092A4D;text-decoration:underline;font:inherit;cursor:pointer}',
    '.trw-demo{font-size:13px;background:#99ADC5;color:#25282A;padding:6px 10px;border-radius:4px;margin:0 0 12px;display:inline-block;font-weight:700}',
    '.trw-qr{margin-top:14px;font-size:14px;color:#454a4f}',
    '.trw-qr svg{display:block;margin:8px 0;background:#fff}'
  ].join('');

  // Badge artwork, served from this site. See assets/badges/NOTICE.md.
  var BADGES = {
    apple: { src: 'assets/badges/apple/add-to-apple-wallet.svg', alt: 'Add to Apple Wallet' },
    google: { src: 'assets/badges/google/enUS_add_to_google_wallet_wallet-button.svg', alt: 'Add to Google Wallet' }
  };

  var COPY = {
    order: {
      tab: 'Tickets',
      number: 'Order number',
      hint: 'Nine digits, shown near the top of the confirmation email.',
      placeholder: '100000000'
    },
    membership: {
      tab: 'Membership',
      number: 'Membership ID',
      hint: 'Nine digits, shown as "ID" on the membership email.',
      placeholder: '300000000'
    }
  };

  function device() {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'apple';
    if (/Android/.test(ua)) return 'google';
    return 'desktop';
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'text') node.textContent = attrs[key];
      else if (key === 'class') node.className = attrs[key];
      else if (attrs[key] !== null && attrs[key] !== undefined) node.setAttribute(key, attrs[key]);
    });
    (children || []).forEach(function (child) { if (child) node.appendChild(child); });
    return node;
  }

  function when(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d)) return '';
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }

  function clock(iso) {
    var d = iso ? new Date(iso) : null;
    return d && !isNaN(d) ? d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '';
  }

  function demoPasses(kind) {
    var soon = new Date(Date.now() + 6 * 86400000);
    soon.setHours(13, 0, 0, 0);
    var nextYear = new Date(new Date().getFullYear() + 1, 11, 31);
    var preview = BASE + 'preview/';
    if (kind === 'membership') {
      return {
        status: 'ok',
        google_all_url: preview,
        passes: [
          { kind: 'membership', title: 'Family', type: 'Member', holder: 'Sample Member', expires: nextYear.toISOString(), apple_url: preview, google_url: preview },
          { kind: 'membership', title: 'Family', type: 'Member', holder: 'Second Member', expires: nextYear.toISOString(), apple_url: preview, google_url: preview }
        ]
      };
    }
    return {
      status: 'ok',
      google_all_url: preview,
      passes: [
        { kind: 'ticket', title: 'General Admission', type: 'Adult', holder: 'Sample Visitor', starts: soon.toISOString(), apple_url: preview, google_url: preview },
        { kind: 'ticket', title: 'General Admission', type: 'Youth', holder: 'Sample Visitor', starts: soon.toISOString(), apple_url: preview, google_url: preview }
      ]
    };
  }

  function mount(host) {
    if (host.getAttribute('data-trpl-wallet-ready')) return;
    host.setAttribute('data-trpl-wallet-ready', '1');

    var endpoint = (host.getAttribute('data-endpoint') || DEFAULT_ENDPOINT).replace(/\/+$/, '');
    var demo = host.getAttribute('data-demo') === 'true';
    var kind = host.getAttribute('data-kind') === 'membership' ? 'membership' : 'order';
    var root = el('div', { 'class': 'trw' });
    host.appendChild(root);

    function clear() { while (root.firstChild) root.removeChild(root.firstChild); }

    function banner() {
      return demo ? el('p', { 'class': 'trw-demo', text: 'Demo: sample passes only' }) : null;
    }

    function message(text, bad) {
      return el('p', { 'class': 'trw-msg' + (bad ? ' trw-bad' : ''), role: bad ? 'alert' : 'status', text: text });
    }

    function showForm(note, bad) {
      clear();
      var copy = COPY[kind];
      var tabs = el('div', { 'class': 'trw-tabs', role: 'tablist', 'aria-label': 'What to look up' },
        ['order', 'membership'].map(function (k) {
          var tab = el('button', { type: 'button', 'class': 'trw-tab', role: 'tab', 'aria-selected': String(k === kind), text: COPY[k].tab });
          tab.addEventListener('click', function () { kind = k; showForm(); });
          return tab;
        }));
      var id = 'trw' + Math.random().toString(36).slice(2, 8);
      var number = el('input', { id: id + 'n', inputmode: 'numeric', autocomplete: 'off', required: '', maxlength: '20', placeholder: copy.placeholder, 'aria-describedby': id + 'h' });
      var email = el('input', { id: id + 'e', type: 'email', autocomplete: 'email', required: '', maxlength: '254' });
      var go = el('button', { type: 'submit', 'class': 'trw-go', text: 'Find my ' + (kind === 'order' ? 'tickets' : 'membership cards') });
      var form = el('form', { novalidate: '' }, [
        el('label', { 'for': id + 'n', text: copy.number }), number,
        el('p', { 'class': 'trw-hint', id: id + 'h', text: copy.hint }),
        el('label', { 'for': id + 'e', text: 'Email used for the ' + (kind === 'order' ? 'order' : 'membership') }), email,
        go
      ]);
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var n = number.value.replace(/\D+/g, '');
        var e = email.value.trim();
        if (!n || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e)) {
          showForm('Enter the ' + (kind === 'order' ? 'order number' : 'Membership ID') + ' and the email address used for it.', true);
          return;
        }
        go.disabled = true;
        go.textContent = 'Looking…';
        lookup(n, e);
      });
      root.appendChild(banner() || document.createTextNode(''));
      root.appendChild(tabs);
      root.appendChild(form);
      if (note) root.appendChild(message(note, bad));
    }

    function lookup(number, email) {
      if (demo) {
        window.setTimeout(function () { showPasses(demoPasses(kind)); }, 400);
        return;
      }
      fetch(endpoint + '/lookup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kind: kind, number: number, email: email }),
        credentials: 'omit',
        referrerPolicy: 'no-referrer'
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          handle(data, response.status);
        });
      }).catch(function () {
        showForm('The lookup could not be reached. Check the connection and try again.', true);
      });
    }

    function handle(data, status) {
      if (data.status === 'ok') return showPasses(data);
      if (data.status === 'sent') return showSent();
      if (status === 429) return showForm('Too many tries. Wait about 15 minutes, then try again.', true);
      if (data.status === 'not_found' || data.status === 'invalid') {
        return showForm('No match for that number and email. Check both against the confirmation email. The email must be the one used at purchase.', true);
      }
      showForm('Wallet passes are not available right now. The QR codes in the confirmation email still work at the door.', true);
    }

    function showSent() {
      clear();
      root.appendChild(message('If that number and email match, a link is on its way to that inbox. Open it on the phone coming to the Library. It can take a minute to arrive.'));
      root.appendChild(again());
    }

    function again() {
      var button = el('button', { type: 'button', 'class': 'trw-again', text: 'Look up something else' });
      button.addEventListener('click', function () { showForm(); });
      return button;
    }

    // Official artwork only. Apple and Google do not allow home-made
    // "Add to Wallet" buttons, so each link shows the vendor's own badge,
    // unaltered. If a badge file is missing the link falls back to plain text.
    function walletButton(wallet, url, label) {
      if (!url) return null;
      var badge = BADGES[wallet];
      var link = el('a', { 'class': 'trw-badge', href: url, rel: 'noopener', 'aria-label': label || badge.alt });
      var img = el('img', { src: BASE + badge.src, alt: label || badge.alt, height: '48' });
      img.addEventListener('error', function () {
        link.className = 'trw-btn';
        link.textContent = label || badge.alt;
      });
      link.appendChild(img);
      return link;
    }

    function showPasses(data) {
      clear();
      var where = device();
      root.appendChild(banner() || document.createTextNode(''));
      var passes = data.passes || [];
      var anyLink = false;
      passes.forEach(function (pass) {
        var line = pass.kind === 'ticket'
          ? [pass.type, when(pass.starts), clock(pass.starts)].filter(Boolean).join(' · ')
          : [pass.holder, pass.expires ? 'Valid through ' + when(pass.expires) : ''].filter(Boolean).join(' · ');
        var buttons = el('div', { 'class': 'trw-btns' }, [
          where !== 'google' ? walletButton('apple', pass.apple_url) : null,
          where !== 'apple' ? walletButton('google', pass.google_url) : null
        ]);
        if (buttons.firstChild) anyLink = true;
        root.appendChild(el('div', { 'class': 'trw-pass' + (pass.kind === 'membership' ? ' trw-m' : '') }, [
          el('h3', { text: pass.kind === 'ticket' ? pass.title : pass.title + ' membership' }),
          el('p', { text: line }),
          buttons
        ]));
      });
      if (where !== 'apple' && data.google_all_url && passes.length > 1) {
        root.appendChild(el('div', { 'class': 'trw-all' }, [
          el('p', { text: 'Or save all ' + passes.length + ' at once:' }),
          walletButton('google', data.google_all_url, 'Add all ' + passes.length + ' to Google Wallet')
        ]));
      }
      if (!anyLink) {
        root.appendChild(message('Passes for this phone are not switched on yet. The QR codes in the confirmation email still work at the door.', true));
      }
      if (where === 'desktop') {
        root.appendChild(phoneHandoff());
      }
      root.appendChild(again());
    }

    // On a computer, offer a QR code that opens this same page on a phone.
    function phoneHandoff() {
      var box = el('div', { 'class': 'trw-qr' });
      box.appendChild(el('span', { text: 'On a computer? Scan to open this page on a phone, then add the pass there.' }));
      if (typeof window.qrcode === 'function') {
        try {
          var qr = window.qrcode(0, 'M');
          qr.addData(window.location.href);
          qr.make();
          var holder = el('div');
          holder.innerHTML = qr.createSvgTag({ cellSize: 3, margin: 2, scalable: false });
          var svg = holder.querySelector('svg');
          if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR code linking to this page'); box.appendChild(svg); }
        } catch (e) { /* The link text above still explains what to do. */ }
      }
      return box;
    }

    // Opened from an emailed link: ?t=<token>
    var token = new URLSearchParams(window.location.search).get('t');
    if (token && !demo) {
      clear();
      root.appendChild(message('Finding your passes…'));
      fetch(endpoint + '/passes?t=' + encodeURIComponent(token), { credentials: 'omit', referrerPolicy: 'no-referrer' })
        .then(function (response) {
          return response.json().catch(function () { return {}; }).then(function (data) {
            if (data.status === 'ok') showPasses(data);
            else showForm('That link has expired. Look up the order again for a fresh one.', true);
          });
        })
        .catch(function () { showForm('The lookup could not be reached. Check the connection and try again.', true); });
    } else {
      showForm();
    }
  }

  function start() {
    if (!document.getElementById('trw-css')) {
      var style = document.createElement('style');
      style.id = 'trw-css';
      style.textContent = CSS;
      document.head.appendChild(style);
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-trpl-wallet]'), mount);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
