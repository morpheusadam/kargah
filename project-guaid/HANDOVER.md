# Handover — 3 August 2026

Everything done in one working session, what was read, what was decided, and exactly where the
work stops. Written so the next session can start without re-deriving anything.

**Branch:** `main`, pushed to `github.com/morpheusadam/kargah`
**Last pushed commit:** `afc8a96`
**Started from:** `a1b2855`

---

## Headline

| | Before | After |
|---|---|---|
| Tests | 517 | **942** |
| Assertions | 2,035 | **4,635** |
| Routes | 69 | **86** |
| Livewire pages | 42 | **56** |
| Modules | 6 | **7** (new: `Platform`) |
| Commits | — | **25** |
| `"Not connected yet"` stubs | 1 | **0** |

Full suite was green at `989 passed, 4,635 assertions` after the last full run.

---

## What was read first

In this order, all of it:

- `project-guaid/spec/00-overview.md` — the principles, and the one constraint everything follows
  from: **Kargah must run on ordinary PHP shared hosting.** No Redis, no daemon, no Node on the
  server, no Docker.
- `01-architecture.md` — runtime, module boundaries, the cron-driven queue.
- `02-data-model.md` — the shared graph, Core as the spine.
- `03-accounting.md` — money rules.
- `04-frontend.md` — islands, the 2026 browser capabilities worth adopting.
- `05-build-order.md` — the seven phases, all already built.
- `06-trello-parity.md` and `07-platform.md` — the actual brief for this session.
- `DECISIONS.md` — read in full. Every entry is a trap somebody already fell into.
- `docs/frontend-conventions.md`.

---

## The 25 commits, in order

| Commit | What |
|---|---|
| `505eaa6` | Cut the toast layer back to what the user cannot already see |
| `a67dd91` | Add the Trello search-operator parser |
| `8953ca1` | Add an RFC 5545 iCalendar builder |
| `af015df` | Add application passwords, and the Platform module to hold them |
| `6cc9628` | Correct 06 and 07 where they were wrong about this codebase |
| `ceaf5b8` | Add the notification spine to Core |
| `cf82d3f` | Record the decisions behind this stage of work |
| `19c8102` | Add the `/api/v1` surface, and the Accounting contracts it needed |
| `61f1a04` | Replace `cards.board_list_id` with `card_placements`, and build mirror cards |
| `6cbf0a2` | Add the assistant's provider layer |
| `306dfc2` | Give the notification preferences page something behind it |
| `fa0335d` | Make the security and profile settings pages real |
| `c90812a` | Add custom fields, per board and per card |
| `130983a` | Finish the card's own fields: markdown, members, dates, completion, number |
| `c44a2a1` | Add `08-postiz-parity.md`: take Postiz's ideas, not its code |
| `8fd3071` | Teach the client page the due state that was just split out |
| `7cb86a5` | Add the Table, Calendar and Dashboard views, and the ICS feed |
| `80c91e0` | Give the notification spine its first producers, and build watching |
| `d2538b9` | Compile the search operators and wire them to the board |
| `7dbccfc` | Let `AttachmentService` count files for many targets in one query |
| `e1def97` | Stop rendering the calendar feed link on every visit |
| `4884637` | Add card covers, wire card attachments, and remove the last stub |
| `5ba0ab5` | Give boards backgrounds and labels Trello's ten colours |
| `6472db8` | Warn before a social token dies |
| `afc8a96` | Make the dashboard show real figures |

---

## The architectural change, because everything else sits on it

**`cards.board_list_id` and `cards.position` are gone.** A card's place lives on `card_placements`,
which carries `card_id`, `board_list_id`, `position` and `is_origin`, unique on
`(card_id, board_list_id)`. A card has exactly one origin placement and any number of mirrors.

Position moved to the placement because that is the point: a mirror has its own place in its own
list. **Any query written against either dropped column throws.**

Archiving the source does not archive the mirrors — they keep rendering with an archived marker and
stop being draggable. Archiving a *list* archives the cards whose **origin** is in it; mirrors there
merely stop being drawn, because archiving your own list must not take somebody else's card off
their board.

`moveCard` still takes three arguments but the first is now a **placement id**.

`Board::cards()` is a **query builder, not a relation**, and `distinct` — a card mirrored onto two
lists of one board must count once.

---

## The Postiz question — read this section first if you are picking up the social work

### What was asked

Download `github.com/gitroomhq/postiz-app` and add it to Kargah, behind a menu, so that
**one post publishes to all social networks from one dashboard with one click.**

### What Postiz actually is

Verified against its own source and README, not from memory:

- TypeScript monorepo — Next.js frontend, NestJS backend, pnpm workspaces
- Prisma on **PostgreSQL**
- **Redis**
- **Temporal** — a durable workflow engine with long-running workers
- **Docker**
- Licence **AGPL-3.0**

Those are five of the exact things `00-overview.md`'s founding constraint forbids, and the licence
is incompatible with Kargah's MIT.

### The decision

**Take the ideas, not the code.** The repository is cloned at
`C:\Users\morph\Projects\postiz-app` (shallow, 32 MB) as **read-only reference material**. It is
deliberately outside the Kargah tree. Nothing has been copied from it and nothing should be.

`project-guaid/spec/08-postiz-parity.md` was written from it, in the same shape as
`06-trello-parity.md`. That document is the brief for this work.

### 🔴 The important part: one dashboard, one click **already exists**

Kargah's Social module already does the thing that was asked for:

- `⚡publish.blade.php` — one composer, pick several accounts, **per-network body overrides**, live
  character counters against each network's own limit, and publish now / schedule / save as draft.
- `post_targets` — one row per post per account, with independent status, so a retry physically
  cannot resend a network that already succeeded.
- `social:publish-due` on the one-minute cron, claiming work with a conditional `UPDATE` so a
  killed worker costs nothing.

**What is missing is not the dashboard or the click. It is the number of networks.** Kargah
publishes to four — Mastodon, Bluesky, LinkedIn, Telegram. Postiz supports about thirty-seven.

### How far the Postiz work got

**Done:** item 1 of `08`'s order — `social_accounts.token_expires_at` was a real column that
**nothing read**. It now warns at seven days, one day, and after expiry, through Core's notification
system, with a dedupe key that folds in the expiry timestamp so re-pasting a credential earns a
fresh warning. Only LinkedIn gets a real expiry (60 days, inferred at paste time); Mastodon, Bluesky
and Telegram issue non-expiring credentials, so theirs stays null, correctly.

**Not started, in `08`'s own order:**

1. **Media pipeline, images only** — four parts, named in the spec. `AttachmentService` shipped in
   phase 6 and is polymorphic, so the blocker `DECISIONS.md` recorded is **gone**. What remains: an
   upload step in the composer; resolving media at send time from attachments rather than the unused
   `posts.media` column; a binary-upload leg on `HttpPublisher` plus a per-network upload step (no
   two networks agree); and media limits in the `Networks` catalogue.
2. **X (Twitter)** — OAuth 1.0a tokens are effectively permanent and fit the pasted-token model.
3. **Instagram** and **Facebook Pages** — one Meta Graph client, two networks.
4. **Threads** — same Meta client.
5. **Discord** — a bot token, exactly like Telegram's proven shape. The cheap win.

Then: link shortening, UTM tagging, first comment, a per-network rate ceiling, and exposing Social
through `Platform`'s API.

**Estimate in the spec:** roughly 80–85% of what a freelancer uses daily is reachable on cron plus
the claim pattern. The unreachable tail is resumable video upload, a few networks needing an awkward
one-time OAuth bootstrap, and the multi-tenant features that have no single-user shape.

**Scope out of the first pass: video.** An image fits in one job's `max_execution_time`. Chunked
video — X's 1 MB, LinkedIn's 2 MB, YouTube's 8 MB resumable — does not.

---

## What is built now, by area

### Project (Trello parity)

- `card_placements` and **mirror cards**
- **Search operators** — the full Trello query language: `label:`, `member:`, `due:`, `created:`,
  `has:`, `is:`, `name:`, `description:`, `checklist:`, `comment:`, `sort:`, negation, quoting,
  OR-within-a-key and AND-across-keys. Parser + SQL compiler + board wiring.
- **Custom fields** — five types, per board, per card, typed value columns so a number field sorts
  numerically, dropdown options with stable ids so renaming cannot orphan cards.
- **Card back**: markdown descriptions and comments (sanitised), multiple members, start dates, the
  five-state due colour scale, mark complete, per-board card numbers, **covers** (colour or image,
  half or full, full hides the badges), **attachments** through `AttachmentService`.
- **Board appearance**: solid / gradient / photo backgrounds, light-dark text tone, translucent list
  columns, **Trello's ten label colours**, **colour-blind patterns**, list header colours.
- **Views**: Table (cursor-paginated, inline edit), Calendar (FullCalendar, drag to reschedule),
  Dashboard (ApexCharts), and a **signed ICS feed** so a real calendar client can subscribe.
- **Watching** — card, list and board, with five notification producers in model observers and a
  due-date sweep on the scheduler.

### Core

- **Notification spine** — `user_notifications`, the `Notifier` contract, a cursor-paginated feed,
  a header bell, a prune command, and `dedupe_key` so a cron sweep cannot repeat itself.
- **Notification preferences** — per event, per channel, plus digest and quiet hours that wrap
  midnight and are evaluated in the user's own timezone.

### Platform (new module)

An **edge module**: it may depend on any module's `Contracts` and on no module's `Models`, and
nothing may depend on it.

- **Application passwords** — WordPress's shape: named, shown once, stored hashed, individually
  revocable, twelve scopes, HTTP Basic so `curl -u` works, rate limited, failures logged.
- **`/api/v1`** — whoami, customers, customer emails, invoices, expenses, issue-an-invoice. Money is
  a **string** in JSON. Cursor pagination. One error envelope across every status.
- **Assistant provider layer** — one driver interface, five providers (Gemini, OpenRouter,
  Anthropic, OpenAI, Ollama), a factory registry, and a fake. The interface already carries tool
  calling so the tool layer can be added without changing a driver signature.

### Application level

- **Dashboard** — every figure traces to a row, through contracts. Four lazy islands.
- **`/settings/security`** — real password change, real database-backed sessions, **RFC 6238 TOTP
  two-factor** with recovery codes. The fake API-token list is gone.
- **`/settings/profile`** — persists.

---

## 🔴 Uncommitted work — stopped mid-flight

Two agents were interrupted. **Their work is on disk, uncommitted, and the tests that cover it
pass** (`69 passed, 235 assertions` across `BoardsTest`, `BoardCanvasTest`, `DashboardTest`,
`InvoiceTotalsTest`). It has **not** been through a full-suite run.

```
 M Modules/Accounting/app/Contracts/InvoiceReader.php
 M Modules/Accounting/app/Services/InvoiceReader.php
 M Modules/Mailbox/app/Contracts/EmailReader.php
 M Modules/Mailbox/app/Services/EmailReader.php
 M Modules/Project/resources/views/components/⚡boards.blade.php
 M resources/views/pages/⚡dashboard.blade.php
 M tests/Feature/DashboardTest.php
 M tests/Feature/SocialModuleTest.php
 M project-guaid/DECISIONS.md
?? tests/Feature/BoardCanvasTest.php
?? tests/Feature/InvoiceTotalsTest.php
```

Two things were in progress:

1. **Board canvas integration** — applying backgrounds, label chips with colour-blind patterns, list
   header colours and card covers to `⚡boards.blade.php`. This is the last visual piece; the models
   and the settings UI are all committed and green, only the canvas rendering was outstanding.
2. **`InvoiceReader::totals()` and an inbox-wide unread count** — the two dashboard tiles that
   currently show an honest placeholder instead of a number.

**To resume:** run the full suite first (`php artisan test`, about nine minutes). If green, commit
the two pieces separately. If not, the failures are in the two areas above and nowhere else.

---

## What remains, in priority order

### 06 — Trello parity, roughly half left

| Batch | Contents | Size |
|---|---|---|
| List and board | WIP limits, sort a list, move/archive all cards, collapse a list, starred boards, recently viewed, board activity feed, CSV/JSON export, print view | one wave |
| Card extras | card templates, voting, emoji reactions, @mentions, per-item checklist assignee and due date, copy list, copy board, the linked-cards UI | one wave |
| **Butler** | rules, card buttons, board buttons, calendar commands, due-date commands | **the largest single feature in 06** |
| Keyboard layer | `Shift+?`, `j`/`k`, `n`, `c`, `space`, `0`–`9`. Needs `Palette` to reach ten colours (it now does) and a global listener in the layout, which is a shared file | half a wave |
| Last | Timeline, Map, card ageing, stickers, email-to-board | one wave |

### 07 — Platform

- Assistant **tool layer** and `php artisan kargah:ask` — the provider layer is done and the
  interface already accommodates tool calls
- API: boards/lists/cards (needs `BoardReader`, which now exists), the vault (needs a
  `Modules\Data\Contracts\VaultReader`), companies (needs a `CompanyReader`), a fuller email surface

### 08 — Postiz

The media pipeline, then X, Instagram, Facebook, Threads, Discord. See the section above.

### Debts deliberately left, each recorded

- 🔴 **Two-factor is not enforced at login.** Enrolment, confirmation, recovery codes and disabling
  are all real and tested, and **nothing in the sign-in flow asks for a code**. An account can show
  "Two-factor authentication is on" and still be reached with a password alone. Wiring it into login
  has its own lockout risk and was left as a deliberate, separate change.
- `Modules\Core\Contracts\CustomerReader` returns Eloquent models where every sibling contract
  returns arrays. A column rename in Core would break Platform's JSON silently.
- No per-card deep link — the board opens a card by dispatching a browser event, not by URL — so a
  notification lands on the card's origin board rather than on the card.
- `has:cover`, `has:stickers` and `is:starred` compile to "match nothing" and say so in the UI,
  because the columns do not exist yet. `has:attachments` can now be un-stubbed —
  `AttachmentService::countForTargets()` was added for exactly that.
- The board issues one extra query per card with an image cover.

### The specs say **do not build** these

Workspaces, teams, approval flow, a content marketplace, a Power-Up framework, live multi-cursor
collaboration, a Temporal-style worker pool, gamification. They need a second user or a daemon, and
Kargah has neither.

---

## Traps found this session

All written up in `DECISIONS.md`. The ones most likely to cost a day:

- **On SQLite, dropping a foreign-keyed column inside a transaction silently deletes rows.** The
  rebuild drops the old table, which fires every `ON DELETE CASCADE` pointing at it. Laravel
  disables foreign keys around it — but `PRAGMA foreign_keys` is a **no-op inside an open
  transaction**, and `RefreshDatabase` wraps every test in one. Copy through a staging table.
- **Never put a `Carbon` or any object inside a `Cache::flexible()` value on the `database` store.**
  The stale-serve branch unserialises it as `__PHP_Incomplete_Class` and 500s the page on a *later*
  request.
- **`use RuntimeException;`** — a `use` with a non-compound name — is fatal inside a Livewire
  single-file component. Livewire compiles the PHP block into a namespaced class where the warning
  becomes an `ErrorException`. Write `\RuntimeException` at the throw site.
- **`DB::table()->whereKey()` silently matches nothing.** `whereKey()` is an Eloquent Builder
  method; on the base query builder it becomes a dynamic where on a column named `key`.
- **A variable set as a bare local in a template body does not exist inside an `@island`.** The
  fragment compiles into an isolated view and inherits `with()` data only.
- **A new Tailwind class has no effect until `kargah.css` is rebuilt.** The command is in the shared
  brief and in `docs/frontend-conventions.md`; `node`/`npx` and the template *are* on this machine,
  at `C:\Users\morph\Projects\admin-panel-ui\veltrix-tailwind-html-starter-kit`. `public/assets` is
  gitignored, so the rebuild is local and every clone must run it. `BoardAppearanceTest` now asserts
  new palette classes are actually present in the compiled sheet.
- **PHPUnit 12 ignores the `@dataProvider` doc annotation.** Use the `#[DataProvider]` attribute.

---

## How the parallel work was organised, if repeating it

Agents were partitioned by **exclusive file ownership**. Two agents editing one file destroy each
other's work with no merge, no conflict marker and no error — the second write simply wins.

The pattern that worked, and which should be used from the start next time: when an agent needs a
file another agent owns, it builds a **nested Livewire component** and reports **one mount line**,
which the owner adds. Custom fields and card watching both did this cleanly.

The two hottest files are `⚡boards.blade.php` and `⚡card-detail.blade.php`. Five separate features
needed them, which is what serialised the work and is the main reason the session ran to about three
and a half hours rather than two.

A shared brief was kept at a scratchpad path and read by every agent before its own prompt. It is
worth recreating: environment, the hard rules, the toast rule, the testing rules, and how to report
back.

---

## Environment notes that still hold

- PHP `C:\Users\morph\PHP\8.3\php.exe`; Composer at `C:\Users\morph\PHP\8.3\composer.phar`
- SQLite at `database/database.sqlite`; dev login `admin@admin.com` / `admin`
- **Always `cd` to the project in every shell call** — the working directory silently reverts
- `php artisan migrate --force`. **Never `migrate:fresh`.** Never bare `module:migrate`
- `php timing-probe.php` measures every page warm. Budget 200 ms. Dashboard 101–134 ms, board
  78–93 ms, table 71 ms, calendar 33 ms
- `EVDS_API_KEY` and `GITHUB_TOKEN` are absent and expected to be. **No CA bundle in `php.ini`**, so
  any real outbound HTTPS fails with cURL error 60. Tests never touch the network
- Do **not** assert wall-clock budgets inside the suite. It is not a quiet machine; assert query
  counts instead
