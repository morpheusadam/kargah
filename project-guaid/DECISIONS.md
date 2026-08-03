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

## Phase 7 — Social

**Status lives on the target, not on the post.**
The migration says it and the module turns on it: a post going to two networks
fails on one far more often than it fails as a whole. `PostTarget::scopeClaimable()`
matches `pending`, `failed`, and `publishing` past a stale window — it cannot match
`published`, so a retry physically cannot resend the network that worked. The test asserts on the
publisher's send count rather than on the row, because a preserved row and a resend that rewrote
the same values look identical from the database.

**Media attachments and engagement metrics were removed, not stubbed.**
The composer had a fake media picker behind a "not wired up yet" toast and `⚡post-show` displayed
invented impression counts. There is no upload path until phase 6 and Kargah collects no metrics
at all, so both are gone rather than lying. `posts.media` and the drivers' `$media` parameter
remain for whoever adds the real thing.

**Pasted tokens rather than OAuth.**
An OAuth callback needs a redirect URI registered per install, which fights the shared-hosting
target — there is no stable public URL to register.

---

## Platform — application passwords

**A new module, and the dependency arrow points the other way.**
`Platform` is an edge module: it may depend on any other module's `Contracts` namespace and on no
module's `Models`, and nothing may depend on it. That is what an API gateway is, and it is the
only module allowed to see all the others. Priority is `10` like every feature module, because it
depends on Core's schema and on nothing else's. Two dependencies on Core that are not `Contracts`
and are deliberate: `Core\Support\MorphMap`, which every module calls to register its aliases, and
`Core\Concerns\InteractsWithToasts`, which is presentation plumbing with no domain in it. Neither
is a model and neither carries a fact about the business.

**The secret is a `protected` property on the Livewire component, not a public one.**
This is the single load-bearing line on the settings page. A public property is serialised into
the page and posted back on every round trip, so a freshly issued secret held in one would sit in
the browser's memory, in the back button and in any proxy in between for as long as the tab stayed
open. Livewire does not serialise protected properties, so `$issuedSecret` exists only for the
request that created it and the very next interaction comes back without it. Nothing has to
remember to clear it, which is exactly why it cannot be forgotten. `dismissSecret()` has an empty
body on purpose: its whole job is to cause a render that does not contain the secret.

**`token_hash` is not in `$fillable`, and there is no factory.**
The issuer writes it with `forceFill`, which makes `ApplicationPasswordIssuer::issue()` the one
creation path in the application — a settings page, an artisan command and a future API all come
through the same generator, the same `Hash::make`, and the same activity entry. A factory would be
a second way to make one of these, which is a second way to make one wrongly; the tests use the
issuer and `forceFill` for the expired and revoked states.

**Never `where('token_hash', …)`.**
The lookup is `user_id` + `prefix`, then a real `Hash::check` over the handful of rows that come
back. Querying by hash would make the database answer "does this hash exist" for anyone who can
ask, and it only works at all with an unsalted digest, which is the wrong kind of hash for a
credential. `ApplicationPasswordTest` watches the query log during a real authentication and fails
if `token_hash` appears in any SQL, so the shortcut cannot be reintroduced quietly.

**A miss costs the same as a hit.**
No such account, no candidate row and a wrong secret all spend one hash operation — the decoy is a
`Hash::make` on a fixed string. Without it, an unknown email address returns measurably faster
than a known one, which is an account enumeration oracle sitting on the only endpoint in Kargah
reachable without a session. Revoked and expired are checked *after* the hash for the same reason.

**Revocation is a conditional `UPDATE`, not an `if` on the model.**
`whereKey(...)->whereNull('revoked_at')->update(...)` returns the row count, and a zero means
somebody else got there first. An `if ($credential->revoked_at === null)` read from a stale
instance lets two callers through and writes two entries into an append-only table. Same rule as
every job here: the second run changes nothing, and the test asserts `revoked_at` does not move
and no second activity entry appears.

**`/api/v1/whoami` requires `core:read`, and yes, that is slightly circular.**
The rule from `07-platform.md` is that no endpoint is reachable without a scope, and exempting the
discovery endpoint would make it the one credential-free surface in the API. The circle is closed
from both ends instead: the settings page ticks `core:read` by default, and a 403 names the scope
it wanted and lists the ones the credential has — so a client that cannot reach `whoami` is still
told what it is missing.

**Middleware aliases are registered by the module, not in `bootstrap/app.php`.**
`PlatformServiceProvider::boot()` calls `aliasMiddleware('app-password', …)` and
`aliasMiddleware('scope', …)`. A module that needs a middleware should be able to declare one, and
the app shell should not have to know Platform exists — the same argument that put Data's backup
disk in `DataServiceProvider` rather than in `config/filesystems.php`.

**`07-platform.md` was wrong that `NoSecretsInHtmlTest` would pick the new table up on its own.**
It says the table "will be picked up automatically because the column will be named `*_hash` and
`token`". The test's pattern was `/(_encrypted|^secret|_secret|password|_token$|credentials)/i`,
and `token_hash` matches none of it. `token_hash$` was added — deliberately not the broader
`_hash$`, because `crypto_payments.tx_hash` is a blockchain transaction reference that is public by
construction and is printed on the invoice page on purpose. A pattern that cannot tell those two
apart would fail the test for doing the right thing.

**`NoDeadEndpointsTest` gained an allowlist rather than losing its assertion.**
It asserted that *no* route starts with `api/v1/`, which was exactly right while Kargah had no
API. `/api/v1/whoami` is real, so the assertion now skips a named list of endpoints somebody wrote
— and a second test asserts every entry on that list is actually routed, so the allowlist cannot
quietly keep excusing a URI after the route behind it has gone.

**Sanctum was not installed, and should not be.**
The spec says Sanctum "can back this". It cannot back it usefully: Sanctum's model is a bearer
token, and the interface here has to be Basic auth so that `curl -u` works in one line. Adding a
package to reimplement its interface is a dependency for nothing.

**`settings-nav.blade.php` gained the same `Route::has` guard the sidebar already had.**
Its first four tabs are application routes and always exist; the fifth belongs to a module, and
`route()` on a disabled module throws rather than degrading.

---

## Card placements — the mirror-cards migration

**On SQLite, dropping a foreign-keyed column inside a transaction silently deletes every row that
cascades to the table. This cost a debugging cycle and it is the nastiest thing found in the whole
build so far.**

SQLite cannot drop a column in place, so Laravel rebuilds the table: create a new one, copy, drop
the old, rename. **Dropping the old table fires every `ON DELETE CASCADE` pointing at it.** Laravel
knows this and turns foreign keys off around the rebuild — which is enough from the command line.

It is not enough from a test. `PRAGMA foreign_keys` is a documented **no-op inside an open
transaction**, and `RefreshDatabase` wraps every test in one. So the pragma appeared to work,
returned no error, and the cascade fired anyway: the migration deleted the `card_placements` rows
it had just backfilled, and would have taken `checklists`, `card_comments`, `card_label` and
`card_members` with it.

The migration now copies through a staging table carrying no foreign keys, in both directions, so
there is nothing for a cascade to travel along. Anything that drops a column with dependents on
SQLite needs the same treatment, and needs testing *inside a transaction* — the command line will
not reproduce it.

**`down()` restores `board_list_id` as nullable.**
A NOT NULL column cannot be added to a populated table without a default, and a default here would
be a foreign key pointing at whichever list happened to be first. Every row is backfilled
immediately after, so the nullability is a step in the migration rather than a property of the
restored schema.

**`moveCard` still takes three arguments, but the first is a placement id.**
The earlier entry recording that the signature was frozen by the front end predates mirror cards.
Once a card can sit in two lists, a card id no longer identifies what was dragged. The part that
actually mattered is intact: the server still derives the visible ordering itself from the same
filter that rendered the page, rather than trusting a client-supplied offset.

**`Board::cards()` is a query builder, not a relation, and says so loudly.**
Board to card is three hops — board → list → placement → card — which `hasManyThrough` cannot span.
More importantly a card mirrored onto two lists of one board must count **once**, and no relation
type deduplicates. The card ids come from a subquery, which is what makes it distinct. The
consolation prize is that `$board->cards` as a *property* now throws Eloquent's `LogicException`
instead of quietly returning something wrong.

**A card whose only placement's list is deleted is soft-deleted with the list.**
Leaving it alive would put it on no board and outside the archive, which reads a card through the
list it lives in — invisible *and* unreachable, which is worse than either alone. A card merely
*mirrored* into a deleted list loses only the mirror.

**Archiving a list archives the cards whose origin is in it; mirrors in it just stop being drawn.**
Archiving your own list must not take somebody else's card off their board.

**An archived mirror renders but is not draggable.**
`Sortable`'s selector is `'[data-placement-id]:not([data-archived])'`. It is a note about where the
card went, not a card on the board. This is the visible half of 06's rule that archiving the source
does not archive the mirrors.

**`CardPlacement::scopeOnCanvas()` is the single definition of "what a board draws".**
Used by the canvas *and* by `CardService`'s neighbour arithmetic, so what is rendered and what a
drop is measured against cannot drift apart.

**The mirror icon is `ki-devices-2`, not `ki-copy`.**
`ki-copy` exists in the bundle and is already the Copy action. A copy is a new card and a mirror is
the same card; blurring those two in the UI is the one thing this feature must not do.

---

## The toast layer

**"Only report what the user cannot already see" is the whole rule, and it decided fifty call
sites.**
The front end toasted on everything, which was right while every page was a fixture and an
interaction had no other way to prove it had happened. Once the pages did real work it became
noise, and noise is where a genuine failure goes to hide. A panel opening is visible — the panel is
open. A write, a dispatch, an export, a clipboard copy, or a row that leaves the screen is not.

**Every error and warning survived untouched.**
A failure is never otherwise visible, so the rule cannot reach it. This also means the cleanup
could be applied mechanically without anyone having to judge, per call site, how bad a failure was.

**A success toast on a branch that deliberately does nothing was kept, not deleted.**
"Nothing to archive", "already there", "nothing needed sending" — these look like the forbidden
case and are its opposite. The user clicked something and *no write happened*; the absence is
precisely what they cannot see. The forbidden case is a method that does nothing and claims it
did something.

**Three `updated*` hooks in Accounting's expenses page were left empty rather than deleted.**
Their entire bodies were a toast. The selects are `wire:model.live`, so the round trip happens
without them, but deleting a Livewire lifecycle hook is a behavioural decision rather than a toast
one and it was left for a human.

---

## Testing — measuring performance inside a test suite

**A wall-clock budget asserted in the suite measures the machine, not the page.**
`InboxPageTest`'s "renders in under 200 ms with 10,000 messages" came back at 93 ms run alone,
255 ms under the full suite, and 533 ms with several agents working in parallel. The assertion was
load-sensitive, which makes it a source of false red and — worse — of false green, because the
ceiling that stops failing under load is one a real regression can also pass.

Raising the number was the wrong fix. The property the timing stood in for is *the page does not
scale with the table*, and that has two load-invariant forms, both now asserted: a bounded query
count with `limit 26` on the list query, and a companion test that renders the same page with 25
messages and with 10,000 and asserts the cost does not track the row count. A generous 2 s ceiling
remains as a pathology detector — a query that grew with the table does not come back marginally
late, it comes back in seconds — and it is commented as such so nobody reads it as the budget.

The real budget lives where the number means something: `php timing-probe.php`, warm, against the
dev database.

---

## Search operators

**An unknown operator becomes free text; it is never dropped.**
`colour:red` is not a key the grammar knows. Dropping it silently means a search box that ignores
what someone typed and returns results that do not match what they asked for. Searching for the
literal string is wrong in a different, *visible* way, and visible is better.

**Dates are recorded, never resolved.**
`created:week` is stored as the string `week`. The parser has no clock and never calls `now()`, so
the compiler decides what a week means against the request's timezone — baking it in would freeze
one timezone into a saved filter.

**Quotes are metacharacters everywhere, with no escape.**
The cost is that a literal double quote cannot be searched for. The gain is that `toString()` is
lossless and the round trip closes, which is what lets a saved filter survive being re-parsed.
`parse(parse($s)->toString())` is asserted stable — the same "runs twice, changes nothing" rule
the project holds jobs to, applied to a serialiser.

**An unterminated quote runs to the end of the input instead of throwing.**
A search box is typed live. Half a quoted phrase is a normal intermediate state, not an error.

**PHPUnit 12 no longer reads the `@dataProvider` doc annotation.**
It silently runs the test with no arguments and errors. `#[DataProvider]` is required. Worth
knowing before writing the next table-driven test.

---

## The ICS feed

**`DTEND` for an all-day event is exclusive, and the caller is not asked to know that.**
RFC 5545 says a card due on 31 July is `20260731` to `20260801`. `IcsEvent` takes the last day the
card covers and adds the day itself. Putting the exclusive end in the constructor would have pushed
the classic off-by-one onto every call site instead of solving it once.

**An all-day date is read in its own timezone and never converted.**
Converting midnight in Istanbul to UTC moves the card to the 30th — the same off-by-one by another
road. Timed events *are* converted to UTC, because a feed consumed by an external client is exactly
the case where UTC on the wire is right.

**Line folding counts octets, not characters.**
75 octets is what the RFC says. Counting characters splits a codepoint in a Turkish title or an em
dash, and the client renders mojibake. A fold also never separates a backslash from the character
it escapes — legal either way, since unfolding precedes unescaping, but enough readers get that
order wrong that one retreating octet is cheap insurance.

**`DTSTAMP` is a parameter, not a call to `now()`.**
It is the one field that legitimately moves between generations, so taking it as an argument makes
the output byte-identical for the same input — which is both testable and the thing that lets the
HTTP layer send a real `ETag` instead of regenerating an unchanged feed on every poll.

**The control-character strip is byte-wise, not `/u`.**
A `/u` pattern returns null on the first malformed byte, which would blank the whole value rather
than clean it. A tab survives: RFC 5545 §3.1 lists HTAB as legal.

**An empty feed emits a bare `VCALENDAR` with no component, which §3.6 does not allow.**
A knowing deviation. The alternatives were a synthetic placeholder event, which is a lie in
somebody's calendar, or a 404 for an empty board, which presents as a broken subscription.

---

## Core — notifications

**The table is `user_notifications`, because `notifications` is Laravel's.**
`App\Models\User` uses `Notifiable`, whose `notifications()` relation targets
`Illuminate\Notifications\DatabaseNotification` — a uuid primary key and `type` /
`notifiable_type` / `data` columns, irreconcilable with this shape. Nothing calls it today, so the
collision is latent rather than live, which is the kind that fails confusingly six months later.

Dropping `Notifiable` from `User` was the other option and was rejected:
`CanResetPassword::sendPasswordResetNotification()` calls `$this->notify()`, so removing the trait
would quietly break password reset. Renaming is safe unconditionally. A test pins all three facts —
the table name, that `notifications` does not exist, and that `Notifiable` is still on `User` — so
nobody renames it back on the grounds that nothing currently breaks.

**`title`, `body` and `url` are denormalised, exactly as `searchables` is.**
The alternative is Core resolving a polymorphic subject to a display string, which means Core
knowing about every module. It also means a notification about a deleted card still renders instead
of 500ing the feed, and that an old row keeps the name the card had when it happened — which is what
you want in a list of things that already occurred.

**`notify()` reads before it writes *and* catches the unique violation.**
The `SELECT` is the fast path for the ordinary second cron tick; the catch is what makes a genuine
overlap correct. The index is the authority, not the read. Verified on SQLite that NULL dedupe keys
do not collide, by an insert that bypasses the service entirely.

**A matched `dedupe_key` returns the existing row completely unchanged.**
It does not update the title to a newer render. A feed of things that already happened keeps what
it said at the time.

**`markRead()` scopes by user in the query rather than checking after loading.**
A notification id is not a capability, and the cheapest way to keep that true is never to load a
row belonging to someone else.

**A too-long `url` throws; a too-long `title` is truncated.**
Truncating a URL stores a link that goes somewhere else, which is worse than refusing it. A
truncated title is still the right notification.

---

## Cross-cutting

**Thirty scaffolded API endpoints were removed.**
`nwidart/laravel-modules` scaffolds an `apiResource` and a placeholder controller into every new
module, and Core additionally got a `Route::resource('cores', …)` in its web routes. All of them
pointed at controllers whose `index`, `create`, `show` and `edit` rendered views that were never
written, so a signed-in request got a 500. Dead surface area is worse than missing surface area —
undocumented, untested, and the first thing anyone poking at the application finds — so they are
gone, and `tests/Feature/NoDeadEndpointsTest.php` walks the real routing table to stop
`module:make` quietly putting them back.

That test said "no route may begin with `api/v1/`", which was true when Kargah had no API and
became a contradiction the moment `07-platform.md` asked for one. It now carries a short allowlist
of URIs that are genuinely written, plus a second test asserting every allowlisted URI is actually
routed — so a line added to the allowlist cannot quietly excuse a URI nobody built. Adding to it is
a deliberate act; weakening it is not.

---

## Environment

**A known environment problem, left alone deliberately.**
A real (non-test) rate fetch fails on this machine with `cURL error 60: unable to get local issuer
certificate` — `C:\Users\morph\PHP\8.3\php.ini` sets neither `curl.cainfo` nor `openssl.cafile`
and the install ships no `cacert.pem`. Tests never touch the network so nothing here is blocked,
but the scheduled job cannot work on this machine until a CA bundle is installed. Not fixed
because it changes PHP globally for every project on the machine.
