# Execution prompt

Paste the block below into a fresh session. It is written to be self-contained — the next context
knows nothing about how the current state came about.

Last updated after phase 1 shipped.

---

## The prompt

You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
The front end is built. Phase 1 of the back end is built. Your job is two things, in this order:

1. **Fix the bugs in the Projects/Boards screen.** The owner reports several; reproduce them
   yourself before changing anything.
2. **Build the rest of the back end**, phases 2 to 7 of `project-guaid/spec/05-build-order.md`.

### Read first, in this order

1. `project-guaid/spec/00-overview.md` — what the product is and the one constraint everything
   follows from
2. `project-guaid/spec/01-architecture.md` — runtime, database, queue, cache, module rules
3. `project-guaid/spec/02-data-model.md` — the Core spine and how modules relate
4. `project-guaid/spec/03-accounting.md` — money. Read this twice before writing any of phase 3
5. `project-guaid/spec/04-frontend.md` — what to adopt from 2026 browser capability
6. `project-guaid/spec/05-build-order.md` — the phases and their acceptance criteria
7. `project-guaid/DECISIONS.md` — judgement calls already made, and where the spec was wrong
8. `docs/frontend-conventions.md` — binding style guide for any Blade you touch

The specs are the product of research already done and arguments already had. Follow them.

### Run this autonomously

**Do not ask questions. Decide, and keep going.** Work through the board fixes and then all
remaining phases without stopping for approval.

- Where the spec is ambiguous, choose what best fits the principles in `00-overview.md` —
  correctness over cleverness, nothing that requires a VPS, no floats in money — record the choice
  in `project-guaid/DECISIONS.md` with one line of reasoning, and carry on.
- Where the spec is wrong, fix the spec file, note it in `DECISIONS.md`, build the corrected thing.
- If an acceptance criterion cannot be met as written, solve it. Only if genuinely impossible,
  record why, build the closest thing that works, and move on.
- Never leave a phase half-finished to ask something. There is nobody to answer.

Stop before the end only if something would destroy existing data, or you need a credential that
is not on this machine.

### Start here: the Boards bugs

`/projects` — `Modules/Project/resources/views/components/⚡boards.blade.php` and its two nested
components, `⚡card-detail.blade.php` and `⚡board-templates.blade.php`.

The report is only "boards has several bugs", so **reproduce before you fix**. Open the page, work
through every interaction, and write down what is actually wrong. Things known to be true that may
or may not be what the owner means:

- Nothing persists. `moveCard`, `addCard`, `addList` and the rest have empty bodies and say so
  through a toast. A card dragged to another list returns on refresh. That is by design until
  phase 2, but check the *in-memory* behaviour is right before building persistence on top of it.
- Drag and drop only started reaching the browser recently — the init script was being discarded.
  Verify it actually works now rather than assuming.
- The filter panel, the inline add-card and add-list forms, the list ⋯ menus and the board picker
  are all driven from Livewire state rather than KTUI. Check they open, close and clear correctly,
  including when two are open at once.

Fix what you find, then build phase 2 on it.

### Environment

| | |
| --- | --- |
| PHP | `C:\Users\morph\PHP\8.3\php.exe` (also on PATH) — 8.3.33, OPcache on |
| Composer | `C:\Users\morph\PHP\8.3\composer.phar`, wrapper `composer.bat` |
| Node / npm | v24.18.0 / 11.16.0 — CSS build only, never on the server |
| Database | SQLite at `database/database.sqlite` |
| Shell | PowerShell 7 on Windows. Not bash — `head`, `wc`, `[ -f x ]` are parse errors |
| GitHub token | field **New** in `C:\Users\morph\Projects\Data\Drain4Brighton\git.txt`. The one in `Data\Visa\github.txt` is revoked |
| Repo | https://github.com/morpheusadam/kargah — push to `main` |
| Dev login | `admin@admin.com` / `admin` |

`php artisan serve` takes about 8 seconds to start listening. Only run one at a time.

### What already exists

- **Laravel 13.23, Livewire 4.3, nwidart/laravel-modules 13, PHP 8.3.33**
- **74 tests pass.** `SmokeTest`, `ShellTest`, `SidebarTest`, `ToastTest`, `CoreSpineTest`
- **43 Livewire page routes**, five feature modules, all pages rendering
- **Phase 1 is done.** `Modules/Core` holds `companies`, `customers`, `links`, `searchables` and
  `activity_log`, with the `CustomerReader` and `Linker` contracts, an enforced morph map, and the
  `Linkable` trait. Fourteen tests cover it.
- **Everything else is still a fixture.** Every page returns literal arrays from `with()`. No other
  module has a model or a migration.
- Packages installed and unused so far: `brick/money`, `laravel/scout`, `spatie/laravel-activitylog`

### Things that will cost you a day if you assume otherwise

**Livewire 4 is not Livewire 3.**
- Components are single-file: `resources/views/components/⚡name.blade.php`, holding
  `new class extends Component { … };` then the template. There is no `app/Livewire` class.
- A dot means a subdirectory: `pages::settings.profile` lives at
  `resources/views/pages/settings/⚡profile.blade.php`.
- Routes use `Route::livewire('/path', 'namespace::name')`.
- Namespaces are registered in `config/livewire.php`, one per module. A new module needs its entry.

**`@push('scripts')` does not work from inside a component.** Livewire carries neither a pushed
stack nor `@assets` through to the layout, so the block is silently discarded. This is why the
board's drag and drop never initialised for days. Use **`@script … @endscript`** for JavaScript. For
CSS, write a real file under `public/css/` and link it from the layout with a cache-busting
`?v={{ filemtime(...) }}` — a static file with no version on it will be served stale forever.

**KTUI fights you over the theme.** `ktui.min.js` reads its own `kt-theme` key and, in `_bindMode`,
strips both `dark` and `light` off `<html>` before applying what it found. The app layout keeps
`kargah.theme` and `kt-theme` in step so they agree. The guest layout does not load the bundle at
all. Do not reintroduce `data-kt-toggle="html"` anywhere.

**Panels inside a Livewire component must be driven from component state, not KTUI.** KTUI toggles
an `open` class in the DOM and Livewire's morph strips it on the next render. For a KTUI-driven
dropdown outside a component the wrapper carries no class and the panel is `kt-dropdown-menu` with
`data-kt-dropdown-menu="true"`.

**Migrations.** Core's carry `2026_01_01_*` timestamps, which is what actually guarantees they run
before anything referencing them. `module:migrate` is interactive and aborts unattended — use
`php artisan migrate --force`, or `php artisan module:migrate --all --force`.

**Icons.** Only names present in `public/assets/vendors/keenicons/styles.bundle.css` render;
anything else is a blank glyph with no error. `ki-send`, `ki-link`, `ki-file`, `ki-global`,
`ki-globe`, `ki-profile-user` and `ki-branch` do **not** exist.

**Styling.** `/assets/css/styles.css` is the prebuilt theme, read-only. `/assets/css/kargah.css`
holds what it lacks — arbitrary values, `line-clamp`, and the success/warning/info tokens Metronic
does not define. If a class has no effect, regenerate it:

```powershell
cd 'C:\Users\morph\Projects\admin-panel-ui\veltrix-tailwind-html-starter-kit'
npx @tailwindcss/cli -i ./src/css/kargah.css -o 'C:\Users\morph\Projects\kargah\public\assets\css\kargah.css' --minify
```

Never build a class name by concatenation — the scanner cannot see `"text-{$tone}"`.

**Brand.** `<x-brand-mark />` is the only place the logo is defined. All icons are generated from
`public/img/kargah-logo.webp` by `python tools/make-icons.py`.

**`public/assets` is gitignored** — the theme is commercially licensed. `public/css`, `public/img`
and `public/vendor` are committed.

### Toasts

Every user-facing action reports through `Modules\Core\Concerns\InteractsWithToasts`:

```php
$this->toastSuccess('Card moved', 'Now first in Review.');
$this->toastError('Could not move', 'That list was archived.');
$this->flashToast('success', 'Signed in');   // survives a redirect
```

**As you implement each action, replace its `toastInfo('Not connected yet', …)` with what actually
happened.** Roughly sixty of those exist and they are your checklist — grep for
`Not connected yet` to see exactly what phases 2 to 7 owe the user. Never leave a success toast on
a method that does nothing; in Accounting that is a lie about money.

### How to work

**Use subagents.** One per module or phase, each confined to its own module directory. Do not let
two agents edit the same file. `tests/Feature/SmokeTest.php` is the one shared file they may touch,
and only the `pageProvider` array.

**One phase at a time.** Do not start N+1 until every acceptance criterion in N passes.

**Replace fixtures, do not add to them.** A phase is not finished while its pages still return
literal arrays from `with()`.

**Test what matters.** Every job needs a test that runs it twice and asserts the second run changes
nothing — that is what makes cron safe. Every money path needs an assertion that no float appears
in it. Every new route goes in `SmokeTest`.

**Verify before claiming.** Run `php artisan test` and paste the decisive line. If you could not
verify something, say so plainly rather than hedging.

**Commit per phase**, with a message that says what changed and why rather than listing files. Push
to `main`, rebasing if the remote has moved. End messages with:

```
Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```

### Performance

Pages render in 75–190 ms warm and the suite runs in about 12 seconds. Do not regress past 200 ms
per page. If it slows down, check what you added to the layout before blaming the framework —
loading ApexCharts and FullCalendar globally once cost 854 KB on every page and made the dev server
time out.

### Start

Reproduce and fix the Boards bugs. Then build phase 2. Report what you built, what you verified,
and what you found that the spec got wrong.
