<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Trello-style board.
 *
 * Frontend phase: boards, lists and cards come from a static fixture so the
 * interaction model (drag between lists, reorder within a list, filtering,
 * inline creation) can be built and reviewed before any persistence exists.
 * `moveCard`, `addCard` and `addList` already carry the exact signature the
 * backend will implement — only their bodies change later.
 *
 * The card drawer and the board template picker are nested components; this
 * page opens them by dispatching an event rather than by holding their state.
 */
new
#[Title('Boards — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url(as: 'board')]
    public string $activeBoard = 'client-work';

    /** Free-text filter, shared by the toolbar search box and the filter panel. */
    #[Url]
    public string $search = '';

    /** @var string[] Label keys the filter is limited to. */
    #[Url(as: 'label')]
    public array $filterLabels = [];

    /** @var string[] Member keys the filter is limited to. */
    #[Url(as: 'who')]
    public array $filterAssignees = [];

    /** One of '', 'overdue', 'soon', 'none'. */
    #[Url(as: 'due')]
    public string $filterDue = '';

    public bool $filterOpen = false;

    public bool $boardPickerOpen = false;

    /** The list whose ⋯ menu is open, if any. */
    public ?string $listMenuOpen = null;

    /** The list whose inline "add a card" form is open, if any. */
    public ?string $addingCardIn = null;

    public string $newCardTitle = '';

    public bool $addingList = false;

    public string $newListName = '';

    /**
     * An `#[Url]` property is whatever the address bar says, which may be
     * nothing this board has ever heard of. Without this the page renders an
     * empty board titled "Board" and offers no way back to a real one.
     */
    public function mount(): void
    {
        $this->activeBoard = $this->resolveBoard($this->activeBoard);
    }

    private function resolveBoard(string $key): string
    {
        $keys = array_column($this->boards(), 'key');

        return in_array($key, $keys, true) ? $key : ($keys[0] ?? '');
    }

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

    /** The search box, ignoring whitespace nobody meant to type. */
    private function searchTerm(): string
    {
        return trim($this->search);
    }

    /** Labels available on this board. */
    private function labels(): array
    {
        return [
            'copy' => ['name' => 'Copywriting', 'chip' => 'bg-primary/15 text-primary', 'dot' => 'bg-primary'],
            'outreach' => ['name' => 'Outreach', 'chip' => 'bg-success/15 text-success', 'dot' => 'bg-success'],
            'dev' => ['name' => 'Development', 'chip' => 'bg-info/15 text-info', 'dot' => 'bg-info'],
            'bug' => ['name' => 'Bug', 'chip' => 'bg-destructive/15 text-destructive', 'dot' => 'bg-destructive'],
            'finance' => ['name' => 'Finance', 'chip' => 'bg-warning/15 text-warning', 'dot' => 'bg-warning'],
            'admin' => ['name' => 'Admin', 'chip' => 'bg-accent/60 text-secondary-foreground', 'dot' => 'bg-muted-foreground'],
        ];
    }

    /** People who can be assigned a card. */
    private function members(): array
    {
        return [
            'nima' => ['name' => 'Nima Fazlipour', 'initials' => 'NF', 'tone' => 'bg-primary/15 text-primary'],
            'sara' => ['name' => 'Sara Rahimi', 'initials' => 'SR', 'tone' => 'bg-success/15 text-success'],
            'dan' => ['name' => 'Daniel Whitfield', 'initials' => 'DW', 'tone' => 'bg-info/15 text-info'],
            'mina' => ['name' => 'Mina Karimi', 'initials' => 'MK', 'tone' => 'bg-warning/15 text-warning'],
        ];
    }

    private function boards(): array
    {
        return [
            ['key' => 'client-work', 'name' => 'Client Work', 'color' => 'bg-primary'],
            ['key' => 'outreach', 'name' => 'Outreach', 'color' => 'bg-success'],
            ['key' => 'personal', 'name' => 'Personal', 'color' => 'bg-warning'],
        ];
    }

    /** Every list of the active board, before filtering. */
    private function lists(): array
    {
        $all = [
            'client-work' => [
                [
                    'id' => 'backlog', 'name' => 'Backlog',
                    'cards' => [
                        ['id' => 1, 'title' => 'Rewrite portfolio landing copy', 'labels' => ['copy'], 'assignee' => 'nima', 'due' => null, 'checklist' => [0, 4], 'comments' => 2, 'attachments' => 1],
                        ['id' => 2, 'title' => 'Collect testimonials from past clients', 'labels' => ['outreach', 'admin'], 'assignee' => 'sara', 'due' => ['label' => 'Aug 12', 'state' => 'later'], 'checklist' => [1, 3], 'comments' => 0, 'attachments' => 0],
                        ['id' => 8, 'title' => 'Scope the Bluepeak booking widget', 'labels' => ['dev'], 'assignee' => null, 'due' => null, 'checklist' => [0, 0], 'comments' => 0, 'attachments' => 2],
                    ],
                ],
                [
                    'id' => 'todo', 'name' => 'To Do',
                    'cards' => [
                        ['id' => 3, 'title' => 'Send the Northwind retainer proposal', 'labels' => ['outreach'], 'assignee' => 'nima', 'due' => ['label' => 'Aug 05', 'state' => 'soon'], 'checklist' => [5, 20], 'comments' => 1, 'attachments' => 1],
                        ['id' => 4, 'title' => 'Fix invoice PDF margins', 'labels' => ['bug'], 'assignee' => 'dan', 'due' => null, 'checklist' => [0, 0], 'comments' => 0, 'attachments' => 0],
                    ],
                ],
                [
                    'id' => 'doing', 'name' => 'In Progress',
                    'cards' => [
                        ['id' => 5, 'title' => 'Build the Acme Studio mail module', 'labels' => ['dev'], 'assignee' => 'nima', 'due' => ['label' => 'Aug 20', 'state' => 'later'], 'checklist' => [3, 9], 'comments' => 4, 'attachments' => 3],
                    ],
                ],
                [
                    'id' => 'review', 'name' => 'Review',
                    'cards' => [
                        ['id' => 6, 'title' => 'Q3 expense reconciliation', 'labels' => ['finance'], 'assignee' => 'mina', 'due' => ['label' => 'Aug 01', 'state' => 'overdue'], 'checklist' => [8, 8], 'comments' => 0, 'attachments' => 1],
                    ],
                ],
                [
                    'id' => 'done', 'name' => 'Done',
                    'cards' => [
                        ['id' => 7, 'title' => 'Register the kargah.dev domain', 'labels' => ['admin'], 'assignee' => 'nima', 'due' => null, 'checklist' => [0, 0], 'comments' => 0, 'attachments' => 0],
                    ],
                ],
            ],
            'outreach' => [
                [
                    'id' => 'leads', 'name' => 'Leads',
                    'cards' => [
                        ['id' => 11, 'title' => 'Orbit Studio — referred by Bluepeak', 'labels' => ['outreach'], 'assignee' => 'nima', 'due' => ['label' => 'Aug 04', 'state' => 'soon'], 'checklist' => [1, 4], 'comments' => 1, 'attachments' => 0],
                        ['id' => 12, 'title' => 'Follow up with Harbour & Finch', 'labels' => ['outreach'], 'assignee' => 'sara', 'due' => ['label' => 'Jul 28', 'state' => 'overdue'], 'checklist' => [0, 0], 'comments' => 3, 'attachments' => 0],
                    ],
                ],
                [
                    'id' => 'talking', 'name' => 'In Conversation',
                    'cards' => [
                        ['id' => 13, 'title' => 'Northwind Ltd — retainer renewal call', 'labels' => ['finance'], 'assignee' => 'nima', 'due' => ['label' => 'Aug 14', 'state' => 'later'], 'checklist' => [2, 5], 'comments' => 2, 'attachments' => 1],
                    ],
                ],
                [
                    'id' => 'won', 'name' => 'Won',
                    'cards' => [
                        ['id' => 14, 'title' => 'Acme Studio — signed for Q3', 'labels' => ['admin'], 'assignee' => 'nima', 'due' => null, 'checklist' => [0, 0], 'comments' => 0, 'attachments' => 2],
                    ],
                ],
            ],
            'personal' => [
                [
                    'id' => 'admin', 'name' => 'Admin',
                    'cards' => [
                        ['id' => 21, 'title' => 'File the Q2 self-assessment', 'labels' => ['finance'], 'assignee' => 'nima', 'due' => ['label' => 'Aug 07', 'state' => 'soon'], 'checklist' => [2, 6], 'comments' => 0, 'attachments' => 1],
                    ],
                ],
                [
                    'id' => 'learning', 'name' => 'Learning',
                    'cards' => [
                        ['id' => 22, 'title' => 'Finish the Livewire 4 upgrade notes', 'labels' => ['dev'], 'assignee' => 'nima', 'due' => null, 'checklist' => [4, 11], 'comments' => 1, 'attachments' => 0],
                    ],
                ],
            ],
        ];

        return $all[$this->activeBoard] ?? [];
    }

    /** Cards that survive the current filter, list by list. */
    private function filtered(array $lists): array
    {
        return array_map(function (array $list): array {
            $term = $this->searchTerm();

            $list['cards'] = array_values(array_filter($list['cards'], function (array $card) use ($term): bool {
                if ($term !== '' && stripos($card['title'], $term) === false) {
                    return false;
                }

                if ($this->filterLabels !== [] && array_intersect($this->filterLabels, $card['labels']) === []) {
                    return false;
                }

                if ($this->filterAssignees !== [] && ! in_array($card['assignee'], $this->filterAssignees, true)) {
                    return false;
                }

                return match ($this->filterDue) {
                    'overdue' => ($card['due']['state'] ?? null) === 'overdue',
                    'soon' => in_array($card['due']['state'] ?? null, ['overdue', 'soon'], true),
                    'none' => $card['due'] === null,
                    default => true,
                };
            }));

            return $list;
        }, $lists);
    }

    private function countCards(array $lists): int
    {
        return array_sum(array_map(fn (array $list): int => count($list['cards']), $lists));
    }

    /** How the board reads once the current filter is applied. Used in filter toasts. */
    private function filterSummary(): string
    {
        $lists = $this->lists();

        return 'Showing '.$this->countCards($this->filtered($lists)).' of '.$this->countCards($lists).' cards.';
    }

    public function with(): array
    {
        $lists = $this->lists();
        $visible = $this->filtered($lists);

        return [
            'boards' => $this->boards(),
            'labels' => $this->labels(),
            'members' => $this->members(),
            'lists' => $visible,
            'totalCards' => $this->countCards($lists),
            'visibleCards' => $this->countCards($visible),
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

    /** The active board's display name, for the heading. */
    public function boardName(): string
    {
        foreach ($this->boards() as $board) {
            if ($board['key'] === $this->activeBoard) {
                return $board['name'];
            }
        }

        return 'Board';
    }

    public function selectBoard(string $key): void
    {
        $this->activeBoard = $this->resolveBoard($key);

        // A filter set against one board's labels and people usually matches
        // nothing on the next one, and an empty board with no visible reason
        // reads as a bug. Switching board starts clean.
        $this->search = '';
        $this->filterLabels = [];
        $this->filterAssignees = [];
        $this->filterDue = '';

        $this->closeOverlays();

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

    public function toggleListMenu(string $listId): void
    {
        $opening = $this->listMenuOpen !== $listId;
        $this->closeOverlays();
        $this->listMenuOpen = $opening ? $listId : null;

        $opening
            ? $this->toastSuccess('List menu open', 'Add a card, or archive what the list holds.')
            : $this->toastSuccess('List menu closed');
    }

    /* Filtering ---------------------------------------------------------- */

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

    public function toggleLabelFilter(string $key): void
    {
        $this->filterLabels = in_array($key, $this->filterLabels, true)
            ? array_values(array_diff($this->filterLabels, [$key]))
            : [...$this->filterLabels, $key];

        $name = $this->labels()[$key]['name'] ?? $key;

        $this->toastSuccess(
            in_array($key, $this->filterLabels, true)
                ? $name.' added to the filter'
                : $name.' dropped from the filter',
            $this->filterSummary(),
        );
    }

    public function toggleAssigneeFilter(string $key): void
    {
        $this->filterAssignees = in_array($key, $this->filterAssignees, true)
            ? array_values(array_diff($this->filterAssignees, [$key]))
            : [...$this->filterAssignees, $key];

        $name = $this->members()[$key]['name'] ?? $key;

        $this->toastSuccess(
            in_array($key, $this->filterAssignees, true)
                ? $name.' added to the filter'
                : $name.' dropped from the filter',
            $this->filterSummary(),
        );
    }

    public function setDueFilter(string $state): void
    {
        $this->filterDue = $this->filterDue === $state ? '' : $state;

        $this->toastSuccess(
            $this->filterDue === '' ? 'Due date filter cleared' : 'Due date filter set',
            $this->filterSummary(),
        );
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterLabels = [];
        $this->filterAssignees = [];
        $this->filterDue = '';

        $this->toastSuccess('Filters cleared', $this->filterSummary());
    }

    /* Inline creation ---------------------------------------------------- */

    public function startAddCard(string $listId): void
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
    public function addCard(string $listId): void
    {
        // Backend: persist the card, then clear $newCardTitle and keep the form open.
        $this->toastInfo('Not connected yet', 'Cards are stored once the backend phase lands.');
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
        // Backend: persist the list, then clear $newListName and keep the form open.
        $this->toastInfo('Not connected yet', 'Lists are stored once the backend phase lands.');
    }

    /* Card and list actions ---------------------------------------------- */

    /**
     * Called by Sortable when a card is dropped.
     * Backend phase fills this in; the contract stays the same.
     */
    public function moveCard(int $cardId, string $toList, int $position): void
    {
        // no-op during the frontend phase
        $this->toastInfo('Not connected yet', 'The card returns to its old list on the next refresh.');
    }

    /**
     * Open the card drawer, which listens for this event.
     *
     * The drawer is what actually opens, so the drawer is what reports it.
     * Toasting here as well would fire the same message twice.
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

    public function archiveList(string $listId): void
    {
        // Backend: archive the list and every card still in it.
        $this->toastInfo('Not connected yet', 'Archiving a list lands with the backend phase.');
    }

    public function archiveCardsInList(string $listId): void
    {
        // Backend: archive the cards but keep the empty list on the board.
        $this->toastInfo('Not connected yet', 'Archiving a list of cards lands with the backend phase.');
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
                        @if ($b['key'] === $activeBoard)
                            <span class="size-2.5 rounded-full {{ $b['color'] }}"></span>
                            {{ $b['name'] }}
                        @endif
                    @endforeach
                    <i class="ki-filled ki-down text-xs"></i>
                </button>

                <div class="kt-dropdown absolute z-20 mt-1 w-[240px] start-0 {{ $boardPickerOpen ? 'open' : '' }}">
                    <div class="p-2 flex flex-col gap-1">
                        @foreach ($boards as $b)
                            <button wire:click="selectBoard('{{ $b['key'] }}')"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full {{ $b['key'] === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full {{ $b['color'] }}"></span>
                                {{ $b['name'] }}
                            </button>
                        @endforeach
                    </div>
                    <div class="border-t border-border p-2">
                        <a href="{{ route('projects.board-settings', ['board' => $activeBoard]) }}" wire:navigate
                           class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                            <i class="ki-filled ki-setting-2 text-sm"></i> Board settings
                        </a>
                    </div>
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
                            @foreach ($labels as $key => $label)
                                <button wire:click="toggleLabelFilter('{{ $key }}')"
                                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60 {{ in_array($key, $filterLabels, true) ? 'bg-accent/60' : '' }}">
                                    <span class="size-3 rounded-sm {{ $label['dot'] }}"></span>
                                    <span class="grow text-secondary-foreground">{{ $label['name'] }}</span>
                                    @if (in_array($key, $filterLabels, true))
                                        <i class="ki-filled ki-check text-sm text-primary"></i>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        <div class="flex flex-col gap-2">
                            <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Members</span>
                            @foreach ($members as $key => $member)
                                <button wire:click="toggleAssigneeFilter('{{ $key }}')"
                                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60 {{ in_array($key, $filterAssignees, true) ? 'bg-accent/60' : '' }}">
                                    <span class="size-6 rounded-full grid place-items-center text-[10px] font-semibold {{ $member['tone'] }}">{{ $member['initials'] }}</span>
                                    <span class="grow text-secondary-foreground">{{ $member['name'] }}</span>
                                    @if (in_array($key, $filterAssignees, true))
                                        <i class="ki-filled ki-check text-sm text-primary"></i>
                                    @endif
                                </button>
                            @endforeach
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

            <a href="{{ route('projects.board-settings', ['board' => $activeBoard]) }}" wire:navigate
               class="kt-btn kt-btn-outline kt-btn-icon" title="Board settings" aria-label="Board settings">
                <i class="ki-filled ki-setting-2"></i>
            </a>

            <button wire:click="openTemplates" class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New board
            </button>
        </div>
    </div>

    {{-- The board --}}
    <div class="flex gap-4 overflow-x-auto pb-4 kt-scrollable-x items-start" id="kargah-board">

        @foreach ($lists as $list)
            <div class="kt-card w-[290px] shrink-0 bg-muted/40" data-list-id="{{ $list['id'] }}" wire:key="list-{{ $list['id'] }}">

                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-mono">{{ $list['name'] }}</h3>
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ count($list['cards']) }}</span>
                    </div>

                    <div class="relative">
                        <button wire:click="toggleListMenu('{{ $list['id'] }}')" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                title="List actions" aria-label="List actions">
                            <i class="ki-filled ki-dots-horizontal text-sm"></i>
                        </button>
                        <div class="kt-dropdown absolute z-20 end-0 mt-1 w-[210px] {{ $listMenuOpen === $list['id'] ? 'open' : '' }}">
                            <div class="p-2 flex flex-col gap-1">
                                <button wire:click="startAddCard('{{ $list['id'] }}')" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                    <i class="ki-filled ki-plus text-sm"></i> Add a card
                                </button>
                                <button wire:click="archiveCardsInList('{{ $list['id'] }}')" class="kt-btn kt-btn-ghost justify-start gap-2 w-full">
                                    <i class="ki-filled ki-archive text-sm"></i> Archive the cards
                                </button>
                                <button wire:click="archiveList('{{ $list['id'] }}')" class="kt-btn kt-btn-ghost justify-start gap-2 w-full text-destructive">
                                    <i class="ki-filled ki-trash text-sm"></i> Archive this list
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kargah-list flex flex-col gap-2 px-3 pb-3 min-h-[60px]" data-list="{{ $list['id'] }}">
                    @forelse ($list['cards'] as $card)
                        <div wire:click="openCard({{ $card['id'] }})"
                             wire:key="card-{{ $card['id'] }}"
                             role="button" tabindex="0"
                             aria-label="Open card {{ $card['title'] }}"
                             class="kt-card bg-background border border-border rounded-lg p-3 cursor-grab hover:border-primary/40 transition-colors active:cursor-grabbing"
                             data-card-id="{{ $card['id'] }}">

                            @if (count($card['labels']))
                                <div class="flex flex-wrap gap-1 mb-2">
                                    @foreach ($card['labels'] as $labelKey)
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded {{ $labels[$labelKey]['chip'] }}">
                                            {{ $labels[$labelKey]['name'] }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <p class="text-sm text-mono leading-snug">{{ $card['title'] }}</p>

                            @if ($card['checklist'][1] > 0 || $card['due'] || $card['comments'] > 0 || $card['attachments'] > 0 || $card['assignee'])
                                <div class="flex items-center gap-3 mt-2.5 text-xs text-muted-foreground">
                                    @if ($card['checklist'][1] > 0)
                                        <span class="inline-flex items-center gap-1 {{ $card['checklist'][0] === $card['checklist'][1] ? 'text-success' : '' }}">
                                            <i class="ki-filled ki-check-squared text-sm"></i>
                                            {{ $card['checklist'][0] }}/{{ $card['checklist'][1] }}
                                        </span>
                                    @endif
                                    @if ($card['due'])
                                        <span class="inline-flex items-center gap-1 {{ $card['due']['state'] === 'overdue' ? 'text-destructive' : ($card['due']['state'] === 'soon' ? 'text-warning' : '') }}">
                                            <i class="ki-filled ki-calendar text-sm"></i>{{ $card['due']['label'] }}
                                        </span>
                                    @endif
                                    @if ($card['comments'] > 0)
                                        <span class="inline-flex items-center gap-1">
                                            <i class="ki-filled ki-message-text-2 text-sm"></i>{{ $card['comments'] }}
                                        </span>
                                    @endif
                                    @if ($card['attachments'] > 0)
                                        <span class="inline-flex items-center gap-1">
                                            <i class="ki-filled ki-paper-clip text-sm"></i>{{ $card['attachments'] }}
                                        </span>
                                    @endif
                                    @if ($card['assignee'])
                                        <span class="ms-auto size-6 rounded-full grid place-items-center text-[10px] font-semibold {{ $members[$card['assignee']]['tone'] }}"
                                              title="{{ $members[$card['assignee']]['name'] }}">
                                            {{ $members[$card['assignee']]['initials'] }}
                                        </span>
                                    @endif
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
                @if ($addingCardIn === $list['id'])
                    <div class="flex flex-col gap-2 px-3 pb-3">
                        <textarea rows="2" class="kt-textarea" autofocus
                                  aria-label="New card title"
                                  placeholder="e.g. Draft the Northwind scope document"
                                  wire:model="newCardTitle"
                                  wire:keydown.escape="cancelAddCard"
                                  wire:keydown.enter.prevent="addCard('{{ $list['id'] }}')"></textarea>
                        <div class="flex items-center gap-2">
                            <button wire:click="addCard('{{ $list['id'] }}')"
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
                    <button wire:click="startAddCard('{{ $list['id'] }}')"
                            class="kt-btn kt-btn-ghost w-full justify-start gap-2 text-sm text-secondary-foreground px-4 py-2.5 rounded-b-lg">
                        <i class="ki-filled ki-plus text-sm"></i> Add a card
                    </button>
                @endif
            </div>
        @endforeach

        {{-- Inline list creation --}}
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
