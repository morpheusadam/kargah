# 06 — Trello parity

**Status:** researched 3 August 2026 against Atlassian's own support documentation plus three
2026 third-party reviews. This is an implementation checklist, not a wish list.

What Kargah already has: boards, lists, cards, drag and drop on a fractional order, labels,
checklists with items, comments, due dates, card and board archive, board templates, filtering by
label/member/due, and an activity trail.

Two claims in that sentence were wrong when it was written, and both cost less to correct than to
rediscover:

- **"One assignee per card" is not what the schema says.** `card_members` is a genuine
  many-to-many with `unique(card_id, user_id)`, `Card::members()` is a `BelongsToMany`, and the
  board card front *already renders an avatar stack*. The only thing forcing one is the drawer's
  `updatedAssignee()`, which calls `sync([$id])`. Multiple members is one method, not a feature.
- **"Search" overstates it considerably.** The board filters with `stripos($card->title, $term)`
  and matches the title only — not the description, not comments, not checklists. The command
  palette searches a hard-coded list of route names. There is no cross-module search: `searchables`
  has no writer and no reader, and no model uses Scout's `Searchable` trait.

Everything below is what Trello has that Kargah does not — with the same caveat. Check the code
before building a row; several of them turned out to be half-present.

---

## The one architectural decision to make first — **decided, and done**

**A card used to belong to exactly one list, by foreign key.** Two features on this list break
that: mirror cards (one card shown and edited from several boards) and, more mildly, Table and
Workspace views. So `cards.board_list_id` had to become a `card_placements` join table carrying
its own `position` per placement.

The decision was to **do it**, on the reasoning that it is the difference between a board tool and
a board tool that can show one piece of work in the two places it belongs — one migration and one
pass over `CardService` now, or a rewrite later.

It is built. `card_placements` carries `card_id`, `board_list_id`, `position` and `is_origin`, with
`unique(card_id, board_list_id)`. **Both `cards.board_list_id` and `cards.position` are gone** —
position moved to the placement, because that is the whole point: a mirror has its own place in
its own list. Anything written against either column now throws.

Two consequences worth carrying into every row below:

- A card has exactly **one origin placement** and any number of mirrors. `unmirror()` refuses the
  origin; archiving is what removes a card from its own list.
- Archiving the source **does not** archive the mirrors — they keep rendering the card with an
  archived marker, which is Trello's behaviour and the honest one. You mirrored it somewhere
  because it mattered there; it silently vanishing is worse than it going grey.

---

## What the shared-hosting constraint actually rules out

Almost nothing. Everything here is reachable with Laravel, a database and one cron entry. Three
things need the scheduler rather than a request:

1. Due-date reminders and Butler's due-date commands — a sweep every few minutes.
2. Butler's calendar commands (scheduled recurring actions) — the same sweep.
3. Email notifications, instant or digested — the same sweep, and Kargah already runs
   `queue:work --stop-when-empty` every minute.

Nothing here needs Redis, a websocket, or a daemon. Trello's live multi-cursor collaboration is
the one thing that genuinely does, and it is explicitly out of scope.

---

## Card

| Feature | Notes | Effort |
| --- | --- | --- |
| **Multiple members per card** | The pivot is already many-to-many and the card front already draws an avatar stack. Only the drawer's `updatedAssignee()` forces one, with `sync([$id])`. Change that method and add a multi-picker. | ~2 hours, not a feature |
| **Start date** | Distinct from due date. Both are needed before Timeline view means anything. | trivial |
| **Due-date colour badges** | Grey beyond 24h, yellow inside 24h, red at due, pink overdue, green complete. `dueState()` already returns four of the five — `null`, `done`, `overdue`, `soon`, `later` — because `cards.completed_at` already exists. **`Palette` has six colours and no pink**; extend it first, with whole class strings, never concatenation. | trivial |
| **Mark complete** | A green tick on the due date, independent of which list the card sits in. Suppresses due-date automation once set. **The column and the display logic both exist; nothing writes to `completed_at` and the board never renders the `done` branch.** | ~2 hours |
| **Card cover** | Colour band (half or full) or an image from an attachment. Full cover hides the badges. **Not blocked** — `AttachmentService` shipped in phase 6 and is live. The real prerequisite is *card attachments*, so a cover has an attachment to point at; the colour half needs no file layer at all and can ship first. | moderate |
| **Custom fields** | Five types — checkbox, date, dropdown (≤50 options), number, text. Defined per board, valued per card, type immutable after creation, ≤50 per board. Deleting a definition wipes every value. | moderate — an EAV pair of tables |
| **Card templates** | A card flagged as a template; "create from template" at the bottom of the list. Editing the template does not change cards already made from it. Dates are not copied. | moderate |
| **Card number** | Per-board sequential `#123` plus a short URL slug. Not the database id. | trivial |
| **Linked cards** | Paste a card URL, get a two-way reference. Core's `links` table already does exactly this. | trivial |
| **Mirror cards** | The same card shown and edited from several lists or boards. See the architectural note above. Archiving the source does not archive the mirrors; they show an archived state. | moderate-hard |
| **Voting** | Toggle a vote, show the tally and who voted. | trivial |
| **Emoji reactions on comments** | A `comment_id, user_id, emoji` table. | trivial |
| **@mentions** | In comments and descriptions. Always notifies, regardless of watch state. Needs an autocomplete. | moderate |
| **Markdown descriptions** | Bold, italic, strikethrough, code, headings, lists, quotes, rules, links, emoji shortcodes. The card drawer already has the toolbar and stores the markdown — it just renders it as plain text. **`league/commonmark` 2.8.3 is already installed**, transitively via `laravel/framework`, so `Str::markdown()` works today. Do not `composer require` it. Escape on output: a description is user input and the page must not gain a `{!! !!}` without a sanitiser behind it. | trivial |
| **Advanced checklist items** | One assignee and a due date per *item*, not just per card. Item due dates appear in calendar view. Converting an item to a card carries both across. | moderate |
| **Copy card / list / board** | Copy card offers checkboxes for what to keep. Match Trello's exclusions exactly so behaviour is not surprising: copying a **list** keeps descriptions, attachments, comments and checklists but not activity; copying a **board** keeps cards and descriptions only — no comments, no activity, no card members, no archived cards. | moderate |
| **Archive before delete** | Trello refuses a hard delete on a card that is not archived first, then asks again. **Boards already have this** — `⚡board-settings.blade.php` requires the board's name to be typed. Narrow this row to cards and lists. | trivial |
| **Watch a card** | See notifications below. | moderate |
| **Card activity** | Every board action already writes a named `activity_log` row — `card.created`, `card.moved` with both list names, `card.mirrored`, `card.archived`, `card.label_added`, `card.customer_set`. **The card back already has a section headed `Activity` that renders comments and nothing else.** The work is not "add a section", it is "the existing heading is a lie about what is under it". | trivial |
| **Stickers** | Placed anywhere on the card front with position and rotation. Low value; last. | moderate |

## List

| Feature | Notes | Effort |
| --- | --- | --- |
| **WIP limits** | A maximum card count per list; the header warns when exceeded. Trello ships this as a Power-Up; build it natively. | trivial |
| **Sort list by** | Alphabetical, due date, created date, or any date/number custom field. | trivial |
| **Move all cards** | Bulk move to another list or board. | trivial |
| **Archive all cards** | With an optional "only cards untouched for N days" filter, which needs a last-activity timestamp per card. | moderate |
| **Collapse a list** | Persist per user per board. | trivial |
| **List colour** | Header colour. Use the existing `Palette`. | trivial |
| **Watch a list** | Notifies on everything in it *and* on new cards created in it. | moderate |

## Board

| Feature | Notes | Effort |
| --- | --- | --- |
| **Backgrounds** | Solid colours are trivial; a photo background needs `AttachmentService`. Include the light/dark text toggle. | trivial → moderate |
| **Starred boards** | A user/board pivot; starred boards pin to the top everywhere. | trivial |
| **Recently viewed** | `last_viewed_at` per user per board. | trivial |
| **Card ageing** | Cards fade or crack with time since last activity, with an "evergreen" exemption flag. Needs the same last-activity timestamp as archive-all. | moderate |
| **Board description** | Markdown, with mentions. **`boards.description` already exists and is editable and persisted** in board settings — it is rendered as plain text. Same job as the card description. | trivial |
| **Export** | CSV and JSON. It is a query dump. | trivial |
| **Print** | A print-optimised view. This row used to say "Kargah already has the pattern from the invoice PDF" — it does not: the invoice path is `barryvdh/laravel-dompdf` rendering server-side to a file, which is a different feature from a print stylesheet. Decide which is wanted. A `@media print` sheet is the cheap one and needs no library. | trivial |
| **Board activity feed** | Same events table as card activity, board-scoped. | moderate |
| **Search operators** | See below. | moderate |
| **Email to board** | A unique unguessable address per board; a message sent there becomes a card, attachments included. Kargah already syncs IMAP, so this is a folder rule away rather than new infrastructure. | moderate |
| **Visibility and roles** | Private / workspace / public, and admin / member / observer. Only worth building alongside workspaces. | moderate |

### Search operators

Trello's search is a small query language, and it is the single highest-value item on this page for
a power user. All of it maps to SQL; no search engine is needed at this scale.

```
member:nima   board:"Client Work"   list:"To Do"   label:red
created:7  created:week    due:day  due:overdue  due:complete  due:incomplete
edited:month
has:attachments  has:cover  has:members  has:description  has:stickers
name:widget  description:scope  checklist:deploy  comment:blocked
is:open  is:archived  is:starred
sort:due  sort:-edited
-label:red        (negation)
```

## Views

Every view is another rendering of rows Kargah already has. None needs real-time anything.

| View | Needs | Effort |
| --- | --- | --- |
| **Table** | Cards flattened to rows, inline-editable name, due date, list, members, labels. | moderate |
| **Calendar** | Due dates, start dates, and advanced-checklist item due dates. Drag to reschedule. Publish an **ICS feed** — pure PHP, and it is what makes the calendar useful outside the app. The RFC 5545 serialiser is **built** (`Modules/Project/app/Support/IcsCalendar.php`, 48 tests); the page, the route and the feed endpoint are not. FullCalendar is already vendored. | moderate |
| **Timeline** | Start *and* due date required for a card to appear. Lanes grouped by list, member, label or custom field. Drag the edges to change the range. The interaction is the hard part, not the data. | moderate-hard |
| **Dashboard** | `GROUP BY` counts per list, per due date, per member, per label. ApexCharts is already in `public/assets/vendors/` — load it from `@script` on this page only, never from the layout. | trivial |
| **Map** | A location per card. Leaflet with OpenStreetMap tiles needs no key and no server component, and is **already vendored** in `public/assets/vendors/leaflet/`. | moderate |

🔴 **Every view here must be its own component with its own route, not a second island in
`⚡boards.blade.php`.** `BoardsTest` asserts that file contains exactly one `@island(`, and it is
right to: two islands in one file share a compile-time token and the client morphs the wrong one.
A view toggle that adds an island to the board file fails that test by design.

## Butler — automation

Five command types on one `trigger → condition → action` model. This is the largest single feature
in this document and the one that turns a board tool into a system.

- **Rules** — a trigger fires, actions run. The trigger *is* a web request Kargah already handles,
  so these run synchronously. No infrastructure.
- **Card buttons** — a button on the card back that runs an action chain on that card.
- **Board buttons** — a button in the sidebar that runs an action chain over a filtered set.
- **Calendar commands** — scheduled recurring actions. Needs the cron sweep.
- **Due-date commands** — relative to a card's due date. Needs the cron sweep.

**Triggers**: card moved into or out of a list · card created · label added or removed · due date
set, changed, arriving or overdue · card marked complete · member added or removed · checklist item
checked, or a whole checklist completed · comment matching a pattern · custom field changed ·
attachment added · card archived, copied or moved between boards · vote count changed.

**Actions**: move card · add or remove member · add or remove label · set or clear dates, absolute
or relative · add a checklist, optionally copied from another card · check items · post a comment
with variable interpolation (`{card name}`, `{due date}`, `{member}`) · set a custom field · copy
card · create a card, optionally from a template · archive or unarchive · sort a list · change the
cover · mark complete.

**Conditions**: if/else inside a rule, filters that qualify the trigger before it fires, and date
arithmetic for the scheduled variants.

Start with rules and buttons. They need no scheduler and cover most of what people actually
automate.

## Workspaces

Kargah is single-user, so this is the least urgent section — but board roles, collections and the
workspace-wide table and calendar views all hang off it, and adding an entity *above* boards later
is the same kind of retrofit as mirror cards. Decide early whether it is ever wanted.

## Notifications

Trello's model, which is worth copying because it is well-judged:

- **Always notified**, regardless of watching: being @mentioned, being added to a card or board,
  a board being closed, being made an admin.
- **Watch a card** → comments, date changes, moves, archiving.
- **Watch a list** → all of that for every card in it, plus new cards created in it.
- **Watch a board** → all of that for every card on it, plus new cards anywhere.
- **Delivery**: an in-app feed always; email as never / hourly digest / instant.

In-app is an events table with a read flag. Email is the existing cron. Nothing needs a daemon.

**The in-app half is built.** Core owns a `notifications` table — morph subject, denormalised
title/body/url so a deleted card cannot 500 the feed, `read_at`, and a `dedupe_key` unique per
user so a cron sweep cannot tell someone the same thing five hundred times. There is a `Notifier`
contract, a feed page at `/notifications` with cursor pagination, a header bell, and a prune
command on the scheduler.

What remains is everything that would *use* it: **no watch tables, and not one producer.** Nothing
in any module calls `notify()`.

Build **one producer end to end before the watch UI** — card commented → notify the watchers.
Producers are cheap once the first exists, and a watch UI built first has nothing to prove itself
against.

One more thing already drawn and not wired: `resources/views/pages/settings/⚡notifications.blade.php`
renders ten event rows with in-app and email switches, a digest select and quiet hours, and
**persists none of it** — there is no `save()` and no table. That is the delivery-preference half
of this section, already designed.

## Keyboard

Trello is keyboard-driven and Kargah is not. `Shift+?` opens the list. The confirmed set:

`b` board switcher · `f` filter · `x` clear filters · `z` undo · `Shift+z` redo · `r` repeat ·
`j`/`k` or arrows to move between cards · `n` new card below the selected one · `t` edit title ·
`c` archive · `space` assign yourself · `l` labels · `0`–`9` toggle a label by colour ·
`Shift`+scroll to pan the board · `g` then `i`/`p`/`b` to jump.

Purely front-end over endpoints that already exist. Cheap, and it is what makes a board feel fast.
Spec 04 already asks for a keyboard fallback for drag and drop; this supersedes that item — but
note the starting point is **no fallback at all**, not a partial one. A card carries
`role="button" tabindex="0"` and opens the drawer on Enter; there is no way to move a card without
a mouse.

Two constraints this runs into:

- `0`–`9` toggling a label by colour wants ten colours. `Palette` has six. Extend it first.
- `Shift+?` wants a global listener, which belongs in the layout — and
  `docs/frontend-conventions.md` forbids editing `resources/views/partials/`. That is a shared
  file: whoever builds this reports the change rather than making it.

## Do not build

- **Trello Gold** — discontinued. **Business Class** — renamed Premium years ago.
- **Timeline on mobile** — Trello itself removed it.
- **A generic Power-Up framework** — iframe sandboxing, capability registration and an OAuth
  broker, for a marketplace that would have no entries. Hard-code the two or three integrations
  that are actually wanted instead.
- **Live multi-cursor collaboration** — the one thing here that genuinely needs persistent
  connections, and Kargah has one user.
- **Card dependencies** — Trello has no structured blocked-by graph; people fake it with links and
  automation. Building a real one would *exceed* Trello, which is fine, but it is not parity.

## Suggested order

1. The card-placement decision, if mirroring is wanted. Everything else assumes it is settled.
2. Markdown rendering, multiple members, start dates, due-date colours, mark-complete, card
   numbers, WIP limits, list sort, starred boards. All trivial, all immediately visible.
3. Search operators. Highest value per hour on this page.
4. Custom fields — several later features depend on them.
5. Butler rules and buttons, synchronous only.
6. Table, calendar with an ICS feed, and dashboard views.
7. Notifications and watching.
8. Keyboard layer.
9. Timeline, map, card ageing, covers, stickers.
