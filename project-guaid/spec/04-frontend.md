# 04 — Front end: what to adopt from 2026

The front end is finished and works. This is a list of what current browser and Livewire capability
would materially improve, ordered by payoff per hour, with the things worth skipping said plainly
so nobody re-litigates them later.

---

## Adopt now

### Livewire 4 islands — the single highest-leverage change

An island is a region inside a component that re-renders on its own. An action inside it sends back
only that region's markup instead of the whole component.

The inbox is the exact shape islands were built for: a long message list beside a detail pane.
Today, selecting a message re-renders and re-sends the entire list as well. With the list in one
island and the reading pane in another, selecting a message sends back only the pane.

Apply to, in order:

| Page | Islands |
| --- | --- |
| Mailbox inbox | message list · reading pane |
| Project boards | the board canvas (one island, see below) |
| Accounting invoices | filter tabs · table body |
| Dashboard | each widget, so a slow query never blocks first paint |

### One island per `@island` in the source — not per loop iteration

**An island inside a `@foreach` cannot be targeted.** This was measured against
Livewire 4.3, not assumed, and it is why the board's islands are the canvas and the
filter panel rather than one per list column.

An island's identity is its **token**, and the token is assigned at compile time from
the file hash plus the ordinal of the `@island` directive *in the source file*
(`Compiler/IslandCompiler.php:91-92`). A directive inside a loop is one directive, so
every iteration renders with the same token. On the client, `renderIsland()` finds the
fragment to morph by `type` and `token` only — not by name — and `findFragment()` stops
at the first match (`dist/livewire.js:14933-14936`, `6637-6656`). Ask for the seventh
column and the seventh column's HTML is morphed into the first one.

`renderSlot()` twenty lines below it matches on `name` *and* `token`, so this reads as an
oversight rather than a design decision. Revisit when it is fixed upstream.

Two consequences worth carrying to every other page in this table:

- Give each island its own `@island` block in the source. A fixed set of regions —
  list and pane, filters and body — is fine. A variable-length list of them is not.
- **An island nobody names does not update.** After an action that does not call
  `$this->renderIsland(…)`, the fragment is emitted with `mode=skip` and the morph
  engine skips the entire range between its markers, so the DOM keeps whatever it had.
  Any action that changes what an island shows must name it, or the change is computed
  server-side, sent, and silently thrown away. `always: true` opts out of the skip and
  therefore out of the benefit.

Islands also take `lazy: true`, which defers a region until after first paint — right for dashboard
widgets whose queries are slow. Multiple lazy regions can be bundled into one request rather than
one each, which matters on shared hosting where concurrent connections are scarce.

### View transitions

Livewire 4's `wire:transition` runs on the native View Transitions API, and `wire:navigate` takes a
`.transition` modifier. Same-document transitions are supported in every current browser; anything
older simply gets no animation.

Cost: a modifier on links and a few `::view-transition-*` rules. Do it.

### Dark mode done properly

Dark is already the default and applied before first paint. Two things remain:

- Add `color-scheme: dark light` to `:root` so form controls, scrollbars and the browser's own UI
  follow the theme. Without it the native widgets stay light on a dark page.
- Use `light-dark()` for the handful of one-off values that need both. It has been in every
  evergreen browser since 2024.

Design **dark-first**: the base custom properties hold the dark values, and `.light` overrides them.
That is the inverse of the usual arrangement and it matches which theme is now the default.

### content-visibility on long tables

```css
.kt-table tbody tr { content-visibility: auto; contain-intrinsic-size: auto 52px; }
```

Skips layout and paint for off-screen rows. No library, no change to how rows are rendered, and it
composes with Livewire's DOM morphing — which is exactly what JavaScript virtualisers do not.

### Cursor pagination on the tables that will actually get long

`cursorPaginate()` instead of `paginate()` for emails, activities and ledger entries. Offset
pagination scans and discards every skipped row; at page 400 of an inbox that is the whole table.
The cost is losing "jump to page 12", which nobody does in an inbox.

Leave offset pagination on short lists — clients, providers, boards — where numbered pages are more
useful than a deep-scan cost that will never be paid.

### Platform features that delete JavaScript

| Feature | Support | Replaces |
| --- | --- | --- |
| `:has()` | universal | class-toggling JS for form and row state |
| `field-sizing: content` | shipped mid-2026 | auto-growing textarea scripts |
| `popover` + CSS anchor positioning | Chrome 125+, Firefox 132+, Safari 18.2+ | Popper/Tippy for dropdowns and tooltips |
| `<dialog>` | baseline | custom modal focus-trap code |

`popover` and `<dialog>` bring correct focus trapping, Escape handling and light-dismiss for free —
behaviour that is tedious to write and easy to get subtly wrong. Use them for **new** panels; do not
rewrite working Metronic components for their own sake.

One caveat that already bit this project: KTUI toggles an `open` class in the DOM, and Livewire's
morph strips it on the next render. Any panel inside a Livewire component must be driven from
component state, not from KTUI. That rule is in `docs/frontend-conventions.md` and it stands.

### Keyboard fallback for drag and drop

The board is drag-only. A keyboard user cannot move a card. Add "move up / move down / move to
list" to the card menu — this is cheap, and it is the difference between an accessible product and
one that quietly excludes people.

SortableJS stays. The native HTML drag-and-drop API is still verbose and still has touch gaps in
2026; there is no replacement worth switching for.

### Check the bfcache headers

Confirm no `Cache-Control: no-store` is being sent on authenticated pages that do not need it. It
silently disables the browser's back/forward cache, which is a free instant back button. Costs
nothing to check.

## Adopt later

**A real command palette.** One exists and navigates; it does not yet search records. Making it
find an invoice by number or a customer by name is worth doing once `searchables` exists — the two
are the same feature. The mainstream libraries (`cmdk`, `kbar`) are React-first and would drag in a
build step, so this stays hand-rolled on `<dialog>`.

**Global keyboard shortcuts.** Cheap once the palette has an action registry, since it is the same
list of actions under a different trigger.

**Optimistic UI on high-frequency actions.** Starring a message, ticking a checklist item, toggling
a switch — paint the new state immediately, reconcile when the server answers. Worth a focused pass
on the three or four actions that happen most, not a blanket policy.

## Skip

**Speculation Rules (prefetch/prerender).** The published wins — Ray-Ban's LCP 4.69s → 2.66s — come
from high-traffic anonymous e-commerce. Kargah is a low-concurrency authenticated admin panel on
shared hosting where PHP workers are the scarce resource. Speculatively rendering pages on hover
would spend the exact resource that is scarce to save time `wire:navigate` already saves. It is
also Chromium-only. WordPress excludes `wp-admin` from its own speculation rules for the same
reason.

**JavaScript virtual scrolling.** Virtualisers assume they own the render loop. Livewire owns the
DOM through morphing. `content-visibility` solves the same problem with one CSS line and no
conflict.

**React-based palette libraries.** They would require a build step the project deliberately does
not have.

## Order of work

1. Islands on the inbox, then boards, then the dashboard — biggest gain, no new dependency.
2. `color-scheme` and the dark-first custom-property pass.
3. `content-visibility` on tables; cursor pagination on emails and activities.
4. `wire:navigate.transition`.
5. Keyboard fallback for the board.
6. `:has()` and `field-sizing` cleanups as files are touched — not as a sweep of their own.
