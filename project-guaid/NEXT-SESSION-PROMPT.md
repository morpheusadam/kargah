You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
Laravel 13.23 · Livewire 4.3 · `nwidart/laravel-modules` 13 · PHP 8.3 · SQLite. Eight modules:
`Accounting` `Blog` `Core` `Data` `Mailbox` `Platform` `Project` `Social`. Branch `main`, **clean and
fully pushed** to `github.com/morpheusadam/kargah` at `bd40f2f`. **1,304 tests passing**, 6,274
assertions, ~11 minutes for the full suite.

🔴 **Use the `subagents` skill.** Read `~/.claude/skills/subagents/SKILL.md` and follow it exactly.
Three sessions have now run on it. What earned its place, in order of value:

1. **Exclusive file ownership, decided before anything launches.** Two agents editing one file destroy
   each other silently. Give every agent an exact list of files it owns *and* the list it must not
   touch, naming who owns those. Cross-cutting reconciliation is **yours** — it is precisely what
   could not be partitioned.
2. **One shared brief in the scratchpad every agent reads before its own prompt.** Last session used
   `BRIEF.md` (environment, traps, house style, report format) plus a domain brief
   (`ACCOUNTING-BRIEF.md`) carrying the money rules. Recreate both.
3. 🔴 **Mutation-test, do not trust a green suite.** Break the code deliberately, confirm the test
   fails, restore, and paste the failure message. Last session did this on seven invariants and
   re-ran four of them independently of the agent that claimed them. A test that passes both ways is
   worth nothing. **Put this in the brief** — two agents did it unprompted and it was the single
   highest-value habit of the session.
4. **Agents never run `git add`, `commit`, `checkout`, `stash`, `restore` or `reset`.** Other agents
   have uncommitted work on disk. The main thread commits.
5. **Reviewers write findings and do not edit.**

**Agents caught three of my own wrong premises last session** — the owner's country, "TRY is the
reporting currency", and "expenses block a client delete". Write briefs that invite that: say what
you believe and why, so an agent can check it rather than obey it.

## Read these first

```
project-guaid/HANDOVER-ACCOUNTING-2026-08-06.md   the accounting module — start here
project-guaid/HANDOVER-2026-08-06.md              publishing, credential leaks, module ownership
project-guaid/HANDOVER-2026-08-05.md              the seventeen publishing destinations
docs/frontend-conventions.md                      before writing any Blade
project-guaid/DECISIONS.md                        skim, read the traps in full
```

## 🔴 Do this first, before any new feature

**Open the app in a browser and look at it.** Nothing in the last session was seen by a human eye —
there is no browser on this machine and every "it works" means an HTTP 200 plus an assertion against
server-rendered markup.

That matters most for the **dashboard charts**, which are the only thing in the codebase that is not
server-rendered:

- Nothing here executes JavaScript, so **nothing proves ApexCharts parses the options at all.**
- Every chart has a server-rendered table underneath, hidden only once the chart actually renders. If
  the charts are broken you will see tables, not empty boxes — that was deliberate.
- Check `/` (dashboard), `/accounting/reports`, `/accounting/estimates`, `/accounting/recurring`,
  `/blog/1/edit`, and a board with `?card=<id>`.
- Nothing has been checked at 375 / 768 / 1440px.

Start the server **detached or it dies with the session**:
```powershell
Start-Process -FilePath "C:\Users\morph\PHP\8.3\php.exe" -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8123' -WorkingDirectory "C:\Users\morph\Projects\kargah" -WindowStyle Hidden
```
Login: `admin@admin.com` / `admin`. Two-factor is not enabled.

⚠️ `php artisan serve` is **single-threaded** and `PHP_CLI_SERVER_WORKERS` does not work on Windows.
A browser audit that loads pages in a loop queues behind itself and times out at about a dozen pages.
One fresh iframe per page with a gap between them; a screenshot taken during contention can show a
**stale frame** — that has caused a wrong conclusion twice.

## Then, in the order I would take them

### 1. 🔴 The double-entry decision — the only debt that gets more expensive by waiting

`ACCOUNTING-RESEARCH.md` (in the last session's scratchpad; regenerate if gone) recommends a
double-entry engine under a single-entry UI — what Wave, Akaunting and QuickBooks Solopreneur all do.
It was **deliberately not built**: it touches every model and every report and was not asked for.

Every month of invoices and expenses makes the migration bigger. **Put the decision to the owner
before building anything else large.** If they say yes, plan it properly — it is a session of its own
and needs a written plan before code.

Related and smaller: **nothing posts a `TYPE_EXPENSE` ledger entry.** Only the seeder and factory do.
So any balance read from `LedgerEntry::standing()` is income-only. `⚡expense-edit::delete()` already
reverses whatever it finds, so nothing breaks when expenses start posting.

### 2. Time tracking → billable hours → invoice

The biggest must-have in the research that does not exist. Kargah already has projects, boards and
cards; this is the missing link between doing the work and billing it. Harvest's whole product thesis.
Needs: time entries against a card or project, a billable flag, an hourly rate, and "unbilled time"
as a queue that feeds invoice creation.

### 3. Accounting debts the last session recorded rather than fixed

- **`payments` has no frozen reporting figure**, so a collection is valued in lira at its *invoice's*
  issue-date rate. This is why cash-in/cash-out per month could not be built and why the P&L says it
  does not fold FX in. Needs one column written at the moment of payment.
- **Credit notes**, deposits/retainers, payment reminders.
- **`AccountingDatabaseSeeder` mirrors `InvoiceIssuer`'s freezing logic** rather than calling it, and
  writes `reporting_currency => USD` for expenses — so a freshly seeded demo no longer demonstrates
  the shipped default.
- **Duplicated by necessity, worth extracting**: `Estimate::nextInvoiceNumber()` duplicates the
  private `⚡invoice-edit::nextNumber()`; `reportingFigures()` exists in the expense form *and* the
  recurring-expense command; the expense category list exists on two classes. All three are private
  to anonymous Livewire classes nothing can import.
- **`Estimate`/`EstimateLine` are in no morph alias**, which is why neither uses `Linkable` or
  `LogsActivity`. `MorphMap::enforce()` throws for an unaliased model used polymorphically — add the
  aliases *first* if you want activity logging on estimates.

### 4. Security debt named last session and not closed

🔴 **`⚡card-detail.blade.php:479` — `openCard()` is a public `#[On('open-card')]` listener doing a
bare `Card::find()`.** Reachable straight from the browser with `Livewire.dispatch('open-card',
{cardId: N})`. `⚡calendar.blade.php:360` and `⚡table.blade.php:455` expose the same pattern. This
leaks nothing *today* because Kargah has **no per-user visibility model at all** — no policy, no
gate, `Board::active()` with no user scoping — but it is where the first policy has to land.

### 5. Older debts still standing

`Modules\Core\Contracts\CustomerReader` returns Eloquent models where every sibling returns arrays ·
`has:stickers` · Butler's missing calendar and branching · uncursored `/api/v1/customers` · card
writes and mail sending absent from `/api/v1` · `v23.0` a choice rather than a verified current
version · no permalink for Instagram, Threads or Slack · Reddit takes no pictures · `MEDIA_NOT_READY`
written and unproven · Tumblr on the legacy post endpoint ·
`SocialAccountFactory::connected()`'s literal `'test-credential'`.

**Pinterest is the closest refused platform to being possible** — it needs a signed public image URL,
which now exists and is long-lived.

## Environment — each of these has cost an hour

- PHP is `C:\Users\morph\PHP\8.3\php.exe`. Composer at `C:\Users\morph\PHP\8.3\composer.phar`.
- 🔴 **`cd` to the project explicitly in EVERY shell call.** The working directory silently reverts to
  `C:\Users\morph\Projects\Visa`. **This bit me last session**: a `git status` without a `cd` reported
  a *clean tree* — in the wrong repository — and for a minute it looked like six agents' work had been
  wiped. The wrong answer is indistinguishable from a catastrophe. Every git call gets a `cd`.
- The shell is **PowerShell 7**. `head`, `wc`, `[ -f x ]` and backtick substitution are parse errors.
- **`php artisan tinker <file>` HANGS.** `--execute="…"` works and is the fast way to ask the booted
  app a question.
- `php artisan migrate --force`. 🔴 **NEVER `migrate:fresh`** — the dev database holds real data.
  Never bare `php artisan module:migrate`; it is interactive and aborts.
- Tests run against `:memory:`, so the dev database is safe from `RefreshDatabase`.
- Full suite ~11 min and **exceeds a 600s tool timeout** — run it in the background.
  `--filter=SmokeTest` is ~15 s and walks every route.
- ⚠️ **Scripted edits with Python change line endings**; `pint` then reports `line_ending` fixes on
  every file. Git normalises so diffs stay proportional, but check `git diff --stat` after any
  scripted edit before believing the change is small.

## The traps. Each has already cost a full debugging cycle

1. **An island is skipped unless you name it.** `HandlesIslands::renderIslandDirective()` returns a
   `mode: skip` fragment for every `@island` nobody called `renderIsland()` for.
2. **SQLite: dropping a foreign-keyed column inside a transaction silently deletes rows.** Adding is
   safe. To drop, copy through a staging table.
3. **`use RuntimeException;`** — a `use` with a non-compound name — is fatal inside a Livewire
   single-file component. Write `\RuntimeException` at the throw site.
4. **`DB::table()->whereKey()` silently matches nothing.** Eloquent-only.
5. **A new Tailwind class does nothing until `public/assets/css/kargah.css` is rebuilt**, from
   `C:\Users\morph\Projects\admin-panel-ui\veltrix-tailwind-html-starter-kit`. Prefer classes that
   already appear in the tree. Never build a class name by concatenation.
6. 🔴 **A `.kt-dropdown` panel that also carries a display utility is permanently visible** — the theme
   hides it from the components layer, Tailwind's `.flex` lives in utilities, and a cascade layer beats
   specificity outright. Put the utility inside the conditional. `DropdownVisibilityTest` refuses it.
7. **A flex item's `min-width: auto` will not shrink below its content.** `min-w-0` must be on *both*
   flex items or it does nothing.
8. **Never guard a JS mount with a `data-*` attribute** — the morph strips it and you bind twice. Ask
   the library: `chart.destroy()`, `Sortable.get(el)`.
9. 🔴 **`@push('scripts')` does not work inside a Livewire component.** Livewire discards it and
   `@assets` **silently** — no error, no warning, no script. Use `@script … @endscript`.
10. 🔴 **ApexCharts and FullCalendar must never go in the global layout** — together they added 854 KB
    to every page and made the single-threaded dev server time out. Load from `@script` on the page
    that needs them, and always ship a server-rendered fallback underneath.
11. 🔴 **ApexCharts' `hexToRgba()` replaces any colour not starting with `#` with grey `#999999`.**
    This theme's `--color-primary` computes to `oklch(…)`, so CSS variables come out grey on a chart
    that still renders — nothing looks broken. Series colours are hex literals on purpose.
12. **Livewire ships `@script` inside `wire:effects` as JSON**, so every slash in a `src` is escaped
    to `\/`. An assertion on the full path fails against HTML that does contain the script.
13. **`cards.board_list_id` and `cards.position` do not exist.** A card's place lives on
    `card_placements`. `Board::cards()` is a query builder, not a relation.
14. **Pivots raise no Eloquent events.** `card_label` and `card_members` notify Butler by hand.
15. **PHPUnit 12 ignores the `@dataProvider` doc annotation.** Use the `#[DataProvider]` attribute.
16. **Never put a `Carbon` or any object inside a `Cache::flexible()` value** on the database store.
17. **`Str::slug()` transliterates rather than strips**, so a Persian tag became `brnamhnoysy`. Drop
    what you cannot render; never invent copy nobody wrote.
18. **GitHub push protection blocks a plausible-looking fake token.** A test fixture only has to be a
    distinctive string. See `SlackTumblrPublisherTest::SLACK_TOKEN`.
19. 🔴 **`Article::factory()->make()` writes to the database** — it resolves a nested `Post::factory()`
    by *creating* the row. Wrap any factory probe in a transaction and roll it back.
20. 🔴 **A module factory needs a `newFactory()` override on the model.** `resolveFactoryName()` looks
    for `Database\Factories\Modules\<X>\Models\<Y>Factory`, which is not where a module keeps anything.

### Accounting-specific, and they are load-bearing

21. 🔴 **Never `SUM()` money in SQL.** SQLite stores a decimal as an IEEE double, so a SQL sum of money
    is approximate. Fetch rows, add through `Money`. `NoFloatsInMoneyTest` enforces this.
22. 🔴 **Never mix currencies in one figure.** Two currencies get two figures. Adding them needs a
    rate, and a rate needs a date and a source before anybody can argue with the result.
23. 🔴 **A converted figure is frozen on its own document and never re-derived.** Re-converting history
    at today's rate would make last March move every time the lira does. Changing
    `config('accounting.reporting_currency')` moves **nothing** already issued — a mixed book is the
    normal state.
24. 🔴 **The ledger is append-only.** `LedgerEntry` refuses `update()` and `delete()`. "Delete" means
    `reverse($reason)` + soft-delete + **recompute derived state**. If the entry cannot be identified,
    do none of it.
25. **A sequential invoice number is never reused** — an issued invoice is voided, never deleted, and
    the soft delete keeps the number reserved because `nextNumber()` counts `withTrashed()`.
26. 🔴 **Tax numbers are all second-hand from research and unverified against the Gelir İdaresi
    Başkanlığı.** Rates live in `config/config.php` because Turkish brackets are revalued annually.
    Where the research said "could not verify" — whether stopaj applies to a *foreign* payer — the
    page says so and prints no number. **Keep it that way. Silence beats a wrong tax number.**

## Standing instructions

- **Decide for yourself at every step and keep going.** Pick the best option, write down why in a
  docblock at the site of the decision, and move on. Do not stop to ask unless proceeding would be
  destructive or would waste an hour if the guess were wrong.
- **Any problem found along the way can be fixed later** — note it and continue. But *do* fix a real
  bug you hit on the way, and say plainly that you did.
- Do not write new tests unless the behaviour genuinely has no coverage and would be dangerous
  without it. If an existing assertion describes behaviour your change legitimately altered, correct
  it and say so. **Prefer assertions on behaviour over assertions on prose.**
- Run the full suite once at the end, in the background. Run `vendor/bin/pint` on the files you
  touched, not the whole tree.
- Commit per batch with one-line messages ending:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- **Pushing to `origin/main` is authorised.** The repo is public; the branch is clean.
- Report honestly: what was verified and how, what was not, and what you left out and why. Scaling
  the work down is the owner's call, not yours.
