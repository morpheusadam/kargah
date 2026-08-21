# Prompt for the next session — Social

> **Domain note, 21 August 2026.** This file was written while the host was `lavzen.com`. Every
> address in it has been rewritten to `bineret.com`, which is where the system actually runs: the old
> zone is not being renewed and both `lavzen.com` and `panel.lavzen.com` have stopped answering.
> Account *names* are deliberately left alone — `@lavzencom`, `@lavzenbot`, `r/lavzen` and the Worker
> `lavzen-mail-ingest` are the real handles of live things, not addresses, and renaming them here
> would describe a world that does not exist. See `HANDOVER-DOMAIN-2026-08-21.md`.

Paste everything below the line into a fresh context.

---

You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
Laravel 13 · Livewire 4 · `nwidart/laravel-modules` · PHP 8.3 · SQLite in development. Nine modules:
`Accounting` `Blog` `Core` `Data` `Mailbox` `Platform` `Project` `Site` `Social`. Branch `main`.

I want to do several pieces of work on the **Social** module. Before touching anything, get yourself
connected and oriented in the order below, and do not skip step 3.

## 1. Local

```
PHP CLI          C:\Users\morph\PHP\8.3\php.exe        (not on PATH — always the full path)
Composer         C:\Users\morph\PHP\8.3\composer.phar  (php composer.phar …; `composer` alone is not installed)
Shell            PowerShell 7. Not installed: rg, jq, tree, gh, ast-grep
Tests            php artisan test          full suite ~8 minutes, ~1,560 tests
                 php artisan test --filter=SocialModuleTest
```

Read these before writing anything, in this order:

```
docs/frontend-conventions.md              mandatory before any Blade — the ⚡single-file component format
project-guaid/DECISIONS.md                skim; read the traps in full, each is a hole somebody fell in
project-guaid/spec/08-postiz-parity.md    what Social is for
project-guaid/spec/09-destinations.md     which destinations exist and why the rest were refused
project-guaid/HANDOVER-SITE-2026-08-17.md the newest module, and the house style for this kind of work
```

⚠️ **Two local traps that cost time in the last session.**

- Writing or editing a Blade file while `php artisan test` is running invalidates the compiled view
  cache mid-run, and the failure surfaces as `FileNotFoundException … storage/framework/views/livewire/views/<hash>.blade.php`
  in a completely unrelated test. It is not a real failure. Run `php artisan view:clear` and re-run.
  Better: do not edit while the suite is running.
- `Http::fake()` called a second time **appends** stubs rather than replacing them, and the earliest
  matching stub still wins. To make a faked endpoint answer differently across calls in one test, use
  `Http::sequence()`. Two tests were written the wrong way and failed with "an expected request was
  not recorded", which reads like a bug in the component and is not one.

## 2. The server, and syncing it down

Kargah is live at **`https://panel.bineret.com`**, on Hostinger shared hosting.

Everything needed to reach it — SSH host, user, port, panel login, hPanel account — is in
**`.data/ssh.txt`**, which is gitignored and stays that way. Read it; do not print its contents into
the transcript, do not copy them into a file that is tracked, and do not paste them into a command
that gets echoed back.

Shape of the deployment, which is unusual and matters:

```
~/kargah                     the application, outside every document root
~/kargah/public              NOT the web root
<web root>/                  only a copy of public/ lives here; shared with a WordPress install
/opt/alt/php83/usr/bin/php   the server's PHP 8.3 CLI (the account's default is 8.2)
```

Deploy is `git pull` on the server. **Four things are not in git** and exist only on the server:
`database/database.sqlite`, `.env`, `public/assets/`, `public/img/signature.png`.

### Syncing the host down to local — the part to get right

🔴 **The SQLite file on the server is the owner's real book.** Four issued invoices, ₺80,000, paid.
There is no second copy. Every rule below exists because of that one sentence.

1. **Pull down, never push up.** Nothing in this session should write to `~/kargah/database/`,
   `~/kargah/.env` or the web root. If you believe you need to, stop and ask.
2. **Back up before you overwrite anything local.** Copy the existing
   `database/database.sqlite` to `database/database.sqlite.<date>.bak` first — a local ledger with
   test data in it is still somebody's afternoon.
3. **Take the database with the application idle.** The one-minute cron runs `social:publish-due`,
   `mailbox:dispatch-sends` and others; copying mid-write gives a torn file. Either use
   `sqlite3 … ".backup"` on the server and download the result, or accept a plain copy and verify it
   afterwards with `PRAGMA integrity_check`.
4. **`.env` comes down for reference and does not get committed.** It carries real provider
   credentials. Keep it outside the repository or as `.env.server` in `.data/`, which is gitignored.
5. **Never run `php artisan migrate`, `db:seed` or `migrate:fresh` against the copy you pulled**
   until you have confirmed which file the local `.env` points at. `migrate:fresh` on the real
   ledger is unrecoverable.
6. After the sync, prove it landed: open `/accounting/invoices` locally and confirm four invoices
   totalling ₺80,000. If the numbers are not there, the copy is wrong — do not start work on it.

The cron on the server, for reference, and one trap in it:

```
* * * * *   /opt/alt/php83/usr/bin/php /home/<user>/kargah/artisan schedule:run
```

⚠️ The documented `cd … && php artisan …` form **never fires** on this host — Hostinger's cron
wrapper does not take the shell form. A plain absolute path with no `cd`, no `&&` and no redirect is
what works, and dropping the redirect is what makes hPanel's *View Output* readable.

## 3. Get to know Social before changing it

Read the code, not only the docs. Start at these, in this order:

```
Modules/Social/app/Support/Networks.php               the catalogue: every destination, its credentials,
                                                      limits, media rules and connect-page copy
Modules/Social/app/Services/Publishing.php            the driver registry, and extend()
Modules/Social/app/Services/PostPublisher.php         the thing that decides what gets sent where
Modules/Social/app/Services/Publishers/HttpPublisher.php   the network policy every driver shares
Modules/Social/app/Models/PostTarget.php              one row per post per destination — the spine
Modules/Social/resources/views/components/⚡publish.blade.php   the composer
```

The shape in one paragraph: a destination is a `social_accounts` row holding an encrypted credential
bag. A post is one `posts` row plus one `post_targets` row per destination, each with its own status,
attempt count, error and published copy. `social:publish-due` runs **every minute** (in
`routes/console.php`) and dispatches `PublishPost`; `social:refresh-tokens` and
`social:check-token-expiry` run daily at 08:05 and 08:15. Adding a destination is three edits — a
`Networks` entry, a publisher class, a line in a service provider — and `NetworkRegistryTest` fails
if you forget the third.

**Seventeen destinations exist. Twelve of the drivers have never been called for real.** Every
request shape is proved with `Http::fake()` and nothing else, because there is no CA bundle in this
machine's `php.ini` and outbound HTTPS from PHP dies with cURL error 60. Each driver's docblock lists
what could not be corroborated from a first-party source. Treat those lists as real.

What *has* been exercised against live networks, on 16 August 2026: LinkedIn, Telegram, Threads and
Instagram all published a real post from the composer.

### 🔴 Three defects found by using it, none of them fixed

These were observed live rather than reasoned about, and they are the obvious first work:

1. **A failed publish never writes `social_accounts.last_error`.** That column is written only by
   `RefreshTokens` (line ~103) and `SyncNotifications` (line ~77), never on the publish path. An
   Instagram account whose token Meta had invalidated kept showing `Connected` and counted toward
   "4 of 4 ready to publish" while every post to it failed. `CheckTokenExpiry` cannot help: it only
   examines rows with a non-null `token_expires_at`, so a credential pasted without a recorded
   lifetime is invisible to it.
2. **`Networks` has no `min_aspect_ratio`.** The media contract has `max_aspect_ratio` only, and it
   is `null` for Instagram. Instagram's feed accepts 4:5 to 1.91:1; a 1080×2400 phone screenshot has
   a ratio of 0.45 and the composer accepts it without a word. It had to be padded to 1080×1350 by
   hand outside the panel. Commit `249c911` already converts a picture a network refuses into the
   JPEG it takes, so there is a natural home for this.
3. **Retry publishes inside the web request.** `⚡post-show.blade.php` (~line 99) calls
   `PostPublisher::publishPost()` directly rather than queueing `PublishPost`. For Instagram, whose
   container creation was given more than ten seconds in `84e665c`, that holds an HTTP request for
   thirty seconds or more. Observed: the first Retry click appeared to do nothing at all — attempts
   stayed at 1 — and the tab's renderer froze.

Also worth knowing, found the same day: **Telegram's caption limit drops from 4,096 to 1,024 as soon
as an image is attached**, and the composer catches this correctly. Instagram publishing needs a
long-lived token; one copied straight out of Graph API Explorer dies in about an hour.

## 4. House rules

- Never paste a credential, token or password into a file, a command or the transcript. If a task
  needs one, say which and let me put it in.
- Commit as you go, one commit per coherent change, with a message that says **why** rather than
  what. Do not push unless I ask.
- Do not run `git add -A` without looking at what it caught — a stray untracked report from an older
  session was swept into a commit last time.
- The suite must be green before you tell me something is done, and say the number.

Tell me what you have read and what you found before you start changing anything.
