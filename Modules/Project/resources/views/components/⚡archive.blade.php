<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Palette;

/**
 * The archive, reading from the database.
 *
 * Nothing in Kargah is ever removed by a person. Archiving a board, a list or a
 * card sets `archived_at`, and this page is the only route back. Three tables
 * are read rather than one, because the three things being archived are not
 * rows of the same shape, and a card that reads "on Backlog, on Client Work" is
 * the only version of the row somebody can act on.
 *
 * **Restoring works upwards.** A card whose list is still archived would come
 * back invisible: the board draws `BoardList::active()`, so the card would sit
 * on a column nobody can see, and the archive would no longer list it either.
 * Restoring therefore brings the ancestors back with it — the list, and the
 * board if that is archived too — and says so in the toast. The alternative,
 * refusing until the list is restored first, is defensible but leaves the user
 * hunting for a row that the card filter may well be hiding.
 *
 * **Delete is a soft delete.** The button says "Delete", it writes
 * `deleted_at`, and the row leaves this page for good as far as the UI is
 * concerned. Nothing calls `forceDelete()` anywhere in this module.
 */
new
#[Title('Archive — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $search = '';

    /** One of 'all', 'boards', 'lists', 'cards'. */
    #[Url]
    public string $kind = 'all';

    /** Board slug the archive is narrowed to, or '' for every board. */
    #[Url(as: 'board')]
    public string $boardFilter = '';

    /** Per-request memo. Private, so Livewire neither ships nor rehydrates it. */
    private ?Collection $resolvedRows = null;

    /* Reading the archive ---------------------------------------------------- */

    /**
     * Every archived row across the three tables, newest first.
     *
     * One shape per row rather than three: the table draws one `@forelse`, and
     * the filters below do not have to know which query a row came from. The
     * soft-delete scope is doing real work here — a row somebody deleted from
     * this page must not come back on the next visit.
     */
    private function rows(): Collection
    {
        if ($this->resolvedRows !== null) {
            return $this->resolvedRows;
        }

        $rows = collect();

        foreach (Board::query()->archived()->orderBy('name')->get() as $board) {
            $rows->push([
                'kind' => 'board',
                'id' => $board->id,
                'title' => $board->name,
                'context' => 'The whole board',
                'board' => $board->name,
                'slug' => $board->slug,
                'dot' => $board->dotClass(),
                'archived' => $board->archived_at,
            ]);
        }

        $lists = BoardList::query()
            ->whereNotNull('archived_at')
            ->with('board')
            ->orderBy('name')
            ->get();

        foreach ($lists as $list) {
            $rows->push([
                'kind' => 'list',
                'id' => $list->id,
                'title' => $list->name,
                'context' => 'A list and everything left on it',
                'board' => $list->board?->name ?? 'No board',
                'slug' => $list->board?->slug ?? '',
                'dot' => $list->board?->dotClass() ?? Palette::dot('neutral'),
                'archived' => $list->archived_at,
            ]);
        }

        $cards = Card::query()
            ->archived()
            ->with('list.board')
            ->orderBy('title')
            ->get();

        foreach ($cards as $card) {
            $rows->push([
                'kind' => 'card',
                'id' => $card->id,
                'title' => $card->title,
                'context' => $card->list?->name ?? 'No list',
                'board' => $card->list?->board?->name ?? 'No board',
                'slug' => $card->list?->board?->slug ?? '',
                'dot' => $card->list?->board?->dotClass() ?? Palette::dot('neutral'),
                'archived' => $card->archived_at,
            ]);
        }

        return $this->resolvedRows = $rows
            ->sortByDesc(fn (array $row) => $row['archived']?->getTimestamp() ?? 0)
            ->values();
    }

    /** Drop the memo so the next read sees what an action just wrote. */
    private function refreshArchive(): void
    {
        $this->resolvedRows = null;
    }

    /* Filtering -------------------------------------------------------------- */

    private function searchTerm(): string
    {
        return trim($this->search);
    }

    private function matches(array $row): bool
    {
        if ($this->kind !== 'all' && $row['kind'].'s' !== $this->kind) {
            return false;
        }

        if ($this->boardFilter !== '' && $row['slug'] !== $this->boardFilter) {
            return false;
        }

        $term = $this->searchTerm();

        if ($term === '') {
            return true;
        }

        return stripos($row['title'].' '.$row['context'].' '.$row['board'], $term) !== false;
    }

    private function visibleRows(): Collection
    {
        return $this->rows()->filter(fn (array $row): bool => $this->matches($row))->values();
    }

    public function with(): array
    {
        $rows = $this->rows();

        return [
            'rows' => $this->visibleRows(),
            'total' => $rows->count(),
            'counts' => [
                'all' => $rows->count(),
                'boards' => $rows->where('kind', 'board')->count(),
                'lists' => $rows->where('kind', 'list')->count(),
                'cards' => $rows->where('kind', 'card')->count(),
            ],
            'kinds' => [
                'all' => 'Everything',
                'boards' => 'Boards',
                'lists' => 'Lists',
                'cards' => 'Cards',
            ],
            // Only boards that actually have something in the archive: a select
            // full of options that match nothing reads as a broken filter.
            'boardOptions' => $rows
                ->filter(fn (array $row): bool => $row['slug'] !== '')
                ->unique('slug')
                ->sortBy('board')
                ->map(fn (array $row): array => ['slug' => $row['slug'], 'name' => $row['board']])
                ->values(),
            'badges' => [
                'board' => 'kt-badge kt-badge-sm kt-badge-primary',
                'list' => 'kt-badge kt-badge-sm kt-badge-info',
                'card' => 'kt-badge kt-badge-sm kt-badge-outline',
            ],
            'activeFilters' => ($this->kind !== 'all' ? 1 : 0)
                + ($this->boardFilter !== '' ? 1 : 0)
                + ($this->searchTerm() !== '' ? 1 : 0),
        ];
    }

    public function setKind(string $kind): void
    {
        $this->kind = in_array($kind, ['all', 'boards', 'lists', 'cards'], true) ? $kind : 'all';
    }

    public function updatedSearch(): void
    {
        $this->refreshArchive();
    }

    /**
     * The board select narrows `visibleRows()`, which reads the same memo the
     * page was drawn from — there is nothing to invalidate and nothing to say
     * that the table does not already show.
     */
    public function updatedBoardFilter(): void
    {
        //
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->kind = 'all';
        $this->boardFilter = '';

        $this->refreshArchive();
    }

    /* Restoring -------------------------------------------------------------- */

    public function restoreBoard(int $boardId): void
    {
        $board = Board::query()->find($boardId);

        if ($board === null) {
            $this->toastError('That board is gone', 'It was deleted from the archive while this page was open.');
            $this->refreshArchive();

            return;
        }

        if (! $board->isArchived()) {
            $this->toastSuccess($board->name.' is already on the board list', 'Nothing needed restoring.');
            $this->refreshArchive();

            return;
        }

        $this->restoreBoardRecord($board);

        $lists = BoardList::query()->where('board_id', $board->id)->active()->count();

        $this->refreshArchive();

        $this->toastSuccess(
            $board->name.' restored',
            $lists === 0
                ? 'It is back in the board picker, with no lists on it yet.'
                : 'It is back in the board picker with '.$lists.' '.str('list')->plural($lists).' on it.',
        );
    }

    public function restoreList(int $listId): void
    {
        $list = BoardList::query()->with('board')->find($listId);

        if ($list === null) {
            $this->toastError('That list is gone', 'It was deleted from the archive while this page was open.');
            $this->refreshArchive();

            return;
        }

        if (! $list->isArchived()) {
            $this->toastSuccess($list->name.' is already on its board', 'Nothing needed restoring.');
            $this->refreshArchive();

            return;
        }

        $board = $list->board;
        $boardCameBack = $board !== null && $board->isArchived();

        if ($boardCameBack) {
            $this->restoreBoardRecord($board);
        }

        $this->restoreListRecord($list);

        $cards = Card::query()->whereIn('id', $this->cardsLivingIn($list->id))->archived()->count();

        $this->refreshArchive();

        $this->toastSuccess(
            $list->name.' restored',
            trim(implode(' ', array_filter([
                $boardCameBack ? $board->name.' came back with it, because a list cannot sit on an archived board.' : null,
                $cards === 0
                    ? 'It is back on the board.'
                    : $cards.' archived '.str('card')->plural($cards).' stayed behind, and can be restored one at a time.',
            ]))),
        );
    }

    /**
     * Put a card back on its board.
     *
     * The interesting case is a card whose list is still archived. Restoring
     * only the card would leave it on a column the board does not draw, and it
     * would have left this page as well — the one screen that could undo it. So
     * the list comes back with it, and the board above that if need be.
     */
    public function restoreCard(int $cardId): void
    {
        $card = Card::query()->with('list.board')->find($cardId);

        if ($card === null) {
            $this->toastError('That card is gone', 'It was deleted from the archive while this page was open.');
            $this->refreshArchive();

            return;
        }

        if (! $card->isArchived()) {
            $this->toastSuccess($card->title.' is already on the board', 'Nothing needed restoring.');
            $this->refreshArchive();

            return;
        }

        $list = $card->list;

        if ($list === null) {
            $this->toastError(
                'That card has no list to go back to',
                'Its list was deleted, so there is nowhere on the board to put it.',
            );

            return;
        }

        $board = $list->board;
        $boardCameBack = $board !== null && $board->isArchived();
        $listCameBack = $list->isArchived();

        if ($boardCameBack) {
            $this->restoreBoardRecord($board);
        }

        if ($listCameBack) {
            $this->restoreListRecord($list);
        }

        app(CardService::class)->restore($card);

        $this->refreshArchive();

        $extra = array_filter([
            $listCameBack ? $list->name.' came back with it, because the card had nowhere visible to land.' : null,
            $boardCameBack ? $board->name.' was restored too.' : null,
        ]);

        $this->toastSuccess(
            $card->title.' restored',
            $extra === []
                ? 'It is back in '.$list->name.'.'
                : 'It is back in '.$list->name.'. '.implode(' ', $extra),
        );
    }

    /**
     * The ids of the cards that *live* in a list.
     *
     * A card mirrored into a list from another board is shown there but does
     * not belong to it, and nothing this page does to a list may follow a
     * mirror back to somebody else's card.
     *
     * @param  int|list<int>  $listIds
     */
    private function cardsLivingIn(int|array $listIds): \Illuminate\Database\Eloquent\Builder
    {
        return CardPlacement::query()
            ->origin()
            ->whereIn('board_list_id', is_array($listIds) ? $listIds : [$listIds])
            ->select('card_id');
    }

    private function restoreBoardRecord(Board $board): void
    {
        $board->forceFill(['archived_at' => null])->save();

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.restored')
            ->log('restored from the archive');
    }

    private function restoreListRecord(BoardList $list): void
    {
        $list->forceFill(['archived_at' => null])->save();

        activity('list')
            ->performedOn($list)
            ->causedBy(auth()->user())
            ->event('list.restored')
            ->log('restored from the archive');
    }

    /* Deleting --------------------------------------------------------------- */

    /**
     * Every delete below is a soft delete.
     *
     * The row keeps its id, its relations and its history, and stops being
     * something the application will show anybody. `cascadeOnDelete` in the
     * schema only fires on a real delete, so the children are written here.
     */
    public function deleteBoard(int $boardId): void
    {
        $board = Board::query()->find($boardId);

        if ($board === null) {
            $this->toastError('That board is gone', 'Somebody deleted it while this page was open.');
            $this->refreshArchive();

            return;
        }

        $listIds = BoardList::query()->where('board_id', $board->id)->pluck('id');

        $cardIds = CardPlacement::query()->whereIn('board_list_id', $listIds)->origin()->pluck('card_id');
        $cards = $cardIds->count();

        // Cards that live here go with the board. Cards merely mirrored onto it
        // lose the mirror and keep living where they live.
        Card::query()->whereIn('id', $cardIds)->delete();
        CardPlacement::query()->whereIn('board_list_id', $listIds)->delete();
        BoardList::query()->whereIn('id', $listIds)->delete();
        $board->delete();

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.deleted')
            ->log('deleted from the archive');

        $this->refreshArchive();

        $this->toastSuccess(
            $board->name.' deleted',
            $listIds->count().' '.str('list')->plural($listIds->count()).' and '.$cards.' '
                .str('card')->plural($cards).' went with it. Nothing was erased — the rows are marked deleted.',
        );
    }

    public function deleteList(int $listId): void
    {
        $list = BoardList::query()->find($listId);

        if ($list === null) {
            $this->toastError('That list is gone', 'Somebody deleted it while this page was open.');
            $this->refreshArchive();

            return;
        }

        $cardIds = CardPlacement::query()->where('board_list_id', $list->id)->origin()->pluck('card_id');
        $cards = $cardIds->count();

        // The cards that live here go with the list. `BoardList::deleting`
        // clears the placements and catches anything this missed, so no card
        // can be left placed nowhere and therefore visible nowhere.
        Card::query()->whereIn('id', $cardIds)->delete();
        $list->delete();

        activity('list')
            ->performedOn($list)
            ->causedBy(auth()->user())
            ->event('list.deleted')
            ->log('deleted from the archive');

        $this->refreshArchive();

        $this->toastSuccess(
            $list->name.' deleted',
            $cards === 0
                ? 'It held nothing. The row is marked deleted, not erased.'
                : $cards.' '.str('card')->plural($cards).' went with it. The rows are marked deleted, not erased.',
        );
    }

    public function deleteCard(int $cardId): void
    {
        $card = Card::query()->find($cardId);

        if ($card === null) {
            $this->toastError('That card is gone', 'Somebody deleted it while this page was open.');
            $this->refreshArchive();

            return;
        }

        $card->delete();

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.deleted')
            ->log('deleted from the archive');

        $this->refreshArchive();

        $this->toastSuccess($card->title.' deleted', 'The row is marked deleted, not erased.');
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Heading --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Archive</h1>
            <p class="text-sm text-secondary-foreground mt-1">Boards, lists and cards you closed but did not delete.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="kt-input max-w-[260px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search archive…" aria-label="Search the archive"
                       wire:model.live.debounce.300ms="search">
            </div>

            <select class="kt-select max-w-[200px]" aria-label="Limit to one board" wire:model.live="boardFilter">
                <option value="">Every board</option>
                @foreach ($boardOptions as $option)
                    <option value="{{ $option['slug'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>

            <button wire:click="clearFilters" class="kt-btn kt-btn-outline gap-2" @disabled($activeFilters === 0)>
                <i class="ki-filled ki-eraser"></i> Clear
            </button>
        </div>
    </div>

    {{-- What kind of thing --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($kinds as $key => $label)
            <button wire:click="setKind('{{ $key }}')" wire:key="kind-{{ $key }}"
                    class="kt-btn kt-btn-sm gap-2 {{ $kind === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}"
                    aria-pressed="{{ $kind === $key ? 'true' : 'false' }}">
                {{ $label }}
                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $counts[$key] }}</span>
            </button>
        @endforeach

        <span class="text-xs text-muted-foreground ms-auto" wire:loading.remove wire:target="search">
            {{ $rows->count() }} of {{ $total }} archived items
        </span>
        <span class="text-xs text-muted-foreground ms-auto" wire:loading wire:target="search">
            <i class="ki-filled ki-loading animate-spin"></i> Searching…
        </span>
    </div>

    <div class="kt-card">
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[300px]">Item</th>
                            <th class="w-[110px]">Kind</th>
                            <th class="w-[170px]">Board</th>
                            <th class="w-[140px]">Archived</th>
                            <th class="w-[200px] text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="{{ $row['kind'] }}-{{ $row['id'] }}">
                                <td>
                                    <div class="text-mono font-medium">{{ $row['title'] }}</div>
                                    <div class="text-xs text-muted-foreground mt-0.5">{{ $row['context'] }}</div>
                                </td>
                                <td>
                                    <span class="{{ $badges[$row['kind']] }}">{{ ucfirst($row['kind']) }}</span>
                                </td>
                                <td>
                                    <span class="inline-flex items-center gap-2 text-secondary-foreground">
                                        <span class="size-2.5 rounded-full {{ $row['dot'] }}"></span>
                                        {{ $row['board'] }}
                                    </span>
                                </td>
                                <td class="text-secondary-foreground">
                                    {{ $row['archived']?->format('d M Y') ?? '—' }}
                                </td>
                                <td class="text-end">
                                    @php($restore = 'restore'.ucfirst($row['kind']))
                                    @php($delete = 'delete'.ucfirst($row['kind']))

                                    <div class="inline-flex items-center gap-1">
                                        <button wire:click="{{ $restore }}({{ $row['id'] }})"
                                                wire:loading.attr="disabled" wire:target="{{ $restore }}"
                                                class="kt-btn kt-btn-sm kt-btn-outline gap-1">
                                            <span wire:loading.remove wire:target="{{ $restore }}" class="inline-flex items-center gap-1">
                                                <i class="ki-filled ki-arrow-circle-left text-sm"></i> Restore
                                            </span>
                                            <span wire:loading wire:target="{{ $restore }}" class="inline-flex items-center gap-1">
                                                <i class="ki-filled ki-loading animate-spin"></i> Restoring…
                                            </span>
                                        </button>

                                        <button wire:click="{{ $delete }}({{ $row['id'] }})"
                                                wire:loading.attr="disabled" wire:target="{{ $delete }}"
                                                class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1"
                                                title="Delete {{ $row['title'] }}" aria-label="Delete {{ $row['title'] }}">
                                            <i class="ki-filled ki-trash text-sm"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-12">
                                    <i class="ki-filled ki-archive text-2xl text-muted-foreground"></i>
                                    <p class="text-sm text-secondary-foreground mt-3">
                                        {{ $activeFilters > 0
                                            ? 'Nothing in the archive matches that filter.'
                                            : 'Nothing archived yet. Whatever you close on a board turns up here.' }}
                                    </p>
                                    @if ($activeFilters > 0)
                                        <button wire:click="clearFilters" class="kt-btn kt-btn-outline gap-2 mt-4">
                                            <i class="ki-filled ki-eraser"></i> Clear the filters
                                        </button>
                                    @else
                                        <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-primary gap-2 mt-4">
                                            <i class="ki-filled ki-arrow-left"></i> Back to the boards
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-xs text-muted-foreground">
        Restoring a card whose list is still archived brings the list back with it, and the board above that if it
        needs to. Delete marks a row deleted rather than erasing it.
    </p>
</div>
