<?php

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Spatie\Activitylog\Models\Activity;

/**
 * One board's history, newest first.
 *
 * **The same table the card drawer's history comes from, scoped to a board.**
 * `activity_log` rows carry a subject, not a board, so the scope is written as
 * three alternatives in one `where` group: the board row itself, any list of
 * that board, and any card placed in one of those lists. The list and card
 * sides are subqueries rather than `pluck()`ed id arrays — a board with four
 * hundred cards would otherwise send four hundred bindings on every page.
 *
 * Deleted lists are included (`withTrashed()`) so "deleted the list Backlog"
 * still appears after the list it names has gone. Deleted *cards* keep their
 * rows too, because every delete in this module is a soft delete and the
 * placements survive it — except when a whole list or board is deleted, which
 * hard-deletes the placements and therefore cuts those cards' history off from
 * this feed. That is a real gap, and the alternative — writing `board_id` into
 * every card activity's properties — is a change to producers this page does
 * not own.
 *
 * **Every sentence is named, none is a `description` dump.** `description` is
 * a fragment written for a card-level feed ("moved from A to B") with no
 * subject in it, so read on a board feed it would say who did something and
 * not what to. `sentence()` composes the whole sentence per event name, and a
 * subject that has since been deleted falls back to whatever the properties
 * recorded rather than throwing — `subject_returns_soft_deleted_models` is
 * false in config, so `$activity->subject` really is null for those.
 *
 * **Cursor pagination on the primary key.** The feed only grows, and offset
 * pagination scans and discards every row it skips to reach a later page. The
 * id is unique, never null, and monotonic with `created_at`, which is what a
 * cursor needs — the same reasoning ⚡table.blade.php documents for placements.
 */
new
#[Title('Activity — Kargah')]
class extends Component
{
    /** Enough to fill a screen without making the properties decode expensive. */
    private const PER_PAGE = 30;

    /** The log names this page will filter on, in the order the strip shows them. */
    private const LOG_NAMES = ['card', 'list', 'board'];

    #[Url(as: 'board')]
    public string $activeBoard = '';

    /** Empty means every log name, and stays out of the URL. */
    #[Url(as: 'log')]
    public string $logName = '';

    /** The encoded cursor. Empty means the first page. */
    #[Url]
    public string $cursor = '';

    public bool $boardPickerOpen = false;

    /** Per-request memos; see ⚡boards.blade.php for why these are private. */
    private ?Board $resolvedBoard = null;

    private ?Collection $resolvedBoards = null;

    private ?CursorPaginator $resolvedFeed = null;

    public function mount(): void
    {
        $this->activeBoard = $this->resolveBoard($this->activeBoard);

        if (! in_array($this->logName, self::LOG_NAMES, true)) {
            $this->logName = '';
        }
    }

    private function resolveBoard(string $slug): string
    {
        $slugs = $this->allBoards()->pluck('slug');

        return $slugs->contains($slug) ? $slug : (string) $slugs->first();
    }

    private function allBoards(): Collection
    {
        return $this->resolvedBoards ??= Board::query()->active()->orderBy('position')->orderBy('name')->get();
    }

    private function board(): ?Board
    {
        return $this->resolvedBoard ??= $this->allBoards()->firstWhere('slug', $this->activeBoard);
    }

    public function boardName(): string
    {
        return $this->board()?->name ?? 'Board';
    }

    /* The query ---------------------------------------------------------------- */

    /**
     * Every list this board has ever had, trashed ones included — a fresh
     * builder each call, because the same instance is embedded in two places.
     */
    private function listIdsQuery(int $boardId): Builder
    {
        return BoardList::query()->withTrashed()->select('id')->where('board_id', $boardId);
    }

    private function feedQuery(): Builder
    {
        $board = $this->board();

        if ($board === null) {
            return Activity::query()->whereRaw('1 = 0');
        }

        // Morph *aliases*, never class names: `subject_type` holds the alias
        // registered in ProjectServiceProvider. `getMorphClass()` is what
        // resolves one from the other — see Modules\Core\Support\MorphMap.
        $boardType = $board->getMorphClass();
        $listType = (new BoardList)->getMorphClass();
        $cardType = (new Card)->getMorphClass();

        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->where(function (Builder $scope) use ($board, $boardType, $listType, $cardType): void {
                $scope
                    ->where(fn (Builder $q) => $q
                        ->where('subject_type', $boardType)
                        ->where('subject_id', $board->id))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', $listType)
                        ->whereIn('subject_id', $this->listIdsQuery($board->id)))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', $cardType)
                        ->whereIn('subject_id', CardPlacement::query()
                            ->select('card_id')
                            ->whereIn('board_list_id', $this->listIdsQuery($board->id))));
            });

        if (in_array($this->logName, self::LOG_NAMES, true)) {
            $query->where('log_name', $this->logName);
        }

        return $query;
    }

    private function feed(): CursorPaginator
    {
        return $this->resolvedFeed ??= $this->feedQuery()
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $this->currentCursor());
    }

    /**
     * The cursor the address bar is carrying, if it is one. An unreadable
     * cursor is the first page, not a crash — same as the table view.
     */
    private function currentCursor(): ?Cursor
    {
        if ($this->cursor === '') {
            return null;
        }

        return rescue(fn (): ?Cursor => Cursor::fromEncoded($this->cursor), null, false);
    }

    public function with(): array
    {
        return [
            'boards' => $this->allBoards(),
            'feed' => $this->feed(),
            'entries' => $this->entries(),
            'total' => $this->feedQuery()->count(),
        ];
    }

    /* Actions ------------------------------------------------------------------ */

    public function toggleBoardPicker(): void
    {
        $this->boardPickerOpen = ! $this->boardPickerOpen;
    }

    /** Click-away. Dismissing a panel is not worth a toast. */
    public function closeBoardPicker(): void
    {
        $this->boardPickerOpen = false;
    }

    public function selectBoard(string $slug): void
    {
        $this->activeBoard = $this->resolveBoard($slug);
        $this->resolvedBoard = null;
        $this->cursor = '';
        $this->boardPickerOpen = false;
        $this->refreshFeed();
    }

    public function filterByLog(string $name = ''): void
    {
        $this->logName = in_array($name, self::LOG_NAMES, true) ? $name : '';
        $this->cursor = '';
        $this->refreshFeed();
    }

    public function goToCursor(string $cursor = ''): void
    {
        $this->cursor = $cursor;
        $this->refreshFeed();
    }

    /**
     * Anything that changes what the feed shows has to name the island, or the
     * new markup is computed, sent back with `mode=skip`, and thrown away.
     */
    private function refreshFeed(): void
    {
        $this->resolvedFeed = null;
        $this->renderIsland('feed');
    }

    /* Rendering ---------------------------------------------------------------- */

    /**
     * The page's rows, already reduced to strings. The island inherits `with()`
     * data and nothing else, so the sentence work happens here rather than in
     * the template.
     *
     * @return list<array<string, string>>
     */
    private function entries(): array
    {
        return collect($this->feed()->items())
            ->map(function (Activity $activity): array {
                $causer = $activity->causer;

                return [
                    'id' => (string) $activity->id,
                    'who' => $causer instanceof User ? $causer->name : 'Kargah',
                    'initials' => $causer instanceof User ? $causer->initials() : '—',
                    'icon' => $this->iconFor($activity),
                    'log' => (string) ($activity->log_name ?? 'default'),
                    'text' => $this->sentence($activity),
                    'relative' => $activity->created_at?->diffForHumans(['short' => true]) ?? '—',
                    'absolute' => $activity->created_at?->format('j M Y, H:i') ?? '',
                ];
            })
            ->all();
    }

    /** One logged property, by dot path, from a row whose subject may be gone. */
    private function prop(Activity $activity, string $path, mixed $default = null): mixed
    {
        return data_get($activity->properties?->toArray() ?? [], $path, $default);
    }

    private function cardTitle(Activity $activity): string
    {
        if ($activity->subject instanceof Card) {
            return $activity->subject->title;
        }

        return (string) ($this->prop($activity, 'attributes.title')
            ?? $this->prop($activity, 'old.title')
            ?? 'a card since deleted');
    }

    private function listLabel(Activity $activity): string
    {
        if ($activity->subject instanceof BoardList) {
            return $activity->subject->name;
        }

        return (string) ($this->prop($activity, 'attributes.name')
            ?? $this->prop($activity, 'old.name')
            ?? 'a list since deleted');
    }

    private function boardLabel(Activity $activity): string
    {
        if ($activity->subject instanceof Board) {
            return $activity->subject->name;
        }

        return (string) ($this->prop($activity, 'attributes.name')
            ?? $this->prop($activity, 'old.name')
            ?? $this->boardName());
    }

    /** A stored date value, read for a person. Never trusted to parse. */
    private function readableDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'nothing';
        }

        return (string) rescue(fn (): string => Carbon::parse($value)->format('j M Y'), (string) $value, false);
    }

    private function iconFor(Activity $activity): string
    {
        $event = (string) $activity->event;

        return match (true) {
            str_contains($event, 'deleted') => 'ki-trash',
            str_contains($event, 'archived') => 'ki-archive',
            str_contains($event, 'restored') => 'ki-arrow-circle-left',
            str_contains($event, 'commented') => 'ki-message-text-2',
            str_contains($event, 'label') => 'ki-tag',
            str_contains($event, 'mirror') => 'ki-devices-2',
            str_contains($event, 'moved') => 'ki-arrow-right',
            str_contains($event, 'created') => 'ki-plus',
            str_contains($event, 'colour') || str_contains($event, 'background') => 'ki-brush',
            $activity->log_name === 'list' => 'ki-row-vertical',
            $activity->log_name === 'board' => 'ki-row-horizontal',
            default => 'ki-notepad-edit',
        };
    }

    /**
     * The whole sentence, per event name.
     *
     * Two families arrive here. The named ones — `card.moved`, `list.renamed`,
     * `board.label-added` — are written by hand by the producers and carry the
     * properties their sentence needs. The plain ones — `created`, `updated`,
     * `deleted` — come from each model's `getActivitylogOptions()` and carry an
     * attribute diff instead, which `attributeSentence()` reads.
     */
    private function sentence(Activity $activity): string
    {
        $event = (string) $activity->event;

        return match ($event) {
            'card.created' => 'created '.$this->cardTitle($activity),
            'card.moved' => $this->movedSentence($activity),
            'card.mirrored' => 'mirrored '.$this->cardTitle($activity).' onto '
                .($this->prop($activity, 'list') ?? 'another list'),
            'card.unmirrored' => $this->prop($activity, 'list') === null
                ? 'removed a mirror of '.$this->cardTitle($activity)
                : 'stopped mirroring '.$this->cardTitle($activity).' onto '.$this->prop($activity, 'list'),
            'card.archived' => 'archived '.$this->cardTitle($activity),
            'card.restored' => 'restored '.$this->cardTitle($activity).' to the board',
            'card.deleted' => 'deleted '.$this->cardTitle($activity),
            'card.commented' => 'commented on '.$this->cardTitle($activity),
            'card.label_added' => 'put the label '.($this->prop($activity, 'label') ?? 'a label')
                .' on '.$this->cardTitle($activity),
            'card.label_removed' => 'took the label '.($this->prop($activity, 'label') ?? 'a label')
                .' off '.$this->cardTitle($activity),
            'card.customer_set' => 'attached '.$this->cardTitle($activity).' to a customer',
            'card.customer_cleared' => 'detached '.$this->cardTitle($activity).' from its customer',

            'list.renamed' => 'renamed the list '.($this->prop($activity, 'from') ?? $this->listLabel($activity))
                .' to '.($this->prop($activity, 'to') ?? $this->listLabel($activity)),
            'list.recoloured' => $this->prop($activity, 'colour') === null
                ? 'cleared the colour on the list '.$this->listLabel($activity)
                : 'coloured the list '.$this->listLabel($activity).' '.$this->prop($activity, 'colour'),
            'list.moved' => 'reordered the list '.$this->listLabel($activity),
            'list.archived' => 'archived the list '.$this->listLabel($activity),
            'list.deleted' => 'deleted the list '.$this->listLabel($activity),
            'list.restored' => 'restored the list '.$this->listLabel($activity),

            'board.updated' => $this->boardUpdatedSentence($activity),
            'board.recoloured' => 'changed the board colour to '.($this->prop($activity, 'to') ?? 'another colour'),
            'board.background-changed' => 'changed the board background',
            'board.background-text-tone-changed' => 'set the board text tone to '
                .($this->prop($activity, 'tone') ?? 'its other setting'),
            'board.label-added' => 'added the board label '.($this->prop($activity, 'label') ?? ''),
            'board.label-updated' => 'updated the board label '.($this->prop($activity, 'to')
                ?? $this->prop($activity, 'from') ?? ''),
            'board.label-deleted' => 'deleted the board label '.($this->prop($activity, 'label') ?? ''),
            'board.custom-field-added' => 'added the custom field '.($this->prop($activity, 'field') ?? ''),
            'board.custom-field-renamed' => 'renamed the custom field '.($this->prop($activity, 'from') ?? '')
                .' to '.($this->prop($activity, 'to') ?? ''),
            'board.custom-field-moved' => 'reordered the custom field '.($this->prop($activity, 'field') ?? ''),
            'board.custom-field-deleted' => 'deleted the custom field '.($this->prop($activity, 'field') ?? ''),
            'board.custom-field-option-added' => 'added the option '.($this->prop($activity, 'option') ?? '')
                .' to '.($this->prop($activity, 'field') ?? 'a custom field'),
            'board.custom-field-option-renamed' => 'renamed the option '.($this->prop($activity, 'from') ?? '')
                .' to '.($this->prop($activity, 'to') ?? '').' on '.($this->prop($activity, 'field') ?? 'a custom field'),
            'board.custom-field-option-deleted' => 'removed the option '.($this->prop($activity, 'option') ?? '')
                .' from '.($this->prop($activity, 'field') ?? 'a custom field'),
            'board.archived' => 'archived the board '.$this->boardLabel($activity),
            'board.restored' => 'restored the board '.$this->boardLabel($activity),
            'board.deleted' => 'deleted the board '.$this->boardLabel($activity),
            'board.feed_link_revealed' => 'revealed the calendar subscription link',

            default => $this->attributeSentence($activity),
        };
    }

    private function movedSentence(Activity $activity): string
    {
        $what = ($this->prop($activity, 'mirror') === true ? 'the mirror of ' : '').$this->cardTitle($activity);
        $from = $this->prop($activity, 'from_list');
        $to = $this->prop($activity, 'to_list');

        if ($to === null) {
            return 'moved '.$what;
        }

        return $from === null || $from === $to
            ? 'reordered '.$what.' in '.$to
            : 'moved '.$what.' from '.$from.' to '.$to;
    }

    private function boardUpdatedSentence(Activity $activity): string
    {
        $from = $this->prop($activity, 'from');
        $to = $this->prop($activity, 'to');

        if ($from !== null && $to !== null && $from !== $to) {
            return 'renamed the board '.$from.' to '.$to;
        }

        return 'updated the board description';
    }

    /**
     * The model-driven rows: an attribute diff and no event name of its own.
     *
     * Only the changes worth a sentence get one; anything else falls back to
     * naming the fields that moved, which is still more use than "updated".
     */
    private function attributeSentence(Activity $activity): string
    {
        $new = (array) $this->prop($activity, 'attributes', []);
        $old = (array) $this->prop($activity, 'old', []);
        $event = (string) $activity->event;

        $what = match ($activity->log_name) {
            'list' => 'the list '.$this->listLabel($activity),
            'board' => 'the board '.$this->boardLabel($activity),
            default => $this->cardTitle($activity),
        };

        if ($event === 'created') {
            return 'created '.$what;
        }

        if ($event === 'deleted') {
            return 'deleted '.$what;
        }

        if ($event === 'restored') {
            return 'restored '.$what;
        }

        if (array_key_exists('name', $new) && ($old['name'] ?? null) !== null && $old['name'] !== $new['name']) {
            return 'renamed '.$old['name'].' to '.$new['name'];
        }

        if (array_key_exists('title', $new) && ($old['title'] ?? null) !== null && $old['title'] !== $new['title']) {
            return 'renamed '.$old['title'].' to '.$new['title'];
        }

        if (array_key_exists('completed_at', $new)) {
            return $new['completed_at'] === null ? 'reopened '.$what : 'ticked '.$what.' as complete';
        }

        if (array_key_exists('archived_at', $new)) {
            return $new['archived_at'] === null ? 'restored '.$what.' to the board' : 'archived '.$what;
        }

        if (array_key_exists('due_on', $new)) {
            return $new['due_on'] === null
                ? 'cleared the due date on '.$what
                : 'set the due date on '.$what.' to '.$this->readableDate($new['due_on']);
        }

        if (array_key_exists('start_on', $new)) {
            return $new['start_on'] === null
                ? 'cleared the start date on '.$what
                : 'set the start date on '.$what.' to '.$this->readableDate($new['start_on']);
        }

        if (array_key_exists('customer_id', $new)) {
            return $new['customer_id'] === null
                ? 'detached '.$what.' from its customer'
                : 'attached '.$what.' to a customer';
        }

        if (array_key_exists('company_id', $new)) {
            return $new['company_id'] === null
                ? 'detached '.$what.' from its company'
                : 'attached '.$what.' to a company';
        }

        if (array_key_exists('description', $new)) {
            return 'rewrote the description on '.$what;
        }

        $fields = collect(array_keys($new))
            ->map(fn (string $key): string => str_replace('_', ' ', $key))
            ->all();

        return $fields === []
            ? 'updated '.$what
            : 'changed the '.implode(', ', $fields).' on '.$what;
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($boardPickerOpen)
        <div class="fixed inset-0 z-10" wire:click="closeBoardPicker" aria-hidden="true"></div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            <div>
                <h1 class="text-xl font-semibold text-mono">{{ $this->boardName() }} — activity</h1>
                <p class="text-sm text-secondary-foreground mt-1">
                    Everything that has happened on this board, newest first — cards, lists and the board itself.
                </p>
            </div>

            {{--
                Driven from component state, never KTUI's own controller: the
                morph strips any class it did not itself render, so a
                data-kt-dropdown-toggle panel would snap shut on the next
                unrelated write. See docs/frontend-conventions.md.
            --}}
            <div class="relative">
                <button wire:click="toggleBoardPicker" class="kt-btn kt-btn-outline gap-2">
                    <i class="ki-filled ki-down text-xs"></i> Switch board
                </button>
                <div class="kt-dropdown absolute z-20 mt-1 w-[220px] {{ $boardPickerOpen ? 'open' : '' }}">
                    <div class="p-2 flex flex-col gap-1">
                        @forelse ($boards as $b)
                            <button wire:click="selectBoard('{{ $b->slug }}')" wire:key="activity-pick-{{ $b->id }}"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full {{ $b->slug === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full {{ $b->dotClass() }}"></span>
                                {{ $b->name }}
                            </button>
                        @empty
                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">No boards yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Views. The four sibling views need the matching Activity link added by whoever owns them. --}}
        <div class="flex items-center gap-1">
            <a href="{{ route('projects.boards', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-row-horizontal text-sm"></i> Board
            </a>
            <a href="{{ route('projects.table', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-row-vertical text-sm"></i> Table
            </a>
            <a href="{{ route('projects.calendar', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-calendar text-sm"></i> Calendar
            </a>
            <a href="{{ route('projects.dashboard', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-chart-simple text-sm"></i> Dashboard
            </a>
            <span class="kt-btn kt-btn-sm kt-btn-primary gap-1.5">
                <i class="ki-filled ki-time text-sm"></i> Activity
            </span>
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <h3 class="kt-card-title">History</h3>

            <div class="flex items-center gap-1">
                <button wire:click="filterByLog('')"
                        class="kt-btn kt-btn-sm {{ $logName === '' ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                    Everything
                </button>
                <button wire:click="filterByLog('card')"
                        class="kt-btn kt-btn-sm {{ $logName === 'card' ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                    Cards
                </button>
                <button wire:click="filterByLog('list')"
                        class="kt-btn kt-btn-sm {{ $logName === 'list' ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                    Lists
                </button>
                <button wire:click="filterByLog('board')"
                        class="kt-btn kt-btn-sm {{ $logName === 'board' ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                    Board
                </button>
            </div>
        </div>

        @island(name: 'feed')
        <div>
            <div class="kt-card-content p-0">
                @forelse ($entries as $entry)
                    <div wire:key="activity-{{ $entry['id'] }}"
                         class="flex items-start gap-3 px-5 py-3 border-b border-border last:border-b-0"
                         style="content-visibility: auto; contain-intrinsic-size: auto 56px;">
                        <span class="size-8 rounded-full grid place-items-center text-[11px] font-semibold shrink-0 bg-primary/15 text-primary"
                              title="{{ $entry['who'] }}">
                            {{ $entry['initials'] }}
                        </span>

                        <div class="min-w-0 grow">
                            <p class="text-sm text-secondary-foreground">
                                <span class="font-medium text-mono">{{ $entry['who'] }}</span>
                                {{ $entry['text'] }}
                            </p>
                            <div class="flex items-center gap-2 mt-0.5">
                                <i class="ki-filled {{ $entry['icon'] }} text-xs text-muted-foreground"></i>
                                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $entry['log'] }}</span>
                                <span class="text-xs text-muted-foreground" title="{{ $entry['absolute'] }}">
                                    {{ $entry['relative'] }}
                                </span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center text-center py-14">
                        <i class="ki-filled ki-time text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">
                            {{ $boards->isEmpty() ? 'No boards yet.' : 'Nothing has happened on this board yet.' }}
                        </p>
                    </div>
                @endforelse
            </div>

            @if ($feed->hasPages())
                <div class="kt-card-footer flex items-center justify-between gap-3">
                    <span class="text-xs text-muted-foreground">{{ $total }} {{ str('entry')->plural($total) }}.</span>
                    <div class="flex items-center gap-2">
                        <button wire:click="goToCursor('{{ $feed->previousCursor()?->encode() }}')"
                                wire:loading.attr="disabled" wire:target="goToCursor"
                                @disabled($feed->onFirstPage())
                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                            <i class="ki-filled ki-arrow-left text-xs"></i> Newer
                        </button>
                        <button wire:click="goToCursor('{{ $feed->nextCursor()?->encode() }}')"
                                wire:loading.attr="disabled" wire:target="goToCursor"
                                @disabled(! $feed->hasMorePages())
                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                            Older <i class="ki-filled ki-arrow-right text-xs"></i>
                        </button>
                    </div>
                </div>
            @endif
        </div>
        @endisland
    </div>
</div>
