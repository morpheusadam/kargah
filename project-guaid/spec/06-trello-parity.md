# 06 — Trello parity

**Status:** researched 3 August 2026 against Atlassian's own support documentation plus three
2026 third-party reviews. This is an implementation checklist, not a wish list.

What Kargah already has: boards, lists, cards, drag and drop on a fractional order, labels, one
assignee per card, checklists with items, comments, due dates, card and board archive, board
templates, filtering by label/member/due, search, and an activity trail.

Everything below is what Trello has that Kargah does not.

---

## The one architectural decision to make first

**A card currently belongs to exactly one list, by foreign key.** Two features on this list break
that: mirror cards (one card shown and edited from several boards) and, more mildly, Table and
Workspace views. If mirroring is wanted, `cards.board_list_id` has to become a `card_placements`
join table carrying its own `position` per placement.

Decide this before building anything else in this document, because retrofitting it later means
rewriting every query in the Project module.

The recommendation is **do it**: it is the difference between a board tool and a board tool that
can show one piece of work in the two places it belongs. It costs one migration and one pass over
`CardService` now, and a rewrite later.

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
| **Multiple members per card** | Kargah has one assignee on a `card_members` pivot already — the pivot supports many, the UI does not. Avatar stack on the card front. | trivial |
| **Start date** | Distinct from due date. Both are needed before Timeline view means anything. | trivial |
| **Due-date colour badges** | Grey beyond 24h, yellow inside 24h, red at due, pink overdue, green complete. Pure display logic; Kargah's `dueState()` already does three of the five. | trivial |
| **Mark complete** | A green tick on the due date, independent of which list the card sits in. Suppresses due-date automation once set. | trivial |
| **Card cover** | Colour band (half or full) or an image from an attachment. Full cover hides the badges. Needs phase 6's `AttachmentService`. | moderate |
| **Custom fields** | Five types — checkbox, date, dropdown (≤50 options), number, text. Defined per board, valued per card, type immutable after creation, ≤50 per board. Deleting a definition wipes every value. | moderate — an EAV pair of tables |
| **Card templates** | A card flagged as a template; "create from template" at the bottom of the list. Editing the template does not change cards already made from it. Dates are not copied. | moderate |
| **Card number** | Per-board sequential `#123` plus a short URL slug. Not the database id. | trivial |
| **Linked cards** | Paste a card URL, get a two-way reference. Core's `links` table already does exactly this. | trivial |
| **Mirror cards** | The same card shown and edited from several lists or boards. See the architectural note above. Archiving the source does not archive the mirrors; they show an archived state. | moderate-hard |
| **Voting** | Toggle a vote, show the tally and who voted. | trivial |
| **Emoji reactions on comments** | A `comment_id, user_id, emoji` table. | trivial |
| **@mentions** | In comments and descriptions. Always notifies, regardless of watch state. Needs an autocomplete. | moderate |
| **Markdown descriptions** | Bold, italic, strikethrough, code, headings, lists, quotes, rules, links, emoji shortcodes. Use `league/commonmark`; do not hand-roll it. Kargah's card drawer already has the toolbar and stores the markdown — it just renders it as plain text. | trivial |
| **Advanced checklist items** | One assignee and a due date per *item*, not just per card. Item due dates appear in calendar view. Converting an item to a card carries both across. | moderate |
| **Copy card / list / board** | Copy card offers checkboxes for what to keep. Match Trello's exclusions exactly so behaviour is not surprising: copying a **list** keeps descriptions, attachments, comments and checklists but not activity; copying a **board** keeps cards and descriptions only — no comments, no activity, no card members, no archived cards. | moderate |
| **Archive before delete** | Trello refuses a hard delete on a card that is not archived first, then asks again. Kargah soft-deletes; add the gate and the second confirmation. | trivial |
| **Watch a card** | See notifications below. | moderate |
| **Card activity** | Kargah logs to `activity_log` already; it needs rendering on the card back. | trivial |
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
| **Board description** | Markdown, with mentions. | trivial |
| **Export** | CSV and JSON. It is a query dump. | trivial |
| **Print** | A print-optimised view. Kargah already has the pattern from the invoice PDF. | trivial |
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
| **Calendar** | Due dates, start dates, and advanced-checklist item due dates. Drag to reschedule. Publish an **ICS feed** — pure PHP, and it is what makes the calendar useful outside the app. | moderate |
| **Timeline** | Start *and* due date required for a card to appear. Lanes grouped by list, member, label or custom field. Drag the edges to change the range. The interaction is the hard part, not the data. | moderate-hard |
| **Dashboard** | `GROUP BY` counts per list, per due date, per member, per label. ApexCharts is already in `public/assets/vendors/` — load it from `@script` on this page only, never from the layout. | trivial |
| **Map** | A location per card. Leaflet with OpenStreetMap tiles needs no key and no server component. | moderate |

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

## Keyboard

Trello is keyboard-driven and Kargah is not. `Shift+?` opens the list. The confirmed set:

`b` board switcher · `f` filter · `x` clear filters · `z` undo · `Shift+z` redo · `r` repeat ·
`j`/`k` or arrows to move between cards · `n` new card below the selected one · `t` edit title ·
`c` archive · `space` assign yourself · `l` labels · `0`–`9` toggle a label by colour ·
`Shift`+scroll to pan the board · `g` then `i`/`p`/`b` to jump.

Purely front-end over endpoints that already exist. Cheap, and it is what makes a board feel fast.
Spec 04 already asks for a keyboard fallback for drag and drop; this supersedes that item.

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
