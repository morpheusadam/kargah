# Handover — the day somebody looked, 4 August 2026

The previous handover ended with a sentence: *"The first thing the next session should do is open the
dashboard and the reports page in a browser and look at them."*

It was right, and the reason it was right is the whole of this document. **The suite was green — 1,304
tests, 6,274 assertions — and the home screen was broken, both charts had never rendered, both
calendars had never rendered, and two pages returned HTTP 500.** None of it was visible to a single
assertion, because every assertion in this project is against server-rendered markup and the
server-rendered markup was correct the entire time.

---

## 1. 🔴 There is a browser on this machine, and there always was

Three handovers in a row say *"there is no browser on this machine"* and treat that as a fact of the
environment. It is not. **Chrome is installed** at
`C:\Program Files\Google\Chrome\Application\chrome.exe`, and `playwright-core` drives it with no
browser download at all:

```powershell
npm install playwright-core          # ~2 MB, no bundled browser
```
```js
chromium.launch({ executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' })
```

That is the single most valuable thing in this document. Every "we cannot verify this without a
browser" note in every previous handover was answerable in four minutes.

The harnesses written today are in the session scratchpad and are worth recreating rather than
re-inventing: a page-load audit that asks the DOM whether a library loaded, an interactive audit that
clicks every `wire:click` on a page, a form audit that submits every form empty and filled, and a
responsive audit that measures sideways overflow at 375 / 768 / 1440.

### 🔴 Never point a clicking harness at the dev database

`database/database.sqlite` holds the owner's real book. A harness that clicks everything **will**
delete an invoice, change the admin password and sign every session out — all three happened today.

The pattern that makes it safe, and it was proved by measurement rather than assumed:

```powershell
Copy-Item "database\database.sqlite" "storage\app\audit-copy.sqlite" -Force
$env:DB_DATABASE = "C:\Users\morph\Projects\kargah\storage\app\audit-copy.sqlite"
Start-Process -FilePath "C:\Users\morph\PHP\8.3\php.exe" `
  -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8124' `
  -WorkingDirectory "C:\Users\morph\Projects\kargah" -WindowStyle Hidden
```

Then check the mtimes: a request to 8124 must move the copy and leave `database/database.sqlite`
alone. `env()` reaches the child process because there is no `bootstrap/cache/config.php` — **if
somebody ever runs `config:cache`, this stops working silently and the harness starts writing to the
real book.**

Run `php artisan migrate --force` against each copy too. A copy made before a migration lands is a
copy that 500s on the page you are trying to audit.

Two servers with two copies halves the wall clock. `php artisan serve` is single-threaded and
`PHP_CLI_SERVER_WORKERS` is a no-op on Windows, so one browser per server, never two.

---

## 2. What the browser found

### 2.1 🔴 The dashboard destroyed itself on every load

The server sent a complete 108,597-byte page. Livewire's lazy-island morph then threw

```
TypeError: Cannot read properties of null (reading 'before')
    at Block.appendChild (livewire.js:13729)
    at context.patchChildren (livewire.js:13560)
    at Object.morphBetween (livewire.js:13437)
```

and left the DOM at 86,317 bytes with **370 characters of readable text**. Gone: the receivables
card, both charts, both fallback tables, the agenda, the activity feed, the quick actions. What
remained was four stat tiles and a page of nothing.

**The cause is a rule that is not written down anywhere in Livewire's documentation.** Livewire stops
emitting the island body at `@endplaceholder`. A `@placeholder` written *inside* the body's own
elements therefore leaves their closing tags unwritten, and the parser puts `[if ENDFRAGMENT]`
**inside** those elements instead of beside `[if FRAGMENT]`. Read off the served HTML:

```html
<div class="kt-card-content p-0 divide-y divide-border">
    <div class="p-5 flex flex-col gap-3">
        <!--[if BLOCK]-->…3 skeleton spans…<!--[if ENDBLOCK]-->
    </div>
<!--[if ENDFRAGMENT:type=island|name=due-cards|…]-->   ← inside the div, not beside the start marker
```

`morphBetween()` builds `new Block(startMarker, endMarker)` and walks `startComment.nextSibling`
looking for `endComment`. When the two are not siblings it never arrives, `fromBlockEnd` comes back
`null`, and `Block.appendChild()` calls `null.before()` — **part-way through a morph**, so the
browser keeps whatever Livewire had already torn down.

**The rule: a lazy island's `@placeholder` must be a complete, balanced subtree and must be the
island's first child.** Three of the four islands on the dashboard were malformed and one was not,
so the page half-worked, which is the worst way for this to present.

`DashboardTest::test_every_lazy_island_placeholder_is_a_balanced_subtree` enforces it by counting
`<div>` against `</div>` between each island's own markers. Mutation-tested: restoring the old shape
fails with *"Island 'agenda' renders unbalanced markup between its own markers… Failed asserting that
2 is identical to 4."*

### 2.2 🔴 No vendor JavaScript bundle had ever loaded — none of them, ever

**A `<script src="…">` written inside `@script … @endscript` is silently dropped.** `@script` hands
Livewire *inline* JavaScript to evaluate; a tag whose whole content is an external `src` has no code
to evaluate. No tag in the DOM, no network request, no error. Measured:

```
/dashboard          ApexCharts:  early=false after6s=false   network for "apexcharts": NO REQUEST
/projects/dashboard ApexCharts:  early=false after6s=false   network for "apexcharts": NO REQUEST
/social/calendar    FullCalendar early=false after6s=false   network for "fullcalendar": NO REQUEST
/projects/calendar  FullCalendar early=false after6s=false   network for "fullcalendar": NO REQUEST
```

Both bundles were on disk the whole time. **Four pages, two libraries, never once rendered.** The
only reason nobody noticed is the decision the last session made deliberately: every chart has a
server-rendered table beneath it and every calendar a plain list, hidden only once the library draws.
That decision is the sole reason this was a cosmetic failure rather than four blank pages, and it
should be kept for everything that follows.

**The fix is `@assets … @endassets` for the bundle and `@script` for the code that uses it.** After
it: `ApexCharts: early=true`, bundle fetched 200, `apex=2/2` charts drawn with SVG, fallback tables
correctly hidden, FullCalendar drawing 4 events — and `/accounting/invoices` and `/mail/inbox` still
request neither bundle, so the 854 KB rule is intact.

⚠️ **`docs/frontend-conventions.md` said Livewire discards `@assets` too. That sentence was wrong and
it is what kept this alive.** It is now corrected, with the measurement written beside it. The same
file also named `Modules/Social`'s calendar as "the pattern to copy" — that calendar was one of the
four that had never rendered.

### 2.3 Two pages were returning HTTP 500

`/accounting/estimates` and `/accounting/recurring`, both shipped and both documented as working.
`estimates` and `recurring_expenses` had never been migrated onto the dev database. Tests run against
`:memory:`, so the suite created the tables itself and was green throughout.

**Before believing a page works, run `php artisan migrate:status` and look for `Pending`.**

### 2.4 Three pages scrolled the body sideways at 375px

Against the conventions' own rule that the page body never scrolls sideways. Measured with a 5-second
settle, because a first attempt at this produced a false positive from an island that had not
finished loading:

| Page | Offender | Was |
|---|---|---|
| `/accounting/clients/1` | the tab strip, `Notes` toggle | ended at 414px |
| `/accounting/recurring` | header action group | ended at 417px |
| `/mail/suppression` | header action group | ended at 396px |

Both action groups were `flex items-center gap-2` with no `flex-wrap`, inside an outer row that
*did* wrap. The tab strip needed `kt-scrollable-x-auto`. ⚠️ `.kt-btn` carries
`white-space: nowrap; flex-shrink: 0` in the theme, so a row of buttons **cannot** shrink to fit —
`flex-wrap` is the only thing that saves it. `⚡clients.blade.php` has the same unwrapped tab strip
and has not overflowed yet only because its tabs are shorter.

### 2.5 A blank link could be saved, past its own validation

`⚡link-create::save()` called `$this->validate(['kind' => …])`. **Passing an explicit rules array to
`validate()` replaces the `#[Validate]` attribute rules for that call.** `kind` carries a default, so
the only rule that ran always passed: `required|string|max:190` on `title` and `required|url|max:500`
on `url` never executed. An empty submit produced `bookmarks` row `title="" url=""`, flashed a
success toast reading *"Saved "* and redirected to the list as though it had worked.

🔴 **The narrow rule, because the broad version of it is false.** `HandlesValidation::getRules()`
ends `return array_merge($rulesFromComponent, $rulesFromOutside);` — the `rules()` method and the
`#[Validate]` attributes **are** merged. What replaces the set is *passing an array to `validate()`*.
A brief written today claimed the broad version, an agent disproved it with the vendor source, and
the narrow version is what is true.

Swept the rest of the codebase: `⚡board-settings:464` and `⚡card-detail:1356` use the same shape and
are **correct** — both are upload handlers that deliberately validate only the upload.

⚠️ Found and **not** fixed: setting `⚡link-create`'s `kind` to an unknown value passes validation's
refusal and then kills the page with `Undefined array key` from `$kinds[$kind]` in the template.
Reachable, because `kind` is driven by `$set('kind', …)` from `wire:click` rather than `wire:model`.

### 2.6 What the clicking found: nothing, and that is worth saying

**~430 `wire:click` targets across 48 routes, on two throwaway databases, with every `wire:confirm`
accepted. Zero JavaScript errors, zero 500s, zero Livewire error dialogs.** Both form passes — every
form submitted empty and then filled — produced zero errors too.

Coverage was honest but not total: `/mail/inbox` has 84 targets and the harness capped at 45, so
**39 were never clicked**; and pages whose row-level actions need rows only became reachable after
the copies were seeded. The five `NO-VALIDATION` verdicts in the form audit were all my heuristic
mistaking a "open the form" button for a submit — checked one by one, four were false and the fifth
was §2.5.

---

## 3. The Mail section

The owner asked for two things: the empty reading pane gone, and a visual pass over every Mail page.

### 3.1 🔴 The inbox pane, and the trap that got both of us

The idle inbox devoted half a 1440px screen to a `min-h-[700px]` card containing an icon and *"No
message open"*. It is gone. The list now takes the width until a message is opened.

**The mechanism cost two attempts and is the most important paragraph in this file.**

The first attempt put a conditional `col-span` on the two `<section>` elements, which sit **outside**
both islands. It rendered correctly on a fresh `GET` and then never moved again: clicking a message
tinted the row and the pane stayed hidden, with the whole suite green and no error anywhere.

The disagreement was resolved by measuring rather than by reading. An agent argued from Livewire's
source that `renderIsland()` cannot suppress the full render — `HandlesIslands.php` contains no
`skipRender` call, and `HandleComponents.php:225` adds the `html` effect unconditionally. Both of
those statements are true. **The browser still receives this:**

```
effect keys: returns, islandFragments
html effect present: false
```

🔴 **So: naming an island suppresses the full-component `html` effect by the time it reaches the
client. A class outside an island does not update on an island response.** Read the source all you
like; open Chrome before you believe it.

⚠️ And `Livewire::test(...)->html()` renders the component in full **regardless**, which is why the
first attempt's test passed. A test that asserts on `html()` after a `call()` cannot see this bug at
all. `InboxPageTest::test_the_pane_reaches_the_browser_inside_its_island_and_not_only_on_a_page_load`
asserts on the island fragment instead, and is mutation-tested: moving the `<section>` back outside
the island fails with *"The pane island did not carry its own width…"*.

**The shape that works.** The pane's `<section>` moved *inside* `@island(name: 'pane')`, and the
layout became flex rather than a twelve-column grid — under flex the list needs no conditional class
at all, it simply grows into whatever the pane is not using. One conditional class, inside the island
that redraws it. Measured in Chrome:

| | list | pane |
|---|---|---|
| idle | 860px | `hidden` |
| open | 420px | 420px |
| closed again | 860px | `hidden` |

🔴 **A second island trap, found on the way and not previously recorded:** `renderIslandDirective()`
registers an island only while the component is mounting. An `@island` behind an `@if` that is false
on first paint **is never registered at all**, and `renderIsland()` then finds no token and sends
nothing for the rest of the page's life. That is why the pane's section is `hidden` rather than
absent.

Also fixed while in there: `selectEmail()` named the list island only when the read flag changed, but
the open row also carries `bg-accent/60` and a 3px bar driven by `$selected` from *inside* the list
island — so opening an already-read message left the tint on the row you had just left.

### 3.2 The visual pass

Five pages, three agents, exclusive file ownership. What was genuinely wrong:

- `⚡campaign-edit` — header action group missing `flex-wrap`; three summary values carried `truncate`
  without `min-w-0`, which does nothing inside a `justify-between` flex row.
- `⚡contact-import` — a CSV with headers and no data rows rendered a header-only table and
  *"First 0 rows of 0"* with no message. Now an empty state.
- `⚡suppression` — the four stat cards stayed two-up at 375px.
- `⚡compose`, `⚡provider-edit` — footer button rows could not wrap.
- `⚡campaigns`, `⚡campaign-show`, `⚡providers` — **audited and deliberately left alone.** They were
  already right. A diff that rewrites a file to change nothing visible is a bad diff.

Every page verified afterwards in Chrome at 375 / 768 / 1440: all 200, no sideways scroll anywhere,
compose modal fine at 375, zero JS errors.

---

## 4. The invoice builder

The owner bills as a developer: one line carries the price and the name of the engagement, and the
work sits underneath it **unpriced**. Plus a working period, a signature, and `lavzen.com`.

**Schema — three columns, all added, nothing dropped.**
`invoice_lines.tasks` (JSON, nullable, cast to `array`), `invoices.starts_on`, `invoices.ends_on`.
🔴 Adding is deliberate: on SQLite, dropping a foreign-keyed column makes Laravel recreate the table
and copy the rows, which fires every `ON DELETE CASCADE` pointing at it — and `PRAGMA foreign_keys`
is a documented no-op inside an open transaction.

**Config** — `accounting.document.footer` (`lavzen.com`), `signature_name` (`Hesam Ahmadpour`),
`signature_image` (a path under `public/`, and **a missing file is not an error**).

**The document.** Verified by decoding the actual PDF bytes rather than by reading the template:

```
WORK PERIOD
10 August 2026 – 30 September 2026
…
Full website SEO
· Keyword research and a mapped target list
· On-page fixes across every template
· Technical audit: speed, crawl and structured data
4   $300.00   $1,200.00
…
Hesam Ahmadpour
2 June 2026
lavzen.com
```

One figure on the priced line, **none against any task**. A line with no tasks and an invoice with no
period render exactly as before — most rows in the database are both.

The signature image is inlined as a base64 `data:` URI rather than handed to dompdf as a path:
`isRemoteEnabled => false` and dompdf's own chroot mean a path that resolves in PHP does not
necessarily resolve in the renderer, and a missing image there draws nothing and reports nothing.

⚠️ **`public/img/signature.png` does not exist yet.** Until it does the document prints the rule, the
name and the date, which is a valid signature block. The owner has the source image; extracting it is
one script away (`scratchpad/signature.py` selects ink by luminance and crops).

⚠️ A one-line invoice currently runs to two pages. The `position: fixed` footer therefore prints
twice, which is correct footer behaviour — but the page break itself is worth a look.

---

## 5. Double-entry: the plan exists, the code does not

The owner decided: **write the plan this session, build it later.** It is
`project-guaid/DOUBLE-ENTRY-PLAN.md`, 1,010 lines: chart of accounts, schema, posting rules per
event, the backfill, every report that changes, ten invariants with the mutation that proves each,
and execution stages sized so the suite stays green between them.

Two measured facts from it that change how the module should be read:

- **The whole book froze `USD` while `config('accounting.reporting_currency')` says `TRY`.** Every
  expense and 6 of 7 invoices. So `expensesByMonth()` returns `counted=0 excluded=8` and the
  dashboard's expense series is flat at zero on real data. That is the documented behaviour — issuing
  never backfills history — not a defect. The seeder debt is what makes a fresh demo misleading.
- **`ledger_entries` is 3 real rows plus 8 seeded ones.** Nothing in production code writes a
  `TYPE_EXPENSE` entry. The plan therefore backfills from the *documents*, not from the old ledger.

🔴 **`ACCOUNTING-RESEARCH.md` had never been committed** — it existed only in a previous session's
`%TEMP%` scratchpad, one clear away from gone. It is now at `project-guaid/ACCOUNTING-RESEARCH.md`.

---

## 6. Where earlier beliefs were wrong

| Belief | Reality |
|---|---|
| "There is no browser on this machine" | Chrome is installed. Three sessions worked around a constraint that did not exist. |
| `docs/frontend-conventions.md`: Livewire discards `@assets` | It does not. This sentence is why four pages never loaded their JavaScript. |
| `Modules/Social`'s calendar is "the pattern to copy" | That calendar had never rendered. |
| A brief written today: `#[Validate]` is dead whenever `rules()` exists | False — they are merged. Only passing an array to `validate()` replaces them. |
| An agent today: `renderIsland()` cannot suppress the `html` effect | False at the client. The browser receives `islandFragments` and no `html`. |
| An agent today: `text-[10px]` is in neither stylesheet | False. It is in both and used in 14 files. |

The pattern is the same every time: **a claim derived by reading, contradicted by one measurement.**
Two of the six were mine.

---

## 7. Open, in the order I would take them

1. 🔴 **`public/img/signature.png`.** One file away from finished.
2. **The 39 unclicked `wire:click` targets on `/mail/inbox`**, and the interactive harness re-run with
   the cap raised.
3. **`⚡link-create`'s `$kinds[$kind]`** — an unknown `kind` 500s the page (§2.5).
4. **`⚡clients.blade.php`'s tab strip** — same unwrapped shape as `⚡client-show`'s, not yet long
   enough to overflow.
5. **`⚡invoice-show::voidInvoice()` has no guard against voiding an invoice with standing payments.**
   Harmless today; a real defect the moment anything derives a balance from the ledger.
6. **`Email::markRead()` throws a `TypeError` on every call** — `save()` returned against a `static`
   return type. `⚡inbox` works around it with `forceFill` and says so.
7. The double-entry build, when the owner wants it.
8. Everything from the previous handover that is still standing: `CustomerReader` returning Eloquent
   models, `has:stickers`, Butler's calendar and branching, the uncursored `/api/v1/customers`, card
   writes and mail sending absent from `/api/v1`, `v23.0` unverified, no permalink for Instagram,
   Threads or Slack, Reddit taking no pictures, `MEDIA_NOT_READY` unproven, Tumblr on the legacy
   endpoint, and the `openCard()` listeners that will need the first policy.

## 8. For the next session

**Open the browser first, every time.** Not at the end, not to confirm — first. Everything in this
document that mattered was found in the first twenty minutes of doing it, and none of it was
reachable any other way.

And keep the discipline that caught the rest: **when a report and a measurement disagree, measure
again.** Four of the six corrections in §6 came from someone insisting on running the thing.
