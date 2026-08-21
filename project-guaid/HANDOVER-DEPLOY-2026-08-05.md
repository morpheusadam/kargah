# Handover — the day Kargah left the laptop, 5 August 2026

> **Domain note, 21 August 2026.** This file was written while the host was `lavzen.com`. Every
> address in it has been rewritten to `bineret.com`, which is where the system actually runs: the old
> zone is not being renewed and both `lavzen.com` and `panel.lavzen.com` have stopped answering.
> Account *names* are deliberately left alone — `@lavzencom`, `@lavzenbot`, `r/lavzen` and the Worker
> `lavzen-mail-ingest` are the real handles of live things, not addresses, and renaming them here
> would describe a world that does not exist. See `HANDOVER-DOMAIN-2026-08-21.md`.

Kargah is deployed. It runs at **`https://panel.bineret.com`** on the owner's Hostinger account,
serving the owner's real book: four invoices, ₺80,000, all paid.

Everything needed to reach it — SSH, the panel login, server paths, the DNS record, the hPanel
account — is in **`.data/ssh.txt`**, which is gitignored and must stay that way. Nothing in this
file is a credential.

---

## 1. 🔴 Four bugs that only a deployment could find

The suite was green through every one of them: 1,313 tests, 6,333 assertions, on a machine where all
four are invisible. They share one assumption — **Kargah believed it was always at a domain root, on
Windows.**

**`Route::redirect('/', '/login')` hands its target to `RedirectController` as a literal string**, and
Laravel emits it unchanged. Under a subdirectory the root URL answered `Location: /login`, which is
the WordPress login page one level up. Every *other* route was correct, because every other route
builds its URL through `route()` and `route()` knows the base path. Both root redirects are
`redirect()->route()` now.

**Twenty-one asset paths were hardcoded `/assets/…`.** The browser asked the parent domain for all of
them, collected 404s dressed as HTML, and drew the login page in Times New Roman. Nothing errored
server-side; the page was simply naked. All of them go through `asset()` now, and so do the icons in
`partials/head-icons.blade.php` and the logo in `components/brand-mark.blade.php`, which were building
`'/'.$path` by hand.

🔴 **`use Brick\Money\ISOCurrencyProvider;` — the file is `IsoCurrencyProvider.php`.** PSR-4 resolves a
class name to a path, so that spelling finds the file on Windows, whose filesystem does not care about
case, and finds nothing on Linux, whose filesystem does. First deployed page load: HTTP 500,
`Class "Brick\Money\ISOCurrencyProvider" not found`, from the dashboard, after a clean install.

**The printed invoice claimed a conversion that never happened.** A lira invoice reported in lira
printed "₺20,000.00 converted" and a provenance box explaining a rate of one. Both are suppressed when
the two currencies are the same, and the box is now rendered only when it has something to say.

**What to take from this:** a green suite on one machine is not evidence about another. The same
lesson the browser taught this project on 4 August, one layer further out.

---

## 2. How the deployment is shaped, and why

**The application lives at `~/kargah`, outside every document root.** Only `public/` is copied into
the web folder. `.env`, the SQLite ledger and `storage/` are unreachable over HTTP by construction
rather than by rewrite rule — which matters, because the ledger is the owner's real book and the web
root is shared with a WordPress install.

`public/index.php` therefore carries **absolute** paths to `vendor/autoload.php` and
`bootstrap/app.php`: the public directory is no longer a sibling of the application and Laravel's
shipped front controller assumes it is.

**Deploy is `git pull` on the server**, because the repository is public. Four things are not in git
and must be copied by hand on a rebuild: `public/assets/`, `database/database.sqlite`, `.env`,
`public/img/signature.png`.

Three `.htaccess` rules earn their place, each commented at the site of the decision:

1. **`AddHandler … alt-php83 .php`** — the account serves PHP 8.2 and Kargah needs 8.3. Without it the
   response is a bare 500 with **nothing in the Laravel log**, because Composer's platform check
   aborts before Laravel boots. Setting the version in hPanel would change it for `bineret.com` as a
   whole, WordPress included.
2. **No `RewriteBase`, deliberately.** The directory answers to two hostnames and no single value is
   right for both. Without it, a relative substitution resolves against the directory the file sits
   in, which is correct under either.
3. **Canonical host.** Anything that is not `panel.bineret.com` over HTTPS is 301'd there, path intact.
   Two working URLs for one login page is one more than anybody needs: sessions are scoped per host,
   so signing in at one leaves you signed out at the other.

---

## 3. DNS, and the measurement that found it

`panel.bineret.com` pointed at `<the old address>` — a different live machine, running Caddy — while the
Hostinger server is `<host>`. The subdomain and its document root existed in hPanel the whole
time; only the record was wrong.

🔴 **The test that separated the three possible causes**, and it is worth reusing: send the request to
the server you *think* should answer, with the hostname you *think* is configured.

```powershell
curl.exe -s -i --resolve panel.bineret.com:80:<host> "http://panel.bineret.com/"
```

LiteSpeed answered `302 → /login`. That one line proved the vhost existed and the document root was
right, so the only remaining suspect was DNS. Chrome can be pinned the same way, which proved the
whole application worked under the subdomain **before** the record was touched:

```js
chromium.launch({ args: ['--host-resolver-rules=MAP panel.bineret.com <host>'] })
```

The record is now `<host>`, **DNS only**. Proxy is off on purpose: Hostinger then issues and
renews the certificate itself and the zone's SSL/TLS mode is never touched. Turning the proxy on would
require mode Flexible, which is zone-wide and would weaken `bineret.com`. Hostinger issued
`CN=panel.bineret.com` (Let's Encrypt, expires 3 November 2026) within about fifteen minutes of the
correction, unprompted.

---

## 4. Not indexed, in three layers

The panel is out of every search index, and each layer was verified from outside:

- **`X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex` on every response**, set with
  `always` so it reaches 404s and 500s too. This is the layer that matters: the most sensitive thing
  this host serves is not HTML. An invoice PDF carries a client's name, a total and a signature, and a
  meta tag cannot mark it.
- **`<meta name="robots">` in `layouts/app.blade.php`.** It was already in `guest` and the error
  layout, which meant the only pages a crawler could never reach were marked and the ones holding the
  invoices were not.
- **`robots.txt`** said `Disallow:` — which permits everything. It says `Disallow: /` now.

⚠️ If a URL here is ever found to be indexed, `robots.txt` is the wrong tool for removing it: a crawler
that may not fetch the page never reads the noindex telling it to drop the page. Allow the crawl, let
the header work, then put the block back.

---

## 5. The invoices

Four, twenty days apart, covering 26 May to 4 August. Each is one page, ₺20,000, paid, with a classic
double-ruled frame and the work listed as ticked items in SEO vocabulary. They are on the server and
the PDFs render from it.

**One question is still open and it is the owner's to answer:** *Billed to* reads `Nima Fazlipour`,
taken from a direct answer to "who should the invoice be issued to". The owner later said Nima is the
person who gave them the Claude account and that their own name is morpheus. If that name is wrong on
the documents, four issued invoices and their filenames need correcting — and an issued number is
never reused, so the honest correction is void and reissue rather than an edit in place.

---

## 6. Open, in the order I would take them

1. 🔴 **The panel password is in a chat transcript** and was used to test the login. Change it from
   Settings → Security. The owner types it; nobody else should.
2. **Answer the *Billed to* question above**, before those invoices go to anyone.
3. **The first real post on each network.** Still the highest-value work in the product, still blocked
   only on credentials — `HANDOVER-2026-08-05.md` §3 has the per-network steps. Outbound HTTPS from
   PHP works on this machine now, and `accounting:fetch-rates` has been run for real against
   Frankfurter and CoinGecko.
4. **`EVDS_API_KEY` is not set**, so no invoice to a domestic Turkish company can carry the lira
   equivalent the law requires. Free, from `evds2.tcmb.gov.tr` → Profil → API Anahtarı.
5. **Nothing keeps the deployed copy up to date.** Every deploy so far has been a hand-run `git pull`.
   A one-line script, or a webhook, is an hour's work and removes the step people forget.
6. **`payments` has no frozen reporting figure**, so a collection is valued at its *invoice's*
   issue-date rate. This is why cash-in/cash-out per month does not exist.
7. **Time tracking → billable hours → invoice.** The biggest must-have that does not exist.
8. **The double-entry build**, when the owner asks. §4(d) of the plan landed on 4 August, so the plan
   is one stage shorter than it reads.
9. Older debts, unchanged: `CustomerReader` returning Eloquent models where every sibling returns
   arrays · `has:stickers` · Butler's calendar and branching · uncursored `/api/v1/customers` · card
   writes and mail sending absent from `/api/v1` · no permalink for Instagram, Threads or Slack ·
   Reddit takes no pictures · `MEDIA_NOT_READY` unproven · Tumblr on the legacy endpoint · the
   `openCard()` listeners that will need the first policy.

---

## 7. For the next session

Everything the browser handovers say still holds: open the app before believing anything about it,
never point a clicking harness at the dev database, and when a report and a measurement disagree,
measure again.

One thing this session adds. **When something works on your machine and not on the server, do not
reason about the difference — send a request that isolates it.** Every one of the four bugs above was
found in minutes by asking the deployed thing a question, and none of them was reachable from the test
suite, which was green the entire time.
