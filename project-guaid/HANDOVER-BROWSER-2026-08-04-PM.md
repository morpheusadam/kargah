# Handover — the afternoon the open list got shorter, 4 August 2026

The previous handover said: *open the browser first, every time*. This session did, and the answer
this time was **nothing**. That is worth as much as the last one's long list of failures, and it is
the first thing to read here: the audits that found four broken pages in twenty minutes on the
morning of 4 August now come back clean, so the fixes held and the harnesses still work.

What follows is what was measured, what was fixed, and the two things that changed outside the
repository.

---

## 1. The browser said the app is fine

**Load audit — 59 routes.** All HTTP 200. Zero console errors, zero page errors, zero failed
requests, zero sideways overflow at 375px. ApexCharts drew 5 chart canvases on `/dashboard` and 8 on
`/projects/dashboard`; FullCalendar drew a view on both `/projects/calendar` and `/social/calendar`.
The four pages that had **never once** loaded their vendor bundle before 4 August all load it now.

**Click audit — `/mail/inbox`, every target.** The previous session capped at 45 of 84 and left 39
unclicked. This one found **92** targets and clicked all of them, one per fresh page load, with every
`wire:confirm` accepted: **zero findings.** No JavaScript error, no 500, no page that emptied itself.
That item is closed rather than deferred.

`/data/links/create` and `/accounting/invoices/2` were click-audited too, after being changed. Clean.

🔴 **The harnesses now live in the repository**, at `tools/audit/`, with `playwright-core` in
`devDependencies` — they were lost to a scratchpad last time and rewritten from scratch this time.
`tools/audit/README.md` carries the isolated-database procedure and the reason for it. Read that
before running anything that clicks.

---

## 2. What was fixed

### 2.1 An unknown `kind` no longer kills `/data/links/create`

`⚡link-create`'s preview indexed `$kinds[$kind]` directly. `kind` is driven by `$set('kind', …)`
from `wire:click` rather than `wire:model`, and `$set` sets whatever the client sends — so an unknown
value reached the template and threw `Undefined array key` **during render**, before `save()`'s
allow-list ever ran.

Reproduced in Chrome before touching it: `$wire.$set('kind', 'not-a-kind')` answered **HTTP 500**
from `/livewire/update`. After: the call resolves and the preview reads *"Not a known kind"*, none of
the four cards is highlighted, and `save()` still refuses the value with the same message. The
fallback names the problem rather than quietly showing the default kind.

### 2.2 Voiding an invoice money has landed against is refused

`⚡invoice-show::voidInvoice()` had no guard. `DOUBLE-ENTRY-PLAN.md` §4(d) already specifies the rule
and the sentence, so this is that plan's behaviour change landed early and on its own — deliberately
ahead of the ledger work rather than inside it, so the two are reviewable apart.

- `PaymentRecorder::paid()` is new and is now **the one place** the paid figure is computed;
  `statusFor()` and `outstanding()` both read it instead of each writing the same `pluck`.
- The void dialog tells the truth *before* the click: while a payment stands it names the amount, the
  reason and the way out, and the destructive button is not rendered at all.
- The server-side guard stays regardless, because the action is callable from the client.

`InvoicePagesTest::test_an_invoice_with_a_standing_payment_refuses_to_be_voided` covers it, asserts on
the invoice's own state rather than on the toast's prose, and asserts the way back out — reverse the
payment, and the same component voids the same invoice. **Mutation-tested:** disabling the guard fails
with *"A paid invoice was voided. Failed asserting that Illuminate\Support\Carbon Object … is null."*

Verified in Chrome on real rows: `INV-0038` (two payments) shows the refusal and no Void button, and
calling `voidInvoice()` anyway left `status=paid voided_at=NULL`; `INV-0041` (no payments) voided
normally.

### 2.3 The seeder freezes the currency the application actually ships

🔴 **This one changes what a fresh demo looks like.** `AccountingDatabaseSeeder` hardcoded `USD` as
the reporting currency while `config('accounting.reporting_currency')` ships `TRY`. Because a
converted figure is frozen on issue and never re-derived, the dashboard's expense series counted
**none** of the eight seeded expenses and drew a flat zero.

It now asks `InvoiceIssuer::reportingCurrency()`. Two more things had to move with it, and both were
latent bugs the old hardcoding hid:

- **`RATE_DAYS` was 40 while the fixtures reach 71 days back.** `INV-0038` (63 days) and the Apple
  Store expense (71 days) found no rate at all and froze null. Now 75.
- **`rateFor()` does not chain**, so a USDT invoice reporting in lira had no figure unless the pair
  itself is on file. The seeder now records `USDT/TRY` as the cross of the two series it already
  writes.

Measured on a database seeded from scratch: every issued invoice and all eight expenses now carry a
TRY figure at a real rate, `INV-0044` stays null because it is the deliberate never-issued draft, and
`expensesByMonth()` went from `counted=0 excluded=8` to **`counted=8 excluded=0`**.

### 2.4 An ordinary invoice fits on one page again

26mm of fixed whitespace — `.provenance` at 14mm and `.sign` at 12mm — was what tipped it, not the
content: `INV-0042` (one line, three-line address, tax number) ran to two pages while `INV-0041` with
a shorter address ran to one. Reduced to 8mm and 6mm, and both blocks now carry
`page-break-inside: avoid`.

Measured across all seven fixture invoices: six of seven are now one page (`INV-0039` went 2 → 1).
`INV-0043` is genuinely long — USDT, a chain hash, a rate note — and stays at two, but the break was
checked by extracting the text of each page: the provenance box is whole on page 1 and the break
falls **between** blocks, with notes, terms and the signature together on page 2.

### 2.5 `Email::markRead()` was already fixed; the workaround was not

The open list said it throws a `TypeError` on every call. It does not — the model taps correctly now.
Checked against a real row inside a rolled-back transaction before changing anything. `⚡inbox`'s
local `forceFill` workaround and its comment claiming the model is broken are gone; `setRead()` and
`toggleStar()` call `markRead()` / `markStarred()`.

### 2.6 `⚡clients`' tab strip

Wrapped in `kt-scrollable-x-auto`, matching `⚡client-show`. **Preventive** — measured at 375px it does
not overflow today (strip 327px, body overflow 0), because it is only three tabs long. `.kt-tab-toggle`
inherits the theme's `nowrap`, so a fourth filter would widen the body rather than shrink.

---

## 3. 🔴 Two things changed outside the repository

### 3.1 PHP can reach the internet now

The claim *"there is no CA bundle in this machine's `php.ini`, so outbound HTTPS from PHP fails with
cURL error 60"* was true and is now false. Fixed:

```
C:\Users\morph\PHP\8.3\cacert.pem      Mozilla's bundle from curl.se, 119 roots, 16 July 2026
C:\Users\morph\PHP\8.3\php.ini         curl.cainfo and openssl.cafile both point at it
C:\Users\morph\PHP\8.3\php.ini.bak-2026-08-04   the file as it was
```

The Windows root store was **not** used: it holds 48 roots and is populated on demand, so it would
have worked until the day it silently did not.

Verified: `api.github.com`, `graph.facebook.com`, `api.telegram.org` and `reddit.com` all answer at
the HTTP layer from raw cURL, and `Http::` inside the application returns 200.

**This is not in git.** A fresh machine has to redo it, and every "we cannot make a real request"
note in every previous handover is now answerable in four minutes.

### 3.2 The first real network calls this project has ever made

`php artisan accounting:fetch-rates` was run for real. It recorded three rates —
`USD/TRY 47.556000` from frankfurter, `USDT/USD 0.999300` and `USDT/TRY 47.520000` from coingecko —
and correctly skipped TCMB with the sentence it was written to say, because `EVDS_API_KEY` is not
set. Two of the three rate sources are therefore **proved against their real servers**, not just
`Http::fake()`.

That run is also what settled §2.3's USDT/TRY question: production really does record that pair.

**`v23.0` is no longer an unverified choice.** Graph reads an unknown version as a node name —
`/v99.0/me` and `/vXYZ/me` both answer *"Unknown path components: /me"* while a real version answers
*"An active access token must be used…"*. By that test `v23.0` is live and `v26.0` is the newest;
`v27.0` does not exist yet. The pin is deliberately left where it is: bumping it changes required
parameters and error codes on nine calls that have never been made against a real account.

**The publishing drivers still have not published anything.** That needs the owner's credentials, per
`HANDOVER-2026-08-05.md` §3. What changed is that the wire is no longer the obstacle.

---

## 4. Open, in the order I would take them

1. 🔴 **The first real post on each network.** Still the highest-value work available, and now only
   blocked on credentials rather than on cURL. Ask the owner for one network's token and do that one
   end to end.
2. **`EVDS_API_KEY`.** Without it no invoice to a domestic Turkish company can show the lira
   equivalent the law requires. Free, from `evds2.tcmb.gov.tr` → Profil → API Anahtarı. The command
   already says so out loud when it is missing.
3. **`payments` has no frozen reporting figure**, so a collection is valued at its *invoice's*
   issue-date rate. This is why cash-in/cash-out per month does not exist. `DOUBLE-ENTRY-PLAN.md`
   covers it.
4. **Time tracking → billable hours → invoice.** The biggest must-have that does not exist, and the
   missing link between doing the work and billing it.
5. **The double-entry build**, when the owner asks. The plan is 1,010 lines and ready; §4(d) is
   already done as of this session.
6. **`⚡card-detail:479`'s `openCard()`** and the same shape on `⚡calendar` and `⚡table`. Leaks
   nothing today because Kargah has no per-user visibility model at all — it is where the first
   policy has to land, and that is an architecture decision.
7. Older debts still standing: `CustomerReader` returning Eloquent models where every sibling returns
   arrays · `has:stickers` · Butler's missing calendar and branching · uncursored `/api/v1/customers`
   · card writes and mail sending absent from `/api/v1` · no permalink for Instagram, Threads or Slack
   · Reddit takes no pictures · `MEDIA_NOT_READY` written and unproven · Tumblr on the legacy endpoint.

**Closed this session:** the 39 unclicked inbox targets, `⚡link-create`'s `$kinds[$kind]`,
`⚡clients`' tab strip, `voidInvoice()`'s missing guard, `Email::markRead()`, the seeder's mirrored
freezing logic, the two-page one-line invoice, and `v23.0` being unverified.

## 5. For the next session

Everything the last handover said still holds — open the browser first, never point a clicking
harness at the dev database, and when a report and a measurement disagree, measure again. Two of this
session's findings came from measuring something a document asserted: `Email::markRead()` was
described as broken and was not, and `v23.0` was described as unverified and could be verified in
one command.
