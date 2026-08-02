# Execution prompt

Paste the block below into a fresh session. It is written to be self-contained — the next context
knows nothing about how the current state came about.

---

## The prompt

You are continuing work on **Kargah**, a self-hosted freelance workspace at
`C:\Users\morph\Projects\kargah`. The front end is finished. Your job is to build the entire back
end, following the specification in `project-guaid/spec/`, from phase 1 to phase 7.

### Read first, in this order

1. `project-guaid/spec/00-overview.md` — what the product is and the one constraint everything
   follows from
2. `project-guaid/spec/01-architecture.md` — runtime, database, queue, cache, module rules
3. `project-guaid/spec/02-data-model.md` — the Core spine and how modules relate
4. `project-guaid/spec/03-accounting.md` — money. Read this twice before writing any of phase 3
5. `project-guaid/spec/04-frontend.md` — what to adopt from 2026 browser capability
6. `project-guaid/spec/05-build-order.md` — the seven phases and their acceptance criteria
7. `docs/frontend-conventions.md` — binding style guide for any Blade you touch

These specs are the product of research and decisions already argued through. Follow them.

### Run this autonomously

**Do not ask me anything. Decide, and keep going.** Work through all seven phases end to end
without stopping for approval between them.

- Where the spec is ambiguous, choose the option most consistent with the principles in
  `00-overview.md` — correctness over cleverness, nothing that requires a VPS, no floats in money —
  and write the choice down in `project-guaid/DECISIONS.md` with one line saying why. Then carry on.
- Where you find the spec is wrong, fix the spec file, note it in `DECISIONS.md`, and build the
  corrected version. Do not stop to ask permission.
- If an acceptance criterion cannot be met as written, solve it. Only if it is genuinely
  impossible, record why in `DECISIONS.md`, build the closest thing that is possible, and move on.
- Never leave a phase half-finished to ask a question. There is nobody to answer.

The only reasons to stop before phase 7 is complete: something would destroy existing data, or you
need a credential that is not on this machine. In either case say exactly what you need and what
you did instead in the meantime.

### Environment

| | |
| --- | --- |
| PHP | `C:\Users\morph\PHP\8.3\php.exe` (also on PATH as `php`) — 8.3.33, OPcache enabled |
| Composer | `C:\Users\morph\PHP\8.3\composer.phar`, wrapper at `composer.bat` |
| Node / npm | v24.18.0 / 11.16.0 — used only for the CSS build, never on the server |
| Database | SQLite at `database/database.sqlite` for development |
| Shell | PowerShell 7 on Windows. Not bash. `head`, `wc`, `[ -f x ]` are parse errors |
| GitHub token | field **New** in `C:\Users\morph\Projects\Data\Drain4Brighton\git.txt`. The token in `Data\Visa\github.txt` is revoked — ignore it |
| Repo | https://github.com/morpheusadam/kargah — push to `main` |

Start the dev server with `php artisan serve`. It takes about 8 seconds to begin listening.

### Stack facts that will trip you if you assume otherwise

**Laravel 13.23, Livewire 4.3, nwidart/laravel-modules 13.** Livewire 4 is not Livewire 3.

- Components are **single-file**: `resources/views/components/⚡name.blade.php`, holding
  `new class extends Component { … };` then the template. There is no `app/Livewire` class.
- A dot in a component name means a **subdirectory**: `pages::settings.profile` lives at
  `resources/views/pages/settings/⚡profile.blade.php`. Getting this wrong gives
  `ComponentNotFoundException`.
- Routes use `Route::livewire('/path', 'namespace::name')`, never `Route::get` with a class.
- Namespaces are registered in `config/livewire.php` — `pages`, `layouts`, and one per module
  (`project`, `accounting`, `mailbox`, `data`, `social`). A new module needs its own entry.
- Page layout defaults to `layouts::app`. Guest pages carry `#[Layout('layouts::guest')]`.

**Migrations: run `php artisan module:migrate`. Never `php artisan migrate:fresh`.** The global
command ignores module priority, falls back to filename order, and will fail on Core's foreign
keys. Core is `"priority": 0` in its `module.json`; every feature module is `10`.

**Theme.** Metronic 9, dark by default. Two stylesheets: `/assets/css/styles.css` is the prebuilt
theme and is read-only; `/assets/css/kargah.css` holds everything the theme is missing — arbitrary
values, `line-clamp`, and the success/warning/info colour tokens Metronic does not define. If a
Tailwind class has no effect, regenerate the second sheet:

```powershell
cd 'C:\Users\morph\Projects\admin-panel-ui\veltrix-tailwind-html-starter-kit'
npx @tailwindcss/cli -i ./src/css/kargah.css -o 'C:\Users\morph\Projects\kargah\public\assets\css\kargah.css' --minify
```

Never build a class name by concatenation — the scanner cannot see `"text-{$tone}"`. Put whole
class strings in a PHP map.

**Icons.** Only names present in `public/assets/vendors/keenicons/styles.bundle.css` render;
anything else is a blank glyph with no error. `ki-send`, `ki-link`, `ki-file`, `ki-global`,
`ki-globe`, `ki-profile-user` and `ki-branch` do **not** exist. Check before using one.

**Dropdowns and modals inside a Livewire component** must be driven from component state, not from
KTUI. KTUI toggles an `open` class in the DOM and Livewire's morph strips it on the next render.
For a KTUI-driven panel outside a component, the wrapper carries no class and the panel is
`kt-dropdown-menu` with `data-kt-dropdown-menu="true"`.

**`public/assets` is gitignored** — the theme is commercially licensed and must not be committed.
`public/vendor/sortablejs` is committed and is MIT.

### How to work

**Use subagents.** One per module or phase, working only inside its own module directory. Give each
the spec sections it needs and the conventions file. Do not let two agents edit the same file.
`tests/Feature/SmokeTest.php` is the one shared file agents may touch, and only the `pageProvider`
array.

**One phase at a time.** Do not start phase N+1 until every acceptance criterion in phase N passes.
The criteria in `05-build-order.md` are written to be checkable by a test, not by opinion.

**Replace fixtures, do not add to them.** Every page currently renders a static array from
`with(): array`. A phase is not finished while its pages still do. Grep for literal arrays in
`with()` before calling anything done.

**Test everything that touches money or runs on a schedule.** Every job needs a test that runs it
twice and asserts the second run changes nothing — that is what makes cron safe. Every money path
needs an assertion that no float appears in it.

**Verify before claiming.** Run `php artisan test` and paste the decisive line. If you could not
verify something, say so plainly rather than hedging.

**Commit per phase**, with a message that says what changed and why — not a list of files. Push to
`main`. End commit messages with:

```
Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```

### Current state

- 43 Livewire page routes, 72 Blade files, 54 tests passing
- Pages render in 75–190 ms warm. Do not regress past 200 ms
- Zero models, zero migrations, zero queries exist. Everything is a fixture
- Modules present: Project, Accounting, Mailbox, Data, Social. **Core does not exist yet — phase 1
  creates it**

### Start

Read the specs, then build phase 1. Report what you built, what you verified, and what you found
that the spec got wrong.
