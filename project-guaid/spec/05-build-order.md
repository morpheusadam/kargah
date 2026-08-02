# 05 — Build order

Seven phases. Each ends with something that works, and none begins before the previous one's
acceptance criteria pass. The order is chosen so that the riskiest thing — that the modules do not
actually connect — is proven first, while it is still cheap to change.

---

## Phase 1 — Core, the spine

Nothing else can be built correctly until this exists.

**Build**
- `Core` module, `"priority": 0`
- `companies`, `customers` with migrations, models, factories, seeders
- `links` table, the enforced morph map, and a `Linkable` trait
- `activities` via `spatie/laravel-activitylog`
- `searchables` and Scout on the `database` driver
- Contracts: `CompanyReader`, `CustomerReader`, `Linker`, `Search`
- Company and Customer CRUD screens, reusing existing page conventions

**Done when**
- `php artisan module:migrate` builds a clean database from scratch, twice in a row
- A customer can be created, linked to a company, archived and restored
- A record of every morph-mapped type can be linked to any other and read back both ways
- Search returns a customer by partial name
- Renaming a model class does not orphan a single `links` row

## Phase 2 — Project, and the first real connection

Boards prove the graph works, because a card is the thing everything else wants to point at.

**Build**
- `boards`, `board_lists`, `cards`, labels, checklists, comments
- `cards.position` as `decimal(20,10)` with midpoint insertion and a rebalance command
- Persist drag and drop: `moveCard(cardId, toList, position)` already has its final signature
- Card ↔ customer, card ↔ company
- Replace the board fixtures with queries
- Islands on the list columns

**Done when**
- A card dragged between lists is still there after a refresh
- Reordering 500 cards writes one row, not 500
- A card links to a customer and the customer's page lists its cards
- Every board action appears in the activity feed

## Phase 3 — Accounting

The part where being wrong costs money. Read [03-accounting.md](03-accounting.md) before writing a
line of it.

**Build**
- `currencies`, `exchange_rates`, `invoices`, `invoice_lines`, `expenses`, `payments`,
  `crypto_payments`, `ledger_entries`
- `brick/money` throughout, with the USDT currency defined once
- `accounting:fetch-rates` — TCMB for lira, Frankfurter as reference, CoinGecko for tether
- Rate frozen at issue; reporting-currency figures frozen alongside
- Realised FX gain or loss on payment
- Invoice PDF
- Card → invoice line through `links`

**Done when**
- An invoice issued at one rate still shows that rate after the market moves — asserted by a test
  that changes the rate and re-reads the invoice
- A USD invoice settled in USDT records the chain, the hash and the gain or loss
- A domestic Turkish invoice shows the TCMB buying rate, its date and the lira equivalent
- No float appears anywhere in the money path — enforced by a test that greps for it
- Deleting an invoice does not delete its ledger entries

## Phase 4 — Mailbox, receiving

The hardest thing to make reliable on shared hosting.

**Build**
- `mail_accounts` with encrypted credentials, `emails`, `email_threads`, `attachments`
- `mailbox:sync-imap` on `webklex/php-imap`, chunked and resumable, storing a cursor per account
- `emails.message_id` unique so re-running is safe
- Sender address → customer resolution
- Email → card through `links`
- Islands on the list and reading pane

**Done when**
- Killing the sync job mid-run and restarting it produces no duplicates
- A 2,000-message mailbox syncs across several cron ticks without one tick exceeding
  `max_execution_time`
- An email from a known address shows its customer
- Converting an email to a card links both ways
- The inbox renders in under 200 ms with 10,000 messages stored

## Phase 5 — Mailbox, sending

**Build**
- `delivery_providers` with per-provider daily and hourly quotas, health score and sending domain
- A driver per provider on `symfony/mailer` transports; a router that picks by quota and health
- `campaigns`, `campaign_recipients`, `suppressions`
- Chunked dispatch from cron, each job idempotent per recipient
- Bounce and complaint webhooks writing to the shared suppression list
- `List-Unsubscribe`, and signed `Reply-To` tokens so replies thread back

**Done when**
- A 500-recipient campaign completes across cron ticks with no recipient sent twice, proven by
  killing the worker mid-run
- A hard bounce on one provider blocks that address on every provider
- Exhausting one provider's quota moves the remainder to the next, and the report shows the split
- The pre-flight refuses to send when SPF, DKIM or unsubscribe are missing

## Phase 6 — Data

**Build**
- `attachments` with a morph target and `AttachmentService` as the only writer to disk
- `credentials` encrypted with the application key, revealed per item, each reveal logged
- `bookmarks` for Telegram bots and deployed projects
- `repositories` synced from the GitHub API and cached
- `backups` on a schedule, stored outside the web root

**Done when**
- A file attaches to a card, an invoice and an email through one service
- No secret appears in any rendered HTML — asserted by a test
- Every reveal appears in the activity log with who and when
- A backup restores into a clean database

## Phase 7 — Social

**Build**
- `social_accounts` with encrypted credentials, `posts`, `post_targets`
- Per-network publishing drivers, per-target status and error
- Scheduling through the existing queue pattern
- Notification ingestion where each network's API allows it

**Done when**
- One post publishes to two networks with independent status
- A failed target retries without resending the succeeded one
- A scheduled post fires from cron within a minute of its time

---

## Cross-cutting, every phase

**Tests.** Every new route goes in `SmokeTest`. Every money path gets an assertion. Every job gets
a test that runs it twice and asserts the second run changes nothing.

**Migrations.** `module:migrate`, never `migrate:fresh`.

**No fixtures left behind.** A phase is not done while its page still renders a static array. Grep
for `with(): array` returning literals before calling a phase finished.

**Performance budget.** Any page over 200 ms warm gets looked at before the phase closes. The
current baseline is 75–190 ms and it should not regress.

**Nothing requires a VPS.** If a phase cannot be built without a daemon, the design is wrong —
change the design, not the hosting requirement.
