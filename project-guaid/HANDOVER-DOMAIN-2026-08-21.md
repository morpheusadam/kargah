# Handover — the domain move, 21 August 2026

Kargah, and the site it sits beside, answer to **`bineret.com`**. `lavzen.com` is not being renewed.
Neither `lavzen.com` nor `panel.lavzen.com` answers any more — both fail at the TLS handshake, because
Hostinger's vhost and its certificate went away with the domain entry, not because a rule sends them
somewhere. There is nothing left to redirect *from*, so nothing here tries to.

This file exists because the move happened in three sittings and each one left the next a different
kind of debt. 19 August moved the server and the code. Today moved the documents, the ledger's own
copies of the old address, and the one thing on the printed invoice a client reads. What is still
open is at the bottom, and one item of it is a live dependency with an expiry date on it.

---

## 1. What answers now, measured

```
panel.bineret.com/       302 → https://panel.bineret.com/login
bineret.com/             200
bineret.com/panel/       301 → https://panel.bineret.com/
panel.lavzen.com/        no answer (TLS)
lavzen.com/              no answer (TLS)
```

Certificate `CN=panel.bineret.com`, Let's Encrypt, issued 19 August, **expires 17 November 2026** —
Hostinger issues and renews it because the Cloudflare proxy is off for that record. That is still
deliberate and the reason is unchanged: turning the proxy on would need SSL/TLS mode Flexible, which
is zone-wide.

`bineret.com` is on Cloudflare (`kaiser`/`paige.ns.cloudflare.com`, zone
`f76dc37040367437bee84c18ee4009db`), and `panel.bineret.com` is an A record to `82.29.185.21`, DNS
only. The document root moved with the domain: `~/domains/bineret.com/public_html/panel/`. The
application itself never moved — it is still `~/kargah`, outside every document root.

---

## 2. The invoice, which is the part that was actually broken

`ACCOUNTING_DOCUMENT_FOOTER` was set to the empty string on 19 August, with the comment *"Domain is
changing; left blank so no old address is printed on a document."* That was the right call for a day.
Left alone it was a slow bug: `config/config.php` had already been corrected to default to
`bineret.com`, and an `.env` value of `""` overrides a default just as firmly as a wrong one would.
Every invoice was printing with **no footer at all**, and nothing said so, because an empty footer is
a `@if` that renders nothing rather than an error.

🔴 **The footer is read at render time, not frozen at issue.** `InvoiceDocument::data()` calls
`config('accounting.document.footer')` on every render, so the fix reached all five issued invoices
the moment the value changed — including the four from May–August. Nothing needed reissuing. This is
the opposite of how `reporting_currency`, `reporting_rate` and `reporting_amount` behave, which *are*
frozen on the row; do not assume one from the other.

Verified by rendering, not by reading the config:

```
php artisan tinker --execute="…view('accounting::documents.invoice', \$doc->data(\$inv))->render()…"
matches in rendered invoice: bineret.com
```

---

## 3. The ledger's own copies of the old address

Backed up first — `database/database.sqlite.before-bineret-domain-2026-08-21`.

**Rewritten**, because they are configuration or navigation and the old value is a dead end:

- `mail_accounts` id 2 — `name` and `email` → `bineret.com` / `info@bineret.com`. Safe either way:
  `InboundMailController::account()` matches on the envelope recipient and *falls back to the oldest
  active inbound account*, so ingestion never depended on this string being right.
- `user_notifications` — ten rows whose `url` was `https://panel.lavzen.com/projects?board=…`. Every
  one of them was a link that now times out.

**Left alone, deliberately**, and this is the more important half:

- `emails` bodies, and the `Message-ID`s inside them — `<…@lavzen.com>`. These are the real headers of
  real messages that were really sent from that domain. Rewriting them would not tidy anything; it
  would make the mailbox claim a message carried an id it never carried.
- `activity_log` properties, which quote the same ids.
- `social_accounts` handles — `lavzencom` on Instagram, Threads and Telegram. **The handle is not a
  copy of the domain; it is the name of the account.** Editing the column would make Kargah disagree
  with what `verify()` gets back from the platform, and `verify()` is what tells the connect page
  whether the credential still works. These change when the accounts are renamed on the platforms
  themselves, in that order, or not at all.

---

## 4. Mail, and the one thing with a deadline on it

**Inbound is done and it works.** Cloudflare Email Routing on `bineret.com` (MX `route1/2/3.mx
.cloudflare.net`, SPF `include:_spf.mx.cloudflare.net`) hands the message to the Worker, which posts
raw RFC822 to `https://panel.bineret.com/mail/inbound`. The Worker's `KARGAH_URL` var was already
correct. Proven end to end today by sending a real message to `info@bineret.com` and watching it land
in the `emails` table.

⚠️ **The Worker is still called `lavzen-mail-ingest`.** Only the name. A Cloudflare Worker cannot be
renamed in place — a rename means deploying `kargah-mail-ingest` (which is what `wrangler.toml` in
this repository already says) and re-pointing every Email Routing rule at it before deleting the old
one. That is a small job but not a free one, and doing it wrong drops mail on the floor between the
two steps. It is cosmetic; it is listed in §6 rather than done.

🔴 **Outbound still leaves as `info@lavzen.com`, and that is the item with an expiry date.**
`delivery_providers` id 1 has `sending_domain=lavzen.com`. It works *today* only because the
`lavzen.com` zone is still on Cloudflare with its Resend DKIM and `send.lavzen.com` records intact.
When the domain lapses, every outbound message fails, and it fails at the recipient's SPF/DKIM check
rather than at ours — which means it will look like delivery got worse rather than like a
configuration expired.

`bineret.com` has been **added** to Resend (id `225bb7fa-6cbb-43c1-96f1-3aa0cdd9d8a8`, region
`eu-west-1`, status `not_started`). It cannot verify until three DNS records exist, and none of the
API tokens on this machine can write DNS on that zone — `wor.txt`'s token holds
`#zone_settings:edit`, `#worker:edit`, `#zone:read` and nothing for records. The records are in §6.

**Do not flip `delivery_providers` before Resend reports `verified`.** Flipping first does not degrade
sending gradually; it stops it, because Resend refuses a `from` on an unverified domain outright.

---

## 5. What was already done on 19 August, so nobody redoes it

- `~/domains/lavzen.com` → `~/domains/bineret.com` on Hostinger; `wp-config.php` repointed at the
  `u523965318_bineret` database, old one kept as `wp-config.php.bak-lavzen-db-2026-08-19`.
- `panel/.htaccess` — canonical host is `panel.bineret.com`; previous file kept as `.htaccess.bak-lavzen`.
- `panel/index.php` — comments and absolute paths.
- Server `.env` — `APP_URL=https://panel.bineret.com`.
- Worker var `KARGAH_URL`.
- Commit `0e97af3`, which had **not been pushed** and so had never reached the server. Today's deploy
  carried it and `c5b27d3` together.

The WordPress side is clean: the `lv_`-prefixed tables hold no `lavzen` string except two
`_site_transient` theme caches that name the *directories* `lavtheme` and `lavzentheme`, and those
regenerate. `bineret.com` serves `hello@bineret.com` in its footer.

There is a runtime output filter at `wp-content/themes/lavtheme/inc/code-studio-inject.php:195`
mapping `lavzen.com → bineret.com` and the wordmarks with it. It is a safety net from the rebrand and
is now a no-op against a clean database. Left in place; harmless, and cheap insurance if an export
from somewhere older is ever imported.

---

## 6. Open, in the order I would take them

1. 🔴 **Add three DNS records to `bineret.com` and verify it in Resend**, before the old domain
   lapses. Type / name / value:

   | Type | Name                | Value                                          | Priority |
   |------|---------------------|------------------------------------------------|----------|
   | TXT  | `resend._domainkey` | `p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDZhBR+YSihNYWf3ASdKWQQBm0SAeZSrCn7MGF2T2WVZEJd3+WUzaneorCjWefjJ/6WlnLogRKjXGNd2goFmNfepKi7Q74vviozx/skE7ZURVGryZxGj14cO4Gidk8/A4peZCziohI9OGvXF78CYzAfWKzH6kRVdjqLrTEcY082bwIDAQAB` | — |
   | MX   | `send`              | `feedback-smtp.eu-west-1.amazonses.com`        | 10 |
   | TXT  | `send`              | `v=spf1 include:amazonses.com ~all`            | — |

   All DNS-only; none of them is proxied, and none of them touches the zone's existing MX, which
   belongs to Email Routing and must stay. Then `POST /domains/225bb7fa-…/verify` on the Resend API,
   and only once it answers `verified`:

   ```sql
   UPDATE delivery_providers
      SET sending_domain = 'bineret.com', from_email = 'info@bineret.com'
    WHERE id = 1;
   ```

   Send one real message afterwards and read the headers at the far end, rather than trusting the
   dashboard: SPF and DKIM are checked by the *recipient*.

2. **Confirm the Email Routing rules on `bineret.com` cover every published address.** The site
   advertises `hello@bineret.com`; the old zone routed `info@`, `support@` and `send@`. Routing rules
   are not readable with the tokens on this machine, so this is a dashboard check — or a test message
   to each address, which is the better evidence anyway.

3. **`~/domains/bineret.com/public_html/bot/config.php`** still has `base_url =
   https://bot.lavzen.com` and MySQL `u523965318_lavzen`. `bot.lavzen.com` resolves to
   `148.135.128.251` / `147.79.120.54` — a different machine entirely — and answers nothing. This is
   a separate Telegram bot application, not Kargah, and the database *name* cannot be changed by
   editing a config file; it needs a new database and a dump restored into it. Nothing in Kargah
   depends on it.

4. **`.data/ssh.txt` in this repository still says `panel.lavzen.com`** in the panel URL, the paths
   and the DNS section. It is gitignored and could not be edited from this session's tooling. It is
   the first file a next session reads.

5. **Rename the Worker** to `kargah-mail-ingest` — deploy new, re-point rules, delete old. §4.

6. **The social handles**, if the accounts are to carry the new name: Instagram, Threads and Telegram
   are all `lavzencom`, and the Telegram channel is stored as a numeric `chat_id` deliberately, so a
   rename there does *not* break the stored credential. Reddit is the one that cannot be tidied —
   `r/lavzencom` answers `is banned`, and that is a property of the name.

7. Everything in `HANDOVER-DEPLOY-2026-08-05.md` §6 that was open then and is still open now.

---

## 7. For the next session

The lesson from 5 August held again today, in a new shape. **A green suite and a correct default are
not evidence about production**, because production has an `.env` and an `.env` overrides a default
silently. `config/config.php` said `bineret.com` and had said it for two days; the invoice printed
nothing, and the only way to find that out was to render the invoice on the server and look at the
string. Ask the deployed thing.

The second one is about scope. "Change every `lavzen.com` to `bineret.com`" is not a `sed` over a
repository. Roughly a third of the occurrences were **names of live things** — an account handle, a
Worker, a subreddit, a message id already in somebody's mailbox — and rewriting those would have
replaced a stale-but-true record with a tidy-looking falsehood. The domain moved. The names did not,
and each place they stayed says why.
