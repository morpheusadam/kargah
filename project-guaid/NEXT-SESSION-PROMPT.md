You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
Laravel 13.23 · Livewire 4.3 · `nwidart/laravel-modules` 13 · PHP 8.3 · SQLite. Eight modules:
`Accounting` `Blog` `Core` `Data` `Mailbox` `Platform` `Project` `Social`. Branch `main`, **clean and
fully pushed** to `github.com/morpheusadam/kargah` at `91f6406`. **1,312 tests passing**, 6,328
assertions, ~7 minutes for the full suite.

The owner writes in Persian and expects Persian back. Code, paths, commands and product names stay in
Latin script.

---

# 🔴 Read this part first. It is the one that changes how you work

**There is a browser on this machine.** Three handovers in a row said there was not, and every one of
them was wrong. Chrome is at `C:\Program Files\Google\Chrome\Application\chrome.exe`, and
`playwright-core` drives it with no browser download:

```powershell
npm install playwright-core          # ~2 MB, no bundled browser
```
```js
chromium.launch({ executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' })
```

On 4 August 2026 that took twenty minutes and found, on a suite that was fully green:

- the **dashboard destroying itself on every load** — the server sent 108 KB, Livewire's island morph
  threw, and the page ended with 370 characters of readable text;
- **not one vendor JavaScript bundle had ever loaded** — both dashboard charts, both
  `/projects/dashboard` charts and **both calendars** had never rendered once, on any machine, ever;
- **two pages returning HTTP 500.**

None of it was visible to a single assertion, because every assertion in this project is against
server-rendered markup and the server-rendered markup was correct throughout.

🔴 **So: open the app in a browser before you believe anything about it, and do it first rather than
last.** A passing test is not evidence that a person can use the page.

The harnesses are no longer something to recreate: they are in the repository at **`tools/audit/`**,
with `playwright-core` in `devDependencies`. `tools/audit/README.md` is the procedure. Running them
again on the afternoon of 4 August found **nothing** across 59 routes and 92 inbox click targets —
which is what a clean answer from this tooling looks like, and worth knowing before you go hunting.

`project-guaid/HANDOVER-BROWSER-2026-08-04-PM.md` is the latest account; the file it is named after
is the morning session that found all of the above.

## 🔴 Never point a clicking harness at the dev database

`database/database.sqlite` holds the owner's real book. A harness that clicks everything **will**
delete an invoice, change the admin password and sign every session out — all three happened.

```powershell
Copy-Item "database\database.sqlite" "storage\app\audit-copy.sqlite" -Force
$env:DB_DATABASE = "C:\Users\morph\Projects\kargah\storage\app\audit-copy.sqlite"
Start-Process -FilePath "C:\Users\morph\PHP\8.3\php.exe" `
  -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8124' `
  -WorkingDirectory "C:\Users\morph\Projects\kargah" -WindowStyle Hidden
```

Then **prove the isolation by measurement**: a request to 8124 must move the copy's mtime and leave
`database/database.sqlite` untouched. This works only because there is no `bootstrap/cache/config.php`
— if anybody ever runs `config:cache`, the override stops applying **silently** and the harness starts
writing to the real book.

Run `php artisan migrate --force` against every copy, and `php artisan db:seed --force` if you need
rows to click on. A copy made before a migration lands is a copy that 500s on the page you are
auditing.

Two servers with two copies halves the wall clock. `php artisan serve` is single-threaded and
`PHP_CLI_SERVER_WORKERS` is a no-op on Windows, so **one browser per server, never two**.

Port **8123** is the owner's own server against the real database. Read from it; never click on it.
Clean up after yourself: stop the servers you started and delete the copies.

---

# The app, so you can find your way around

Login `admin@admin.com` / `admin`. Two-factor is not enabled. `php artisan route:list` is the map;
the shape of it:

| Area | Routes | Component prefix |
|---|---|---|
| Home | `/dashboard`, `/notifications` | `pages::` |
| Projects | `/projects` + `/table` `/calendar` `/dashboard` `/activity` `/archive` `/butler` `/{board}/settings` | `project::` |
| Accounting | `/accounting/` invoices · estimates · expenses · recurring · clients · reports, and `/invoices/{id}/pdf` | `accounting::` |
| Mail | `/mail/` inbox · campaigns · contacts · providers · suppression | `mailbox::` |
| Data | `/data/` files · links · passwords · repos · backups | `data::` |
| Social | `/social/` accounts · calendar · posts · publish · notifications | `social::` |
| Blog | `/blog`, `/blog/compose`, `/blog/{article}/edit` | `blog::` |
| API | `/api/v1/…` read-only, token-authenticated | `Modules/Platform` |

**Components are Livewire 4 single-file components** — one file holding both the class and the
template, named `⚡<name>.blade.php` under `Modules/<X>/resources/views/components/`. Read
`docs/frontend-conventions.md` before writing any Blade. It is the law here, and it was corrected on
4 August; the `@assets` section is new and right.

---

# The traps. Each has cost somebody a full debugging cycle

## Livewire

1. 🔴 **A `<script src="…">` inside `@script … @endscript` is never fetched.** `@script` carries
   *inline* JavaScript to Livewire's runtime, which evaluates it; a tag whose whole content is an
   external `src` has no code to evaluate. No tag in the DOM, no request, no error. **Use
   `@assets … @endassets` for the bundle and `@script` for the code that uses it.**
2. 🔴 **`@push('scripts')` does not work inside a Livewire component.** Livewire discards a pushed
   stack silently. `@push` remains correct in a layout or a plain Blade view.
3. 🔴 **Naming an island suppresses the full-component `html` effect.** Measured in Chrome — the
   browser receives `effect keys: returns, islandFragments` and **no `html`**. So a class outside an
   island does not update on an island response. Livewire's *source* suggests otherwise; the source
   is not what the browser gets. **Anything whose class changes with state must live inside the
   island that redraws it.**
4. ⚠️ **`Livewire::test(...)->html()` renders the component in full regardless**, so a test that
   asserts on `html()` after a `call()` cannot see trap 3 at all. Assert on the island fragment.
5. 🔴 **A lazy island's `@placeholder` must be a complete, balanced subtree and the island's first
   child.** Livewire stops emitting the body at `@endplaceholder`, so a placeholder nested inside the
   body's own elements leaves their closing tags unwritten; the parser then puts `[if ENDFRAGMENT]`
   *inside* those elements, `morphBetween()`'s two markers stop being siblings, and
   `Block.appendChild()` throws on `null.before` **part-way through a morph** — the browser keeps
   whatever Livewire had already torn down. `DashboardTest` enforces it.
6. 🔴 **An `@island` behind an `@if` that is false on first paint is never registered at all.**
   `renderIslandDirective()` registers only while mounting, so `renderIsland()` then finds no token
   and sends nothing for the rest of the page's life. Render the element `hidden`, not absent.
7. **An island is skipped unless you name it.** An action that changes markup inside an island must
   call `renderIsland()` for it, or the new markup is computed, sent and thrown away.
8. **`use RuntimeException;`** — a `use` with a non-compound name — is fatal inside a single-file
   component. Write `\RuntimeException` at the throw site.
9. **Never guard a JS mount with a `data-*` attribute** — the morph strips it and you bind twice.
   Ask the library: `chart.destroy()`, `Sortable.get(el)`.
10. **`morph.updated` fires per DOM node; `morphed` fires per component.** The first is almost never
    what you want.

## Validation

11. 🔴 **Passing an explicit rules array to `validate()` REPLACES the `#[Validate]` attribute rules
    for that call.** A blank link could be saved for exactly this reason. ⚠️ The broader version of
    this claim is **false**: `HandlesValidation::getRules()` ends
    `array_merge($rulesFromComponent, $rulesFromOutside)`, so `rules()` and `#[Validate]` **are**
    merged. Only the argument to `validate()` replaces.
12. **`after_or_equal:startsOn` must be conditional on `startsOn` being present.** Laravel resolves
    the referenced field through `date_create()`, and `date_create('')` is *now* — so an
    unconditional rule silently rejects any end date before today.

## CSS and the theme

13. **A new Tailwind class does nothing until `public/assets/css/kargah.css` is rebuilt**, from
    `C:\Users\morph\Projects\admin-panel-ui\veltrix-tailwind-html-starter-kit`. **Check before you
    use a class**, prefer ones already in the tree, and never build a class name by concatenation —
    the scanner reads source text.
14. 🔴 **`.kt-btn` carries `white-space: nowrap; flex-shrink: 0`**, so a row of buttons **cannot**
    shrink to fit. `flex-wrap` on the group is the only thing that saves it at 375px. Three pages
    were found scrolling the body sideways for exactly this.
15. 🔴 **A `.kt-dropdown` panel that also carries a display utility is permanently visible** — the
    theme hides it from the components layer, Tailwind's `.flex` lives in utilities, and a cascade
    layer beats specificity. Put the utility inside the conditional. `DropdownVisibilityTest` refuses it.
16. **A flex item's `min-width: auto` will not shrink below its content.** `min-w-0` must be on
    *both* flex items, and `truncate` does nothing without it.
17. 🔴 **ApexCharts' `hexToRgba()` replaces any colour not starting with `#` by grey `#999999`.**
    This theme's `--color-primary` computes to `oklch(…)`, so a CSS variable comes out grey on a
    chart that still renders. Series colours are hex literals on purpose.
18. 🔴 **ApexCharts and FullCalendar must never go in the global layout** — together they added
    854 KB to every page. Load from `@assets` on the page that needs them, and always ship a
    server-rendered fallback underneath. **That fallback is the only reason trap 1 was cosmetic
    rather than catastrophic. Keep doing it.**
19. **Keenicons only**, and only names present in
    `public/assets/vendors/keenicons/styles.bundle.css` render — anything else is a blank glyph with
    no error. `ki-send`→`ki-paper-plane`, `ki-link`→`ki-arrow-up-right`, `ki-file`→`ki-document`,
    `ki-global`→`ki-map`, `ki-profile-user`→`ki-profile-circle`, `ki-branch`→`ki-tree`.

## Database and models

20. 🔴 **SQLite: dropping a foreign-keyed column makes Laravel recreate the table**, which fires
    every `ON DELETE CASCADE` pointing at it — and `PRAGMA foreign_keys` is a documented **no-op
    inside an open transaction**. Adding a column is safe. To drop, copy through a staging table with
    no foreign keys of its own; `card_placements`' migration is the pattern.
21. 🔴 **NEVER `migrate:fresh`.** The dev database holds the owner's real data. `migrate --force` is
    fine. Never bare `php artisan module:migrate` — it is interactive and aborts.
22. **The dev database can have unrun migrations while the suite is green** — tests run against
    `:memory:`. Check `php artisan migrate:status` for `Pending` before believing a page works.
23. **`DB::table()->whereKey()` silently matches nothing.** Eloquent-only.
24. **`cards.board_list_id` and `cards.position` do not exist.** A card's place lives on
    `card_placements`. `Board::cards()` is a query builder, not a relation.
25. **Pivots raise no Eloquent events.** `card_label` and `card_members` notify Butler by hand.
26. 🔴 **`Article::factory()->make()` writes to the database** — it resolves a nested `Post::factory()`
    by *creating* the row. Wrap any factory probe in a transaction and roll it back.
27. 🔴 **A module factory needs a `newFactory()` override on the model.**
28. **PHPUnit 12 ignores the `@dataProvider` doc annotation.** Use the `#[DataProvider]` attribute.
29. **Never put a `Carbon` or any object inside a `Cache::flexible()` value** on the database store.
30. **`Str::slug()` transliterates rather than strips** — a Persian tag became `brnamhnoysy`. Drop
    what you cannot render; never invent copy nobody wrote.
31. **GitHub push protection blocks a plausible-looking fake token.** A fixture only has to be a
    distinctive string.

## Accounting, and these are load-bearing

32. 🔴 **Never `SUM()` money in SQL.** SQLite stores a decimal as an IEEE double. Fetch rows, add
    through `Money`. `NoFloatsInMoneyTest` enforces it.
33. 🔴 **Never mix currencies in one figure.** Two currencies get two figures.
34. 🔴 **A converted figure is frozen on its document and never re-derived.** Changing
    `config('accounting.reporting_currency')` moves **nothing** already issued — a mixed book is the
    normal state. ⚠️ The whole current book froze `USD` while the config says `TRY`, so the
    dashboard's expense series is legitimately empty on real data. That is the design, not a defect.
35. 🔴 **The ledger is append-only.** "Delete" means `reverse($reason)` + soft-delete + **recompute
    derived state**. If the entry cannot be identified, do none of it.
36. **A sequential invoice number is never reused** — an issued invoice is voided, never deleted.
37. 🔴 **Tax numbers are all second-hand and unverified against the Gelir İdaresi Başkanlığı.** Where
    the research said "could not verify" the page prints the open question and no number. **Keep it
    that way. Silence beats a wrong tax number.**

---

# Environment. Each of these has cost an hour

- PHP is `C:\Users\morph\PHP\8.3\php.exe`. Composer at `C:\Users\morph\PHP\8.3\composer.phar`.
  Python 3 with PIL, and Node, are both available.
- 🔴 **`cd` to the project explicitly in EVERY shell call.** The working directory silently reverts to
  `C:\Users\morph\Projects\Visa`. A `git status` without a `cd` once reported a clean tree in the
  wrong repository and it looked like six agents' work had been wiped.
- The shell is **PowerShell 7**. `head`, `wc`, `[ -f x ]` and backticks are parse errors. In a
  double-quoted string `\$` stays literal and PHP then reads it as a namespace separator — **use
  single-quoted strings for `--execute` payloads**. `$pid` is a read-only automatic variable; naming
  a variable `$pid` fails silently.
- **`php artisan tinker <file>` HANGS.** `--execute="…"` works.
- Full suite ~7 minutes and **exceeds a 600 s tool timeout** — run it in the background, and note
  that `| Out-String` buffers, so you see nothing until it ends. `--filter=SmokeTest` is ~15 s and
  walks every route.
- ⚠️ **Scripted edits with Python change line endings.** Check `git diff --stat` after any scripted
  edit before believing the change is small.
- **Run `php artisan view:clear` before believing a Blade result.** Two edits inside the same second
  can leave a stale compiled view and you will test the previous code.

---

# Where things stand

## Decisions the owner has taken

- **Double-entry accounting: the plan is written, the code is not.**
  `project-guaid/DOUBLE-ENTRY-PLAN.md` is 1,010 lines — chart of accounts, schema, posting rules per
  event, backfill, every report that changes, ten invariants with the mutation that proves each, and
  stages sized so the suite stays green between them. **Building it is a session of its own and the
  owner has not asked for it yet.**
- **The invoice is a scope-of-work document.** One line carries the price and the name of the
  engagement — "Full website SEO" — and underneath it sits the work, **with no price against any of
  it**. `invoice_lines.tasks` (JSON), `invoices.starts_on` / `ends_on`. The PDF prints the period, a
  signature block and `lavzen.com`, all from `config('accounting.document.*')`.
- 🔴 **The signature PNG is deliberately NOT in git.** The repo is public, and a signature image in a
  public repository is one anybody can lift; git keeps it in the history even after a delete. It sits
  at `public/img/signature.png`, is in `.gitignore` with the reasoning, and **a clone without it
  still renders a valid signature block** — the rule, the name and the date. If the machine changes,
  re-extract it from the owner's source image by luminance threshold: the ink is below 16, the
  watermark above 224, so they separate cleanly and nothing has to be painted over.

## Open, in the order I would take them

⚠️ **Items 1–5, 7 and 9 of the list this section used to carry were closed on the afternoon of
4 August** — see `HANDOVER-BROWSER-2026-08-04-PM.md`. What is left, renumbered:

1. 🔴 **The first real post on each network.** Now blocked on credentials only: PHP could not reach
   the internet at all until this machine got a CA bundle, and it can now. See the PM handover §3.
2. **`EVDS_API_KEY` is not set**, so no invoice to a domestic Turkish company can show the lira
   equivalent the law requires. Free, from `evds2.tcmb.gov.tr` → Profil → API Anahtarı.
3. **`payments` has no frozen reporting figure**, so a collection is valued at its *invoice's*
   issue-date rate. This is why cash-in/cash-out per month does not exist.
4. **Time tracking → billable hours → invoice** — the biggest must-have that does not exist, and the
   missing link between doing the work and billing it.
5. **`⚡card-detail:479`'s `openCard()`** is a public `#[On('open-card')]` listener doing a bare
    `Card::find()`, and `⚡calendar` and `⚡table` expose the same shape. It leaks nothing today
    because Kargah has **no per-user visibility model at all** — but it is where the first policy has
    to land, and writing that policy is an architecture decision, not a bug fix.
6. Older debts still standing: `CustomerReader` returning Eloquent models where every sibling
    returns arrays · `has:stickers` · Butler's missing calendar and branching · uncursored
    `/api/v1/customers` · card writes and mail sending absent from `/api/v1` · no permalink for
    Instagram, Threads or Slack · Reddit takes no pictures · `MEDIA_NOT_READY` written and unproven ·
    Tumblr on the legacy endpoint.
7. **The double-entry build**, when the owner asks for it. §4(d) of the plan — void refusing a
    standing payment — is already done, so the plan is one stage shorter than it reads.

⚠️ **Two claims in this file are now out of date and are corrected in the PM handover:** there is a
CA bundle in this machine's `php.ini` (outbound HTTPS from PHP works, and `accounting:fetch-rates`
has been run for real), and `v23.0` has been verified against the live Graph host. Credential steps
per network are still in `HANDOVER-2026-08-05.md` §3.

## What to read, in order

```
project-guaid/HANDOVER-BROWSER-2026-08-04-PM.md   the most recent session — start here
project-guaid/HANDOVER-BROWSER-2026-08-04.md      the morning before it: why you open a browser
tools/audit/README.md                             before running anything that clicks
project-guaid/HANDOVER-ACCOUNTING-2026-08-06.md   the money rules
project-guaid/HANDOVER-2026-08-06.md              publishing, credential leaks, module ownership
project-guaid/HANDOVER-2026-08-05.md              the seventeen publishing destinations
project-guaid/DOUBLE-ENTRY-PLAN.md                only if the owner asks for it
project-guaid/ACCOUNTING-RESEARCH.md              the source the accounting decisions came from
docs/frontend-conventions.md                      before writing any Blade
project-guaid/DECISIONS.md                        skim; read the traps in full
```

---

# 🔴 Use the `subagents` skill

Read `~/.claude/skills/subagents/SKILL.md` and follow it. Four sessions have now run on it. What
earns its place, in order:

1. **Exclusive file ownership, decided before anything launches.** Two agents editing one file
   destroy each other silently — no merge, no conflict marker, the second write wins. Give every
   agent an exact list of files it owns *and* the list it must not touch, naming who owns those.
   Cross-cutting reconciliation is **yours**; it is precisely what could not be partitioned.
2. **One shared brief in the scratchpad that every agent reads before its own prompt** — environment,
   traps, house style, report format. Add a domain brief when the work has its own rules.
3. 🔴 **Mutation-test, do not trust a green suite.** Break the code deliberately, confirm the test
   fails, restore, and paste the failure message. A test that passes both ways is worth nothing.
   **Put this in the brief** — agents do it unprompted once it is there.
4. **Agents never run `git add`, `commit`, `checkout`, `stash`, `restore` or `reset`.** Other agents
   have uncommitted work on disk. The main thread commits.
5. **Reviewers write findings and do not edit.**
6. **Agents have no browser.** Do not ask one to confirm a layout. Have it say precisely what to look
   at, then look yourself.

🔴 **And the rule that mattered most on 4 August: when a report and a measurement disagree, measure
again.** Six confidently-stated claims were disproved that day — two were mine, one was the shared
brief's, one was an agent arguing correctly from Livewire's source and wrongly about the browser.
Every one fell to somebody running the thing rather than reading about it. **Write briefs that invite
that** — say what you believe and why, so an agent can check it rather than obey it — and then verify
what comes back yourself. Never take "the code was written" as green.

---

# Standing instructions

- **Decide for yourself at every step and keep going.** Pick the best option, write down why in a
  docblock **at the site of the decision**, and move on. Do not stop to ask unless proceeding would
  be destructive or would waste an hour if the guess were wrong.
- **Any problem found along the way can be fixed later** — note it and continue. But *do* fix a real
  bug you hit on the way, and say plainly that you did.
- **Minimal diffs.** If a file already follows the conventions, leave it and say so. A diff that
  rewrites a file to change nothing visible is a bad diff.
- **Do not write new tests** unless the behaviour genuinely has no coverage and would be dangerous
  without it. If an existing assertion describes behaviour your change legitimately altered, correct
  it and say so. **Prefer assertions on behaviour over assertions on prose** — and never assert on a
  bare three-digit string, which is how one test came to fail once in six runs against a random
  address.
- Run the full suite once at the end, in the background. Run `vendor/bin/pint` on the files you
  touched, not the whole tree.
- Commit per batch with one-line messages ending:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- **Pushing to `origin/main` is authorised.** The repo is public; the branch is clean. 🔴 **Because it
  is public, think before committing anything personal** — the signature is the precedent, and it is
  the kind of decision that cannot be taken back by deleting the file later.
- **Report honestly**: what was verified and how, what was not, and what you left out and why.
  Scaling the work down is the owner's call, not yours.
