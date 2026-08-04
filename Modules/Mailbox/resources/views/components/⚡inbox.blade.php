<?php

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Customer;
use Modules\Core\Support\MorphMap;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\MailAccount;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Services\CardService;

/**
 * The unified inbox, reading from the local mail store.
 *
 * Never from IMAP directly: `mailbox:sync-imap` fills the store on a schedule
 * and this page only ever reads it. That separation is the whole reason the
 * inbox stays fast on shared hosting, where a request has about thirty seconds
 * and an IMAP round trip can spend all of them.
 *
 * Five things here are worth knowing before changing anything.
 *
 * **Two islands: the message list and the reading pane.** This is the shape
 * islands were built for — reading down a conversation, or replying, sends back
 * the pane and leaves the twenty-five rows beside it alone. Moving the
 * selection does have to send both, because the row carries the tint that says
 * which message is open; see `selectEmail()`, which used to get that wrong.
 * There are exactly two `@island`
 * directives in this file and neither is inside a loop: a directive in a
 * `@foreach` shares one compile-time token with every iteration, and the client
 * finds the fragment to morph by token alone, so asking for the seventh row
 * morphs the first. See project-guaid/spec/04-frontend.md.
 *
 * **An island nobody names does not update.** After an action the fragment
 * comes back with `mode=skip` and the morph engine walks the whole range, so
 * the DOM keeps what it had. Every action that changes what an island shows
 * goes through `refreshList()` or `refreshPane()`, and the ones that change
 * neither — ticking a checkbox, opening the convert modal — deliberately name
 * nothing, because that is the saving.
 *
 * **Cursor pagination, not offset.** Emails are one of the two tables that will
 * actually get long. Offset pagination scans and discards every row it skips;
 * at page 400 of an inbox that is the whole table. The order is
 * `coalesce(received_at, created_at)` rather than `received_at` alone, because
 * a cursor compares on the ordering column and `NULL < ?` is never true — a
 * message that arrived without a date would drop out of every page after the
 * first. Laravel resolves the alias back to the expression, so the comparison
 * is made against the same `coalesce` the ordering used.
 *
 * **The list never loads a body.** `body_text` and `body_html` are `longText`,
 * and twenty-five of either is the difference between a fast page and a slow
 * one. The list selects a 400-character slice in SQL as `list_preview` and
 * nothing else of the body travels.
 *
 * **Remote HTML is never rendered.** `body_html` arrives from strangers. It is
 * stripped to text and printed through an escaped echo, so a message cannot
 * style, script or beacon its way into this page. There is no unescaped echo
 * anywhere in this file and there must not be one — `InboxPageTest` asserts it.
 */
new
#[Title('Inbox — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Enough rows to fill the column, few enough that the query stays cheap. */
    private const PER_PAGE = 25;

    /**
     * The folders a mailbox is expected to have, in reading order.
     *
     * Whole class and icon strings in a map, never built by concatenation:
     * Tailwind's scanner reads source, and keenicons that do not exist render
     * as a blank glyph with no error. Any other folder the sync finds is shown
     * too, below these.
     */
    public const FOLDERS = [
        'INBOX' => ['label' => 'Inbox', 'icon' => 'ki-sms'],
        'Archive' => ['label' => 'Archive', 'icon' => 'ki-archive'],
        'Sent' => ['label' => 'Sent', 'icon' => 'ki-paper-plane'],
        'Drafts' => ['label' => 'Drafts', 'icon' => 'ki-notepad-edit'],
        'Junk' => ['label' => 'Junk', 'icon' => 'ki-shield-cross'],
        'Trash' => ['label' => 'Trash', 'icon' => 'ki-trash'],
    ];

    #[Url]
    public string $folder = 'INBOX';

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * The open message.
     *
     * Named `email` in the address bar because that is the link Mailbox already
     * hands other modules: `EmailReader::forCustomer()` builds
     * `route('mail.inbox', ['email' => $id])`, and a client page that opens a
     * message has to land on it.
     */
    #[Url(as: 'email')]
    public ?int $selected = null;

    /** The encoded cursor. Empty means the first page, and stays out of the URL. */
    #[Url]
    public string $cursor = '';

    #[Url]
    public bool $unreadOnly = false;

    #[Url]
    public bool $starredOnly = false;

    /** @var array<int, int> Message ids ticked for a bulk action. */
    public array $checked = [];

    /** The "turn into a card" modal, which lives outside both islands. */
    public bool $convertOpen = false;

    /** null | 'reply' | 'replyAll' | 'forward' */
    public ?string $replyMode = null;

    public string $replyTo = '';

    public string $replyBody = '';

    /**
     * Per-request memos. Private, so Livewire neither ships nor rehydrates
     * them, and a new component instance starts empty — no code here may assume
     * either a fresh process or a persistent one.
     *
     * Without them the page counts the folders three times: `with()`, the
     * heading and the sidebar each go looking independently.
     */
    private ?CursorPaginator $resolvedRows = null;

    private ?Collection $resolvedFolders = null;

    private ?Collection $resolvedCustomers = null;

    private ?Collection $resolvedLists = null;

    private ?Email $resolvedOpen = null;

    private bool $openWasResolved = false;

    /**
     * A folder in the address bar may be one this mailbox does not have, and a
     * message id may be one that was deleted while a link sat in a chat.
     */
    public function mount(): void
    {
        if (! $this->folderExists($this->folder)) {
            $this->folder = 'INBOX';
        }

        $email = $this->openEmail();

        if ($email === null) {
            $this->selected = null;

            return;
        }

        // Arriving on a deep link from a client page is opening the message, so
        // it counts as read — the same gesture as clicking the row.
        $this->folder = $email->folder;

        $this->markAsRead($email);
    }

    private function folderExists(string $folder): bool
    {
        return array_key_exists($folder, self::FOLDERS) || $this->folderCounts()->has($folder);
    }

    /* Reading the store ------------------------------------------------------ */

    private function searchTerm(): string
    {
        return trim($this->search);
    }

    /**
     * Every message in the current folder that survives the filters.
     *
     * The search reaches into `body_text` on purpose — "the thing Sam said
     * about the PO number" is how people look for mail — and that is a scan, so
     * it happens only when something was actually typed.
     */
    private function baseQuery(): Builder
    {
        $term = $this->searchTerm();

        return Email::query()
            ->inFolder($this->folder)
            ->when($this->unreadOnly, fn (Builder $query) => $query->unread())
            ->when($this->starredOnly, fn (Builder $query) => $query->starred())
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($term): void {
                $like = '%'.$term.'%';

                $query->where('subject', 'like', $like)
                    ->orWhere('from_name', 'like', $like)
                    ->orWhere('from_email', 'like', $like)
                    ->orWhere('body_text', 'like', $like);
            }));
    }

    /**
     * One page of the list.
     *
     * Only the columns a row draws, plus a 400-character slice of whichever
     * body exists. `body_html` is never selected as a column — a newsletter is
     * routinely a hundred kilobytes of markup and twenty-five of them is a
     * megabyte of nothing anyone will read.
     */
    private function rows(): CursorPaginator
    {
        return $this->resolvedRows ??= $this->baseQuery()
            ->select([
                'id', 'email_thread_id', 'subject', 'from_name', 'from_email',
                'is_read', 'is_starred', 'has_attachments', 'customer_id',
                'folder', 'received_at', 'created_at',
            ])
            ->selectRaw("substr(coalesce(nullif(body_text, ''), body_html, ''), 1, 400) as list_preview")
            ->selectRaw('coalesce(received_at, created_at) as sort_at')
            ->orderByDesc('sort_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $this->currentCursor());
    }

    /**
     * The cursor the address bar is carrying, if it is one.
     *
     * A cursor is base64 in a query string, so it can arrive edited, truncated
     * or pasted from a chat. An unreadable one is the first page, not a stack
     * trace.
     */
    private function currentCursor(): ?Cursor
    {
        if ($this->cursor === '') {
            return null;
        }

        return rescue(fn (): ?Cursor => Cursor::fromEncoded($this->cursor), null, false);
    }

    /**
     * Total and unread per folder, in one grouped query rather than one count
     * per folder in the sidebar.
     *
     * @return Collection<string, array{total: int, unread: int}>
     */
    private function folderCounts(): Collection
    {
        return $this->resolvedFolders ??= Email::query()
            ->selectRaw('folder, count(*) as total, sum(case when is_read then 0 else 1 end) as unread')
            ->groupBy('folder')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->folder => ['total' => (int) $row->total, 'unread' => (int) $row->unread],
            ]);
    }

    /** The sidebar: the expected folders, then anything else the sync found. */
    private function folders(): array
    {
        $counts = $this->folderCounts();
        $folders = [];

        foreach (self::FOLDERS as $key => $meta) {
            $folders[] = [
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'total' => $counts[$key]['total'] ?? 0,
                'unread' => $counts[$key]['unread'] ?? 0,
            ];
        }

        foreach ($counts as $key => $row) {
            if (array_key_exists($key, self::FOLDERS)) {
                continue;
            }

            $folders[] = [
                'key' => $key,
                'label' => $key,
                'icon' => 'ki-folder',
                'total' => $row['total'],
                'unread' => $row['unread'],
            ];
        }

        return $folders;
    }

    private function unreadTotal(): int
    {
        return (int) $this->folderCounts()->sum('unread');
    }

    /**
     * The customers behind the senders on this page, in one query.
     *
     * `emails.customer_id` is resolved at sync time, so the inbox only has to
     * look up the names — but looking them up per row is twenty-five queries,
     * which is the shape this exists to avoid.
     *
     * @return Collection<int, Customer>
     */
    private function customersOnPage(): Collection
    {
        if ($this->resolvedCustomers !== null) {
            return $this->resolvedCustomers;
        }

        $ids = collect($this->rows()->items())
            ->pluck('customer_id')
            ->filter()
            ->unique()
            ->values();

        return $this->resolvedCustomers = $ids->isEmpty()
            ? collect()
            : Customer::query()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
    }

    /** The open message, or null. Resolved once, including the null case. */
    private function openEmail(): ?Email
    {
        if ($this->openWasResolved) {
            return $this->resolvedOpen;
        }

        $this->openWasResolved = true;

        return $this->resolvedOpen = $this->selected === null
            ? null
            : Email::query()->with(['customer', 'account'])->find($this->selected);
    }

    /**
     * The other messages in the open message's conversation, oldest first.
     *
     * @return Collection<int, Email>
     */
    private function threadMessages(?Email $email): Collection
    {
        if ($email === null || $email->email_thread_id === null) {
            return collect();
        }

        return Email::query()
            ->where('email_thread_id', $email->email_thread_id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get(['id', 'subject', 'from_name', 'from_email', 'is_read', 'received_at']);
    }

    /**
     * The cards this message was turned into.
     *
     * Read back through Core's `links` table rather than a column, and read
     * from the email's end — the same row answers the card's end too, which is
     * what "links both ways" means.
     *
     * @return Collection<int, Card>
     */
    private function cardsFor(?Email $email): Collection
    {
        if ($email === null) {
            return collect();
        }

        $cards = $email->linked($this->cardAlias(), 'converted_to');

        return $cards->isEmpty() ? $cards : $cards->load('list.board');
    }

    /** Board lists a message can be dropped into. Only read when the modal is open. */
    private function boardLists(): Collection
    {
        if (! $this->convertOpen) {
            return collect();
        }

        return $this->resolvedLists ??= BoardList::query()
            ->active()
            ->whereHas('board', fn (Builder $query) => $query->active())
            ->with('board')
            ->orderBy('board_id')
            ->orderBy('position')
            ->get();
    }

    /** What the header can honestly say about the sync. */
    private function accountSummary(): array
    {
        $row = MailAccount::query()
            ->active()
            ->selectRaw('count(*) as total, max(last_synced_at) as last_synced_at')
            ->first();

        $total = (int) ($row->total ?? 0);
        $last = $row?->last_synced_at;

        return [
            'total' => $total,
            'last_synced_at' => $last === null ? null : Carbon::parse($last),
            'failing' => $total === 0 ? 0 : MailAccount::query()->active()->whereNotNull('last_error')->count(),
        ];
    }

    public function with(): array
    {
        $open = $this->openEmail();

        return [
            'folders' => $this->folders(),
            'emails' => $this->rows(),
            'customers' => $this->customersOnPage(),
            'unreadTotal' => $this->unreadTotal(),
            'accounts' => $this->accountSummary(),
            'open' => $open,
            'openAttachments' => $open === null
                ? collect()
                : $open->attachments()->whereNull('content_id')->orderBy('id')->get(),
            'threadMessages' => $this->threadMessages($open),
            'openCards' => $this->cardsFor($open),
            'boardLists' => $this->boardLists(),
        ];
    }

    /* How a row and a message read ------------------------------------------- */

    /**
     * The line under the subject.
     *
     * Built from the 400-character slice the list query took, which may be the
     * start of an HTML body. Tags come off here and the result is printed
     * through `{{ }}` — the page never renders markup a stranger sent.
     */
    public function previewOf(Email $email): string
    {
        $raw = (string) ($email->list_preview ?? '');

        if (trim($raw) === '') {
            return '';
        }

        // A slice can end mid-element, so the style and script guards match an
        // unterminated tail as well as a closed one.
        $raw = preg_replace('#<(script|style)\b[^>]*>.*?(</\1>|$)#is', ' ', $raw) ?? $raw;
        $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $raw) ?? ''), 120);
    }

    /**
     * The message body as text.
     *
     * `body_text` is what the sender's own client already flattened, so it is
     * preferred. An HTML-only message is stripped rather than rendered: the
     * markup came from outside and rendering it would hand a stranger a script
     * tag, a tracking pixel and a stylesheet on an authenticated page.
     */
    public function bodyOf(Email $email): string
    {
        $text = (string) $email->body_text;

        if (trim($text) !== '') {
            return $text;
        }

        $html = (string) $email->body_html;

        if (trim($html) === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** True when the body had to be flattened out of HTML, which is worth saying. */
    public function bodyWasHtml(Email $email): bool
    {
        return trim((string) $email->body_text) === '' && trim((string) $email->body_html) !== '';
    }

    /**
     * A JSON address list as people write addresses.
     *
     * The column is whatever the sync wrote, which may be plain strings or
     * `{name, email}` pairs depending on what the message carried.
     *
     * @return list<string>
     */
    public function addressList(mixed $value): array
    {
        $entries = match (true) {
            is_array($value) => $value,
            is_string($value) => json_decode($value, true) ?: [],
            default => [],
        };

        return collect($entries)
            ->map(function ($entry): string {
                if (is_string($entry)) {
                    return $entry;
                }

                if (! is_array($entry)) {
                    return '';
                }

                $address = $entry['email'] ?? $entry['address'] ?? null;
                $name = $entry['name'] ?? null;

                if ($name && $address) {
                    return $name.' <'.$address.'>';
                }

                return (string) ($address ?? $name ?? '');
            })
            ->filter()
            ->values()
            ->all();
    }

    /** Today gets a clock, this year gets a date, anything older gets a year. */
    public function listTime(Email $email): string
    {
        $when = $email->received_at;

        if ($when === null) {
            return '—';
        }

        return match (true) {
            $when->isToday() => $when->format('H:i'),
            $when->isCurrentYear() => $when->format('j M'),
            default => $when->format('j M Y'),
        };
    }

    public function initialsOf(Email $email): string
    {
        $name = trim((string) ($email->from_name ?: $email->from_email));

        if ($name === '') {
            return '—';
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        return mb_strtoupper(count($parts) >= 2
            ? mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1)
            : mb_substr($name, 0, 2));
    }

    /**
     * Where a converted card can be opened — the card itself, not just its board.
     *
     * The `card` parameter used to be absent here, which meant somebody following
     * "this email became a card" landed on a board of forty cards with nothing
     * indicating which one. `⚡boards` now reads `?card=` in `mount()` and opens
     * that card, refusing an id that is archived, deleted, or on another board —
     * so a stale link degrades to the board it already showed rather than to an
     * error. `Watching::cardUrl()` and `BoardCalendar` build the same shape.
     *
     * The board is still required: the card is resolved against the board named
     * in the same URL, so a `card` without a `board` opens nothing.
     */
    public function cardUrl(Card $card): string
    {
        $slug = $card->list?->board?->slug;

        return $slug === null
            ? route('projects.boards')
            : route('projects.boards', ['board' => $slug, 'card' => $card->getKey()]);
    }

    /**
     * The stable aliases in `links.source_type`, looked up rather than spelled.
     *
     * Both are registered by their own module's service provider, and reading
     * them from the map is what keeps this page correct if either alias is
     * ever renamed in one place.
     */
    private function cardAlias(): string
    {
        return MorphMap::aliasFor(Card::class) ?? 'card';
    }

    /* Islands ----------------------------------------------------------------- */

    /**
     * Redraw the message list.
     *
     * An island nobody names keeps whatever the DOM already had, so an action
     * that changes a row and does not come through here has its new markup
     * computed, sent and thrown away.
     */
    private function refreshList(): void
    {
        $this->resolvedRows = null;
        $this->resolvedFolders = null;
        $this->resolvedCustomers = null;

        $this->renderIsland('list');
    }

    /** Redraw the reading pane. Same rule. */
    private function refreshPane(): void
    {
        $this->resolvedOpen = null;
        $this->openWasResolved = false;

        $this->renderIsland('pane');
    }

    /* Opening a message -------------------------------------------------------- */

    /**
     * Open a message.
     *
     * The pane always changes, so the pane is always named. The list is named
     * whenever the selection actually moves — and that last part is a
     * correction. This comment used to say the bold row and the blue dot were
     * the only thing selecting altered over there, and named the list only when
     * the message had been unread. It is not true: the open row also carries
     * `bg-accent/60` and a 3px primary bar, both driven by
     * `$selected === $email->id` from inside the list island. Opening a message
     * you had already read therefore left the tint and the bar sitting on the
     * row you had just left, for as long as you stayed on the page.
     *
     * What survives of the saving is the case that is genuinely free: clicking
     * the message that is already open — which the Conversation strip inside
     * the pane does on every render — re-sends the pane and nothing else.
     */
    public function selectEmail(int $id): void
    {
        $email = Email::query()->find($id);

        if ($email === null) {
            $this->toastError('That message is gone', 'It was deleted while the page was open.');
            $this->refreshList();

            return;
        }

        $selectionMoved = $this->selected !== $email->id;

        $this->selected = $email->id;
        $this->convertOpen = false;
        $this->cancelReply(silent: true);

        $listChanged = $this->markAsRead($email) || $selectionMoved;

        // The pane island now contains its own `<section>`, so naming it also
        // carries the class that makes the pane appear. Nothing outside an
        // island has to change — see the note at the flex container.
        $this->refreshPane();

        if ($listChanged) {
            $this->refreshList();
        }
    }

    /**
     * Close the reading pane and give its width back to the list.
     *
     * The only path in this component that sets `$selected` back to null after
     * `mount()`. Everything else that moves a message out from under the open
     * one — `archive()`, `moveToTrash()`, the bulk actions, `setFolder()`, a
     * search — deliberately leaves the selection alone, so the pane keeps
     * showing what you were reading even once the list has stopped listing it.
     * That is consistent rather than broken: the pane is open, the list is
     * narrow, and the two agree about which state the page is in. Closing is
     * the one gesture that disagrees with the current markup, so it names both
     * islands — the pane empties, and the row it was tinting has to stop being
     * tinted.
     */
    public function closeMessage(): void
    {
        if ($this->selected === null) {
            return;
        }

        $this->selected = null;
        $this->convertOpen = false;
        $this->cancelReply(silent: true);

        // Closing is the same transition in reverse, and the same two calls
        // cover it: the pane island redraws its own `<section>` back to
        // `hidden`, and the list simply grows into the space without being
        // told to.
        $this->refreshPane();
        $this->refreshList();
    }

    /** Returns true when the row actually changed, so callers know what to redraw. */
    private function markAsRead(Email $email): bool
    {
        if ($email->is_read) {
            return false;
        }

        $this->setRead($email, true);

        return true;
    }

    /**
     * Write the read flag.
     *
     * `forceFill` rather than the model's own `markRead()`: that helper
     * currently returns the result of `save()` against a `static` return type
     * and throws a TypeError on every call. The bug is in
     * `Modules\Mailbox\app\Models\Email`, which this page does not own.
     */
    private function setRead(Email $email, bool $read): void
    {
        $email->forceFill(['is_read' => $read])->save();
    }

    /* Message actions ---------------------------------------------------------- */

    public function toggleStar(int $id): void
    {
        $email = Email::query()->find($id);

        if ($email === null) {
            $this->toastError('That message is gone', 'It was deleted while the page was open.');
            $this->refreshList();

            return;
        }

        $email->forceFill(['is_starred' => ! $email->is_starred])->save();

        $this->refreshList();

        if ($email->id === $this->selected) {
            $this->refreshPane();
        }
    }

    public function markUnread(int $id): void
    {
        $email = Email::query()->find($id);

        if ($email === null) {
            $this->toastError('That message is gone');

            return;
        }

        $this->setRead($email, false);

        $this->refreshList();

        if ($email->id === $this->selected) {
            $this->refreshPane();
        }
    }

    public function markRead(int $id): void
    {
        $email = Email::query()->find($id);

        if ($email === null) {
            $this->toastError('That message is gone');

            return;
        }

        $this->setRead($email, true);

        $this->refreshList();

        if ($email->id === $this->selected) {
            $this->refreshPane();
        }
    }

    public function archive(int $id): void
    {
        $this->moveTo($id, 'Archive', 'Archived', 'It is in the archive, not deleted.');
    }

    public function moveToTrash(int $id): void
    {
        $this->moveTo($id, 'Trash', 'Moved to trash', 'It stays in the trash folder until the mailbox clears it.');
    }

    /** Moving a message is a folder change, which is what it is on the server too. */
    private function moveTo(int $id, string $folder, string $title, string $description): void
    {
        $email = Email::query()->find($id);

        if ($email === null) {
            $this->toastError('That message is gone', 'It was deleted while the page was open.');
            $this->refreshList();

            return;
        }

        if ($email->folder === $folder) {
            $this->toastSuccess('Already there', 'That message is in '.$folder.' already.');

            return;
        }

        $email->forceFill(['folder' => $folder])->save();

        $this->checked = array_values(array_diff($this->checked, [$email->id]));

        $this->refreshList();
        $this->refreshPane();

        $this->toastSuccess($title, $description);
    }

    /* Bulk actions --------------------------------------------------------------- */

    /**
     * Tick or untick one row.
     *
     * Names no island on purpose. The browser has already toggled the checkbox
     * it was told to toggle, and the toolbar that counts them sits outside both
     * islands — re-sending twenty-five rows to change a number would be paying
     * exactly the cost islands exist to avoid.
     */
    public function toggleChecked(int $id): void
    {
        $this->checked = in_array($id, $this->checked, true)
            ? array_values(array_diff($this->checked, [$id]))
            : [...$this->checked, $id];
    }

    /** Ticks every checkbox on the page, so the rows themselves have to come back. */
    public function checkAllOnPage(): void
    {
        $ids = collect($this->rows()->items())->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->checked = count(array_diff($ids, $this->checked)) === 0 ? [] : $ids;

        $this->refreshList();
    }

    public function clearChecked(): void
    {
        $had = $this->checked !== [];

        $this->checked = [];

        if ($had) {
            $this->refreshList();
        }
    }

    public function archiveChecked(): void
    {
        $moved = $this->applyToChecked(fn (Builder $query): int => $query->update(['folder' => 'Archive']));

        if ($moved === 0) {
            return;
        }

        $this->toastSuccess(
            $moved.' '.str('message')->plural($moved).' archived',
            'They are in the archive, not deleted.',
        );
    }

    public function markCheckedRead(): void
    {
        $changed = $this->applyToChecked(fn (Builder $query): int => $query->update(['is_read' => true]));

        if ($changed > 0) {
            $this->toastSuccess($changed.' '.str('message')->plural($changed).' marked read');
        }
    }

    public function markCheckedUnread(): void
    {
        $changed = $this->applyToChecked(fn (Builder $query): int => $query->update(['is_read' => false]));

        if ($changed > 0) {
            $this->toastSuccess($changed.' '.str('message')->plural($changed).' marked unread');
        }
    }

    public function trashChecked(): void
    {
        $moved = $this->applyToChecked(fn (Builder $query): int => $query->update(['folder' => 'Trash']));

        if ($moved > 0) {
            $this->toastSuccess(
                $moved.' '.str('message')->plural($moved).' moved to trash',
                'They stay there until the mailbox clears them.',
            );
        }
    }

    /** One statement for the whole selection, never one per ticked row. */
    private function applyToChecked(callable $apply): int
    {
        if ($this->checked === []) {
            $this->toastError('Nothing is selected', 'Tick the messages you want to act on first.');

            return 0;
        }

        $changed = (int) $apply(Email::query()->whereIn('id', $this->checked));

        $this->checked = [];

        $this->refreshList();
        $this->refreshPane();

        return $changed;
    }

    /* Filters and paging ---------------------------------------------------------- */

    public function setFolder(string $folder): void
    {
        if (! $this->folderExists($folder)) {
            $this->toastError('No such folder', 'The sync has not seen a folder by that name.');

            return;
        }

        $this->folder = $folder;

        // A cursor points into the list it was taken from; carried across a
        // folder change it silently drops rows off the front of the new one.
        $this->cursor = '';
        $this->checked = [];

        $this->refreshList();
    }

    public function toggleUnreadOnly(): void
    {
        $this->unreadOnly = ! $this->unreadOnly;
        $this->cursor = '';

        $this->refreshList();
    }

    public function toggleStarredOnly(): void
    {
        $this->starredOnly = ! $this->starredOnly;
        $this->cursor = '';

        $this->refreshList();
    }

    public function updatedSearch(): void
    {
        $this->cursor = '';

        $this->refreshList();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->cursor = '';

        $this->refreshList();
    }

    /** Step a page. The reading pane is untouched, so it is not named. */
    public function goToCursor(string $cursor = ''): void
    {
        $this->cursor = $cursor;

        $this->refreshList();
    }

    /* Turning a message into a card ------------------------------------------------ */

    /**
     * Open the picker.
     *
     * The modal sits outside both islands, so this names neither. Nothing on
     * screen inside them changes.
     */
    public function openConvert(): void
    {
        if ($this->openEmail() === null) {
            $this->toastError('No message open', 'Pick one from the list first.');

            return;
        }

        $this->convertOpen = true;
    }

    public function dismissPanels(): void
    {
        $this->convertOpen = false;
    }

    /**
     * Create a card from the open message and join the two.
     *
     * The join is a row in Core's `links` table, not a column on either side. A
     * message can end up pointing at a card, an invoice and a customer, and
     * each of those as a nullable foreign key would be one more thing Mailbox
     * has to know about Project. The link reads back from both ends, which is
     * what makes the card's own page able to show where it came from.
     */
    public function convertToCard(int $listId): void
    {
        $email = $this->openEmail();

        if ($email === null) {
            $this->toastError('No message open', 'Pick one from the list first.');

            return;
        }

        $list = BoardList::query()->active()->with('board')->find($listId);

        if ($list === null) {
            $this->toastError('That list is gone', 'It was archived while the page was open.');
            $this->convertOpen = false;

            return;
        }

        $card = app(CardService::class)->append(
            $list,
            Str::limit($email->subject ?: 'Message from '.$email->senderLabel(), 120, ''),
            [
                'description' => $this->cardDescription($email),
                // The sender was already resolved to a customer at sync time,
                // so the card inherits it rather than asking again.
                'customer_id' => $email->customer_id,
            ],
        );

        $email->linkTo($card, 'converted_to');

        $this->convertOpen = false;

        $this->refreshPane();

        $this->toastSuccess(
            'Card created',
            $card->title.' is at the bottom of '.$list->name.' on '.$list->board?->name.'.',
        );
    }

    /** What the card says about where it came from. */
    private function cardDescription(Email $email): string
    {
        $lines = [
            'From '.$email->senderLabel().' <'.$email->from_email.'>',
            'Received '.($email->received_at?->format('j F Y \a\t H:i') ?? 'at an unrecorded time'),
            '',
            Str::limit($this->bodyOf($email), 1200),
        ];

        return implode("\n", $lines);
    }

    /* The composer ------------------------------------------------------------------ */

    public function startReply(string $mode): void
    {
        $email = $this->openEmail();

        if ($email === null) {
            $this->toastError('No message open', 'Pick one from the list before replying.');

            return;
        }

        $this->replyMode = in_array($mode, ['reply', 'replyAll', 'forward'], true) ? $mode : 'reply';
        $this->replyTo = $mode === 'forward' ? '' : (string) $email->from_email;
        $this->replyBody = '';

        // The composer names its own mode in its header, so opening it needs no
        // second announcement. It lives in the pane, which does have to redraw.
        $this->refreshPane();
    }

    public function cancelReply(bool $silent = false): void
    {
        $wasOpen = $this->replyMode !== null;

        $this->replyMode = null;
        $this->replyTo = '';
        $this->replyBody = '';

        if ($silent) {
            return;
        }

        if ($wasOpen) {
            $this->refreshPane();
        }
    }

    /**
     * Sending genuinely does not exist yet.
     *
     * Delivery providers, quotas and the router that picks between them are
     * phase 5, and there is no honest way to fake them: a message that says it
     * went out and did not is worse than a button that says when it will work.
     */
    public function send(): void
    {
        $this->toastInfo(
            'Sending arrives with the next phase',
            'Replies are written here now; they go out once the delivery providers land.',
        );
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Page header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Mail</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                @if ($accounts['total'] === 0)
                    No mailbox is connected yet — messages appear here once an account is syncing.
                @else
                    {{ $unreadTotal }} unread across
                    {{ $accounts['total'] }} {{ \Illuminate\Support\Str::plural('mailbox', $accounts['total']) }}.
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if ($accounts['total'] > 0)
                <span class="text-xs text-muted-foreground">
                    <i class="ki-filled ki-arrows-circle text-sm"></i>
                    @if ($accounts['last_synced_at'])
                        Last checked {{ $accounts['last_synced_at']->diffForHumans() }}
                    @else
                        Not checked yet
                    @endif
                </span>
            @endif
            <button class="kt-btn kt-btn-primary gap-2" wire:click="$dispatch('open-compose')">
                <i class="ki-filled ki-pencil"></i> Compose
            </button>
        </div>
    </div>

    {{--
        The two-pane grid, and where its widths are decided.

        With nothing open there is no reading pane: the list takes the whole
        region beside the folder rail. Opening a message brings the pane back at
        the width it always had and narrows the list to 5/12 of that region.

        ── Why a conditional `col-span` out here actually works ──

        Both `<section>` elements sit outside both islands, which looks like it
        cannot update: naming an island is supposed to restrict the response to
        that island. It is not what Livewire 4 does. `renderIsland()` — see
        vendor/livewire/livewire/src/Features/SupportIslands/HandlesIslands.php —
        only *appends* to an `islandFragments` effect. The component still
        renders in full and `HandleComponents::render()` still adds the `html`
        effect. What the islands change is their own bodies: a directive whose
        island nobody named returns an empty `mode=skip` fragment and the client
        morph walks that range without touching the DOM inside it.

        Measured on 4 August 2026, not assumed. After `selectEmail()`, which
        names only the pane, the `html` effect was 18,883 bytes and contained
        both `<section>` class lists, the page header and the folder rail, and
        none of the twenty-five rows — four `mode=skip` markers and one island
        fragment. The page already leaned on this before today: the Unread and
        Starred filter buttons and the whole bulk-action toolbar live outside
        both islands and change appearance on actions that name the list alone.

        So this needs no special-cased full render on the selection transition,
        and no restructuring of which element carries the columns.

        🔴 What that does NOT buy, and what a future writer has to keep doing.
        The width follows `$open` on every render, so an action that changes the
        selection cannot get the *width* wrong. It can very easily get the
        *contents* wrong, because those are inside the islands: change
        `$selected` without calling `refreshPane()` and the previous message
        keeps showing inside a correctly-sized section, and without calling
        `refreshList()` the tint and the 3px bar stay on the row you left. Those
        are the two rules `refreshList()` and `refreshPane()` already state —
        the widths do not add a third.

        🔴 The pane's `<section>` is always emitted, `hidden` rather than
        absent. `renderIslandDirective()` calls `storeIsland()` only while
        `islandIsMounting()`, so an `@island` wrapped in an `@if` that was false
        on first paint is never registered at all, and `renderIsland('pane')`
        then finds no token and sends nothing for the rest of the page's life.
        Keeping the directive on every render costs one `display:none` element.

        The columns are a 220px rail plus a nested twelve, rather than one
        twelve-column grid, because `lg:col-span-10` is not in either compiled
        stylesheet — kargah.css carries `lg:col-span-2` through `-9` and nothing
        wider, and a class that is not in the sheet does nothing at all. 5 + 7
        of a nested twelve lands within 20px of the old 4 + 6, and the rail
        stops growing to 300px on a wide screen.
    --}}
    <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-5 items-start">

        {{-- ────────────────────────────── Folders ────────────────────────────── --}}
        <aside class="min-w-0">
            <div class="kt-card">
                <div class="kt-card-content p-2 flex flex-col gap-0.5">
                    @foreach ($folders as $f)
                        @php($on = $folder === $f['key'])
                        <button wire:click="setFolder('{{ $f['key'] }}')" wire:key="folder-{{ $f['key'] }}"
                                class="flex items-center justify-between gap-2 w-full px-3 py-2 rounded-lg text-start transition-colors {{ $on ? 'bg-accent/60' : 'hover:bg-accent/40' }}">
                            <span class="flex items-center gap-2.5 min-w-0">
                                <i class="ki-filled {{ $f['icon'] }} text-base shrink-0 {{ $on ? 'text-primary' : 'text-muted-foreground' }}"></i>
                                <span class="truncate text-sm {{ $on ? 'text-primary font-semibold' : 'text-foreground' }}">{{ $f['label'] }}</span>
                            </span>
                            @if ($f['unread'] > 0)
                                <span class="text-xs font-semibold shrink-0 {{ $on ? 'text-primary' : 'text-muted-foreground' }}">{{ $f['unread'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="kt-card mt-5">
                <div class="kt-card-content p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-3">Sync</div>
                    @if ($accounts['total'] === 0)
                        <p class="text-xs text-secondary-foreground">
                            No mailbox is connected. The sync runs from cron once an account exists.
                        </p>
                    @else
                        <p class="text-xs text-secondary-foreground">
                            {{ $accounts['total'] }} {{ \Illuminate\Support\Str::plural('mailbox', $accounts['total']) }}
                            checked on a schedule. This page reads the local store, never IMAP.
                        </p>
                        @if ($accounts['failing'] > 0)
                            <p class="text-xs text-destructive mt-2">
                                <i class="ki-filled ki-shield-cross text-sm"></i>
                                {{ $accounts['failing'] }} {{ \Illuminate\Support\Str::plural('mailbox', $accounts['failing']) }}
                                failed on the last run.
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </aside>

        {{--
            The list and the pane share the rail's neighbouring track. Their own
            indentation is left alone deliberately: re-indenting four hundred
            unchanged lines to show one level of nesting is a worse diff than
            this comment.
        --}}
        {{--
            🔴 Flex, not a twelve-column grid, and the reason is mechanical.

            With a grid, "the list is wide when nothing is open" has to be a
            conditional `col-span` on the list's own element — and that element
            sits outside both islands. **Naming an island suppresses the
            full-component `html` effect**, so a class outside one never reaches
            the browser on an island update. Measured in Chrome rather than read
            out of Livewire's source, which suggests the opposite:

                effect keys: returns, islandFragments
                html effect present: false

            The first attempt at this page put a conditional `col-span` on both
            sections. It rendered correctly on a page load and then never moved
            again: clicking a message tinted the row and the pane stayed hidden,
            with the whole suite green and no error anywhere.

            Under flex the list needs no conditional at all — it simply grows
            into whatever the pane is not using. That leaves exactly one
            conditional class, on the pane, and the pane's `<section>` is inside
            the pane island, so `refreshPane()` carries it. Nothing outside an
            island has to change for the layout to move.
        --}}
        <div class="flex flex-col lg:flex-row gap-5 items-start min-w-0 w-full">

        {{-- ──────────────────────────── Message list ─────────────────────────── --}}
        <section class="w-full min-w-0 grow">
            <div class="kt-card">

                {{--
                    The search box and the filter toolbar sit outside the island
                    deliberately: the input carries the focus while a search is
                    being typed, and there is no reason to morph the element
                    someone is typing into.
                --}}
                <div class="kt-card-header flex-col items-stretch gap-3 py-3">
                    <div class="kt-input w-full">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input type="text" placeholder="Search subject, sender or body…" aria-label="Search mail"
                               wire:model.live.debounce.300ms="search">
                        <i class="ki-filled ki-loading animate-spin text-muted-foreground" wire:loading wire:target="search"></i>
                        @if ($search !== '')
                            <button wire:click="clearSearch" class="kt-btn kt-btn-icon kt-btn-ghost size-6" aria-label="Clear search">
                                <i class="ki-filled ki-cross text-xs"></i>
                            </button>
                        @endif
                    </div>

                    {{-- Bulk actions replace the filters once anything is ticked. --}}
                    <div class="flex items-center justify-between gap-2">
                        @if (count($checked))
                            <div class="flex items-center gap-1">
                                <button wire:click="clearChecked" class="kt-btn kt-btn-icon kt-btn-ghost size-7" aria-label="Clear selection">
                                    <i class="ki-filled ki-cross text-sm"></i>
                                </button>
                                <span class="text-xs font-medium text-mono">{{ count($checked) }} selected</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="archiveChecked" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                        title="Archive selected" aria-label="Archive selected">
                                    <i class="ki-filled ki-archive text-sm"></i>
                                </button>
                                <button wire:click="markCheckedRead" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                        title="Mark selected read" aria-label="Mark selected read">
                                    <i class="ki-filled ki-eye text-sm"></i>
                                </button>
                                <button wire:click="markCheckedUnread" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                        title="Mark selected unread" aria-label="Mark selected unread">
                                    <i class="ki-filled ki-sms text-sm"></i>
                                </button>
                                <button wire:click="trashChecked" class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive"
                                        title="Move selected to trash" aria-label="Move selected to trash">
                                    <i class="ki-filled ki-trash text-sm"></i>
                                </button>
                            </div>
                        @else
                            <button wire:click="checkAllOnPage" class="kt-btn kt-btn-sm kt-btn-ghost gap-2">
                                <i class="ki-filled ki-check-squared text-sm"></i> Select page
                            </button>
                            <div class="flex items-center gap-1">
                                <button wire:click="toggleUnreadOnly"
                                        class="kt-btn kt-btn-sm {{ $unreadOnly ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                                    Unread
                                </button>
                                <button wire:click="toggleStarredOnly"
                                        class="kt-btn kt-btn-sm gap-1.5 {{ $starredOnly ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                                    <i class="ki-filled ki-star text-sm"></i> Starred
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

                {{--
                    Island one: the message rows.

                    Every row carries `content-visibility: auto`, which lets the
                    browser skip layout and paint for the ones scrolled out of
                    view. It is an inline style rather than a utility because
                    neither stylesheet has one, and inventing a design token for
                    a single rule is worse than saying it here.
                --}}
                @island(name: 'list')
                <div>
                    <div class="kt-card-content p-0 divide-y divide-border max-h-[640px] overflow-y-auto kt-scrollable-y">
                        @forelse ($emails as $email)
                            @php
                                $isOpen = $selected === $email->id;
                                $customer = $customers[$email->customer_id] ?? null;
                            @endphp
                            <div wire:key="email-{{ $email->id }}"
                                 style="content-visibility: auto; contain-intrinsic-size: auto 96px;"
                                 class="relative flex items-start gap-2.5 px-3 py-3 transition-colors {{ $isOpen ? 'bg-accent/60' : 'hover:bg-accent/30' }}">

                                @if ($isOpen)
                                    <span class="absolute inset-y-0 start-0 w-[3px] bg-primary"></span>
                                @endif

                                <div class="flex flex-col items-center gap-2 pt-0.5 shrink-0">
                                    <input type="checkbox"
                                           class="kt-checkbox kt-checkbox-sm"
                                           wire:click="toggleChecked({{ $email->id }})"
                                           @checked(in_array($email->id, $checked, true))
                                           aria-label="Select message">
                                    <button wire:click="toggleStar({{ $email->id }})"
                                            title="{{ $email->is_starred ? 'Remove star' : 'Star this message' }}"
                                            aria-label="{{ $email->is_starred ? 'Remove star' : 'Star this message' }}">
                                        <i class="ki-filled ki-star text-sm {{ $email->is_starred ? 'text-warning' : 'text-muted-foreground/40 hover:text-muted-foreground' }}"></i>
                                    </button>
                                </div>

                                <button wire:click="selectEmail({{ $email->id }})" class="min-w-0 grow text-start">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="flex items-center gap-2 min-w-0">
                                            @unless ($email->is_read)
                                                <span class="size-1.5 rounded-full bg-primary shrink-0"></span>
                                            @endunless
                                            <span class="text-sm truncate {{ $email->is_read ? 'text-secondary-foreground' : 'font-semibold text-mono' }}">
                                                {{ $email->senderLabel() }}
                                            </span>
                                        </span>
                                        <span class="text-[11px] text-muted-foreground shrink-0">{{ $this->listTime($email) }}</span>
                                    </div>

                                    <div class="text-sm truncate mt-0.5 {{ $email->is_read ? 'text-secondary-foreground' : 'text-mono font-medium' }}">
                                        {{ $email->subject ?: 'No subject' }}
                                    </div>

                                    <div class="text-xs text-muted-foreground truncate mt-0.5">
                                        {{ $this->previewOf($email) ?: '—' }}
                                    </div>

                                    @if ($customer || $email->has_attachments)
                                        <div class="flex items-center gap-1.5 mt-1.5">
                                            @if ($customer)
                                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground inline-flex items-center gap-1">
                                                    <i class="ki-filled ki-profile-circle text-[11px]"></i>{{ $customer->name }}
                                                </span>
                                            @endif
                                            @if ($email->has_attachments)
                                                <i class="ki-filled ki-paper-clip text-xs text-muted-foreground" title="Has attachments"></i>
                                            @endif
                                        </div>
                                    @endif
                                </button>
                            </div>
                        @empty
                            <div class="flex flex-col items-center py-16 text-center px-6">
                                <i class="ki-filled ki-sms text-4xl text-muted-foreground mb-3"></i>
                                <p class="text-sm font-medium text-mono">
                                    @if ($search !== '')
                                        Nothing in this folder matches “{{ $search }}”.
                                    @elseif ($unreadOnly || $starredOnly)
                                        Nothing here matches those filters.
                                    @else
                                        This folder is empty.
                                    @endif
                                </p>
                                {{--
                                    The primary action an empty state owes the
                                    reader. It used to sit in the reading pane,
                                    which no longer exists when nothing is open;
                                    the header keeps its own Compose either way.
                                --}}
                                @if ($search !== '')
                                    <button wire:click="clearSearch" class="kt-btn kt-btn-sm kt-btn-ghost mt-3">Clear search</button>
                                @elseif (! $unreadOnly && ! $starredOnly)
                                    <button class="kt-btn kt-btn-sm kt-btn-primary gap-2 mt-3" wire:click="$dispatch('open-compose')">
                                        <i class="ki-filled ki-pencil"></i> Compose
                                    </button>
                                @endif
                            </div>
                        @endforelse
                    </div>

                    @if ($emails->hasPages())
                        <div class="kt-card-footer flex items-center justify-between gap-3">
                            <span class="text-xs text-muted-foreground">
                                Newest first, {{ $emails->count() }} on this page.
                            </span>
                            <div class="flex items-center gap-2">
                                <button wire:click="goToCursor('{{ $emails->previousCursor()?->encode() }}')"
                                        wire:loading.attr="disabled" wire:target="goToCursor"
                                        @disabled($emails->onFirstPage())
                                        class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                                    <i class="ki-filled ki-black-left text-xs"></i> Newer
                                </button>
                                <button wire:click="goToCursor('{{ $emails->nextCursor()?->encode() }}')"
                                        wire:loading.attr="disabled" wire:target="goToCursor"
                                        @disabled(! $emails->hasMorePages())
                                        class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                                    Older <i class="ki-filled ki-arrow-right text-xs"></i>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
                @endisland
            </div>
        </section>

        {{--
            Reading pane. The island opens **before** the `<section>` on
            purpose: the section carries the only class that changes with the
            selection, so it has to be inside the island that redraws it. See
            the note on the flex container above for what happens when it is
            not.

            The section is `hidden` rather than absent, and that is also
            deliberate. `renderIslandDirective()` registers an island only while
            the component is mounting, so an `@island` behind an `@if` that is
            false on first paint is never registered at all — and
            `renderIsland('pane')` would then find no token and send nothing,
            for the rest of the page's life.
        --}}
        @island(name: 'pane')
        <section class="{{ $open ? 'w-full min-w-0 grow' : 'hidden' }}">
            @if ($open)
                {{--
                    `min-h-[700px]` outlives the empty state it was added for.
                    It used to stop the page jumping between "no message open"
                    and a message; that state is gone. What it stops now is the
                    card resizing every time you move between a two-line reply
                    and a long thread, beside a list that is 640px tall either
                    way. It is unconditional because there is no `lg:min-h-*` in
                    either compiled stylesheet, so below `lg` a short message is
                    a 700px card — worth a look on a phone, but it was already
                    that before today and narrowing it is not this change.
                --}}
                <div class="kt-card min-h-[700px] flex flex-col">
                    {{-- Message header --}}
                    <div class="border-b border-border px-6 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold text-mono leading-snug">{{ $open->subject ?: 'No subject' }}</h2>
                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                    @if ($open->customer)
                                        <a href="{{ route('accounting.client-show', ['client' => $open->customer->id]) }}" wire:navigate
                                           class="text-xs inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-primary/10 text-primary hover:bg-primary/20">
                                            <i class="ki-filled ki-profile-circle text-[13px]"></i>{{ $open->customer->name }}
                                        </a>
                                    @endif
                                    <span class="text-xs text-muted-foreground">{{ $open->folder }}</span>
                                    @if ($threadMessages->count() > 1)
                                        <span class="text-xs text-muted-foreground">·</span>
                                        <span class="text-xs text-muted-foreground">
                                            {{ $threadMessages->count() }} {{ \Illuminate\Support\Str::plural('message', $threadMessages->count()) }} in this conversation
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-0.5 shrink-0">
                                <button wire:click="toggleStar({{ $open->id }})" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                                        title="{{ $open->is_starred ? 'Remove star' : 'Star this message' }}"
                                        aria-label="{{ $open->is_starred ? 'Remove star' : 'Star this message' }}">
                                    <i class="ki-filled ki-star text-base {{ $open->is_starred ? 'text-warning' : '' }}"></i>
                                </button>
                                <button wire:click="openConvert" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                                        title="Turn into a card" aria-label="Turn into a card">
                                    <i class="ki-filled ki-check-squared text-base"></i>
                                </button>
                                <button wire:click="archive({{ $open->id }})" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                                        title="Archive" aria-label="Archive">
                                    <i class="ki-filled ki-archive text-base"></i>
                                </button>
                                <button wire:click="markUnread({{ $open->id }})" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                                        title="Mark unread" aria-label="Mark unread">
                                    <i class="ki-filled ki-sms text-base"></i>
                                </button>
                                <button wire:click="moveToTrash({{ $open->id }})" class="kt-btn kt-btn-icon kt-btn-ghost size-8 text-destructive"
                                        title="Move to trash" aria-label="Move to trash">
                                    <i class="ki-filled ki-trash text-base"></i>
                                </button>
                                {{--
                                    Closing is the only way back to the wide list.
                                    Nothing else on the page ever sets `$selected`
                                    to null, so without this the list can only be
                                    full width on the first paint.
                                --}}
                                <button wire:click="closeMessage" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                                        title="Close this message" aria-label="Close this message">
                                    <i class="ki-filled ki-cross text-base"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Message body --}}
                    <div class="grow overflow-y-auto kt-scrollable-y">
                        <div class="flex items-start gap-3 px-6 py-4">
                            <span class="inline-flex items-center justify-center size-9 rounded-full text-sm font-semibold shrink-0 bg-primary/10 text-primary">
                                {{ $this->initialsOf($open) }}
                            </span>
                            <div class="min-w-0 grow">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold text-mono truncate">{{ $open->senderLabel() }}</span>
                                    <span class="text-xs text-muted-foreground shrink-0">
                                        {{ $open->received_at?->format('j M Y \a\t H:i') ?? 'Date not recorded' }}
                                    </span>
                                </div>
                                <div class="text-xs text-muted-foreground truncate mt-0.5">{{ $open->from_email }}</div>

                                @php
                                    $to = $this->addressList($open->to);
                                    $cc = $this->addressList($open->cc);
                                @endphp

                                <div class="text-xs text-muted-foreground mt-1">
                                    <span class="text-secondary-foreground">To</span>
                                    {{ $to === [] ? '—' : implode(', ', $to) }}
                                </div>
                                @if ($cc !== [])
                                    <div class="text-xs text-muted-foreground mt-0.5">
                                        <span class="text-secondary-foreground">Cc</span> {{ implode(', ', $cc) }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="px-6 pb-5 ps-[76px]">
                            {{--
                                An escaped echo, never an unescaped one. The body came
                                from outside; rendering its markup would hand a stranger
                                a script tag on an authenticated page.
                            --}}
                            <div class="text-sm leading-relaxed text-secondary-foreground whitespace-pre-line">{{ $this->bodyOf($open) ?: 'This message has no body.' }}</div>

                            @if ($this->bodyWasHtml($open))
                                <p class="text-[11px] text-muted-foreground mt-3">
                                    <i class="ki-filled ki-information-2 text-xs"></i>
                                    This message arrived as HTML. It is shown as text, because remote markup is never rendered here.
                                </p>
                            @endif

                            {{-- Attachments: the metadata, honestly labelled as only that. --}}
                            @if ($openAttachments->isNotEmpty())
                                <div class="mt-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-2">
                                        {{ $openAttachments->count() }} {{ \Illuminate\Support\Str::plural('attachment', $openAttachments->count()) }}
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($openAttachments as $attachment)
                                            <div wire:key="attachment-{{ $attachment->id }}"
                                                 class="flex items-center gap-2.5 px-3 py-2 rounded-lg border border-border">
                                                <i class="ki-filled ki-document text-lg text-muted-foreground"></i>
                                                <span class="min-w-0">
                                                    <span class="block text-xs font-medium text-mono truncate max-w-[220px]">{{ $attachment->filename }}</span>
                                                    <span class="block text-[11px] text-muted-foreground">
                                                        {{ $attachment->formattedSize() ?? 'Size not recorded' }}
                                                    </span>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                    <p class="text-[11px] text-muted-foreground mt-2">
                                        Names and sizes only. The files themselves are stored by the Data module, which arrives in phase 6.
                                    </p>
                                </div>
                            @endif

                            {{-- Cards this message became, read back through the link table. --}}
                            @if ($openCards->isNotEmpty())
                                <div class="mt-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-2">
                                        On the board
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        @foreach ($openCards as $card)
                                            <a href="{{ $this->cardUrl($card) }}" wire:navigate wire:key="card-{{ $card->id }}"
                                               class="flex items-center gap-2 px-3 py-2 rounded-lg border border-border hover:border-primary/40 transition-colors">
                                                <i class="ki-filled ki-check-squared text-sm text-primary"></i>
                                                <span class="text-xs text-mono truncate">{{ $card->title }}</span>
                                                <span class="text-[11px] text-muted-foreground ms-auto shrink-0">
                                                    {{ $card->list?->name ?? 'Archived list' }}
                                                </span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- The rest of the conversation. --}}
                        @if ($threadMessages->count() > 1)
                            <div class="border-t border-border px-6 py-4">
                                <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-2">
                                    Conversation
                                </div>
                                <div class="flex flex-col gap-1">
                                    @foreach ($threadMessages as $message)
                                        <button wire:click="selectEmail({{ $message->id }})" wire:key="thread-{{ $message->id }}"
                                                class="flex items-center gap-2 px-2 py-1.5 rounded-md text-start hover:bg-accent/40 transition-colors {{ $message->id === $open->id ? 'bg-accent/60' : '' }}">
                                            <span class="text-xs truncate {{ $message->is_read ? 'text-secondary-foreground' : 'text-mono font-semibold' }}">
                                                {{ $message->senderLabel() }}
                                            </span>
                                            <span class="text-[11px] text-muted-foreground truncate">{{ $message->subject ?: 'No subject' }}</span>
                                            <span class="text-[11px] text-muted-foreground ms-auto shrink-0">{{ $this->listTime($message) }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Action bar, or the composer once you choose to write --}}
                    <div class="border-t border-border p-4 shrink-0">
                        @if ($replyMode === null)
                            <div class="flex flex-wrap items-center gap-2">
                                <button wire:click="startReply('reply')" class="kt-btn kt-btn-outline gap-2">
                                    <i class="ki-filled ki-arrow-left"></i> Reply
                                </button>
                                <button wire:click="startReply('replyAll')" class="kt-btn kt-btn-outline gap-2">
                                    <i class="ki-filled ki-arrows-loop"></i> Reply all
                                </button>
                                <button wire:click="startReply('forward')" class="kt-btn kt-btn-outline gap-2">
                                    <i class="ki-filled ki-arrow-right"></i> Forward
                                </button>
                                <span class="text-[11px] text-muted-foreground ms-auto">
                                    Sending arrives with the next phase
                                </span>
                            </div>
                        @else
                            <div class="flex flex-col gap-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold text-mono">
                                        @if ($replyMode === 'forward') Forward
                                        @elseif ($replyMode === 'replyAll') Reply to everyone
                                        @else Reply
                                        @endif
                                    </span>
                                    <button wire:click="cancelReply" class="kt-btn kt-btn-icon kt-btn-ghost size-7" aria-label="Close composer">
                                        <i class="ki-filled ki-cross text-sm"></i>
                                    </button>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-muted-foreground w-8 shrink-0">To</span>
                                    <input type="text" class="kt-input" placeholder="name@example.com" wire:model="replyTo"
                                           aria-label="Reply recipient">
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-muted-foreground w-8 shrink-0">Re</span>
                                    <span class="text-mono truncate">{{ $open->subject ?: 'No subject' }}</span>
                                </div>

                                <textarea class="kt-textarea min-h-[140px] text-sm"
                                          placeholder="Write your message…"
                                          aria-label="Reply body"
                                          wire:model="replyBody"></textarea>

                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-[11px] text-muted-foreground">
                                        Replies go out once the delivery providers land in the next phase.
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <button wire:click="cancelReply" class="kt-btn kt-btn-ghost">Discard</button>
                                        <button wire:click="send" class="kt-btn kt-btn-primary gap-2" wire:loading.attr="disabled" wire:target="send">
                                            <i class="ki-filled ki-paper-plane"></i>
                                            <span wire:loading.remove wire:target="send">Send</span>
                                            <span wire:loading wire:target="send">Sending…</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </section>
        @endisland

        </div>
    </div>

    {{--
        Turning a message into a card.

        Outside both islands on purpose: opening it changes nothing the list or
        the pane shows, so opening it names no island and re-sends no rows.
        Driven from component state rather than KTUI, because the morph strips
        an `open` class KTUI added — see docs/frontend-conventions.md.
    --}}
    <div class="kt-modal {{ $convertOpen ? 'open' : '' }}" role="dialog" aria-modal="true" aria-label="Turn this message into a card">
        <div class="kt-modal-content max-w-[520px]">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title">Turn into a card</h3>
                <button wire:click="dismissPanels" class="kt-btn kt-btn-icon kt-btn-ghost" aria-label="Close">
                    <i class="ki-filled ki-cross"></i>
                </button>
            </div>
            <div class="kt-modal-body flex flex-col gap-3">
                @if ($open)
                    <p class="text-sm text-secondary-foreground">
                        A card will be created from “{{ $open->subject ?: 'No subject' }}”, and the two will point at
                        each other from then on.
                    </p>
                @endif

                <div class="flex flex-col gap-1.5 max-h-[320px] overflow-y-auto kt-scrollable-y">
                    @forelse ($boardLists as $list)
                        <button wire:click="convertToCard({{ $list->id }})" wire:key="target-{{ $list->id }}"
                                wire:loading.attr="disabled" wire:target="convertToCard"
                                class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg border border-border text-start hover:border-primary/40 transition-colors">
                            <span class="text-sm text-mono truncate">{{ $list->name }}</span>
                            <span class="text-xs text-muted-foreground shrink-0">{{ $list->board?->name }}</span>
                        </button>
                    @empty
                        <p class="text-sm text-secondary-foreground text-center py-6">
                            There are no lists to put a card in yet. Make a board first.
                        </p>
                    @endforelse
                </div>
            </div>
            <div class="kt-modal-footer">
                <button wire:click="dismissPanels" class="kt-btn kt-btn-ghost">Cancel</button>
            </div>
        </div>
    </div>

    {{-- Full composer. Opened by the Compose buttons via the `open-compose` event. --}}
    <livewire:mailbox::compose />
</div>
