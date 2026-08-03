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

---

## Phase 2 — Project

**The spec is wrong about islands, and 04-frontend.md has been corrected.**
It asked for an island per list column. An island's identity is a token assigned at compile time
from the file hash plus the ordinal of the `@island` directive *in the source*
(`IslandCompiler.php:91`), so a directive inside a `@foreach` gives every iteration the same
token. The client locates the fragment to morph by `type` and `token` only — not by name — and
`findFragment()` stops at the first match (`dist/livewire.js:14933`, `6637`). Asking for the
seventh column morphs the seventh column's HTML into the first. `renderSlot()` twenty lines below
matches on name *and* token, so this reads as an oversight upstream rather than a design. The
board canvas is therefore one island, and a test pins the file to a single `@island(`.

**An island nobody names does not update, and that makes `assertSee` misleading in tests.**
After an action, an unnamed island comes back with `mode=skip` and the morph engine walks past the
whole fragment. So every action that changes a card calls `refreshBoard()`, and the board's tests
assert on view data or on `effects.islandFragments` rather than on the response body — after an
action the body holds the toolbar, not the cards. Two tests guard both directions: that an action
changing the board emits a fragment, and that one merely opening a panel does not.

**`moveCard` keeps its three-argument signature and derives the visible ordering server-side.**
Sortable reports the index the card landed on among the cards the *browser* could see. With a
filter on, that is not an offset into the list — rows are hidden between the visible ones. Rather
than widen the signature the front end froze, the component recomputes which cards were visible
from the same filter that rendered the page. The server already knows, and a client-supplied
ordering is one more thing that can be wrong or forged.

**Positions are decimal strings through `brick/math`, not floats.**
Money is not the only place a float ruins a column. Two cards that land on the same position have
whatever order the database feels like that day. `Position` does every operation on decimal
strings, and `MIN_GAP` (1e-4) sits well above the column's own 1e-10 resolution because SQLite
stores a decimal as a float and the last digits of the declared scale are not somewhere to be
operating.

**`Brick\Math\RoundingMode` is an enum, so the spec's `RoundingMode::HALF_UP` does not exist.**
The installed brick/math spells the cases `Down`, `HalfUp`, `Ceiling`. 03-accounting.md's example
will not parse as written; phase 3 uses `RoundingMode::HalfUp`.

**`card_members` rather than `cards.assigned_to`.**
The UI has exactly one assignee and a pivot to model a single value is ceremony. The pivot is what
02-data-model.md names, though, and multi-user is stated as coming — retrofitting a pivot later
means rewriting every read. One mechanism, kept.

**Core's morph map needed `user`, which nothing had noticed.**
The enforced map threw the first time anything wrote an activity entry, because the log stores its
causer polymorphically. `User` is an application model rather than a module one, but Core owns the
map, so Core registers it. Phase 1 could not have found this: nothing logged activity yet.

**Colour keys, never concatenated class names.**
`boards.colour` and `labels.colour` store `'success'`, and `Support\Palette` maps that to whole
Tailwind strings. Tailwind's scanner reads source as text and cannot see a class that only exists
once PHP has run, so `"bg-{$colour}"` is simply absent from the stylesheet.

**"The customer's page lists its cards" is wired now; the rest of that page is still phase 3.**
`/accounting/clients/{id}` gained a Projects tab reading real cards through
`Modules\Project\Contracts\CardReader` — arrays, not models, so Accounting never holds one of
Project's entities. The money on that page is still a fixture, and still says so.

---

## Phase 3 — Accounting

**`brick/math` truncates a float to an integer, and every guard against it was dead code.**
`BigNumber::of()` accepts `int|string|BigNumber`. Hand it a float and PHP coerces to `int` first,
so `BigDecimal::of(34.1527)` is `34` — a whole lira per dollar — behind nothing louder than a
deprecation notice. Worse: a guard written as `is_float($x)` on a parameter typed `string` never
fires, because the type coercion happens before the function body. Every entry point into the
money layer now declares `string|float` *specifically so it can refuse the float by name*, and
`MoneyTest::test_the_maths_library_truncates_a_float_to_an_integer` pins the underlying behaviour
so nobody relaxes the guards thinking they are paranoia.

**`RoundingMode` is an enum; the spec's `RoundingMode::HALF_UP` does not exist.**
The installed brick/math spells the cases `HalfUp`, `Down`, `Ceiling`. 03-accounting.md's example
will not parse as written.

**SQLite loses decimal precision, and the spec's stated maximum is wrong there.**
SQLite has no DECIMAL storage class: NUMERIC affinity stores a non-integer as a double, so
`99,999,999,999,999.999999` — the figure 03-accounting.md quotes — comes back as the integer
100000000000000. Measured, not assumed. The real ceiling is **fourteen significant digits**, set
by PHP's `precision` ini rather than by the double, so ±99,999,999.999999 is exact. Accepted
rather than worked around: integer minor units or money in a `varchar` would be exact everywhere
and would cost `SUM()` and `ORDER BY` on every report, to buy headroom no freelance invoice will
use. MySQL and MariaDB, the primary target, are exact throughout. The rule that follows is now in
the spec: **never do money arithmetic in SQL** — totals are computed in PHP through `brick/money`,
and the database is only storage.

**`Invoice::isIssued()` reads `sent_at`, not `issued_on`.**
`issued_on` is the date printed on the document, and back-dating a draft is ordinary — you write
Monday's invoice on Wednesday. Reading it as "has been issued" made every back-dated draft refuse
to be issued at all. `sent_at` is the moment `InvoiceIssuer` froze the rates, which is the moment
the numbers stopped being allowed to change.

**A `date` cast writes a datetime, which broke two natural keys.**
Eloquent writes a `date` cast through the connection format, so a DATE column receives
`2026-06-24 00:00:00` while `updateOrCreate` looks for `2026-06-24` — and the second run of the
rate fetcher hit the unique index it exists to satisfy. `ExchangeRate::as_of` uses an `Attribute`
that writes a bare date instead. This is load-bearing for every "runs twice, changes nothing"
requirement that keys on a date.

**Realised FX is stored; unrealised is computed and written nowhere.**
`payments.fx_gain_loss` is the difference between what the payment currency was worth at issue and
what it was worth at settlement, in the invoice's currency, because that is the number the owner
is up or down by. Revaluing a still-open invoice at today's rate is a *report* —
`PaymentRecorder::unrealised()` returns the rate, the figure and the difference and touches no
table, because nothing has happened yet. A test asserts the ledger is unchanged by it.

**The TCMB EVDS key is not on this machine, and that does not stop the build.**
`accounting:fetch-rates` skips that source cleanly, logs which rates are therefore unavailable and
why, and still runs Frankfurter and CoinGecko, which need no key. A domestic Turkish invoice
simply cannot show a lira equivalent until a key is configured — which is the honest behaviour,
since the alternative is inventing a legally significant number.

**Three things the spec got wrong about the rate APIs.**
CoinGecko's "free tier: 10k calls/month" is the Demo plan and needs a key; the genuinely keyless
endpoint is rate-limited per minute, which one daily call is comfortably inside. Frankfurter has
moved to `api.frankfurter.dev/v1` with `base`/`symbols` rather than `from`/`to`. And ECB data is
not same-day, so `as_of` comes from the response body, never from the clock — with a ten-day
lookback for weekends and holidays.

**No PDF library was installed; `barryvdh/laravel-dompdf` was added.**
Pure PHP, no binary, no daemon — the only kind of PDF generation shared hosting will run.
wkhtmltopdf and headless Chrome both need a process that cannot be started there.

**Recurring invoices generate drafts, never issued invoices.**
Issuing freezes an exchange rate onto a legal document. That is a decision a person makes, not one
a cron job makes at 3am against whatever rate happened to be current.

**A known environment problem, left alone deliberately.**
(see below)

---

## Phase 4 — Mailbox, receiving

**Attachment *metadata* is Mailbox's; attachment *bytes* are Data's.**
05-build-order.md lists `attachments` under phase 4 and again under phase 6, and 02-data-model.md
says plainly that Data owns storage and nothing else touches a disk. Both cannot be satisfied by
putting the table in Mailbox. So phase 4 creates `email_attachments` — filename, mime, size,
content id, part number — which is what the inbox needs to draw a paperclip and a filename without
claiming to hold the file. It carries a nullable `attachment_id` for phase 6 to fill in when
`AttachmentService` actually stores the bytes. Nothing in Mailbox writes to a disk.

**The documented Eloquent mutator idiom stores an encrypted column in clear text.**
This is the most dangerous thing found in the whole build, because it fails silently and looks
right. `Attribute::make(set: fn ($v) => ['imap_password_encrypted' => $v])` is the form Laravel's
own documentation shows — and a mutator's return value is merged straight into the raw attribute
array, so **casts never run on it**. The `encrypted` cast is skipped and the password goes into the
column verbatim. The first seeder run wrote a plaintext password to disk.

A second attempt, calling `$this->setAttribute()` inside the closure, silently did nothing:
`setAttributeMarkedMutatedAttributeValue` evaluates `array_merge($this->attributes, …)` left to
right, so the snapshot taken before the callback wins.

The working form encrypts inside the setter with `Crypt::encryptString()` and leaves the cast to
handle reads and direct column writes. Any model in this project holding a secret must be written
that way and must have a test asserting the stored bytes are not the plaintext — `MailAccount`,
`DeliveryProvider`, `SocialAccount` and `Credential` all do.

**Resumability rests on the unique index, not on the cursor.**
`mail_accounts.sync_cursor` is an optimisation: it stops the job re-reading a mailbox from the
start every five minutes. The *correctness* is `emails.message_id` being unique, because a cursor
is bookkeeping and a hard kill can lose bookkeeping. Every write goes through the constraint, so
the worst a kill costs is repeating one chunk. `uid_validity` is stored alongside because IMAP
lets a server invalidate every UID it ever issued; when it changes, the cursor means nothing and
the account has to resync from scratch.

---

## Environment

**A known environment problem, left alone deliberately.**
A real (non-test) rate fetch fails on this machine with `cURL error 60: unable to get local issuer
certificate` — `C:\Users\morph\PHP\8.3\php.ini` sets neither `curl.cainfo` nor `openssl.cafile`
and the install ships no `cacert.pem`. Tests never touch the network so nothing here is blocked,
but the scheduled job cannot work on this machine until a CA bundle is installed. Not fixed
because it changes PHP globally for every project on the machine.
