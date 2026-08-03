# Front-end conventions

Read this before writing any Blade in this project. Every page in Kargah follows it, so a new
page written to these rules looks and behaves like the ones already shipped.

## Stack

- **Laravel 13.23**, **Livewire 4.3**, `nwidart/laravel-modules` 13
- PHP CLI on this machine: `C:\Users\morph\PHP\8.3\php.exe`
- Database: SQLite at `database/database.sqlite` (dev)
- Theme: Metronic 9 Tailwind, assets already served from `/assets/…`

## Component format — Livewire 4 single-file components

Livewire 4 does **not** use `app/Livewire` classes here. A component is one file that holds both
the PHP class and the template, and its filename starts with a lightning bolt:

```
Modules/<Module>/resources/views/components/⚡<name>.blade.php
```

Shape of the file:

```blade
<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Page name — Kargah')]
class extends Component
{
    public string $search = '';

    #[Url]
    public string $tab = 'all';

    /** Data for the template. Static fixtures during the front-end phase. */
    public function with(): array
    {
        return ['rows' => []];
    }

    public function doThing(int $id): void
    {
        // Keep the signature the backend will implement; leave the body empty for now.
    }
};

?>

<div class="flex flex-col gap-5">
    …
</div>
```

Rules:

- The template must have **exactly one root element**.
- Use `with()` for view data, not `render()`.
- `#[Title]` on every page component.
- `#[Url]` on any property that should survive a refresh or be shareable.
- Action methods that the backend will later implement should already exist with their final
  signature and an empty body plus a one-line comment. Do not invent fake persistence.

## Namespaces and routing

Each module owns a Livewire namespace, registered in `config/livewire.php`:

| Module | Namespace | Directory |
| --- | --- | --- |
| Project | `project::` | `Modules/Project/resources/views/components` |
| Accounting | `accounting::` | `Modules/Accounting/resources/views/components` |
| Mailbox | `mailbox::` | `Modules/Mailbox/resources/views/components` |
| Data | `data::` | `Modules/Data/resources/views/components` |
| Social | `social::` | `Modules/Social/resources/views/components` |

Routes live in the module's own `routes/web.php`:

```php
Route::middleware('auth')->prefix('projects')->name('projects.')->group(function () {
    Route::livewire('/', 'project::boards')->name('boards');
    Route::livewire('/{board}/settings', 'project::board-settings')->name('board-settings');
});
```

Use `Route::livewire()`, never `Route::get()` with a class.

Nested components render as `<livewire:project::card-detail :card="$card" />`.

## Layout

Authenticated pages use `layouts::app` automatically (set in `config/livewire.php`). Do not add a
`#[Layout]` attribute unless the page needs the guest shell.

The layout provides the sidebar, header, footer and `@stack('scripts')`. Page components render
into `{{ $slot }}` inside a `kt-container-fixed`.

## Markup vocabulary

Use these classes. Do not invent new design tokens or write raw colour utilities where a token
exists.

| Purpose | Classes |
| --- | --- |
| Card | `kt-card`, `kt-card-header`, `kt-card-title`, `kt-card-content`, `kt-card-footer`, `kt-card-table` |
| Buttons | `kt-btn` + `kt-btn-primary` / `kt-btn-outline` / `kt-btn-ghost` / `kt-btn-icon` / `kt-btn-sm` |
| Inputs | `kt-input`, `kt-select`, `kt-textarea`, `kt-checkbox`, `kt-switch`, `kt-form-label` |
| Table | `kt-table` inside `kt-scrollable-x-auto` |
| Badge | `kt-badge` + `kt-badge-sm` / `-primary` / `-success` / `-warning` / `-destructive` / `-outline` / `-info` |
| Dropdown | see below — the wrapper carries **no** class |
| Modal | `kt-modal`, `kt-modal-content`, `kt-modal-header`, `kt-modal-body`, `kt-modal-footer` — see below |
| Scroll area | `kt-scrollable-y` / `kt-scrollable-x` |
| Icons | `<i class="ki-filled ki-<name>"></i>` — keenicons only, no emoji, no SVG |
| Text tone | `text-mono` (primary text), `text-secondary-foreground`, `text-muted-foreground` |
| Surfaces | `bg-background`, `bg-muted`, `bg-accent/60`, `border-border` |

Semantic colours: `primary`, `secondary`, `muted`, `accent`, `destructive`, `mono` come from
Metronic. `success`, `warning` and `info` do **not** — Metronic has no such tokens. Kargah defines
them itself (see *Stylesheets* below), so they behave like the rest but only because we generate
them. Use all of them via `text-*`, `bg-*/10`, `border-*/30`.

### Dropdowns

The theme's CSS calls the *panel* `.kt-dropdown-menu`, and KTUI's JavaScript looks for
`data-kt-dropdown-menu`. The wrapper takes the behaviour attributes and no class of its own:

```blade
<div data-kt-dropdown="true" data-kt-dropdown-trigger="click" data-kt-dropdown-placement="bottom-end">
    <button class="kt-btn kt-btn-icon kt-btn-ghost" data-kt-dropdown-toggle="true" aria-label="Options">
        <i class="ki-filled ki-dots-vertical"></i>
    </button>
    <div class="kt-dropdown-menu w-[220px]" data-kt-dropdown-menu="true">…</div>
</div>
```

Putting `kt-dropdown` on the wrapper hides it: the bundle contains
`.kt-dropdown:not(.open) { display: none }`.

### Dropdowns and modals inside a Livewire component

KTUI toggles an `open` class directly in the DOM. Livewire's morph does not know about it and
strips it on the next render, so a KTUI-driven panel snaps shut whenever the component updates.
When the panel lives inside a component that re-renders, drive it from Livewire state instead and
omit the KTUI attributes entirely:

```blade
<div class="kt-dropdown absolute z-20 mt-1 w-[240px] {{ $filterOpen ? 'open' : '' }}">…</div>
<div class="kt-modal {{ $showForm ? 'open' : '' }}">…</div>
```

Here `.kt-dropdown` **is** correct, because you are using the bundle's
`:not(.open)` rule rather than KTUI's controller. Pick one mechanism per panel; never both.

Page heading pattern, used at the top of every page:

```blade
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold text-mono">Title</h1>
        <p class="text-sm text-secondary-foreground mt-1">One line saying what this page is for.</p>
    </div>
    <button class="kt-btn kt-btn-primary gap-2"><i class="ki-filled ki-plus"></i> Primary action</button>
</div>
```

## Required states

Every list or collection view must handle all three:

1. **Populated** — the normal table/grid.
2. **Empty** — centred icon + one sentence + the primary action. Use `@forelse … @empty`.
3. **Loading** — `wire:loading` on anything that triggers a server round-trip.

```blade
<button wire:click="save" wire:loading.attr="disabled">
    <span wire:loading.remove wire:target="save">Save</span>
    <span wire:loading wire:target="save"><i class="ki-filled ki-loading animate-spin"></i> Saving…</span>
</button>
```

## Forms

- Bind with `wire:model` (add `.live.debounce.300ms` for search boxes only).
- Validate with the `#[Validate]` attribute on the property.
- Show errors under the field: `@error('field')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror`.
- Mark invalid inputs with `@error('field') border-destructive @enderror`.

## JavaScript

Only when Blade and Livewire genuinely cannot do it (drag and drop, charts, editors).

**`@push('scripts')` does not work inside a Livewire component.** Livewire carries neither a
pushed stack nor `@assets` through to the layout, and discards both silently — no error, no
warning, no script. The board's drag and drop was dead for days because of exactly this. Use
`@script … @endscript`, inside the single root element:

```blade
@script
<script>
(function () {
    function mount() {
        // `$wire.$el` is this component's root. A closure left behind by a
        // wire:navigate must not touch the page that replaced it.
        if (! $wire.$el || ! $wire.$el.isConnected) return;
        /* … */
    }

    Livewire.hook('morphed', mount);   // once per component, not per element
    mount();
})();
</script>
@endscript
```

Three things that have each cost a day:

- **Never guard a mount with a `data-*` attribute.** Livewire's morph removes any attribute the
  incoming HTML does not carry, so the flag clears itself on every render and you get a second
  instance bound to the same element. Ask the library: `Sortable.get(el)`, `chart.destroy()`.
- **`morph.updated` fires once per DOM node touched; `morphed` fires once per component.** The
  first is almost never what you want.
- **Talk to your own component with `$wire.method()`, not `Livewire.dispatch()`.** A global event
  needs a `#[On]` listener somewhere, and when nobody declares one the call vanishes without an
  error.

`@push('scripts')` remains correct in a **layout or a plain Blade view** — just not in a component.

Globals the layout loads on **every** page: `Sortable`, `KTMenu`, `KTDrawer`, `KTDropdown`,
`KTModal`, jQuery. That is the whole list, and it should stay short — the layout is the one file
whose weight every page pays for.

Anything heavy and single-purpose is loaded by the page that needs it, from `@push('scripts')`,
with the init guarded:

| Library | Size | Path |
| --- | --- | --- |
| ApexCharts | 563 KB | `/assets/vendors/apexcharts/apexcharts.min.js` (+ its CSS) |
| FullCalendar | 277 KB | `/assets/vendors/fullcalendar/index.global.min.js` |
| TinyMCE | large | `/assets/vendors/tinymce/tinymce.min.js` |
| DataTables | — | `/assets/vendors/datatables-net/…` |
| Dropzone | — | `/assets/vendors/dropzone/…` |

Loading ApexCharts and FullCalendar globally once added 854 KB to every page and made the
single-threaded dev server queue requests until they timed out. Do not put them back.

Two caveats that have already cost time:

- **`Sortable` is not exposed by the theme.** It is compiled into `core.bundle.js` as a webpack
  module with no global binding, so `typeof Sortable === 'undefined'` was silently true and drag
  and drop did nothing. Kargah ships it separately from `/vendor/sortablejs/Sortable.min.js`.
- **`FullCalendar` is not in `core.bundle.js`** either; it lives at
  `/assets/vendors/fullcalendar/index.global.min.js`. Both are loaded by `layouts/app.blade.php`.

TinyMCE, Dropzone and DataTables ship in the theme's `vendors/` directory but are **not** loaded
by the layout. Add the tag yourself from `@push('scripts')` and guard the init.

## Content

Placeholder data must be **plausible freelance data** — real-sounding client names, invoice
numbers, subjects. Never `lorem ipsum`, never `Foo`/`Bar`. Values that only the backend can know
render as an em dash `—`, not `0` or `TODO`.

Copy is British-neutral English, sentence case, no exclamation marks. A subtitle says what the
page is *for*, not what it *contains*.

## Accessibility and responsiveness

- Every icon-only button needs `title` or `aria-label`.
- Tables scroll inside `kt-scrollable-x-auto`; the page body never scrolls sideways.
- Grids collapse: `grid-cols-1 md:grid-cols-2 xl:grid-cols-3`.
- Test at 375px, 768px and 1440px.

## Stylesheets

Two sheets, loaded in this order:

1. `/assets/css/styles.css` — the theme's prebuilt bundle. Contains all `kt-*` component styles.
   It is compiled from the template's own demo HTML, so it does **not** contain utilities that
   only Kargah uses. Treat it as read-only.
2. `/assets/css/kargah.css` — everything the first sheet is missing: arbitrary values
   (`w-[290px]`, `min-h-[620px]`), `line-clamp-*`, `whitespace-pre-wrap`, and the
   success/warning/info colour tokens.

If you add a class that turns out to have no effect, sheet 2 needs regenerating:

```bash
cd <template>/veltrix-tailwind-html-starter-kit
npx @tailwindcss/cli -i ./src/css/kargah.css -o <kargah>/public/assets/css/kargah.css --minify
```

That entry scans `kargah/resources/views` and `kargah/Modules`. **Never build a class name by
concatenation** (`"text-{$tone}"`) — the scanner cannot see it. Put whole class strings in a PHP
map instead, exactly as the existing pages do.

## Icons

Only names present in `public/assets/vendors/keenicons/styles.bundle.css` render; anything else is
a blank glyph with no error. The bundle has 566 names and is missing several obvious ones. Check
before using:

```powershell
Select-String -Path public/assets/vendors/keenicons/styles.bundle.css -Pattern 'ki-your-name'
```

Known absences and their replacements:

| Wanted | Use |
| --- | --- |
| `ki-send` | `ki-paper-plane` |
| `ki-link` | `ki-arrow-up-right` |
| `ki-file` | `ki-document` |
| `ki-global`, `ki-globe` | `ki-map` |
| `ki-profile-user` | `ki-profile-circle` |
| `ki-branch` | `ki-tree` |

## Verifying your work

Add each new route to `tests/Feature/SmokeTest.php` (`pageProvider`) and run:

```
C:\Users\morph\PHP\8.3\php.exe artisan test --filter=SmokeTest
```

All tests must pass before the work is considered done. If you add a component but no route
(a nested component), render it from its parent and confirm the parent page still returns 200.

## Boundaries

Stay inside your module directory. Do **not** edit:

- `config/`, `bootstrap/`, `composer.json`
- `resources/views/layouts/`, `resources/views/partials/`
- another module's files

If you need a change in shared code, say so in your final report instead of making it.
