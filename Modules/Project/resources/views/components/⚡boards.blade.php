<?php

use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;

/**
 * Trello-style board, reading from the database.
 *
 * Two things here are worth knowing before changing anything.
 *
 * **The board canvas is an island.** Toggling the filter panel, the board
 * picker or a list menu skips re-rendering every card on the board. Anything
 * that *does* change what a card looks like has to name the island, or the new
 * markup is computed, sent and thrown away — the morph engine skips the whole
 * fragment. There is exactly one `@island` directive in this file on purpose;
 * one inside the `@foreach` would share a token with every other iteration and
 * morph the wrong column. See project-guaid/spec/04-frontend.md.
 *
 * **`moveCard` trusts the browser for the index and nothing else.** Sortable
 * reports where the card landed among the cards it can *see*. The server knows
 * the filter, so it works out which cards those were itself rather than taking
 * a list of ids from the client.
 */
new
#[Title('Boards — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url(as: 'board')]
    public string $activeBoard = '';

    /** Free-text filter, shared by the toolbar search box and the filter panel. */
    #[Url]
    public string $search = '';

    /** @var array<int, int|string> Label ids the filter is limited to. */
    #[Url(as: 'label')]
    public array $filterLabels = [];

    /** @var array<int, int|string> User ids the filter is limited to. */
    #[Url(as: 'who')]
    public array $filterAssignees = [];

    /** One of '', 'overdue', 'soon', 'none'. */
    #[Url(as: 'due')]
    public string $filterDue = '';

    public bool $filterOpen = false;

    public bool $boardPickerOpen = false;

    /** The list whose ⋯ menu is open, if any. */
    public ?int $listMenuOpen = null;

    /** The list whose inline "add a card" form is open, if any. */
    public ?int $addingCardIn = null;

    public string $newCardTitle = '';

    public bool $addingList = false;

    public string $newListName = '';

    /**
     * Per-request memos. Private, so Livewire neither ships nor rehydrates
     * them, and a new component instance starts empty — no code here may
     * assume either a fresh process or a persistent one.
     *
     * Without these the page asks for the same boards four times and the same
     * users three times, because `with()`, `mount()`, `boardName()` and the
     * template each go looking independently.
     */
    private ?Board $resolvedBoard = null;

    private ?Collection $resolvedLists = null;

    private ?Collection $resolvedBoards = null;

    private ?Collection $resolvedLabels = null;

    private ?Collection $resolvedMembers = null;

    /**
     * An `#[Url]` property is whatever the address bar says, which may be a
     * board that was archived, deleted, or never existed.
     */
    public function mount(): void
    {
        $this->activeBoard = $this->resolveBoard($this->activeBoard);
    }

    private function resolveBoard(string $slug): string
    {
        $slugs = $this->allBoards()->pluck('slug');

        return $slugs->contains($slug) ? $slug : (string) $slugs->first();
    }

    /* Reading the board ---------------------------------------------------- */

    private function allBoards(): Collection
    {
        return $this->resolvedBoards ??= Board::query()->active()->orderBy('position')->orderBy('name')->get();
    }

    private function board(): ?Board
    {
        return $this->resolvedBoard ??= $this->allBoards()->firstWhere('slug', $this->activeBoard);
    }

    /**
     * Every list on the board with its cards, before filtering.
     *
     * One query per relation rather than one per card: the checklist chip is
     * two `withCount` subqueries, not a load of every item on the board.
     */
    private function lists(): Collection
    {
        if ($this->resolvedLists !== null) {
            return $this->resolvedLists;
        }

        $board = $this->board();

        if ($board === null) {
            return $this->resolvedLists = collect();
        }

        return $this->resolvedLists = BoardList::query()
            ->where('board_id', $board->id)
            ->active()
            ->orderBy('position')
            ->with(['cards' => fn ($query) => $query
                ->active()
                ->orderBy('position')
                ->with(['labels', 'members'])
                ->withCount([
                    'comments',
                    'checklistItems as checklist_total',
                    'checklistItems as checklist_done' => fn ($q) => $q->where('is_done', true),
                ]),
            ])
            ->get();
    }

    /** @return Collection<int, \Modules\Project\Models\Label> */
    private function labels(): Collection
    {
        if ($this->resolvedLabels !== null) {
            return $this->resolvedLabels;
        }

        $board = $this->board();

        return $this->resolvedLabels = $board === null ? collect() : $board->labels()->get();
    }

    /** People who can be put on a card. */
    private function members(): Collection
    {
        return $this->resolvedMembers ??= User::query()->orderBy('name')->get();
    }

    /* Filtering ------------------------------------------------------------- */

    /** The search box, ignoring whitespace nobody meant to type. */
    private function searchTerm(): string
    {
        return trim($this->search);
    }

    /** `#[Url]` arrays arrive as strings. Compare ids as ids. */
    private function labelFilterIds(): array
    {
        return array_map('intval', $this->filterLabels);
    }

    private function assigneeFilterIds(): array
    {
        return array_map('intval', $this->filterAssignees);
    }

    private function matches(Card $card): bool
    {
        $term = $this->searchTerm();

        if ($term !== '' && stripos($card->title, $term) === false) {
            return false;
        }

        $labelIds = $this->labelFilterIds();

        if ($labelIds !== [] && $card->labels->pluck('id')->intersect($labelIds)->isEmpty()) {
            return false;
        }

        $assigneeIds = $this->assigneeFilterIds();

        if ($assigneeIds !== [] && $card->members->pluck('id')->intersect($assigneeIds)->isEmpty()) {
            return false;
        }

        return match ($this->filterDue) {
            'overdue' => $card->dueState() === 'overdue',
            'soon' => in_array($card->dueState(), ['overdue', 'soon'], true),
            'none' => $card->due_on === null,
            default => true,
        };
    }

    /** The cards of one list that survive the current filter, in order. */
    private function visibleCards(BoardList $list): Collection
    {
        return $list->cards->filter(fn (Card $card): bool => $this->matches($card))->values();
    }

    /**
     * The ids the browser had on screen for a list.
     *
     * Derived from the same filter that rendered the page rather than sent up
     * with the drop: the server already knows, and a client-supplied ordering
     * is one more thing that can be wrong or forged.
     *
     * @return list<int>
     */
    private function visibleCardIds(BoardList $list): array
    {
        return $this->visibleCards($list)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function countCards(bool $filtered): int
    {
        return $this->lists()->sum(
            fn (BoardList $list): int => $filtered ? $this->visibleCards($list)->count() : $list->cards->count(),
        );
    }

    /** How the board reads once the current filter is applied. Used in filter toasts. */
    private function filterSummary(): string
    {
        return 'Showing '.$this->countCards(true).' of '.$this->countCards(false).' cards.';
    }

    public function with(): array
    {
        $lists = $this->lists();

        return [
            'boards' => $this->allBoards(),
            'labels' => $this->labels(),
            'members' => $this->members(),
            'lists' => $lists->map(fn (BoardList $list): array => [
                'model' => $list,
                'cards' => $this->visibleCards($list),
            ]),
            'totalCards' => $this->countCards(false),
            'visibleCards' => $this->countCards(true),
            'activeFilters' => count($this->filterLabels)
                + count($this->filterAssignees)
                + ($this->filterDue !== '' ? 1 : 0)
                + ($this->searchTerm() !== '' ? 1 : 0),
            'dueOptions' => [
                'overdue' => ['label' => 'Overdue', 'icon' => 'ki-time', 'tone' => 'text-destructive'],
                'soon' => ['label' => 'Due in the next week', 'icon' => 'ki-calendar', 'tone' => 'text-warning'],
                'none' => ['label' => 'No due date', 'icon' => 'ki-calendar-remove', 'tone' => 'text-muted-foreground'],
            ],
        ];
    }

    /* Panels ---------------------------------------------------------------- */

    /**
     * Shut every transient panel. One thing is open at a time, and closing on
     * the way to opening something else is silent — a single gesture reports
     * once, not three times.
     */
    private function closeOverlays(): void
    {
        $this->filterOpen = false;
        $this->boardPickerOpen = false;
        $this->listMenuOpen = null;
        $this->addingCardIn = null;
        $this->newCardTitle = '';
        $this->addingList = false;
        $this->newListName = '';
    }

    /** Click-away from any open panel. Dismissing is not worth announcing. */
    public function dismissPanels(): void
    {
        $this->closeOverlays();
    }

    /** The active board's display name, for the heading. */
    public function boardName(): string
    {
        return $this->board()?->name ?? 'Board';
    }

    public function selectBoard(string $slug): void
    {
        $this->activeBoard = $this->resolveBoard($slug);

        // Every memo below is scoped to a board. Switching invalidates them all.
        $this->resolvedBoard = null;
        $this->resolvedLists = null;
        $this->resolvedLabels = null;

        // A filter set against one board's labels and people matches nothing on
        // the next one, and an empty board with no visible reason reads as a bug.
        $this->search = '';
        $this->filterLabels = [];
        $this->filterAssignees = [];
        $this->filterDue = '';

        $this->closeOverlays();
        $this->refreshBoard();

        $this->toastSuccess('Board switched', 'You are looking at '.$this->boardName().'.');
    }

    public function toggleBoardPicker(): void
    {
        $opening = ! $this->boardPickerOpen;
        $this->closeOverlays();
        $this->boardPickerOpen = $opening;

        $opening
            ? $this->toastSuccess('Board picker open', 'Pick the board you want to work on.')
            : $this->toastSuccess('Board picker closed');
    }

    public function toggleListMenu(int $listId): void
    {
        $opening = $this->listMenuOpen !== $listId;
        $this->closeOverlays();
        $this->listMenuOpen = $opening ? $listId : null;

        $opening
            ? $this->toastSuccess('List menu open', 'Add a card, or archive what the list holds.')
            : $this->toastSuccess('List menu closed');
    }

    public function toggleFilterPanel(): void
    {
        $opening = ! $this->filterOpen;
        $this->closeOverlays();
        $this->filterOpen = $opening;

        $opening
            ? $this->toastSuccess('Filter panel open', 'Narrow the board by label, member or due date.')
            : $this->toastSuccess('Filter panel closed', 'Whatever you set is still applied.');
    }

    public function closeFilterPanel(): void
    {
        $wasOpen = $this->filterOpen;

        $this->filterOpen = false;

        if ($wasOpen) {
            $this->toastSuccess('Filter panel closed', 'Whatever you set is still applied.');
        }
    }

    /**
     * Redraw the board canvas.
     *
     * The canvas is an island, and an island nobody names keeps whatever the
     * DOM already had. Every action that changes a card has to come through
     * here or the change is silently discarded on the way to the browser.
     */
    private function refreshBoard(): void
    {
        $this->resolvedLists = null;

        $this->renderIsland('board');
    }

    /* Filters --------------------------------------------------------------- */

    public function toggleLabelFilter(int $labelId): void
    {
        $current = $this->labelFilterIds();

        $this->filterLabels = in_array($labelId, $current, true)
            ? array_values(array_diff($current, [$labelId]))
            : [...$current, $labelId];

        $name = $this->labels()->firstWhere('id', $labelId)?->name ?? 'Label';

        $this->refreshBoard();

        $this->toastSuccess(
            in_array($labelId, $this->labelFilterIds(), true)
                ? $name.' added to the filter'
                : $name.' dropped from the filter',
            $this->filterSummary(),
        );
    }

    public function toggleAssigneeFilter(int $userId): void
    {
        $current = $this->assigneeFilterIds();

        $this->filterAssignees = in_array($userId, $current, true)
            ? array_values(array_diff($current, [$userId]))
            : [...$current, $userId];

        $name = $this->members()->firstWhere('id', $userId)?->name ?? 'Member';

        $this->refreshBoard();

        $this->toastSuccess(
            in_array($userId, $this->assigneeFilterIds(), true)
                ? $name.' added to the filter'
                : $name.' dropped from the filter',
            $this->filterSummary(),
        );
    }

    public function setDueFilter(string $state): void
    {
        $this->filterDue = $this->filterDue === $state ? '' : $state;

        $this->refreshBoard();

        $this->toastSuccess(
            $this->filterDue === '' ? 'Due date filter cleared' : 'Due date filter set',
            $this->filterSummary(),
        );
    }

    /** Typing in either search box changes what the canvas shows. */
    public function updatedSearch(): void
    {
        $this->refreshBoard();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterLabels = [];
        $this->filterAssignees = [];
        $this->filterDue = '';

        $this->refreshBoard();

        $this->toastSuccess('Filters cleared', $this->filterSummary());
    }

    /* Inline creation -------------------------------------------------------- */

    public function startAddCard(int $listId): void
    {
        $this->closeOverlays();
        $this->addingCardIn = $listId;

        $this->toastSuccess('Card form open', 'It sits at the bottom of the list.');
    }

    public function cancelAddCard(): void
    {
        $wasOpen = $this->addingCardIn !== null;

        $this->addingCardIn = null;
        $this->newCardTitle = '';

        if ($wasOpen) {
            $this->toastSuccess('Card form closed', 'Nothing was added.');
        }
    }

    /** Create a card at the bottom of a list. */
    public function addCard(int $listId): void
    {
        $title = trim($this->newCardTitle);

        if ($title === '') {
            $this->toastError('The card needs a title', 'Type what has to happen, then add it.');

            return;
        }

        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        $card = app(CardService::class)->append($list, $title);

        // Keep the form open: adding one card usually means adding three.
        $this->newCardTitle = '';
        $this->addingCardIn = $listId;

        $this->refreshBoard();

        $this->toastSuccess('Card added', $card->title.' is at the bottom of '.$list->name.'.');
    }

    public function startAddList(): void
    {
        $this->closeOverlays();
        $this->addingList = true;

        $this->toastSuccess('List form open', 'It sits at the end of the board.');
    }

    public function cancelAddList(): void
    {
        $wasOpen = $this->addingList;

        $this->addingList = false;
        $this->newListName = '';

        if ($wasOpen) {
            $this->toastSuccess('List form closed', 'Nothing was added.');
        }
    }

    /** Create a list at the end of the board. */
    public function addList(): void
    {
        $name = trim($this->newListName);

        if ($name === '') {
            $this->toastError('The list needs a name', 'Something like "Waiting on client".');

            return;
        }

        $board = $this->board();

        if ($board === null) {
            $this->toastError('No board is open', 'Pick a board first.');

            return;
        }

        $last = BoardList::query()->where('board_id', $board->id)->orderByDesc('position')->value('position');

        $list = BoardList::query()->create([
            'board_id' => $board->id,
            'name' => $name,
            'position' => Position::after($last === null ? null : Position::format((string) $last)),
            'created_by' => auth()->id(),
        ]);

        $this->newListName = '';
        $this->addingList = true;

        $this->refreshBoard();

        $this->toastSuccess('List added', $list->name.' is at the end of the board.');
    }

    /* Card and list actions --------------------------------------------------- */

    private function listOnThisBoard(int $listId): ?BoardList
    {
        $board = $this->board();

        if ($board === null) {
            return null;
        }

        return BoardList::query()
            ->where('board_id', $board->id)
            ->active()
            ->find($listId);
    }

    /**
     * Called by Sortable when a card is dropped.
     *
     * `$position` is the index the card landed on among the cards the browser
     * could see, which is not an offset into the list when a filter is hiding
     * rows between them.
     */
    public function moveCard(int $cardId, string $toList, int $position): void
    {
        $list = $this->listOnThisBoard((int) $toList);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try the move again.');

            return;
        }

        $card = Card::query()->active()->find($cardId);

        if ($card === null) {
            $this->toastError('That card is gone', 'It was archived or deleted while the page was open.');
            $this->refreshBoard();

            return;
        }

        $from = $card->list;

        app(CardService::class)->move($card, $list, $position, $this->visibleCardIds($list));

        $this->refreshBoard();

        $this->toastSuccess(
            'Card moved',
            $from && $from->id !== $list->id
                ? $card->title.' is now in '.$list->name.'.'
                : $card->title.' moved within '.$list->name.'.',
        );
    }

    /**
     * Open the card drawer, which listens for this event.
     *
     * The drawer is what actually opens, so the drawer is what reports it.
     */
    public function openCard(int $cardId): void
    {
        $this->dispatch('open-card', cardId: $cardId);
    }

    /** Open the board template picker, which listens for this event and reports it. */
    public function openTemplates(): void
    {
        $this->dispatch('open-board-templates');
    }

    /** A card changed in the drawer. Redraw the canvas so the front matches. */
    #[On('card-changed')]
    public function cardChanged(): void
    {
        $this->refreshBoard();
    }

    public function archiveList(int $listId): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board');

            return;
        }

        $cards = Card::query()->where('board_list_id', $list->id)->active()->update(['archived_at' => now()]);

        $list->forceFill(['archived_at' => now()])->save();

        $this->closeOverlays();
        $this->refreshBoard();

        $this->toastSuccess(
            $list->name.' archived',
            $cards === 0
                ? 'It was empty. Nothing else moved.'
                : $cards.' '.str('card')->plural($cards).' went with it, and can be restored from the archive.',
        );
    }

    public function archiveCardsInList(int $listId): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board');

            return;
        }

        $cards = Card::query()->where('board_list_id', $list->id)->active()->update(['archived_at' => now()]);

        $this->closeOverlays();
        $this->refreshBoard();

        $cards === 0
            ? $this->toastSuccess('Nothing to archive', $list->name.' was already empty.')
            : $this->toastSuccess(
                $cards.' '.str('card')->plural($cards).' archived',
                $list->name.' is still on the board, and the cards are in the archive.',
            );
    }
};

?>

<div class="flex flex-col gap-5 h-full">

    {{--
        Click-away for whichever panel is open. Driven from component state
        rather than from a listener, because an attribute added by the morph is
        not a listener the browser has bound. It sits below the panels' own
        z-20 and above everything else, so only the panel stays clickable.
    --}}
    @if ($filterOpen || $boardPickerOpen || $listMenuOpen !== null)
        <div class="fixed inset-0 z-10" wire:click="dismissPanels" aria-hidden="true"></div>
    @endif

    {{-- Board toolbar --}}
    <div class="flex flex-wrap items-start justify-between gap-3">

        <div class="flex items-center gap-3">
            <div>
                <h1 class="text-xl font-semibold text-mono">{{ $this->boardName() }}</h1>
                <p class="text-sm text-secondary-foreground mt-1">Everything you owe a client, in the order you will do it.</p>
            </div>

            {{-- Board picker --}}
            <div class="relative">
                <button wire:click="toggleBoardPicker" class="kt-btn kt-btn-outline gap-2" aria-haspopup="true" aria-expanded="{{ $boardPickerOpen ? 'true' : 'false' }}">
                    @foreach ($boards as $b)
                        @if ($b->slug === $activeBoard)
                            <span class="size-2.5 rounded-full {{ $b->dotClass() }}"></span>
                            {{ $b->name }}
                        @endif
                    @endforeach
                    <i class="ki-filled ki-down text-xs"></i>
                </button>

                <div class="kt-dropdown absolute z-20 mt-1 w-[240px] start-0 {{ $boardPickerOpen ? 'open' : '' }}">
                    <div class="p-2 flex flex-col gap-1">
                        @forelse ($boards as $b)
                            <button wire:click="selectBoard('{{ $b->slug }}')" wire:key="pick-{{ $b->id }}"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full {{ $b->slug === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full {{ $b->dotClass() }}"></span>
                                {{ $b->name }}
                            </button>
                        @empty
                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">No boards yet.</p>
                        @endforelse
                    </div>
                    @if ($activeBoard !== '')
                        <div class="border-t border-border p-2">
                            <a href="{{ route('projects.board-settings', ['board' => $activeBoard]) }}" wire:navigate
                               class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                <i class="ki-filled ki-setting-2 text-sm"></i> Board settings
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <div class="kt-input max-w-[220px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search cards…" aria-label="Search cards"
                       wire:model.live.debounce.300ms="search">
            </div>

            {{-- Filter panel --}}
            <div class="relative">
                <button wire:click="toggleFilterPanel"
                        class="kt-btn kt-btn-outline gap-2 {{ $activeFilters > 0 ? 'border-primary/30 text-primary' : '' }}"
                        aria-haspopup="true" aria-expanded="{{ $filterOpen ? 'true' : 'false' }}">
                    <i class="ki-filled ki-filter"></i>
                    Filter
                    @if ($activeFilters > 0)
                        <span class="kt-badge kt-badge-sm kt-badge-primary">{{ $activeFilters }}</span>
                    @endif
                </button>

                <div class="kt-dropdown absolute z-20 mt-1 end-0 w-[320px] {{ $filterOpen ? 'open' : '' }}" wire:keydown.escape="closeFilterPanel">

                    <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-border">
                        <h3 class="text-sm font-semibold text-mono">Filter cards</h3>
                        <button wire:click="closeFilterPanel" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                title="Close filters" aria-label="Close filters">
                            <i class="ki-filled ki-cross text-sm"></i>
                        </button>
                    </div>

                    <div class="flex flex-col gap-4 px-4 py-4 max-h-[420px] overflow-y-auto kt-scrollable-y">

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label text-xs" for="filter-query">Card text</label>
                            <input id="filter-query" type="text" class="kt-input" placeholder="Words in the card title"
                                   wire:model.live.debounce.300ms="search">
                        </div>

                        <div class="flex flex-col gap-2">
                            <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Labels</span>
                            @forelse ($labels as $label)
                                <button wire:click="toggleLabelFilter({{ $label->id }})" wire:key="flabel-{{ $label->id }}"
                                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60 {{ in_array((string) $label->id, array_map('strval', $filterLabels), true) ? 'bg-accent/60' : '' }}">
                                    <span class="size-3 rounded-sm {{ $label->dotClass() }}"></span>
                                    <span class="grow text-secondary-foreground">{{ $label->name }}</span>
                                    @if (in_array((string) $label->id, array_map('strval', $filterLabels), true))
                                        <i class="ki-filled ki-check text-sm text-primary"></i>
                                    @endif
                                </button>
                            @empty
                                <p class="text-xs text-muted-foreground">This board has no labels yet.</p>
                            @endforelse
                        </div>

                        <div class="flex flex-col gap-2">
                            <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Members</span>
                            @forelse ($members as $member)
                                <button wire:click="toggleAssigneeFilter({{ $member->id }})" wire:key="fwho-{{ $member->id }}"
                                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60 {{ in_array((string) $member->id, array_map('strval', $filterAssignees), true) ? 'bg-accent/60' : '' }}">
                                    <span class="size-6 rounded-full grid place-items-center text-[10px] font-semibold bg-primary/15 text-primary">{{ $member->initials() }}</span>
                                    <span class="grow text-secondary-foreground">{{ $member->name }}</span>
                                    @if (in_array((string) $member->id, array_map('strval', $filterAssignees), true))
                                        <i class="ki-filled ki-check text-sm text-primary"></i>
                                    @endif
                                </button>
                            @empty
                                <p class="text-xs text-muted-foreground">Nobody to filter by yet.</p>
                            @endforelse
                        </div>

                        <div class="flex flex-col gap-2">
                            <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Due date</span>
                            @foreach ($dueOptions as $key => $option)
                                <button wire:click="setDueFilter('{{ $key }}')"
                                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60 {{ $filterDue === $key ? 'bg-accent/60' : '' }}">
                                    <i class="ki-filled {{ $option['icon'] }} text-sm {{ $option['tone'] }}"></i>
                                    <span class="grow text-secondary-foreground">{{ $option['label'] }}</span>
                                    @if ($filterDue === $key)
                                        <i class="ki-filled ki-check text-sm text-primary"></i>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-2 px-4 py-3 border-t border-border">
                        <span class="text-xs text-muted-foreground" wire:loading.remove wire:target="search">
                            Showing {{ $visibleCards }} of {{ $totalCards }} cards
                        </span>
                        <span class="text-xs text-muted-foreground" wire:loading wire:target="search">
                            <i class="ki-filled ki-loading animate-spin"></i> Filtering…
                        </span>
                        <button wire:click="clearFilters" class="kt-btn kt-btn-sm kt-btn-ghost" @disabled($activeFilters === 0)>
                            Clear all
                        </button>
                    </div>
                </div>
            </div>

            @if ($activeBoard !== '')
                <a href="{{ route('projects.board-settings', ['board' => $activeBoard]) }}" wire:navigate
                   class="kt-btn kt-btn-outline kt-btn-icon" title="Board settings" aria-label="Board settings">
                    <i class="ki-filled ki-setting-2"></i>
                </a>
            @endif

            <button wire:click="openTemplates" class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New board
            </button>
        </div>
    </div>

    {{--
        The board canvas. Exactly one island in this file — see the class
        docblock for why it is not one per column, and why every action that
        changes a card calls refreshBoard().
    --}}
    @island(name: 'board')
    <div class="flex gap-4 overflow-x-auto pb-4 kt-scrollable-x items-start" id="kargah-board">

        @forelse ($lists as $entry)
            @php($list = $entry['model'])
            <div class="kt-card w-[290px] shrink-0 bg-muted/40" data-list-id="{{ $list->id }}" wire:key="list-{{ $list->id }}">

                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-mono">{{ $list->name }}</h3>
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $entry['cards']->count() }}</span>
                    </div>

                    <div class="relative">
                        <button wire:click="toggleListMenu({{ $list->id }})" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                title="List actions" aria-label="List actions">
                            <i class="ki-filled ki-dots-horizontal text-sm"></i>
                        </button>
                        <div class="kt-dropdown absolute z-20 end-0 mt-1 w-[210px] {{ $listMenuOpen === $list->id ? 'open' : '' }}">
                            <div class="p-2 flex flex-col gap-1">
                                <button wire:click="startAddCard({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                    <i class="ki-filled ki-plus text-sm"></i> Add a card
                                </button>
                                <button wire:click="archiveCardsInList({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                    <i class="ki-filled ki-archive text-sm"></i> Archive the cards
                                </button>
                                <button wire:click="archiveList({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full text-destructive">
                                    <i class="ki-filled ki-trash text-sm"></i> Archive this list
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kargah-list flex flex-col gap-2 px-3 pb-3 min-h-[60px]" data-list="{{ $list->id }}">
                    @forelse ($entry['cards'] as $card)
                        <div wire:click="openCard({{ $card->id }})"
                             wire:key="card-{{ $card->id }}"
                             role="button" tabindex="0"
                             aria-label="Open card {{ $card->title }}"
                             class="kt-card bg-background border border-border rounded-lg p-3 cursor-grab hover:border-primary/40 transition-colors active:cursor-grabbing"
                             data-card-id="{{ $card->id }}">

                            @if ($card->labels->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mb-2">
                                    @foreach ($card->labels as $label)
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded {{ $label->chipClass() }}">
                                            {{ $label->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <p class="text-sm text-mono leading-snug">{{ $card->title }}</p>

                            @php($dueState = $card->dueState())

                            @if ($card->checklist_total > 0 || $card->due_on || $card->comments_count > 0 || $card->members->isNotEmpty())
                                <div class="flex items-center gap-3 mt-2.5 text-xs text-muted-foreground">
                                    @if ($card->checklist_total > 0)
                                        <span class="inline-flex items-center gap-1 {{ $card->checklist_done === $card->checklist_total ? 'text-success' : '' }}">
                                            <i class="ki-filled ki-check-squared text-sm"></i>
                                            {{ $card->checklist_done }}/{{ $card->checklist_total }}
                                        </span>
                                    @endif
                                    @if ($card->due_on)
                                        <span class="inline-flex items-center gap-1 {{ $dueState === 'overdue' ? 'text-destructive' : ($dueState === 'soon' ? 'text-warning' : '') }}">
                                            <i class="ki-filled ki-calendar text-sm"></i>{{ $card->due_on->format('M d') }}
                                        </span>
                                    @endif
                                    @if ($card->comments_count > 0)
                                        <span class="inline-flex items-center gap-1">
                                            <i class="ki-filled ki-message-text-2 text-sm"></i>{{ $card->comments_count }}
                                        </span>
                                    @endif
                                    @foreach ($card->members as $member)
                                        <span class="ms-auto size-6 rounded-full grid place-items-center text-[10px] font-semibold bg-primary/15 text-primary"
                                              title="{{ $member->name }}">
                                            {{ $member->initials() }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-xs text-muted-foreground text-center py-4">
                            {{ $activeFilters > 0 ? 'No cards match the filter.' : 'Nothing in this list yet.' }}
                        </p>
                    @endforelse
                </div>

                {{-- Inline card creation --}}
                @if ($addingCardIn === $list->id)
                    <div class="flex flex-col gap-2 px-3 pb-3">
                        <textarea rows="2" class="kt-textarea" autofocus
                                  aria-label="New card title"
                                  placeholder="e.g. Draft the Northwind scope document"
                                  wire:model="newCardTitle"
                                  wire:keydown.escape="cancelAddCard"
                                  wire:keydown.enter.prevent="addCard({{ $list->id }})"></textarea>
                        <div class="flex items-center gap-2">
                            <button wire:click="addCard({{ $list->id }})"
                                    wire:loading.attr="disabled" wire:target="addCard"
                                    class="kt-btn kt-btn-sm kt-btn-primary gap-1">
                                <span wire:loading.remove wire:target="addCard">Add card</span>
                                <span wire:loading wire:target="addCard"><i class="ki-filled ki-loading animate-spin"></i> Adding…</span>
                            </button>
                            <button wire:click="cancelAddCard" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                            <span class="text-[11px] text-muted-foreground ms-auto">Enter to add, Esc to cancel</span>
                        </div>
                    </div>
                @else
                    <button wire:click="startAddCard({{ $list->id }})"
                            class="kt-btn kt-btn-ghost w-full justify-start gap-2 text-sm text-secondary-foreground px-4 py-2.5 rounded-b-lg">
                        <i class="ki-filled ki-plus text-sm"></i> Add a card
                    </button>
                @endif
            </div>
        @empty
            @if ($boards->isEmpty())
                <div class="w-full rounded-lg border border-dashed border-border px-6 py-14 text-center">
                    <i class="ki-filled ki-element-plus text-3xl text-muted-foreground"></i>
                    <p class="text-sm text-secondary-foreground mt-3">No boards yet. The first one takes about ten seconds.</p>
                    <button wire:click="openTemplates" class="kt-btn kt-btn-primary gap-2 mt-4">
                        <i class="ki-filled ki-plus"></i> New board
                    </button>
                </div>
            @endif
        @endforelse

        {{-- Inline list creation --}}
        @if ($activeBoard !== '')
            @if ($addingList)
                <div class="kt-card w-[290px] shrink-0 bg-muted/40 p-3 flex flex-col gap-2">
                    <input type="text" class="kt-input" autofocus
                           aria-label="New list name"
                           placeholder="e.g. Waiting on client"
                           wire:model="newListName"
                           wire:keydown.escape="cancelAddList"
                           wire:keydown.enter.prevent="addList">
                    <div class="flex items-center gap-2">
                        <button wire:click="addList" wire:loading.attr="disabled" wire:target="addList"
                                class="kt-btn kt-btn-sm kt-btn-primary gap-1">
                            <span wire:loading.remove wire:target="addList">Add list</span>
                            <span wire:loading wire:target="addList"><i class="ki-filled ki-loading animate-spin"></i> Adding…</span>
                        </button>
                        <button wire:click="cancelAddList" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                    </div>
                </div>
            @else
                <button wire:click="startAddList"
                        class="kt-card w-[290px] shrink-0 bg-muted/20 border border-dashed border-border hover:border-primary/50 transition-colors">
                    <span class="flex items-center gap-2 px-4 py-4 text-sm text-secondary-foreground">
                        <i class="ki-filled ki-plus"></i> Add another list
                    </span>
                </button>
            @endif
        @endif
    </div>
    @endisland

    {{-- Nested components --}}
    <livewire:project::card-detail />
    <livewire:project::board-templates />
{{--
    Kept inside the component's root element on purpose. Livewire renders one
    root node and discards everything after it, so a @push below the closing tag
    never reaches the page.
--}}
@script
<script>
    (function initBoard() {
        // The click that a browser fires after a drag has to be swallowed or
        // the card drawer opens on every drop. One guard for the whole page,
        // registered once however many times this component is re-initialised.
        if (! window.kargahDragGuard) {
            window.kargahDragGuard = { until: 0 };

            document.addEventListener('click', function (event) {
                if (Date.now() < window.kargahDragGuard.until && event.target.closest('[data-card-id]')) {
                    window.kargahDragGuard.until = 0;
                    event.stopPropagation();
                    event.preventDefault();
                }
            }, true);
        }

        const guard = window.kargahDragGuard;

        function mount() {
            if (typeof Sortable === 'undefined') return;

            const root = $wire.$el;

            // After a wire:navigate this closure outlives its own DOM. A hook
            // registered by the previous instance must not touch the new one.
            if (! root || ! root.isConnected) return;

            root.querySelectorAll('.kargah-list').forEach(function (el) {
                // The guard cannot be a data-* attribute: Livewire's morph
                // removes any attribute the incoming HTML does not carry, so
                // the flag cleared itself on every render and a second
                // Sortable bound to the same element — one drop, N writes.
                if (Sortable.get(el)) return;

                new Sortable(el, {
                    group: 'kargah-cards',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    dragClass: 'rotate-2',
                    // Without this the "Nothing in this list yet" paragraph is
                    // itself draggable, and lands in another list as a ghost.
                    draggable: '[data-card-id]',
                    onStart: function () {
                        guard.until = Infinity;
                    },
                    onEnd: function (evt) {
                        guard.until = Date.now() + 300;

                        if (evt.to === evt.from && evt.oldIndex === evt.newIndex) return;

                        $wire.moveCard(
                            parseInt(evt.item.dataset.cardId, 10),
                            evt.to.dataset.list,
                            evt.newIndex,
                        );
                    },
                });
            });
        }

        Livewire.hook('morphed', mount);
        mount();
    })();
</script>
@endscript
</div>
