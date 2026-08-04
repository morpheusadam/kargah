<?php

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Butler\Butler;
use Modules\Project\Butler\Triggers;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Services\CardService;
use Modules\Project\Services\PlacementConflict;

/**
 * The board, flattened to rows.
 *
 * **One row per placement, not per card.** A card mirrored onto two lists of
 * this board genuinely sits in both, and this page's "List" column has to be
 * honest about which one a given row is talking about — a card-level row
 * could not say. Changing the list on a mirror's row moves *that* mirror,
 * exactly as dragging it on the board would; the origin does not notice. The
 * name, due date, labels and members are the card's own, so editing any of
 * those from any row of a mirrored card changes every row — the same rule
 * `⚡card-detail.blade.php` documents for itself.
 *
 * **Cursor pagination, ordered by placement id.** A board's table only grows,
 * and offset pagination scans and discards every row it skips to reach a
 * later page. Ordering is by primary key because a cursor needs a column
 * that is unique and never null.
 *
 * **Inline edits do not toast on success.** The cell changes in front of the
 * person who changed it — the same reasoning the calendar view's drag
 * reschedule uses. A write that fails a validation or a placement conflict
 * still toasts, because that is not otherwise visible.
 */
new
#[Title('Table — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Enough rows to fill a screen, few enough that the query stays cheap. */
    private const PER_PAGE = 25;

    #[Url(as: 'board')]
    public string $activeBoard = '';

    /** The encoded cursor. Empty means the first page, and stays out of the URL. */
    #[Url]
    public string $cursor = '';

    /** The placement whose row is mid-edit, and which field. */
    public ?int $editingPlacementId = null;

    public string $editingField = '';

    public string $editingValue = '';

    /** The placement whose label or member picker is open, and which picker. */
    public ?int $pickerPlacementId = null;

    public string $pickerType = '';

    public bool $boardPickerOpen = false;

    /** Per-request memos; see ⚡boards.blade.php for why these are private. */
    private ?Board $resolvedBoard = null;

    private ?Collection $resolvedBoards = null;

    private ?CursorPaginator $resolvedRows = null;

    public function mount(): void
    {
        $this->activeBoard = $this->resolveBoard($this->activeBoard);
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

    /** @return Collection<int, BoardList> */
    private function lists(): Collection
    {
        $board = $this->board();

        if ($board === null) {
            return collect();
        }

        return BoardList::query()->where('board_id', $board->id)->active()->orderBy('position')->get();
    }

    /** @return Collection<int, \Modules\Project\Models\Label> */
    private function labels(): Collection
    {
        $board = $this->board();

        return $board === null ? collect() : $board->labels()->get();
    }

    private function members(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    private function rowsQuery(): Builder
    {
        $listIds = $this->lists()->pluck('id');

        if ($listIds->isEmpty()) {
            return CardPlacement::query()->whereRaw('1 = 0');
        }

        return CardPlacement::query()
            ->onCanvas()
            ->whereIn('board_list_id', $listIds)
            ->with(['card.labels', 'card.members', 'list']);
    }

    private function rows(): CursorPaginator
    {
        return $this->resolvedRows ??= $this->rowsQuery()
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $this->currentCursor());
    }

    /**
     * The cursor the address bar is carrying, if it is one. See the invoice
     * book for why an unreadable cursor is the first page, not a crash.
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
            'lists' => $this->lists(),
            'labels' => $this->labels(),
            'members' => $this->members(),
            'rows' => $this->rows(),
            'totalRows' => $this->rowsQuery()->count(),
            'totalCards' => $this->board() === null ? 0 : $this->board()->cards()->active()->count(),
        ];
    }

    public function boardName(): string
    {
        return $this->board()?->name ?? 'Board';
    }

    public function selectBoard(string $slug): void
    {
        $this->activeBoard = $this->resolveBoard($slug);
        $this->resolvedBoard = null;
        $this->cursor = '';
        $this->boardPickerOpen = false;
        $this->closeEditors();
        $this->refreshRows();
    }

    public function toggleBoardPicker(): void
    {
        $this->boardPickerOpen = ! $this->boardPickerOpen;
    }

    /** A data change: re-query and redraw. */
    private function refreshRows(): void
    {
        $this->resolvedRows = null;
        $this->redrawRows();
    }

    /**
     * A UI-only change — a cell turning into an input, a picker opening — that
     * still lives inside the `rows` island. An island nobody names is sent
     * back with `mode=skip`, and the morph walks past the whole fragment: the
     * edit box would never actually appear.
     */
    private function redrawRows(): void
    {
        $this->renderIsland('rows');
    }

    public function goToCursor(string $cursor = ''): void
    {
        $this->cursor = $cursor;
        $this->refreshRows();
    }

    private function closeEditors(): void
    {
        $this->editingPlacementId = null;
        $this->editingField = '';
        $this->editingValue = '';
        $this->pickerPlacementId = null;
        $this->pickerType = '';
    }

    /* Inline editing ---------------------------------------------------------- */

    private function placementOnThisBoard(int $placementId): ?CardPlacement
    {
        $board = $this->board();

        if ($board === null) {
            return null;
        }

        return CardPlacement::query()
            ->with('card')
            ->whereIn('board_list_id', $this->lists()->pluck('id'))
            ->find($placementId);
    }

    public function startEdit(int $placementId, string $field, string $current): void
    {
        $this->pickerPlacementId = null;
        $this->pickerType = '';
        $this->editingPlacementId = $placementId;
        $this->editingField = $field;
        $this->editingValue = $current;
        $this->redrawRows();
    }

    public function cancelEdit(): void
    {
        $this->editingPlacementId = null;
        $this->editingField = '';
        $this->editingValue = '';
        $this->redrawRows();
    }

    /** Save whichever field is being edited. No toast: the cell changes on screen. */
    public function saveEdit(): void
    {
        if ($this->editingPlacementId === null) {
            return;
        }

        $placement = $this->placementOnThisBoard($this->editingPlacementId);
        $card = $placement?->card;

        if ($placement === null || $card === null) {
            $this->toastError('That card is gone', 'It was deleted while the page was open.');
            $this->editingPlacementId = null;
            $this->editingField = '';
            $this->editingValue = '';
            $this->refreshRows();

            return;
        }

        match ($this->editingField) {
            'title' => $this->saveTitle($card),
            'due' => $this->saveDue($card),
            default => null,
        };

        $this->editingPlacementId = null;
        $this->editingField = '';
        $this->editingValue = '';
        $this->refreshRows();
    }

    private function saveTitle(Card $card): void
    {
        $title = trim($this->editingValue);

        if ($title === '') {
            $this->toastError('The card needs a title', 'Nothing was changed.');

            return;
        }

        $card->update(['title' => $title]);
    }

    private function saveDue(Card $card): void
    {
        $raw = trim($this->editingValue);

        if ($raw === '') {
            $card->update(['due_on' => null]);

            return;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw);
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date === false) {
            $this->toastError('That is not a date Kargah can read', 'The due date was not changed.');

            return;
        }

        $card->update(['due_on' => $date->toDateString()]);
    }

    /* List, labels, members ----------------------------------------------------- */

    /** Move one placement's row to a different list on this board. Never the origin's board. */
    public function changeList(int $placementId, int $newListId): void
    {
        $placement = $this->placementOnThisBoard($placementId);
        $newList = $this->lists()->firstWhere('id', $newListId);

        if ($placement === null || $placement->card === null || $newList === null) {
            $this->toastError('That row is gone', 'Reload the page and try again.');
            $this->refreshRows();

            return;
        }

        if ($placement->board_list_id === $newList->id) {
            return;
        }

        if ($placement->isMirror() && $placement->card->isArchived()) {
            // Not draggable on the board canvas for the same reason: this row
            // is a note about where the card went, not a live card.
            $this->toastError('That card is archived', 'Restore it from the archive before moving it.');
            $this->refreshRows();

            return;
        }

        try {
            app(CardService::class)->move($placement, $newList, PHP_INT_MAX);
        } catch (PlacementConflict) {
            $this->toastError(
                'That card is already in '.$newList->name,
                'A card can only sit in a list once. Remove the mirror there first.',
            );
            $this->refreshRows();

            return;
        }

        $this->refreshRows();
    }

    public function togglePicker(int $placementId, string $type): void
    {
        if ($this->pickerPlacementId === $placementId && $this->pickerType === $type) {
            $this->pickerPlacementId = null;
            $this->pickerType = '';
            $this->redrawRows();

            return;
        }

        $this->closeEditors();
        $this->boardPickerOpen = false;
        $this->pickerPlacementId = $placementId;
        $this->pickerType = $type;
        $this->redrawRows();
    }

    /** Click-away from any open panel. Dismissing is not worth announcing. */
    public function closePicker(): void
    {
        $this->pickerPlacementId = null;
        $this->pickerType = '';
        $this->boardPickerOpen = false;
        $this->redrawRows();
    }

    /** No toast: the chip appearing or disappearing in the row is the confirmation. */
    public function toggleLabel(int $placementId, int $labelId): void
    {
        $card = $this->placementOnThisBoard($placementId)?->card;

        if ($card === null) {
            return;
        }

        $label = $this->labels()->firstWhere('id', $labelId);

        if ($label === null) {
            return;
        }

        $card->labels()->toggle($labelId);

        // A pivot raises no Eloquent events, so Butler is told by hand.
        app(Butler::class)->fire(
            $card->labels()->whereKey($label->id)->exists() ? Triggers::CARD_LABEL_ADDED : Triggers::CARD_LABEL_REMOVED,
            $card,
            ["label_id" => $label->id],
        );

        $this->refreshRows();
    }

    public function toggleMember(int $placementId, int $userId): void
    {
        $card = $this->placementOnThisBoard($placementId)?->card;

        if ($card === null || $this->members()->doesntContain('id', $userId)) {
            return;
        }

        $card->members()->toggle($userId);

        app(Butler::class)->fire(
            $card->members()->whereKey($userId)->exists() ? Triggers::CARD_MEMBER_ADDED : Triggers::CARD_MEMBER_REMOVED,
            $card,
            ["user_id" => $userId],
        );

        $this->refreshRows();
    }

    /** The drawer changed a card. The rows read straight from the database, so redraw them. */
    #[On('card-changed')]
    public function cardChanged(): void
    {
        $this->refreshRows();
    }

    public function openCard(int $cardId): void
    {
        $this->dispatch('open-card', cardId: $cardId);
    }
};

?>

<div class="flex flex-col gap-5">

    {{--
        Only the panels, never the inline editor. The sheet is `fixed inset-0`
        and the cell input is in ordinary flow, so while it was also raised for
        an open editor it sat *on top of* the very input it was meant to guard:
        the box could be typed into (it autofocuses) but not clicked into, and
        a click meant to put the caret mid-word was swallowed by the sheet. The
        editor closes itself anyway — `wire:blur="saveEdit"` on the input.
    --}}
    @if ($pickerPlacementId !== null || $boardPickerOpen)
        <div class="fixed inset-0 z-10" wire:click="closePicker" aria-hidden="true"></div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            <div>
                <h1 class="text-xl font-semibold text-mono">{{ $this->boardName() }} — table</h1>
                <p class="text-sm text-secondary-foreground mt-1">
                    Every placement is a row — a card mirrored onto two lists appears twice, once per list.
                </p>
            </div>

            {{--
                Driven from component state, never KTUI's own controller: the
                morph strips any class it did not itself render, so a
                data-kt-dropdown-toggle panel would snap shut on the next
                unrelated write. See docs/frontend-conventions.md.
            --}}
            {{--
                Escape sits on the wrapper, not the panel: the trigger still
                holds focus at the moment the panel opens, and a keystroke on
                the trigger never bubbles through a sibling. The wrapper is the
                nearest node both are inside — and still not the window, which
                would answer every other Escape on the page.
            --}}
            <div class="relative" wire:keydown.escape="closePicker">
                <button wire:click="toggleBoardPicker" class="kt-btn kt-btn-outline gap-2"
                        aria-haspopup="true" aria-expanded="{{ $boardPickerOpen ? 'true' : 'false' }}">
                    <i class="ki-filled ki-down text-xs"></i> Switch board
                </button>
                <div class="kt-dropdown absolute z-20 mt-1 w-[220px] {{ $boardPickerOpen ? 'open' : '' }}">
                    <div class="p-2 flex flex-col gap-1">
                        @forelse ($boards as $b)
                            {{-- `min-w-0` plus a `truncate` span: `kt-btn` is `white-space: nowrap`
                                 and does not clip, so a long board name runs straight out of the
                                 220px panel. Same fix as ⚡board-activity.blade.php. --}}
                            <button wire:click="selectBoard('{{ $b->slug }}')" wire:key="table-pick-{{ $b->id }}"
                                    wire:loading.attr="disabled" wire:target="selectBoard"
                                    title="{{ $b->name }}"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full min-w-0 {{ $b->slug === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full shrink-0 {{ $b->dotClass() }}"></span>
                                <span class="truncate">{{ $b->name }}</span>
                            </button>
                        @empty
                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">No boards yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{--
            Views. Five of them since Activity landed, and five `kt-btn-sm`
            pills are wider than a narrow window: `flex-wrap` so the strip
            folds onto a second line instead of pushing the toolbar into a
            horizontal scroll, and `justify-end` so the folded rows stay
            aligned with the edge they started from.
        --}}
        <div class="flex flex-wrap items-center justify-end gap-1">
            <a href="{{ route('projects.boards', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-row-horizontal text-sm"></i> Board
            </a>
            <span class="kt-btn kt-btn-sm kt-btn-primary gap-1.5">
                <i class="ki-filled ki-row-vertical text-sm"></i> Table
            </span>
            <a href="{{ route('projects.calendar', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-calendar text-sm"></i> Calendar
            </a>
            <a href="{{ route('projects.dashboard', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-chart-simple text-sm"></i> Dashboard
            </a>
            <a href="{{ route('projects.activity', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-time text-sm"></i> Activity
            </a>
        </div>
    </div>

    <div class="kt-card">
        @island(name: 'rows')
        <div>
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="min-w-[240px]">Card</th>
                                <th class="w-[170px]">List</th>
                                <th class="w-[140px]">Due</th>
                                <th class="w-[160px]">Labels</th>
                                <th class="w-[160px]">Members</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $placement)
                                @php($card = $placement->card)
                                <tr wire:key="row-{{ $placement->id }}"
                                    style="content-visibility: auto; contain-intrinsic-size: auto 52px;">
                                    <td>
                                        <div class="flex items-center gap-2 min-w-0">
                                            <button wire:click="openCard({{ $card->id }})" class="text-muted-foreground hover:text-primary shrink-0"
                                                    title="Open card" aria-label="Open card">
                                                <i class="ki-filled ki-eye text-sm"></i>
                                            </button>
                                            @if ($editingPlacementId === $placement->id && $editingField === 'title')
                                                <input type="text" class="kt-input kt-input-sm" wire:model="editingValue" autofocus
                                                       aria-label="Card title"
                                                       wire:keydown.enter="saveEdit" wire:keydown.escape="cancelEdit" wire:blur="saveEdit">
                                            @else
                                                {{--
                                                    `max-w-[420px]` and a `truncate` span rather than `truncate` on
                                                    the button. A table cell is sized by its content, so a nowrap
                                                    button never actually elides — it just widens the column until
                                                    the whole table scrolls sideways and every other row's cells go
                                                    off screen. The cap bounds the column; the span is what elides;
                                                    the mirror icon and the Archived badge sit outside it so they
                                                    survive the ellipsis instead of being the first thing cut.
                                                --}}
                                                <button wire:click="startEdit({{ $placement->id }}, 'title', @js($card->title))"
                                                        title="{{ $card->title }}" aria-label="Rename {{ $card->title }}"
                                                        class="flex items-center gap-1 min-w-0 max-w-[420px] text-start text-mono hover:text-primary">
                                                    <span class="truncate">{{ $card->title }}</span>
                                                    @if ($placement->isMirror())
                                                        <i class="ki-filled ki-devices-2 text-xs text-muted-foreground shrink-0" title="Mirror"></i>
                                                    @endif
                                                    @if ($card->isArchived())
                                                        <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">Archived</span>
                                                    @endif
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        {{-- An archived mirror is a note about where the card went, not a live card — see boards.blade.php. Not draggable there, not movable here. --}}
                                        <select class="kt-select kt-select-sm" wire:change="changeList({{ $placement->id }}, $event.target.value)"
                                                aria-label="List for {{ $card->title }}"
                                                wire:loading.attr="disabled" wire:target="changeList"
                                                @disabled($card->isArchived())>
                                            @foreach ($lists as $list)
                                                <option value="{{ $list->id }}" @selected($list->id === $placement->board_list_id)>{{ $list->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        @if ($editingPlacementId === $placement->id && $editingField === 'due')
                                            <input type="date" class="kt-input kt-input-sm" wire:model="editingValue" autofocus
                                                   aria-label="Due date"
                                                   wire:keydown.enter="saveEdit" wire:keydown.escape="cancelEdit" wire:blur="saveEdit">
                                        @else
                                            {{-- The dash is the empty state, and a screen reader reads it as nothing at all. --}}
                                            <button wire:click="startEdit({{ $placement->id }}, 'due', @js($card->due_on?->format('Y-m-d') ?? ''))"
                                                    aria-label="{{ $card->due_on ? 'Change the due date, '.$card->due_on->format('j M Y') : 'Set a due date' }}"
                                                    class="text-start hover:opacity-80 {{ $card->due_on ? \Modules\Project\Support\Palette::tone($card->dueBadgeColour() ?? 'neutral') : 'text-secondary-foreground' }} rounded px-1">
                                                {{ $card->due_on?->format('j M Y') ?? '—' }}
                                            </button>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="relative" wire:keydown.escape="closePicker">
                                            {{-- Capped: a card with eight labels would otherwise widen the column past its 160px header. --}}
                                            <button wire:click="togglePicker({{ $placement->id }}, 'labels')"
                                                    aria-label="Labels on {{ $card->title }}" aria-haspopup="true"
                                                    aria-expanded="{{ $pickerPlacementId === $placement->id && $pickerType === 'labels' ? 'true' : 'false' }}"
                                                    class="flex flex-wrap gap-1 items-center min-h-[24px] max-w-[160px] text-start">
                                                @forelse ($card->labels as $label)
                                                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded {{ $label->chipClass() }}">{{ $label->name }}</span>
                                                @empty
                                                    <span class="text-xs text-muted-foreground">—</span>
                                                @endforelse
                                            </button>
                                            <div class="kt-dropdown absolute z-20 mt-1 w-[220px] {{ $pickerPlacementId === $placement->id && $pickerType === 'labels' ? 'open' : '' }}">
                                                <div class="p-2 flex flex-col gap-1">
                                                    @forelse ($labels as $label)
                                                        @php($labelOn = $card->labels->contains('id', $label->id))
                                                        {{--
                                                            The tick alone was the whole "is it on?" signal, and it sits at
                                                            the far end of the row where the eye is not. `aria-pressed` says
                                                            it out loud and the tinted row says it at a glance.
                                                        --}}
                                                        <button wire:click="toggleLabel({{ $placement->id }}, {{ $label->id }})" wire:key="tl-{{ $placement->id }}-{{ $label->id }}"
                                                                aria-pressed="{{ $labelOn ? 'true' : 'false' }}"
                                                                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60 {{ $labelOn ? 'bg-accent/60' : '' }}">
                                                            <span class="size-3 rounded-sm shrink-0 {{ $label->dotClass() }}"></span>
                                                            <span class="grow min-w-0 truncate text-secondary-foreground">{{ $label->name }}</span>
                                                            @if ($labelOn)
                                                                <i class="ki-filled ki-check text-sm text-primary shrink-0"></i>
                                                            @endif
                                                        </button>
                                                    @empty
                                                        <p class="text-xs text-muted-foreground px-2 py-2">This board has no labels yet.</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="relative" wire:keydown.escape="closePicker">
                                            <button wire:click="togglePicker({{ $placement->id }}, 'members')"
                                                    aria-label="Members on {{ $card->title }}" aria-haspopup="true"
                                                    aria-expanded="{{ $pickerPlacementId === $placement->id && $pickerType === 'members' ? 'true' : 'false' }}"
                                                    class="flex flex-wrap items-center gap-1 min-h-[24px] max-w-[160px] text-start">
                                                @forelse ($card->members as $member)
                                                    <span class="size-6 rounded-full grid place-items-center text-[10px] font-semibold bg-primary/15 text-primary"
                                                          title="{{ $member->name }}">{{ $member->initials() }}</span>
                                                @empty
                                                    <span class="text-xs text-muted-foreground">—</span>
                                                @endforelse
                                            </button>
                                            <div class="kt-dropdown absolute z-20 mt-1 end-0 w-[220px] {{ $pickerPlacementId === $placement->id && $pickerType === 'members' ? 'open' : '' }}">
                                                <div class="p-2 flex flex-col gap-1">
                                                    @forelse ($members as $member)
                                                        @php($memberOn = $card->members->contains('id', $member->id))
                                                        <button wire:click="toggleMember({{ $placement->id }}, {{ $member->id }})" wire:key="tm-{{ $placement->id }}-{{ $member->id }}"
                                                                aria-pressed="{{ $memberOn ? 'true' : 'false' }}"
                                                                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60 {{ $memberOn ? 'bg-accent/60' : '' }}">
                                                            <span class="size-6 rounded-full grid place-items-center text-[10px] font-semibold bg-primary/15 text-primary shrink-0">{{ $member->initials() }}</span>
                                                            <span class="grow min-w-0 truncate text-secondary-foreground">{{ $member->name }}</span>
                                                            @if ($memberOn)
                                                                <i class="ki-filled ki-check text-sm text-primary shrink-0"></i>
                                                            @endif
                                                        </button>
                                                    @empty
                                                        <p class="text-xs text-muted-foreground px-2 py-2">Nobody to add yet.</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="flex flex-col items-center justify-center text-center py-14">
                                            <i class="ki-filled ki-row-vertical text-3xl text-muted-foreground mb-3"></i>
                                            <p class="text-sm text-secondary-foreground">
                                                {{ $boards->isEmpty() ? 'No boards yet.' : 'Nothing on this board yet.' }}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{--
                The footer used to be gated on `hasPages()` alone, which is
                false for any board that fits on one page — so the row count,
                the one place the table says how big it is, appeared only once
                a board grew past 25 rows and never below it. The count is now
                tied to there being rows to count, and the two buttons to there
                being somewhere to go: an empty board says nothing rather than
                "0 rows across 0 cards" under an empty state that already said
                it better.
            --}}
            @if ($totalRows > 0)
                <div class="kt-card-footer flex flex-wrap items-center justify-between gap-3">
                    <span class="text-xs text-muted-foreground">
                        {{ $totalRows }} {{ str('row')->plural($totalRows) }} across {{ $totalCards }} {{ str('card')->plural($totalCards) }}.
                    </span>
                    @if ($rows->hasPages())
                        <div class="flex items-center gap-2">
                            <button wire:click="goToCursor('{{ $rows->previousCursor()?->encode() }}')"
                                    wire:loading.attr="disabled" wire:target="goToCursor"
                                    @disabled($rows->onFirstPage())
                                    class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                                <i class="ki-filled ki-black-left text-xs"></i> Newer
                            </button>
                            <button wire:click="goToCursor('{{ $rows->nextCursor()?->encode() }}')"
                                    wire:loading.attr="disabled" wire:target="goToCursor"
                                    @disabled(! $rows->hasMorePages())
                                    class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                                Older <i class="ki-filled ki-black-right text-xs"></i>
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
        @endisland
    </div>

    <livewire:project::card-detail />
</div>
