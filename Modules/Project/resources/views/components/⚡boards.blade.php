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
use Modules\Project\Models\BoardListUserState;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Services\BoardCopier;
use Modules\Project\Services\CardService;
use Modules\Project\Services\ListOperations;
use Modules\Project\Services\PlacementConflict;
use Modules\Project\Support\Position;
use Modules\Project\Support\SearchCompiler;
use Modules\Project\Support\SearchQuery;

/**
 * Trello-style board, reading from the database.
 *
 * Three things here are worth knowing before changing anything.
 *
 * **The canvas draws placements, not cards.** A card may be placed in several
 * lists — the same work shown on the two boards it belongs to — so what a
 * column holds is a row of `card_placements`, each with its own order. The card
 * id no longer identifies what was dragged, which is why every card element
 * carries `data-placement-id` as well and `moveCard()` takes a placement.
 *
 * **The board canvas is an island.** Toggling the filter panel or the board
 * picker skips re-rendering every card on the board, because both of those are
 * drawn *outside* it. Anything inside it has to name the island or the new
 * markup is computed, sent and thrown away — Livewire returns a `mode: skip`
 * fragment for every island nobody asked for. That covers more than the cards:
 * the list ⋯ menu, the inline "add a card" form and the inline "add a list"
 * form are all inside the island too, which is why every one of their handlers
 * calls `redrawCanvas()`. There is exactly one `@island` directive in this file
 * on purpose; one inside the `@foreach` would share a token with every other
 * iteration and morph the wrong column. See project-guaid/spec/04-frontend.md.
 *
 * **`moveCard` trusts the browser for the index and nothing else.** Sortable
 * reports where the card landed among the cards it can *see*. The server knows
 * the filter, so it works out which cards those were itself rather than taking
 * a list of ids from the client. It still takes three arguments; what changed
 * is that the first one names a placement.
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

    /**
     * Which second-level panel that menu is showing, if any: `'sort'`,
     * `'move'` or `'wip'`.
     *
     * A single scalar rather than three booleans, because they are three views
     * of one menu and only one of them is ever open. Cleared by
     * `closeOverlays()` along with the menu itself, so a menu reopened is a
     * menu at its top level.
     */
    public ?string $listMenuPanel = null;

    /** The WIP limit being typed, as text — '' means "no limit". */
    public string $wipLimitInput = '';

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

    /** @var list<int>|null Which of this board's lists this person has folded away. */
    private ?array $resolvedCollapsed = null;

    /**
     * Operator tokens from the last compiled search that could not be
     * honoured — `has:cover`, for instance. Set every time `lists()` actually
     * runs the query, read by `with()` so the toolbar can say so instead of
     * quietly showing zero cards for a reason nobody typed.
     *
     * @var list<string>
     */
    private array $lastUnsupportedOperators = [];

    /**
     * An `#[Url]` property is whatever the address bar says, which may be a
     * board that was archived, deleted, or never existed.
     */
    public function mount(): void
    {
        $this->activeBoard = $this->resolveBoard($this->activeBoard);
        $this->recordView();
    }

    /**
     * Remember that this person opened this board, for the recently-viewed list.
     *
     * Called from `mount()` and from `selectBoard()`, which between them cover
     * every genuine open without writing a row on every re-render — a filter
     * keystroke is not a visit. The guard is not decorative: `markViewedBy()`
     * takes a non-nullable user and this component is reachable with none.
     */
    private function recordView(): void
    {
        if ($user = auth()->user()) {
            $this->board()?->markViewedBy($user);
        }
    }

    private function resolveBoard(string $slug): string
    {
        $slugs = $this->allBoards()->pluck('slug');

        return $slugs->contains($slug) ? $slug : (string) $slugs->first();
    }

    /* Reading the board ---------------------------------------------------- */

    private function allBoards(): Collection
    {
        // `starredFirstFor()` applies the position and name ordering itself, so
        // this is the old order with starred boards pinned above it — and
        // exactly the old order for a request with no user.
        return $this->resolvedBoards ??= Board::query()->active()->starredFirstFor(auth()->user())->get();
    }

    private function board(): ?Board
    {
        return $this->resolvedBoard ??= $this->allBoards()->firstWhere('slug', $this->activeBoard);
    }

    /**
     * Every list on the board with its placements that survive the current
     * search and filter panel, in the order the query decides — position by
     * default, or `sort:` when one was typed.
     *
     * One query per relation rather than one per card: the checklist chip is
     * two `withCount` subqueries, not a load of every item on the board. The
     * search and filter conditions ride the same `placements` query — see
     * `Modules\Project\Support\SearchCompiler` — rather than being tested in
     * PHP afterwards, which is what lets `checklist:` and `comment:` search
     * text this method has never had to load before without loading it for
     * every card whether it matched or not.
     *
     * `onCanvas()` is what implements the archived-mirror rule: an archived card
     * leaves the list it lives in, and stays on the lists it was mirrored onto,
     * where it is drawn with an archived marker and cannot be dragged.
     *
     * The origin placement is loaded with each card so a mirror can say where
     * the card actually lives. Three queries for the whole board, not one per
     * mirror.
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

        $search = SearchQuery::parse($this->search);
        $compiler = SearchCompiler::forUser(auth()->user());
        $labelIds = $this->labelFilterIds();
        $assigneeIds = $this->assigneeFilterIds();
        $due = $this->filterDue;

        return $this->resolvedLists = BoardList::query()
            ->where('board_id', $board->id)
            ->active()
            ->orderBy('position')
            ->with(['placements' => function ($query) use ($search, $compiler, $board, $labelIds, $assigneeIds, $due): void {
                // `BoardList::placements()` already carries its own
                // `orderBy('position')`. Without clearing it first, a
                // `sort:-due` would land as a *second* order-by clause and
                // lose every tie to the position order that came before it.
                $query->reorder()->onCanvas();

                $this->lastUnsupportedOperators = $compiler->apply($query, $search, $board, $labelIds, $assigneeIds, $due);

                $query->with(['card' => fn ($card) => $card
                    ->with(['labels', 'members', 'originPlacement.list.board'])
                    ->withCount([
                        'comments',
                        'votes',
                        'checklistItems as checklist_total',
                        'checklistItems as checklist_done' => fn ($q) => $q->where('is_done', true),
                    ]),
                ]);
            }])
            ->get();
    }

    /**
     * How many placements this board draws before any filter narrows them —
     * a separate, unadorned count rather than a second full load of every
     * card's labels, members and checklist counts just to discard them.
     */
    private function totalPlacementsCount(): int
    {
        $board = $this->board();

        if ($board === null) {
            return 0;
        }

        return CardPlacement::query()
            ->onCanvas()
            ->whereIn('board_list_id', BoardList::query()->where('board_id', $board->id)->active()->select('id'))
            ->count();
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

    /**
     * The lists this person has folded away, among the ones on screen.
     *
     * One query for the whole board, asked only about the lists this board
     * actually draws — a person who has collapsed forty columns across ten
     * boards should not pay for the other nine here.
     *
     * A collapsed list still loads its cards. That is deliberate: the count in
     * the folded spine has to be right, and the alternative — a second query
     * per folded column to count what the first query would have fetched
     * anyway — costs more than it saves at board scale.
     *
     * @return list<int>
     */
    private function collapsedLists(): array
    {
        return $this->resolvedCollapsed ??= BoardListUserState::collapsedIdsFor(
            auth()->user(),
            $this->lists()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );
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

    /**
     * The placements of one list that survive the current search and filter
     * panel, in order. `lists()` already loaded only the matching rows —
     * `SearchCompiler` filters and sorts in SQL, see its class docblock for
     * why — so this is a name for what is already there, not a second pass.
     */
    private function visiblePlacements(BoardList $list): Collection
    {
        return $list->placements;
    }

    /**
     * The *placement* ids the browser had on screen for a list.
     *
     * Derived from the same filter that rendered the page rather than sent up
     * with the drop: the server already knows, and a client-supplied ordering
     * is one more thing that can be wrong or forged.
     *
     * @return list<int>
     */
    private function visiblePlacementIds(BoardList $list): array
    {
        return $this->visiblePlacements($list)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * "has:cover isn't supported yet" — or null when there is nothing to say.
     * Read after `lists()` has actually run the query, which is what fills
     * `$lastUnsupportedOperators` in.
     */
    private function searchWarning(): ?string
    {
        if ($this->lastUnsupportedOperators === []) {
            return null;
        }

        $tokens = implode(', ', $this->lastUnsupportedOperators);
        $plural = count($this->lastUnsupportedOperators) > 1;

        return $tokens.' '.($plural ? "aren't" : "isn't").' supported yet, so nothing can match.';
    }

    public function with(): array
    {
        $lists = $this->lists();
        $visibleCards = $lists->sum(fn (BoardList $list): int => $this->visiblePlacements($list)->count());

        return [
            // Exposed so the canvas can read the background, the list-surface
            // colour and the text tone straight off the model rather than this
            // component re-deriving them.
            'activeBoardModel' => $this->board(),
            'boards' => $this->allBoards(),
            'labels' => $this->labels(),
            'members' => $this->members(),
            'lists' => $lists->map(fn (BoardList $list): array => [
                'model' => $list,
                'placements' => $this->visiblePlacements($list),
            ]),
            'totalCards' => $this->totalPlacementsCount(),
            'visibleCards' => $visibleCards,
            'collapsedLists' => $this->collapsedLists(),
            'sortOptions' => ListOperations::SORTS,
            'activeFilters' => count($this->filterLabels)
                + count($this->filterAssignees)
                + ($this->filterDue !== '' ? 1 : 0)
                + ($this->searchTerm() !== '' ? 1 : 0),
            'searchWarning' => $this->searchWarning(),
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
        $this->listMenuPanel = null;
        $this->wipLimitInput = '';
        $this->addingCardIn = null;
        $this->newCardTitle = '';
        $this->addingList = false;
        $this->newListName = '';
    }

    /** Click-away from any open panel. Dismissing is not worth announcing. */
    public function dismissPanels(): void
    {
        $this->closeOverlays();
        $this->redrawCanvas();
    }

    /**
     * Redraw the canvas island without discarding the memo of what is on it.
     *
     * `refreshBoard()` below is for the cases where the *cards* changed and the
     * query has to run again. This one is for the cases where only the chrome
     * drawn around them did — a menu opening, an inline form appearing — which
     * is most of them.
     *
     * It is not optional. On any request after the first,
     * `HandlesIslands::renderIslandDirective()` returns a `mode: skip`
     * fragment for every island nobody named, so markup inside the island that
     * is not explicitly re-rendered is computed by the server, discarded, and
     * never reaches the browser. The list menu, the inline "add a card" form
     * and the inline "add a list" form are all inside this file's one island,
     * and all of them were silently dead before this existed.
     */
    private function redrawCanvas(): void
    {
        $this->renderIsland('board');
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
        $this->resolvedCollapsed = null;

        // A filter set against one board's labels and people matches nothing on
        // the next one, and an empty board with no visible reason reads as a bug.
        $this->search = '';
        $this->filterLabels = [];
        $this->filterAssignees = [];
        $this->filterDue = '';

        $this->closeOverlays();
        $this->refreshBoard();
        $this->recordView();
    }

    public function toggleBoardPicker(): void
    {
        $opening = ! $this->boardPickerOpen;
        $this->closeOverlays();
        $this->boardPickerOpen = $opening;
    }

    public function toggleListMenu(int $listId): void
    {
        $opening = $this->listMenuOpen !== $listId;
        $this->closeOverlays();
        $this->listMenuOpen = $opening ? $listId : null;
        $this->redrawCanvas();
    }

    /**
     * Show one of the menu's second-level panels, or go back to the top level.
     *
     * The WIP panel arrives with the list's current limit already in the box,
     * because the common edit is "make it 4 instead of 3", not "type it from
     * nothing".
     */
    public function openListPanel(int $listId, string $panel): void
    {
        $this->listMenuOpen = $listId;
        $this->listMenuPanel = $this->listMenuPanel === $panel ? null : $panel;

        if ($this->listMenuPanel === 'wip') {
            $limit = $this->listOnThisBoard($listId)?->wip_limit;
            $this->wipLimitInput = $limit === null ? '' : (string) $limit;
        }

        $this->redrawCanvas();
    }

    public function toggleFilterPanel(): void
    {
        $opening = ! $this->filterOpen;
        $this->closeOverlays();
        $this->filterOpen = $opening;
    }

    public function closeFilterPanel(): void
    {
        $this->filterOpen = false;
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

        $this->refreshBoard();
    }

    public function toggleAssigneeFilter(int $userId): void
    {
        $current = $this->assigneeFilterIds();

        $this->filterAssignees = in_array($userId, $current, true)
            ? array_values(array_diff($current, [$userId]))
            : [...$current, $userId];

        $this->refreshBoard();
    }

    public function setDueFilter(string $state): void
    {
        $this->filterDue = $this->filterDue === $state ? '' : $state;

        $this->refreshBoard();
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
    }

    /* Inline creation -------------------------------------------------------- */

    public function startAddCard(int $listId): void
    {
        $this->closeOverlays();
        $this->addingCardIn = $listId;
        $this->redrawCanvas();
    }

    public function cancelAddCard(): void
    {
        $this->addingCardIn = null;
        $this->newCardTitle = '';
        $this->redrawCanvas();
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
        $this->redrawCanvas();
    }

    public function cancelAddList(): void
    {
        $this->addingList = false;
        $this->newListName = '';
        $this->redrawCanvas();
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

    /**
     * Archive the cards that *live* in a list, and say how many.
     *
     * The cards whose origin placement is here — those are the ones this list
     * is responsible for. A card mirrored in from another board keeps living
     * where it lives; archiving somebody else's list is not a thing that should
     * take a card off its own board.
     */
    private function archiveCardsLivingIn(BoardList $list): int
    {
        $cardIds = CardPlacement::query()
            ->where('board_list_id', $list->id)
            ->origin()
            ->pluck('card_id');

        return Card::query()->whereIn('id', $cardIds)->active()->update(['archived_at' => now()]);
    }

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
     * The first argument names a **placement**, not a card: once a card can sit
     * in two lists, a card id no longer says which of them was dragged. The
     * signature is still three arguments and the server still derives the
     * visible ordering itself, which is the part that mattered.
     *
     * `$position` is the index the card landed on among the cards the browser
     * could see, which is not an offset into the list when a filter is hiding
     * rows between them.
     */
    public function moveCard(int $placementId, string $toList, int $position): void
    {
        $list = $this->listOnThisBoard((int) $toList);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try the move again.');

            return;
        }

        $placement = CardPlacement::query()->with(['card', 'list'])->find($placementId);

        if ($placement === null || $placement->card === null) {
            $this->toastError('That card is gone', 'It was deleted while the page was open.');
            $this->refreshBoard();

            return;
        }

        $card = $placement->card;

        if ($placement->isOrigin() && $card->isArchived()) {
            $this->toastError('That card is archived', 'Restore it from the archive before moving it.');
            $this->refreshBoard();

            return;
        }

        $from = $placement->list;

        try {
            app(CardService::class)->move($placement, $list, $position, $this->visiblePlacementIds($list));
        } catch (PlacementConflict) {
            // The card is already in the target list, on another placement. The
            // unique index would refuse this too; refusing it by name is what
            // lets the board say something a person can act on.
            $this->refreshBoard();

            $this->toastError(
                'That card is already in '.$list->name,
                'A card can only sit in a list once. Remove the mirror there first.',
            );

            return;
        }

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

        $cards = $this->archiveCardsLivingIn($list);

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

        $cards = $this->archiveCardsLivingIn($list);

        $this->closeOverlays();
        $this->refreshBoard();

        $cards === 0
            ? $this->toastSuccess('Nothing to archive', $list->name.' was already empty.')
            : $this->toastSuccess(
                $cards.' '.str('card')->plural($cards).' archived',
                $list->name.' is still on the board, and the cards are in the archive.',
            );
    }

    /* The keyboard layer -------------------------------------------------------
     *
     * Trello is keyboard-driven and this board was not. Selection, movement and
     * the help sheet are all **client-side** — `j`/`k` must not cost a round
     * trip, and which card is selected is not something the server has any use
     * for. What reaches here is only the three shortcuts that change data, each
     * taking a card id read off the selected element.
     *
     * Every one re-reads the card through `cardOnThisBoard()` rather than
     * trusting the id: the browser is choosing which card to act on, and a
     * keystroke is no more trustworthy than a click.
     */

    /**
     * A card the open board actually draws — origin or mirror.
     *
     * The check is on the placement, not on the card, because that is what
     * being "on this board" means once a card can be mirrored: the card itself
     * may live on somebody else's board entirely.
     */
    private function cardOnThisBoard(int $cardId): ?Card
    {
        $board = $this->board();

        if ($board === null) {
            return null;
        }

        return Card::query()
            ->whereIn(
                'id',
                CardPlacement::query()
                    ->select('card_id')
                    ->whereIn('board_list_id', BoardList::query()->where('board_id', $board->id)->active()->select('id')),
            )
            ->find($cardId);
    }

    /** `space` — put yourself on the card, or take yourself off it. */
    public function quickAssignSelf(int $cardId): void
    {
        $user = auth()->user();
        $card = $this->cardOnThisBoard($cardId);

        if ($user === null || $card === null) {
            return;
        }

        $card->members()->toggle([$user->id]);

        $this->refreshBoard();

        $this->toastSuccess(
            $card->members()->whereKey($user->id)->exists() ? 'Added you to the card' : 'Took you off the card',
            $card->title,
        );
    }

    /** `c` — archive the selected card. */
    public function quickArchive(int $cardId): void
    {
        $card = $this->cardOnThisBoard($cardId);

        if ($card === null || $card->isArchived()) {
            return;
        }

        $card->forceFill(['archived_at' => now()])->save();

        $this->refreshBoard();

        $this->toastSuccess('Card archived', $card->title.' is in the archive, and can be restored.');
    }

    /**
     * `1`–`9` and `0` — toggle a label by its place in the board's own label
     * list, which is the order the filter panel and the card back both draw.
     *
     * Trello calls these "toggle a label by colour"; on a board whose labels
     * are ordered, position and colour are the same handle, and position is the
     * one that survives somebody recolouring a label.
     */
    public function quickToggleLabel(int $cardId, int $index): void
    {
        $card = $this->cardOnThisBoard($cardId);
        $label = $this->labels()->values()->get($index);

        if ($card === null || $label === null) {
            return;
        }

        $card->labels()->toggle([$label->id]);

        $this->refreshBoard();

        $this->toastSuccess(
            $card->labels()->whereKey($label->id)->exists() ? 'Label added' : 'Label removed',
            $label->name.' — '.$card->title,
        );
    }

    /* List operations --------------------------------------------------------- */

    /**
     * Fold a column away, or unfold it.
     *
     * No toast: the column visibly changes width, which is the whole feedback
     * anybody needs, and this is the one action here somebody does repeatedly.
     */
    public function toggleCollapse(int $listId): void
    {
        $user = auth()->user();
        $list = $this->listOnThisBoard($listId);

        if ($user === null || $list === null) {
            return;
        }

        BoardListUserState::setCollapsed(
            $user,
            $list->id,
            ! in_array($list->id, $this->collapsedLists(), true),
        );

        $this->resolvedCollapsed = null;
        $this->closeOverlays();
        $this->refreshBoard();
    }

    /** Put a list into one of `ListOperations::SORTS`. */
    public function sortList(int $listId, string $key): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board');

            return;
        }

        if (! ListOperations::isSort($key)) {
            $this->toastError('That is not a sort order', 'Pick one from the menu.');

            return;
        }

        $sorted = app(ListOperations::class)->sort($list, $key);

        $this->closeOverlays();
        $this->refreshBoard();

        $sorted === 0
            ? $this->toastSuccess('Nothing to sort', $list->name.' is empty.')
            : $this->toastSuccess(
                $list->name.' sorted',
                $sorted.' '.str('card')->plural($sorted).' by '.lcfirst(ListOperations::SORTS[$key]).'.',
            );
    }

    /**
     * Set or clear this list's WIP limit.
     *
     * An empty box means no limit, and zero means a limit of zero — a column
     * nothing should enter. They are different answers and this keeps them so.
     */
    public function saveWipLimit(int $listId): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board');

            return;
        }

        $raw = trim($this->wipLimitInput);

        if ($raw !== '' && (! ctype_digit($raw) || (int) $raw > 999)) {
            $this->toastError('That is not a limit', 'A whole number from 0 to 999, or leave it empty for no limit.');

            return;
        }

        $list->forceFill(['wip_limit' => $raw === '' ? null : (int) $raw])->save();

        $this->closeOverlays();
        $this->refreshBoard();

        $raw === ''
            ? $this->toastSuccess('Limit removed', $list->name.' can hold as many cards as it needs to.')
            : $this->toastSuccess('Limit set', $list->name.' warns above '.$raw.' '.str('card')->plural((int) $raw).'.');
    }

    /**
     * Empty one list into another, and say what could not go.
     *
     * A card already sitting in the target — mirrored into both columns — stays
     * where it is, because `(card_id, board_list_id)` is unique and a card
     * cannot be in one list twice. That is reported rather than hidden.
     */
    public function moveAllCards(int $listId, int $targetId): void
    {
        $from = $this->listOnThisBoard($listId);
        $to = $this->listOnThisBoard($targetId);

        if ($from === null || $to === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        $result = app(ListOperations::class)->moveAllCards($from, $to);

        $this->closeOverlays();
        $this->refreshBoard();

        if ($result['moved'] === 0 && $result['skipped'] === 0) {
            $this->toastSuccess('Nothing to move', $from->name.' was already empty.');

            return;
        }

        $this->toastSuccess(
            $result['moved'].' '.str('card')->plural($result['moved']).' moved',
            $result['skipped'] === 0
                ? $from->name.' is now empty, and everything is in '.$to->name.'.'
                : $result['skipped'].' stayed put — '.($result['skipped'] === 1 ? 'it is' : 'they are').' already in '.$to->name.'.',
        );
    }

    /**
     * Duplicate a list, with everything in it, onto this board.
     *
     * What travels is `BoardCopier`'s decision rather than this method's,
     * including the two the spec left open — mirrors, and custom fields. Its
     * docblock is the one place that reasoning lives.
     */
    public function copyList(int $listId): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        $result = app(BoardCopier::class)->copyList($list);

        $this->closeOverlays();
        $this->refreshBoard();

        $this->toastSuccess(
            $result['list']->name.' created',
            $result['cards'] === 0
                ? $list->name.' was empty, so the copy is too.'
                : $result['cards'].' '.str('card')->plural($result['cards']).' came across with their'
                    .' descriptions, checklists, attachments and comments. The activity trail did not.',
        );
    }
};

?>

@php
    // Only the root element's own class and inline style are computed out
    // here. Everything the canvas itself needs is recomputed just inside the
    // island below — an island compiles to its own included view, evaluated
    // with the component's data but not with a plain local variable declared
    // in the surrounding template, so a value assigned only here would read
    // as undefined the moment the island renders on its own.
    $boardBackgroundClass = $activeBoardModel?->backgroundClass() ?? '';
    $boardBackgroundStyle = $activeBoardModel?->backgroundStyle();
@endphp
<div class="flex flex-col gap-5 h-full {{ $boardBackgroundClass }}"
     @if ($boardBackgroundStyle) style="{{ $boardBackgroundStyle }}" @endif>

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
                        {{--
                            The star is a sibling of the row, not a child of it:
                            a button cannot nest inside a button, and the row is
                            one. `grow min-w-0` rather than `w-full` so the name
                            truncates instead of shoving the star out of the
                            dropdown.
                        --}}
                        @forelse ($boards as $b)
                            <div wire:key="pick-{{ $b->id }}" class="flex items-center gap-1">
                                <button wire:click="selectBoard('{{ $b->slug }}')"
                                        class="kt-btn kt-btn-ghost justify-start gap-2 grow min-w-0 {{ $b->slug === $activeBoard ? 'bg-accent/60' : '' }}">
                                    <span class="size-2.5 rounded-full {{ $b->dotClass() }}"></span>
                                    {{ $b->name }}
                                </button>
                                <livewire:project::board-star :board-id="$b->id" :key="'board-star-'.$b->id" />
                            </div>
                        @empty
                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">No boards yet.</p>
                        @endforelse
                    </div>
                    @if ($activeBoard !== '')
                        <div class="border-t border-border p-2 flex flex-col gap-1">
                            <a href="{{ route('projects.board-settings', ['board' => $activeBoard]) }}" wire:navigate
                               class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                <i class="ki-filled ki-setting-2 text-sm"></i> Board settings
                            </a>
                            {{--
                                No `wire:navigate` on any of these three. The two
                                exports are downloads, which a SPA navigation
                                swallows, and the print page renders on its own
                                bare layout that the current one must not morph
                                into.
                            --}}
                            <a href="{{ route('projects.print', ['board' => $activeBoard]) }}" target="_blank" rel="noopener"
                               class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                <i class="ki-filled ki-printer text-sm"></i> Print this board
                            </a>
                            <div class="flex items-center gap-1">
                                <a href="{{ route('projects.export', ['board' => $activeBoard, 'format' => 'csv']) }}"
                                   class="kt-btn kt-btn-ghost justify-start gap-2 grow min-w-0">
                                    <i class="ki-filled ki-file-down text-sm"></i> CSV
                                </a>
                                <a href="{{ route('projects.export', ['board' => $activeBoard, 'format' => 'json']) }}"
                                   class="kt-btn kt-btn-ghost justify-start gap-2 grow min-w-0">
                                    <i class="ki-filled ki-code text-sm"></i> JSON
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @if ($activeBoardModel !== null)
                {{-- Keyed apart from the picker's stars: the same board appears in both. --}}
                <livewire:project::board-star :board-id="$activeBoardModel->id" :key="'board-star-header-'.$activeBoardModel->id" />
            @endif
        </div>

        <div class="flex items-center gap-2">
            {{-- Views. Table, Calendar and Dashboard already carry the matching switcher back to Board. --}}
            <div class="flex items-center gap-1">
                <span class="kt-btn kt-btn-sm kt-btn-primary gap-1.5">
                    <i class="ki-filled ki-row-horizontal text-sm"></i> Board
                </span>
                <a href="{{ route('projects.table', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                    <i class="ki-filled ki-row-vertical text-sm"></i> Table
                </a>
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
                            <label class="kt-form-label text-xs" for="filter-query">Search</label>
                            <input id="filter-query" type="text" class="kt-input" placeholder="Words, or member: label: due:overdue sort:-due …"
                                   wire:model.live.debounce.300ms="search">
                            @if ($searchWarning)
                                <p class="text-xs text-destructive">{{ $searchWarning }}</p>
                            @endif
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
    @php
        // An island compiles to its own included view file, evaluated with
        // only the component's own data — a local `@php` variable declared in
        // the surrounding template (as `$boardSurfaceClass` briefly was,
        // above the root element) does not cross that boundary and reads as
        // undefined the moment this fragment is rendered on its own, which a
        // fresh `/projects` load never exercises but a later `refreshBoard()`
        // does. `$activeBoardModel` itself is `with()` data, so it *is*
        // available here; everything derived from it is recomputed inside
        // the island instead, once per render rather than once per list.
        $boardSurfaceClass = $activeBoardModel?->canvasSurfaceClass() ?? 'bg-muted/40';
        $boardHasBackground = $activeBoardModel !== null
            && ($activeBoardModel->backgroundClass() !== '' || $activeBoardModel->backgroundStyle() !== null);
        $boardTextTone = $boardHasBackground ? $activeBoardModel->textToneClass() : null;
    @endphp
    <div class="flex gap-4 overflow-x-auto pb-4 kt-scrollable-x items-start" id="kargah-board">

        @forelse ($lists as $entry)
            @php($list = $entry['model'])
            @php($listCount = $entry['placements']->count())
            @php($isCollapsed = in_array((int) $list->id, $collapsedLists, true))

            @if ($isCollapsed)
                {{--
                    A folded column. Rendered as its own branch rather than as a
                    class on the open one, because the point of folding is that
                    the cards are *not* in the DOM: a board with eight columns
                    folded should cost eight buttons, not eight hidden lists.

                    It carries no `.kargah-list` and no `data-list`, so Sortable
                    never binds to it and a card cannot be dropped into
                    somewhere with no visible place to land.
                --}}
                <div class="kt-card w-[48px] shrink-0 {{ $boardSurfaceClass }} self-stretch"
                     data-list-id="{{ $list->id }}" wire:key="list-{{ $list->id }}">
                    <button wire:click="toggleCollapse({{ $list->id }})"
                            class="flex flex-col items-center gap-3 w-full h-full py-3 rounded-lg hover:bg-accent/40 transition-colors"
                            title="Expand {{ $list->name }}" aria-label="Expand {{ $list->name }}">
                        <i class="ki-filled ki-double-right text-sm {{ $boardTextTone ?? 'text-muted-foreground' }}"></i>
                        <span class="{{ $list->wipBadgeClass($listCount) }}">{{ $listCount }}</span>
                        {{-- Inline, not a Tailwind class: `writing-mode` has no utility in the compiled sheet. --}}
                        <span class="text-sm font-semibold whitespace-nowrap {{ $boardTextTone ?? 'text-mono' }}"
                              style="writing-mode: vertical-rl;">{{ $list->name }}</span>
                    </button>
                </div>
            @else
            <div class="kt-card w-[290px] shrink-0 {{ $boardSurfaceClass }}" data-list-id="{{ $list->id }}" wire:key="list-{{ $list->id }}">

                <div class="flex items-center justify-between px-4 py-3 rounded-t-lg {{ $list->headerColourClass() ?? '' }}">
                    <div class="flex items-center gap-2 min-w-0">
                        <h3 class="text-sm font-semibold truncate {{ $boardTextTone ?? 'text-mono' }}">{{ $list->name }}</h3>
                        {{--
                            The count, and against what. A list with no limit
                            shows the bare number it always did; a list with one
                            shows `3/5` and turns amber at the limit and red
                            past it. Nothing refuses the drop — see
                            `BoardList::wipState()` for why.
                        --}}
                        <span class="{{ $list->wipBadgeClass($listCount) }} shrink-0"
                              @if ($list->hasWipLimit()) title="{{ $listCount }} of a {{ $list->wip_limit }} card limit" @endif>
                            {{ $listCount }}@if ($list->hasWipLimit())/{{ $list->wip_limit }}@endif
                        </span>
                    </div>

                    <div class="flex items-center gap-0.5 shrink-0">
                        <button wire:click="toggleCollapse({{ $list->id }})" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                title="Collapse {{ $list->name }}" aria-label="Collapse {{ $list->name }}">
                            <i class="ki-filled ki-double-left text-sm"></i>
                        </button>

                        <div class="relative">
                            <button wire:click="toggleListMenu({{ $list->id }})" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                    title="List actions" aria-label="List actions">
                                <i class="ki-filled ki-dots-horizontal text-sm"></i>
                            </button>
                            <div class="kt-dropdown absolute z-20 end-0 mt-1 w-[250px] {{ $listMenuOpen === $list->id ? 'open' : '' }}">
                                @if ($listMenuOpen === $list->id && $listMenuPanel === 'sort')
                                    <div class="flex items-center gap-2 px-3 py-2 border-b border-border">
                                        <button wire:click="openListPanel({{ $list->id }}, 'sort')" class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                title="Back" aria-label="Back to the list menu">
                                            <i class="ki-filled ki-left text-xs"></i>
                                        </button>
                                        <span class="text-xs font-semibold text-mono">Sort the cards</span>
                                    </div>
                                    <div class="p-2 flex flex-col gap-1">
                                        @foreach ($sortOptions as $sortKey => $sortLabel)
                                            <button wire:click="sortList({{ $list->id }}, '{{ $sortKey }}')" wire:key="sort-{{ $list->id }}-{{ $sortKey }}"
                                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full text-sm">
                                                {{ $sortLabel }}
                                            </button>
                                        @endforeach
                                    </div>

                                @elseif ($listMenuOpen === $list->id && $listMenuPanel === 'move')
                                    <div class="flex items-center gap-2 px-3 py-2 border-b border-border">
                                        <button wire:click="openListPanel({{ $list->id }}, 'move')" class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                title="Back" aria-label="Back to the list menu">
                                            <i class="ki-filled ki-left text-xs"></i>
                                        </button>
                                        <span class="text-xs font-semibold text-mono">Move all cards to…</span>
                                    </div>
                                    <div class="p-2 flex flex-col gap-1 max-h-[280px] overflow-y-auto kt-scrollable-y">
                                        @php($somewhereToGo = false)
                                        @foreach ($lists as $target)
                                            @if ($target['model']->id !== $list->id)
                                                @php($somewhereToGo = true)
                                                <button wire:click="moveAllCards({{ $list->id }}, {{ $target['model']->id }})"
                                                        wire:key="moveall-{{ $list->id }}-{{ $target['model']->id }}"
                                                        class="kt-btn kt-btn-ghost justify-start gap-2 w-full text-sm">
                                                    <i class="ki-filled ki-exit-right text-sm"></i>
                                                    <span class="truncate">{{ $target['model']->name }}</span>
                                                </button>
                                            @endif
                                        @endforeach
                                        @unless ($somewhereToGo)
                                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">
                                                This is the only list on the board.
                                            </p>
                                        @endunless
                                    </div>

                                @elseif ($listMenuOpen === $list->id && $listMenuPanel === 'wip')
                                    <div class="flex items-center gap-2 px-3 py-2 border-b border-border">
                                        <button wire:click="openListPanel({{ $list->id }}, 'wip')" class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                title="Back" aria-label="Back to the list menu">
                                            <i class="ki-filled ki-left text-xs"></i>
                                        </button>
                                        <span class="text-xs font-semibold text-mono">Work-in-progress limit</span>
                                    </div>
                                    <div class="p-3 flex flex-col gap-2">
                                        <input type="text" inputmode="numeric" class="kt-input" autofocus
                                               aria-label="Card limit for {{ $list->name }}"
                                               placeholder="No limit"
                                               wire:model="wipLimitInput"
                                               wire:keydown.enter.prevent="saveWipLimit({{ $list->id }})">
                                        <p class="text-[11px] text-muted-foreground">
                                            The header warns when the list goes over. Nothing is refused. Leave it empty for no limit.
                                        </p>
                                        <div class="flex items-center gap-2">
                                            <button wire:click="saveWipLimit({{ $list->id }})" class="kt-btn kt-btn-sm kt-btn-primary">Save</button>
                                            <button wire:click="dismissPanels" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                        </div>
                                    </div>

                                @else
                                    <div class="p-2 flex flex-col gap-1">
                                        <button wire:click="startAddCard({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                            <i class="ki-filled ki-plus text-sm"></i> Add a card
                                        </button>
                                        <button wire:click="openListPanel({{ $list->id }}, 'sort')" class="kt-btn kt-btn-ghost justify-between gap-2 w-full">
                                            <span class="flex items-center gap-2"><i class="ki-filled ki-sort text-sm"></i> Sort the cards</span>
                                            <i class="ki-filled ki-right text-xs"></i>
                                        </button>
                                        <button wire:click="openListPanel({{ $list->id }}, 'move')" class="kt-btn kt-btn-ghost justify-between gap-2 w-full">
                                            <span class="flex items-center gap-2"><i class="ki-filled ki-exit-right text-sm"></i> Move all cards</span>
                                            <i class="ki-filled ki-right text-xs"></i>
                                        </button>
                                        <button wire:click="openListPanel({{ $list->id }}, 'wip')" class="kt-btn kt-btn-ghost justify-between gap-2 w-full">
                                            <span class="flex items-center gap-2"><i class="ki-filled ki-abstract-26 text-sm"></i> Set a card limit</span>
                                            <span class="text-xs text-muted-foreground">{{ $list->hasWipLimit() ? $list->wip_limit : 'None' }}</span>
                                        </button>
                                        <button wire:click="toggleCollapse({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                            <i class="ki-filled ki-double-left text-sm"></i> Collapse this list
                                        </button>
                                        <button wire:click="copyList({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                            <i class="ki-filled ki-copy text-sm"></i> Copy this list
                                        </button>

                                        <div class="border-t border-border my-1"></div>

                                        <button wire:click="archiveCardsInList({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                            <i class="ki-filled ki-archive text-sm"></i> Archive the cards
                                        </button>
                                        <button wire:click="archiveList({{ $list->id }})" class="kt-btn kt-btn-ghost justify-start gap-2 w-full text-destructive">
                                            <i class="ki-filled ki-trash text-sm"></i> Archive this list
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kargah-list flex flex-col gap-2 px-3 pb-3 min-h-[60px]" data-list="{{ $list->id }}">
                    @forelse ($entry['placements'] as $placement)
                        @php($card = $placement->card)
                        @php($isMirror = $placement->isMirror())
                        @php($isArchived = $card->isArchived())
                        @php($livesIn = $isMirror ? $card->originPlacement?->list : null)
                        @php($cardCover = $card->coverPresentation())
                        {{--
                            Equivalent to `$card->coverHidesBadges()`, not a
                            re-derivation from the raw `cover_size` column: it
                            reads the very `$cardCover` this render already
                            resolved, which is what folds in "the image cover
                            still resolves" for a deleted attachment. Calling
                            `coverHidesBadges()` here too would run
                            `coverPresentation()` a second time per card —
                            doubling the attachment lookups this file is
                            already flagged for.
                        --}}
                        @php($hideCardBadges = $cardCover !== null && $cardCover['size'] === 'full')

                        {{--
                            Keyed by placement, not by card: two placements of
                            one card on the same board would otherwise collide
                            in the morph and only one of them would ever update.
                        --}}
                        <div wire:click="openCard({{ $card->id }})"
                             wire:key="placement-{{ $placement->id }}"
                             role="button" tabindex="0"
                             aria-label="Open card {{ $card->title }}"
                             class="kt-card bg-background border rounded-lg p-3 transition-colors
                                    {{ $isArchived
                                        ? 'border-dashed border-border opacity-70 cursor-pointer'
                                        : 'border-border cursor-grab hover:border-primary/40 active:cursor-grabbing' }}"
                             data-card-id="{{ $card->id }}"
                             data-placement-id="{{ $placement->id }}"
                             @if ($isArchived) data-archived="1" @endif>

                            @if ($cardCover)
                                @if ($cardCover['type'] === 'colour')
                                    <div class="-mx-3 -mt-3 mb-2 rounded-t-lg {{ \Modules\Project\Support\Palette::tone($cardCover['colour']) }} {{ $cardCover['size'] === 'full' ? 'h-20' : 'h-8' }}"></div>
                                @else
                                    <img src="{{ $cardCover['url'] }}" alt=""
                                         class="-mx-3 -mt-3 mb-2 rounded-t-lg w-[calc(100%+1.5rem)] object-cover {{ $cardCover['size'] === 'full' ? 'h-32' : 'h-16' }}">
                                @endif
                            @endif

                            @if ($isMirror || $isArchived)
                                <div class="flex items-center gap-2 mb-2 text-[10px] text-muted-foreground">
                                    @if ($isMirror)
                                        <span class="inline-flex items-center gap-1"
                                              title="Mirrored from {{ $livesIn?->name ?? 'another list' }} on {{ $livesIn?->board?->name ?? 'another board' }}">
                                            <i class="ki-filled ki-devices-2 text-xs"></i>
                                            Mirror of {{ $livesIn?->name ?? 'another list' }}
                                        </span>
                                    @endif
                                    @if ($isArchived)
                                        <span class="kt-badge kt-badge-sm kt-badge-outline gap-1">
                                            <i class="ki-filled ki-archive text-[10px]"></i> Archived
                                        </span>
                                    @endif
                                </div>
                            @endif

                            @if ($card->labels->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mb-2">
                                    @foreach ($card->labels as $label)
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded {{ $label->chipClass() }} {{ auth()->user()?->colour_blind_mode ? $label->patternClass() : '' }}">
                                            {{ $label->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <p class="text-sm text-mono leading-snug">{{ $card->title }}</p>

                            @if ((! $hideCardBadges && ($card->checklist_total > 0 || $card->due_on || $card->comments_count > 0 || $card->votes_count > 0)) || $card->members->isNotEmpty())
                                <div class="flex items-center gap-3 mt-2.5 text-xs text-muted-foreground">
                                    @if (! $hideCardBadges && $card->checklist_total > 0)
                                        <span class="inline-flex items-center gap-1 {{ $card->checklist_done === $card->checklist_total ? 'text-success' : '' }}">
                                            <i class="ki-filled ki-check-squared text-sm"></i>
                                            {{ $card->checklist_done }}/{{ $card->checklist_total }}
                                        </span>
                                    @endif
                                    @if (! $hideCardBadges && $card->due_on)
                                        {{-- Five states, one badge: Card::dueBadgeColour() is the single mapping to a Palette key, so this and the card drawer read the same colour for the same date. --}}
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded {{ \Modules\Project\Support\Palette::tone($card->dueBadgeColour()) }}">
                                            <i class="ki-filled ki-calendar text-sm"></i>{{ $card->due_on->format('M d') }}
                                        </span>
                                    @endif
                                    @if (! $hideCardBadges && $card->comments_count > 0)
                                        <span class="inline-flex items-center gap-1">
                                            <i class="ki-filled ki-message-text-2 text-sm"></i>{{ $card->comments_count }}
                                        </span>
                                    @endif
                                    @if (! $hideCardBadges && $card->votes_count > 0)
                                        <span class="inline-flex items-center gap-1" title="{{ $card->votes_count }} {{ str('vote')->plural($card->votes_count) }}">
                                            <i class="ki-filled ki-like text-sm"></i>{{ $card->votes_count }}
                                        </span>
                                    @endif
                                    {{-- The members stack carries `ms-auto` and must stay last in this row. --}}
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
            @endif
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
                    <span class="flex items-center gap-2 px-4 py-4 text-sm {{ $boardTextTone ?? 'text-secondary-foreground' }}">
                        <i class="ki-filled ki-plus"></i> Add another list
                    </span>
                </button>
            @endif
        @endif
    </div>
    @endisland

    {{--
        The keyboard help sheet, opened with `?`.

        Deliberately **outside** the island and driven entirely by JS rather
        than by component state: an overlay listing the shortcuts is not
        something the server has an opinion about, and keeping it out here
        means opening it costs no request and cannot be swallowed by a
        `mode: skip` fragment.
    --}}
    <div id="kargah-shortcuts" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4"
         role="dialog" aria-modal="true" aria-label="Keyboard shortcuts">
        <div class="kt-card bg-background w-full max-w-[560px] max-h-[80vh] overflow-y-auto kt-scrollable-y">
            <div class="flex items-center justify-between px-5 py-4 border-b border-border">
                <h2 class="text-sm font-semibold text-mono">Keyboard shortcuts</h2>
                <button type="button" data-shortcuts-close class="kt-btn kt-btn-icon kt-btn-ghost size-7" aria-label="Close">
                    <i class="ki-filled ki-cross text-sm"></i>
                </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 px-5 py-4 text-sm">
                @foreach ([
                    '?' => 'This list',
                    'j / ↓' => 'Next card',
                    'k / ↑' => 'Previous card',
                    'Enter' => 'Open the selected card',
                    'n' => 'Add a card to that list',
                    'space' => 'Put yourself on the card',
                    'c' => 'Archive the card',
                    '1 – 0' => 'Toggle that label',
                    'f' => 'Filter',
                    'x' => 'Clear the filters',
                    'b' => 'Switch board',
                    'Esc' => 'Close whatever is open',
                ] as $keys => $does)
                    <div class="flex items-center justify-between gap-3 py-1">
                        <span class="text-secondary-foreground">{{ $does }}</span>
                        <kbd class="kt-badge kt-badge-sm kt-badge-outline font-mono">{{ $keys }}</kbd>
                    </div>
                @endforeach
            </div>
            <p class="px-5 pb-4 text-xs text-muted-foreground">
                Shortcuts are off while you are typing in a box.
            </p>
        </div>
    </div>

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
                    // An archived card left on a mirror is shown but not
                    // dragged: it is not on the board any more, it is a note
                    // saying where it was.
                    draggable: '[data-placement-id]:not([data-archived])',
                    onStart: function () {
                        guard.until = Infinity;
                    },
                    onEnd: function (evt) {
                        guard.until = Date.now() + 300;

                        if (evt.to === evt.from && evt.oldIndex === evt.newIndex) return;

                        // The placement, not the card: the same card may be on
                        // two lists of this board and only one of them moved.
                        $wire.moveCard(
                            parseInt(evt.item.dataset.placementId, 10),
                            evt.to.dataset.list,
                            evt.newIndex,
                        );
                    },
                });
            });
        }

        // ---------------------------------------------------------------
        // The keyboard layer.
        //
        // Selection lives here and nowhere else. `j`/`k` must not cost a
        // request, and which card is highlighted is not state the server has
        // any use for — so it is a placement id in a closure variable, and
        // three shortcuts out of twelve are the only ones that call $wire.
        //
        // The highlight is an **inline outline**, not a Tailwind class:
        // Tailwind's scanner cannot see a class that only exists inside a
        // string in a script block, so a `ring-2` here would simply be absent
        // from the compiled sheet. See docs/frontend-conventions.md.
        // ---------------------------------------------------------------

        // One registry on `window`, holding the *current* $wire.
        //
        // The listener below is registered once for the life of the tab, but
        // this closure is re-entered on every `wire:navigate` — so a $wire
        // captured directly would be the one belonging to a component that has
        // since been torn down, and every shortcut would silently talk to a
        // dead instance. The same reasoning as the drag guard above, and the
        // same reason neither flag can be a data-* attribute: the morph strips
        // any attribute the incoming HTML does not carry.
        const keys = window.kargahBoardKeys ||= { wire: null, selected: null, bound: false };
        keys.wire = $wire;

        function cards() {
            const root = keys.wire && keys.wire.$el;
            return root && root.isConnected
                ? Array.from(root.querySelectorAll('#kargah-board [data-placement-id]'))
                : [];
        }

        function paint() {
            cards().forEach(function (el) {
                const on = el.dataset.placementId === keys.selected;
                // Inline, not a Tailwind class: the scanner reads source text
                // and cannot see a class that only exists inside this string.
                el.style.outline = on ? '2px solid currentColor' : '';
                el.style.outlineOffset = on ? '2px' : '';
                if (on) el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            });
        }

        function select(step) {
            const all = cards();
            if (! all.length) return;

            const at = all.findIndex(el => el.dataset.placementId === keys.selected);
            keys.selected = all[
                at === -1
                    ? (step > 0 ? 0 : all.length - 1)
                    : Math.min(all.length - 1, Math.max(0, at + step))
            ].dataset.placementId;

            paint();
        }

        function selectedEl() {
            return cards().find(el => el.dataset.placementId === keys.selected) || null;
        }

        const sheet = () => document.getElementById('kargah-shortcuts');
        const sheetOpen = () => { const el = sheet(); return !! el && ! el.classList.contains('hidden'); };

        function showSheet(on) {
            const el = sheet();
            if (! el) return;
            el.classList.toggle('hidden', ! on);
            el.classList.toggle('flex', on);
        }

        // Typing in a box is typing, not a shortcut.
        function isTyping(target) {
            if (! target) return false;
            const tag = (target.tagName || '').toLowerCase();
            return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
        }

        if (! keys.bound) {
            keys.bound = true;

            document.addEventListener('click', function (event) {
                if (event.target.closest('[data-shortcuts-close]') || event.target.id === 'kargah-shortcuts') {
                    showSheet(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.metaKey || event.ctrlKey || event.altKey) return;

                if (event.key === 'Escape') {
                    if (sheetOpen()) { showSheet(false); event.preventDefault(); }
                    return;
                }

                if (isTyping(event.target)) return;

                if (event.key === '?') { showSheet(! sheetOpen()); event.preventDefault(); return; }
                if (sheetOpen()) return;

                // Only act on a board that is actually on screen — this
                // listener outlives any one page.
                const wire = keys.wire;
                if (! wire || ! document.getElementById('kargah-board')) return;

                const el = selectedEl();
                const cardId = el ? parseInt(el.dataset.cardId, 10) : null;

                switch (event.key) {
                    case 'j': case 'ArrowDown': select(1); break;
                    case 'k': case 'ArrowUp': select(-1); break;
                    case 'Enter': if (el) el.click(); break;
                    case 'n': {
                        const column = el && el.closest('[data-list-id]');
                        if (column) wire.startAddCard(parseInt(column.dataset.listId, 10));
                        break;
                    }
                    case ' ': if (cardId) wire.quickAssignSelf(cardId); break;
                    case 'c': if (cardId) wire.quickArchive(cardId); break;
                    case 'f': wire.toggleFilterPanel(); break;
                    case 'x': wire.clearFilters(); break;
                    case 'b': wire.toggleBoardPicker(); break;
                    default:
                        // '1'–'9' are the first nine labels; '0' is the tenth.
                        if (/^[0-9]$/.test(event.key) && cardId) {
                            wire.quickToggleLabel(cardId, event.key === '0' ? 9 : parseInt(event.key, 10) - 1);
                            break;
                        }
                        return;
                }

                event.preventDefault();
            });
        }

        Livewire.hook('morphed', function () { mount(); paint(); });
        mount();
    })();
</script>
@endscript
</div>
