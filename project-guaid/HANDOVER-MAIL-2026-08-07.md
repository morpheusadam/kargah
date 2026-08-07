# Handover — mail, 7 August 2026

Paste the block at the bottom into a new session. Everything above it is the
detail that block refers to.

---

## What now works, and is verified on production

**Sending.** A `delivery_providers` row named *Resend*, driver `smtp`, pointed at
`smtp.resend.com:587` with username `resend` and the API key as the password.
From `info@lavzen.com`, daily quota 100 (Resend's free ceiling). Verified by
sending through the compose window's own code path — `Router::pick`,
`Delivery::driverFor`, `$driver->send` — not merely through the API.
mail-tester scored it **10/10**: SPF, DKIM and DMARC all aligned, not
blocklisted, no broken links. The single amber note was a missing
`List-Unsubscribe` header, which is a property of that one-off probe and not of
campaigns — `MessageBuilder` adds it.

**Receiving.** Cloudflare Email Routing holds the MX. `info@`, `support@` and
`send@` are each routed to the Worker `lavzen-mail-ingest`, which posts the raw
RFC822 to `POST panel.lavzen.com/mail/inbound`. All three verified with real
messages that arrived in the panel. The catch-all still forwards to
morpheusadam95@gmail.com **on purpose** — it is the safety net while the new
path beds in.

Read the Activity Log labels: **Handled** means the Worker took it, **Forwarded**
means it went to Gmail.

⚠️ **A routing-rule change takes about a minute to propagate.** This bit twice:
the first test after each change came back *Forwarded* and the second came back
*Handled*. Before hunting a bug after a rule edit, wait a minute and send again.

## Two bugs found, both the same shape

Both were names that resolved and paths no test walked. Worth remembering as a
pattern when reading this module.

1. **CSRF.** The `web` group contains `PreventRequestForgery`;
   `ValidateCsrfToken` is a deprecated empty subclass. `withoutMiddleware()`
   matches on the exact class string, so naming the subclass silently did
   nothing and three routes answered 419 in production — the inbound endpoint,
   the delivery webhook and the one-click unsubscribe. The last two had **never
   worked**.
2. **The mailable.** `Envelope` takes `Illuminate\Mail\Mailables\Address`;
   `CampaignMessage` imported `Symfony\Component\Mime\Address`. Same short name,
   same constructor. Sending from the panel had **never worked** — it threw a
   TypeError the first time a real message was built.

Neither was caught because `Mail::fake()` records a mailable without calling
`envelope()`, and Laravel skips CSRF under `runningUnitTests()`.
`CampaignMessageTest` now renders through the array transport and is
deliberately the only place in Mailbox that builds a message for real.

## Where things live

- `Modules/Mailbox/app/Services/MessageStore.php` — the one place a received
  message becomes rows. Both the IMAP job and the inbound controller use it.
- `Modules/Mailbox/app/Services/Imap/WebklexHydrator.php` — webklex message →
  `RemoteMessage`, shared for the same reason.
- `Modules/Mailbox/app/Http/Controllers/InboundMailController.php` — the
  endpoint. **The status code is the protocol:** the Worker rejects anything
  that is not 2xx and an SMTP rejection makes the *sending* server retry, so a
  failure no retry can fix is accepted and logged. Only a missing inbound
  account answers 503.
- `mail-worker/` — the Worker source. Deployed as `lavzen-mail-ingest`; its vars
  are `KARGAH_URL` and the secret `INBOUND_SECRET`, which must equal
  `MAILBOX_INBOUND_SECRET` in the server `.env`.
- `mail_accounts.kind` is `imap` or `inbound`. `dueForSync` filters on it so the
  scheduler never opens a socket to an account that has no host.

## Credentials and accounts

`.data/ssh.txt` has them; two things there are easy to get wrong:

- **There are two Resend accounts.** The one you reach by logging in as
  morpheusadam95 has `drainage-plumbing.co.uk` verified and its single free-plan
  domain slot is used — that domain is live and sent FOI requests to London
  councils in July, so do not free the slot. The account whose API key sits at
  the end of `ssh.txt` is the one with **lavzen.com verified**.
- The hPanel account is `fazlipournima@gmail.com` and the user reaches it as an
  admin managing that account. The user's own address is
  **morpheusadam95@gmail.com**.

No bank card is available, so every paid tier is out: Resend Pro, Cloudflare's
Email Sending beta (needs Workers Paid), Amazon SES.

## Cron — done, and one trap worth knowing

The scheduler now runs, in hPanel → Advanced → Cron Jobs on panel.lavzen.com:

```
* * * * *   /opt/alt/php83/usr/bin/php /home/u523965318/kargah/artisan schedule:run
```

⚠️ **The first attempt at this never fired**, and the difference is worth
remembering. It was written the way Laravel's own docs write it:

```
cd /home/u523965318/kargah && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

That form sat there for five minutes without running once. The two crons on the
account that *do* work are both a plain absolute path with no `cd`, no `&&` and
no redirect — so Hostinger's cron wrapper appears not to take the shell form.
Dropping `cd` costs nothing: artisan resolves its paths from the script's own
location, so it does not care about the working directory. Dropping the
redirect is what makes hPanel's **View Output** worth reading when something
goes wrong.

Verified rather than assumed: a job was queued, and `queue:work
--stop-when-empty` drained it within the minute — `jobs` went to 0 and the probe
cache key was stamped 15:43:02. No failed jobs. The probe script and its cache
key have been cleaned up.

Immediate sends never needed this — the compose window sends synchronously.
Campaigns and scheduled sends did, and now have it.

## Open and click tracking — built, later the same day

The endpoints are `GET /mail/o/{token}.gif` and `GET /mail/c/{token}/{link}`,
both `signed` and both carrying an HMAC token, exactly as the unsubscribe link
is. `Services/Delivery/Tracking.php` is the whole of the message side and its
docblock is the design; `Http/Controllers/TrackingController.php` is the two
answers.

**The redirect takes an id, never a URL.** Its destination is a `campaign_links`
row written when the message was built, so there is no `?url=` to edit and the
endpoint cannot be pointed anywhere the campaign has not already agreed to. A
link that could not be registered is left in the body untracked rather than
rewritten into a redirect with nowhere to go, and only `http`/`https` is ever
registered — which is what stops a `javascript:` href becoming a destination.
`CampaignTrackingTest` is fifteen assertions about exactly this and would fail
the moment somebody added a parameter.

Three more decisions worth keeping:

- **Nothing logs one row per event.** `DeliveryEvent`'s objection to modelling
  opens at all was volume, and it still stands. An open is a counter and two
  timestamps on the recipient row; a click is one row per (link, recipient) pair
  with a counter on it. A 500-recipient campaign with four links is bounded at
  2,000 rows however often it is read.
- **HTML only.** The plain-text alternative is never rewritten — a signed
  redirect is invisible in HTML and glaring in text, where it reads as the thing
  a phishing message does — and the pixel is inserted inside `</body>` because a
  few clients drop what follows the closing tag.
- **A click does not stamp an open.** Images are blocked by default in most
  clients, so a campaign can honestly show more clicks than opens. Counting one
  as the other would make the open rate incomparable with anything.

The campaign report grew two metric cards, a *Links followed* table (people vs
times, and links nobody followed still listed), open/click markers on each
recipient row, and four more CSV columns. `mailbox.tracking.opens` and
`.clicks` turn each off; off means the message is not changed at all rather than
merely not recorded.

`Tokens` gained `OPEN`, `CLICK` and `LINK`, and `idFrom()` beside
`recipientFrom()` — a link token names a `campaign_links` row rather than a
recipient, and the purpose in the hash is what stops either being replayed as
the other.

## Open

1. **Still missing on the sending side:** double opt-in, and sliding-window
   pacing (the hourly quota covers some of it). A per-campaign tracking toggle
   is a column and a checkbox away if one campaign ever needs to differ from the
   install.

2. **Catch-all still forwards to Gmail.** Deliberate — it is the safety net
   while the new path beds in. Move it to the Worker once the panel inbox has
   handled a few real days of mail.

3. **Volume.** 100/day today. `Router` already fails over by remaining quota,
   then health, then priority, so adding Brevo (300/day) and Mailjet (200/day)
   is one row each and reaches about 600/day free. Both drivers already exist.

## The panel, same day

Three complaints, all from using it rather than reading it.

**New mail did not appear without a reload.** It cannot: cron and the Worker
write rows and neither can tell a browser. `⚡inbox` now polls
`checkForNewMail()` every 20 seconds, which compares `max(emails.id)` with a
`watermark` property and calls `skipRender()` when nothing has arrived. That
early return is the whole design — without it a quiet tick rebuilds the folder
rail, the header and the toolbar, runs every query behind them, and morphs the
result over an identical DOM, once per open tab, for ever. With it the quiet
case is one lookup on a primary key and an empty response.

**Nothing looked clickable.** Tailwind v4 dropped the `cursor: pointer` its
preflight used to give a button, Metronic's bundle does not restore it, and a
`wire:click` on a div never had one — so the whole panel showed an arrow. Fixed
at the source sheet
(`admin-panel-ui/veltrix-tailwind-html-starter-kit/src/css/kargah.css`) as
element and attribute selectors, plus `not-allowed` for the disabled half.
🔴 **`public/assets` is gitignored**, so the rebuilt `kargah.css` has to be
uploaded by hand — `git pull` on the server will not carry it.

**The mail pages felt laggy.** Two causes found by counting queries rather than
guessing, both in `⚡inbox`:

- `with()` computed the rows, their customers, the conversation, the
  attachments and the cards on *every* request, including the ones that name no
  island and draw none of it. Each is now asked for from inside its own island,
  which a skipped island never runs. Ticking a checkbox went from 12 queries
  to 6.
- **`with()` does not run once per request.** Livewire re-runs it for every
  island it renders, so `accountSummary()` asked its two questions three times
  over. Memoised. Starring a message went 18 → 14 queries, opening one 12 → 10.

Both are covered by tests that count queries, so a return to eager `with()`
fails rather than merely slows.

⚠️ **Still worth doing on the server, and not done here:** the documented deploy
ends at `artisan optimize:clear`, which *clears* the caches and rebuilds none of
them, so every request on shared hosting re-parses ~30 config files and
re-registers every route — paid again on each Livewire round trip, which is one
per click. `route:cache` and `view:cache` were verified to build cleanly on this
codebase. `config:cache` should be safe on the server but must **not** be run on
the dev machine: the browser-audit harness points `DB_DATABASE` at the audit
copy through the environment, and a cached config would silently send it back to
the owner's real book.

---

## Prompt for the next session

> Continuing work on Kargah's mail. Read
> `project-guaid/HANDOVER-MAIL-2026-08-07.md` first — it has the full state.
>
> Mail is finished and verified on production: Resend over SMTP for sending, a
> Cloudflare Email Worker posting into `panel.lavzen.com/mail/inbound` for
> `info@`, `support@` and `send@`, and the scheduler running every minute so
> campaigns and scheduled sends work too. Open and click tracking is built on
> top of it: signed routes carrying an HMAC, every link registered in
> `campaign_links` before it is rewritten, and the redirect taking an id so
> there is no URL in the request to point anywhere.
>
> Deploy is `git pull --ff-only` on the server, then `artisan migrate --force`
> and `artisan optimize:clear`. PHP is `/opt/alt/php83/usr/bin/php` — plain
> `php` is 8.2 and wrong. `public/assets` is gitignored, so a rebuilt
> `kargah.css` has to be uploaded by hand.
>
> Note when reading this module: `Mail::fake()` never calls `envelope()` and
> Laravel skips CSRF under `runningUnitTests()`, so two real bugs lived behind
> green tests. If you touch the mailable or route middleware, verify against a
> real render or a real request, not only the suite.
