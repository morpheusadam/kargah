# Decisions

Judgement calls made while building, where the spec was silent or turned out to be wrong. One line
of reasoning each, so nobody has to reconstruct it later.

---

## Phase 1 — Core

**`companies.default_currency` is a plain string, not a foreign key.**
The spec's data model implies a currency table, but that table belongs to Accounting, and Core may
not depend on a feature module. Accounting validates the value when it reads it. This is the first
concrete case of the "foreign keys point at Core, never sideways" rule applying to Core itself.

**Core's migrations are timestamped `2026_01_01_*`, and that — not module priority — is what
guarantees ordering.**
The spec warns that `php artisan migrate` ignores module priority. That is true, but the practical
consequence is milder than the spec implies: nwidart registers module migration paths with the
framework, so plain `migrate` runs them in filename order alongside the app's own. Giving Core the
earliest timestamps makes the correct order hold under *both* commands. Priority is set as well
(Core `0`, features `10`), as belt and braces.

**`module:migrate` is interactive and cannot be used unattended.**
It prompts for module selection and aborts on a non-interactive shell. Use
`php artisan module:migrate --all --force`, or plain `php artisan migrate --force` given the
timestamp rule above. The deploy script must not call bare `module:migrate`.

**The morph map is enforced from `booted`, not from `boot`.**
Each module registers its own aliases in its own `boot()`. Core has priority 0, so Core boots
first; calling `requireMorphMap()` there would fire before the feature modules had registered
anything. Deferring to `$this->app->booted()` lets every module have its turn, and still fails
loudly at the first polymorphic write.

**Links are undirected on read, directed on write.**
A link row records which model created it, but `linked()`, `isLinkedTo()` and `unlinkFrom()` all
look at both ends. Asking a card what it is linked to should return an invoice regardless of which
side created the row — anything else pushes an arbitrary ordering decision onto every caller.

**`linkTo()` is idempotent.**
It is `updateOrCreate` on the full pair plus relation, matching the unique index. Linking the same
two things twice updates the metadata rather than failing or duplicating. Jobs that run twice —
which is the whole cron design — must not produce two links.

**`spatie/laravel-activitylog` resolved to 4.12.3, not v5.**
Research reported v5 as current. Composer resolved 4.12.3 against this Laravel version. The schema
and API used here are the same in both; no change needed, but the version in the spec was wrong.

**The full-text index is created per driver, and SQLite gets none.**
MySQL/MariaDB get `FULLTEXT`, PostgreSQL gets a GIN index over `to_tsvector`, SQLite gets neither
because it has no equivalent in the default build. Scout's database engine falls back to `LIKE`
there, which is correct for development and irrelevant in production.

---

## Boards — bugs found before phase 2

Nine defects, each reproduced by a failing test in `tests/Feature/BoardsTest.php` before anything
was changed. The interesting ones and why the fix is what it is:

**A drop reached the browser and stopped there.**
`Sortable`'s `onEnd` called `Livewire.dispatch('card-moved', …)`, and no component anywhere
declared `#[On('card-moved')]`. `moveCard()` was unreachable — the drag animated, the card snapped
back, and nothing was logged because nothing errored. Fixed by calling `$wire.moveCard(…)`
directly from `@script`, which is already scoped to this component. A global event bus for a call
between a component and its own script is indirection that buys nothing and, here, cost the
feature. `moveCard`'s signature is untouched.

**The re-mount guard was an attribute, and the morph eats attributes.**
The initialiser skipped a list if `el.dataset.sortableMounted` was set. Livewire's patcher removes
any attribute the incoming HTML does not carry, so the flag cleared itself on every single render
and a second `Sortable` bound to the same element — then a third. One drop would have written N
times once persistence existed. `Sortable.get(el)` is the guard now: the library is the only thing
that reliably knows what it already owns.

**`morph.updated` fires per element; `morphed` fires per component.**
The old hook re-ran the initialiser once for every DOM node the patcher touched. Switched to
`morphed`, and the initialiser bails when `$wire.$el` is no longer connected, which is how a
closure left behind by a `wire:navigate` stops touching the page that replaced it.

**Sortable had no `draggable`, so the empty-state paragraph was a card.**
"Nothing in this list yet" could be picked up and dropped into another list. Constrained to
`[data-card-id]`.

**A drag ended in an open card drawer.**
The browser fires a click on the element a drag finished on, and the card carries `wire:click`.
Suppressed with a capture-phase listener for 300 ms after `onEnd`, registered once on `window` so
re-initialising the component does not stack listeners.

**Panels were only pairwise exclusive.**
`toggleFilterPanel` and `toggleBoardPicker` closed each other; neither closed a list ⋯ menu, and
`toggleListMenu` closed nothing at all. Three panels could be open at once. There is now one
`closeOverlays()` that every opener calls first, so the rule is stated once instead of being
re-derived at each call site.

**Cascading closes each announced themselves.**
Switching board called `cancelAddCard()` and `cancelAddList()`, which toast, and then toasted
again — two or three notifications for one click. Closing on the way to opening something else is
silent now. An explicit close still reports.

**Filters survived a board switch.**
A filter set on one board's labels and people usually matches nothing on the next, so switching
board landed on an empty board with no visible cause. Switching now starts clean.

**Nothing validated `?board=`.**
`#[Url]` is whatever the address bar says. An unknown key rendered an empty board headed "Board"
with no way back. `mount()` resolves it against the real list and falls back to the first.

**Whitespace counted as a filter.**
`' '` made the filter badge read 1 and enabled "Clear all" while filtering nothing, because the
count tested the raw property and the filter tested the trimmed one. Both go through
`searchTerm()` now.

**Click-away is a rendered backdrop, not a listener.**
An `x-on:click.outside` added by the morph is not a listener the browser has bound, and the
conventions doc already forbids driving a panel from anything but component state. So the
backdrop is `@if`-rendered at `z-10` under the panels' `z-20`, carrying `wire:click="dismissPanels"`.

**`docs/frontend-conventions.md` was wrong about `@push('scripts')`.**
It instructed putting component JavaScript in `@push('scripts')`. Livewire 4 carries neither a
pushed stack nor `@assets` from a component through to the layout, and discards both without
warning — which is the actual reason the board's drag and drop was dead. The doc now says
`@script … @endscript`, and `BoardsTest` asserts the page really ships the initialiser so the
failure mode cannot come back silently.
