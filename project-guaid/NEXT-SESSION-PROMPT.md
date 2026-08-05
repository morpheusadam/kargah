You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
Laravel 13.23 · Livewire 4.3 · `nwidart/laravel-modules` 13 · PHP 8.3 · SQLite. Eight modules:
`Accounting` `Blog` `Core` `Data` `Mailbox` `Platform` `Project` `Social`. Branch `main`, clean and
fully pushed to `github.com/morpheusadam/kargah`. **1,313 tests passing**, 6,333 assertions,
~7 minutes for the full suite.

The owner writes in Persian and expects Persian back. Code, paths, commands and product names stay in
Latin script. **The owner is morpheus** (`morpheusadam95@gmail.com`); the Claude account belongs to
someone else, so do not address them by the account's name.

---

# 🔴 Read this part first. It is the one that changes how you work

## It is deployed. There is a real server.

Kargah is live at **`https://panel.lavzen.com`**, on the owner's Hostinger account, serving the
owner's real book: four invoices, ₺80,000, all paid.

**`.data/ssh.txt` has every access detail** — SSH, the panel login, server paths, the DNS record, the
hPanel account. It is gitignored and must stay that way; this repository is public. Read it before
touching the server.

Key-based SSH is installed, so it needs no password once you have the host and user from
`.data/ssh.txt`:

```powershell
ssh -p <port> -o BatchMode=yes <user>@<host> "<command>"
```

🔴 **`/opt/alt/php83/usr/bin/php`, never bare `php`** — the account's default is 8.2 and Kargah needs
8.3. Deploy is a `git pull` on the server followed by `artisan optimize:clear`.

`project-guaid/HANDOVER-DEPLOY-2026-08-05.md` is the full account of how it is put together and what
it cost. **Read it before changing anything that touches URLs, assets or class imports** — four bugs
lived in exactly those places and the suite was green through all of them.

## There is a browser on this machine

Chrome is at `C:\Program Files\Google\Chrome\Application\chrome.exe` and `playwright-core` drives it
with no browser download. The harnesses are in **`tools/audit/`** with a README; they are worth using
before you believe anything about a page.

🔴 **Never point a clicking harness at the dev database.** `database/database.sqlite` is the owner's
real book. Copy it, point `DB_DATABASE` at the copy, and **prove the isolation by measuring mtimes**.
`tools/audit/README.md` has the procedure and the reason.

**Open the app in a browser before you believe anything about it, and do it first rather than last.**
A passing test is not evidence that a person can use the page. On 4 August that discipline found a
dashboard destroying itself on load, four pages whose JavaScript had never once loaded, and two 500s —
none of it visible to a single assertion.

## And the one this deployment added

**When something works here and not on the server, do not reason about the difference — send a request
that isolates it.**

```powershell
curl.exe -s -i --resolve panel.lavzen.com:80:<host> "http://panel.lavzen.com/"
```

That one line proved the vhost and document root were right and left DNS as the only suspect. Chrome
takes `--host-resolver-rules=MAP host ip` for the same trick, which proved the whole application
worked under a hostname before its DNS record was touched.

---

# The app, so you can find your way around

Login `admin@admin.com` / `admin` **locally only** — the deployed panel has one real account, in
`.data/ssh.txt`. `php artisan route:list` is the map; the shape of it:

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
`docs/frontend-conventions.md` before writing any Blade. It is the law here.

---

# The traps. Each has cost somebody a full debugging cycle

## Portability — the newest set, and the cheapest to re-break

1. 🔴 **`Route::redirect()` emits its target verbatim.** It takes a literal string and Laravel does not
   prefix the base path, so an install under a subdirectory redirects to the wrong host. Use
   `redirect()->route(...)`.
2. 🔴 **Never write `/assets/…` in a template.** `asset()` resolves against the request root, which is
   the only thing that knows where the application lives. Twenty-one hardcoded paths made the deployed
   login page render in Times New Roman with no server-side error at all.
3. 🔴 **Class imports are case-sensitive on Linux and not on Windows.** `Brick\Money\ISOCurrencyProvider`
   passed 1,313 tests here and 500'd on the first deployed page load; the file is
   `IsoCurrencyProvider.php`.

## Livewire

4. 🔴 **A `<script src="…">` inside `@script … @endscript` is never fetched.** `@script` carries
   *inline* JavaScript to Livewire's runtime; a tag whose whole content is an external `src` has no
   code to evaluate. No tag, no request, no error. **Use `@assets … @endassets` for the bundle.**
5. 🔴 **`@push('scripts')` does not work inside a Livewire component.** The stack is discarded
   silently. `@push` remains correct in a layout or a plain Blade view.
6. 🔴 **Naming an island suppresses the full-component `html` effect.** Measured in Chrome: the browser
   receives `effect keys: returns, islandFragments` and no `html`. Livewire's *source* suggests
   otherwise; the source is not what the browser gets. **Anything whose class changes with state must
   live inside the island that redraws it.**
7. ⚠️ **`Livewire::test(...)->html()` renders the component in full regardless**, so a test asserting
   on `html()` after a `call()` cannot see trap 6. Assert on the island fragment.
8. 🔴 **A lazy island's `@placeholder` must be a complete, balanced subtree and the island's first
   child**, or `morphBetween()`'s markers stop being siblings and the morph throws part-way through.
   `DashboardTest` enforces it.
9. 🔴 **An `@island` behind an `@if` that is false on first paint is never registered at all.** Render
   the element `hidden`, not absent.
10. **An island is skipped unless you name it** in `renderIsland()`.
11. **`use RuntimeException;`** is fatal inside a single-file component. Write `\RuntimeException`.
12. **Never guard a JS mount with a `data-*` attribute** — the morph strips it and you bind twice. Ask
    the library: `chart.destroy()`, `Sortable.get(el)`.
13. **`morph.updated` fires per DOM node; `morphed` fires per component.**

## Validation

14. 🔴 **Passing an explicit rules array to `validate()` REPLACES the `#[Validate]` attribute rules for
    that call.** ⚠️ The broader claim is **false**: `rules()` and `#[Validate]` *are* merged. Only the
    argument to `validate()` replaces.
15. **`after_or_equal:startsOn` must be conditional on `startsOn` being present** — `date_create('')`
    is *now*, so an unconditional rule silently rejects any end date before today.
16. 🔴 **`$set('prop', …)` sets whatever the client sends.** A template that indexes an array by that
    property dies with `Undefined array key` during render, before any validation runs. Give it a
    fallback.

## CSS and the theme

17. **A new Tailwind class does nothing until `public/assets/css/kargah.css` is rebuilt.** Prefer
    classes already in the tree, and never build a class name by concatenation.
18. 🔴 **`.kt-btn` carries `white-space: nowrap; flex-shrink: 0`**, so a row of buttons cannot shrink.
    `flex-wrap` on the group is the only thing that saves it at 375px.
19. 🔴 **A `.kt-dropdown` panel that also carries a display utility is permanently visible** — a cascade
    layer beats specificity. `DropdownVisibilityTest` refuses it.
20. **`min-w-0` must be on *both* flex items, and `truncate` does nothing without it.**
21. 🔴 **ApexCharts' `hexToRgba()` turns any colour not starting with `#` into grey.** Series colours
    are hex literals on purpose.
22. 🔴 **ApexCharts and FullCalendar must never go in the global layout** — together they added 854 KB
    to every page. Load from `@assets` on the page that needs them, and always ship a server-rendered
    fallback underneath.
23. **Keenicons only**, and only names present in `styles.bundle.css` render — anything else is a blank
    glyph with no error.

## Database and models

24. 🔴 **SQLite: dropping a foreign-keyed column makes Laravel recreate the table**, firing every
    `ON DELETE CASCADE` pointing at it. Adding a column is safe. `card_placements`' migration is the
    pattern for dropping.
25. 🔴 **NEVER `migrate:fresh`.** The dev database holds the owner's real data. Never bare
    `php artisan module:migrate` — it is interactive and aborts.
26. **The dev database can have unrun migrations while the suite is green** — tests run against
    `:memory:`. Check `migrate:status` for `Pending` before believing a page works.
27. **`DB::table()->whereKey()` silently matches nothing.** Eloquent-only.
28. **`cards.board_list_id` and `cards.position` do not exist.** A card's place lives on
    `card_placements`.
29. **Pivots raise no Eloquent events.** `card_label` and `card_members` notify Butler by hand.
30. 🔴 **`Article::factory()->make()` writes to the database.** Wrap any factory probe in a transaction.
31. 🔴 **A module factory needs a `newFactory()` override on the model.**
32. **PHPUnit 12 ignores `@dataProvider`.** Use the `#[DataProvider]` attribute.
33. **Never put a `Carbon` inside a `Cache::flexible()` value** on the database store.
34. **`Str::slug()` transliterates rather than strips.** Drop what you cannot render; never invent copy.

## Accounting, and these are load-bearing

35. 🔴 **Never `SUM()` money in SQL.** SQLite stores a decimal as an IEEE double. Fetch rows, add
    through `Money`. `NoFloatsInMoneyTest` enforces it.
36. 🔴 **Never mix currencies in one figure.** Two currencies get two figures.
37. 🔴 **A converted figure is frozen on its document and never re-derived.** Changing
    `config('accounting.reporting_currency')` moves nothing already issued — a mixed book is normal.
38. 🔴 **The ledger is append-only.** "Delete" means `reverse($reason)` + soft-delete + recompute. If
    the entry cannot be identified, do none of it.
39. **A sequential invoice number is never reused** — an issued invoice is voided, never deleted.
40. 🔴 **Voiding refuses when payments stand.** `PaymentRecorder::paid()` is the one definition of what
    has landed; `statusFor()`, `outstanding()` and the void guard all read it.
41. 🔴 **Tax numbers are second-hand and unverified against the Gelir İdaresi Başkanlığı.** Where the
    research said "could not verify" the page prints the open question and no number. **Keep it that
    way. Silence beats a wrong tax number.**

---

# Environment. Each of these has cost an hour

- PHP is `C:\Users\morph\PHP\8.3\php.exe`. Composer at `C:\Users\morph\PHP\8.3\composer.phar`.
  Python 3 with `pypdf` and PIL, and Node, are all available.
- 🔴 **`cd` to the project explicitly in EVERY shell call.** The working directory silently reverts to
  `C:\Users\morph\Projects\Visa`.
- The shell is **PowerShell 7**. `head`, `wc`, `[ -f x ]` and backticks are parse errors. In a
  double-quoted string `\$` stays literal — **use single-quoted strings for `--execute` payloads**.
  `$pid` is read-only and naming a variable `$pid` fails silently.
- **`php artisan tinker <file>` HANGS.** `--execute="…"` works, and
  `--execute='require "C:/…/script.php";'` is the way to run anything longer than a line.
- **Outbound HTTPS from PHP works now.** `C:\Users\morph\PHP\8.3\cacert.pem` is installed and
  `php.ini` points `curl.cainfo` and `openssl.cafile` at it; the backup is `php.ini.bak-2026-08-04`.
  This is **not in git** — a fresh machine has to redo it.
- Full suite ~7 minutes and **exceeds a 600 s tool timeout** — run it in the background. ⚠️ Do not run
  it after every small change; a targeted `--filter` plus a browser check is usually the honest test.
- ⚠️ **Scripted edits change line endings.** Check `git diff --stat` after any scripted edit.
- **Run `php artisan view:clear` before believing a Blade result.**

---

# Where things stand

## Decisions the owner has taken

- **Double-entry accounting: the plan is written, the code is not.** `project-guaid/DOUBLE-ENTRY-PLAN.md`
  is 1,010 lines. §4(d) — void refusing a standing payment — already landed, so it is one stage
  shorter than it reads. **Building the rest is a session of its own and the owner has not asked.**
- **The invoice is a scope-of-work document.** One line carries the price and the name of the
  engagement, and the work sits underneath it **with no price against any of it**. The printed document
  has a classic double-ruled frame, ticks rather than bullets, and drops a fraction that is entirely
  zero (`₺20,000`, not `₺20,000.00`) while keeping `₺20,000.50` intact.
- 🔴 **The signature PNG is deliberately NOT in git.** The repo is public. It sits at
  `public/img/signature.png`, is in `.gitignore` with the reasoning, and a clone without it still
  renders a valid signature block — the rule, the name and the date.

## Open, in the order I would take them

1. 🔴 **The panel password is in a chat transcript.** Change it from Settings → Security. The owner
   types it; nobody else should.
2. **The four invoices read `Billed to: Nima Fazlipour`**, taken from a direct answer to "who should
   the invoice be issued to". The owner later said Nima is the person who gave them the Claude account.
   **Ask before those documents go anywhere** — an issued number is never reused, so correcting the
   name means void and reissue, not an edit.
3. 🔴 **The first real post on each network.** Still the highest-value work in the product, still
   blocked only on credentials — `HANDOVER-2026-08-05.md` §3 has the per-network steps. Not one
   publishing driver has ever been called for real; every request shape is proved with `Http::fake()`
   and nothing else.
4. **`EVDS_API_KEY` is not set**, so no invoice to a domestic Turkish company can carry the lira
   equivalent the law requires. Free, from `evds2.tcmb.gov.tr` → Profil → API Anahtarı.
5. **Nothing keeps the deployed copy up to date.** Every deploy so far has been a hand-run `git pull`.
6. **`payments` has no frozen reporting figure**, so a collection is valued at its *invoice's*
   issue-date rate. This is why cash-in/cash-out per month does not exist.
7. **Time tracking → billable hours → invoice** — the biggest must-have that does not exist.
8. **The double-entry build**, when the owner asks.
9. **`⚡card-detail`'s `openCard()`** and the same shape on `⚡calendar` and `⚡table`: public
   `#[On]` listeners doing a bare `Card::find()`. Leaks nothing today because Kargah has **no per-user
   visibility model at all** — it is where the first policy has to land, and that is an architecture
   decision.
10. Older debts: `CustomerReader` returning Eloquent models where every sibling returns arrays ·
    `has:stickers` · Butler's calendar and branching · uncursored `/api/v1/customers` · card writes and
    mail sending absent from `/api/v1` · no permalink for Instagram, Threads or Slack · Reddit takes no
    pictures · `MEDIA_NOT_READY` unproven · Tumblr on the legacy endpoint.

## What to read, in order

```
project-guaid/HANDOVER-DEPLOY-2026-08-05.md       the server — start here
.data/ssh.txt                                     how to reach it (gitignored)
project-guaid/HANDOVER-BROWSER-2026-08-04-PM.md   the browser audits and what they found
project-guaid/HANDOVER-BROWSER-2026-08-04.md      why you open a browser at all
tools/audit/README.md                             before running anything that clicks
project-guaid/HANDOVER-ACCOUNTING-2026-08-06.md   the money rules
project-guaid/HANDOVER-2026-08-06.md              publishing, credential leaks, module ownership
project-guaid/HANDOVER-2026-08-05.md              the seventeen publishing destinations
project-guaid/DOUBLE-ENTRY-PLAN.md                only if the owner asks for it
docs/frontend-conventions.md                      before writing any Blade
project-guaid/DECISIONS.md                        skim; read the traps in full
```

---

# Standing instructions

- **Decide for yourself at every step and keep going.** Pick the best option, write down why in a
  docblock **at the site of the decision**, and move on. Do not stop to ask unless proceeding would be
  destructive or would waste an hour if the guess were wrong.
- **Back up before writing to the real book or to a live host**, and say where the backup is.
- **Minimal diffs.** If a file already follows the conventions, leave it and say so.
- **Do not write new tests** unless the behaviour genuinely has no coverage and would be dangerous
  without it. If an existing assertion describes behaviour your change legitimately altered, correct
  it and say so. **Mutation-test anything you claim is covered**: break the code, watch the test fail,
  restore, and paste the failure.
- Run the full suite **once, at the end**, in the background. Run `vendor/bin/pint` on the files you
  touched, not the whole tree.
- Commit per batch with one-line messages ending:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- **Pushing to `origin/main` is authorised.** 🔴 **Because the repo is public, think before committing
  anything personal** — the signature and `.data/` are the precedents, and it is the kind of decision
  that cannot be taken back by deleting the file later.
- **Report honestly**: what was verified and how, what was not, and what you left out and why.
