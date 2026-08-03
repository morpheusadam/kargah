<?php

namespace Modules\Project\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Project\Contracts\BoardReader as BoardReaderContract;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\Label;

class BoardReader implements BoardReaderContract
{
    public function boards(bool $includeArchived = false): array
    {
        return Board::query()
            ->unless($includeArchived, fn (Builder $q) => $q->active())
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'colour', 'company_id', 'archived_at'])
            ->map(fn (Board $board): array => $this->shapeBoard($board))
            ->all();
    }

    public function findBoard(string $slug): ?array
    {
        $board = Board::query()->where('slug', $slug)
            ->first(['id', 'slug', 'name', 'colour', 'company_id', 'archived_at', 'description']);

        if ($board === null) {
            return null;
        }

        return $this->shapeBoard($board) + ['description' => $board->description];
    }

    public function listsForBoard(string $boardSlug): array
    {
        $boardId = Board::query()->where('slug', $boardSlug)->value('id');

        if ($boardId === null) {
            return [];
        }

        return BoardList::query()
            ->where('board_id', $boardId)
            ->orderBy('position')
            ->get(['id', 'name', 'position', 'archived_at'])
            ->map(fn (BoardList $list): array => [
                'id' => $list->id,
                'name' => $list->name,
                'position' => (string) $list->position,
                'is_archived' => $list->isArchived(),
            ])
            ->all();
    }

    public function cardsForList(int $listId): array
    {
        return CardPlacement::query()
            ->onCanvas()
            ->where('board_list_id', $listId)
            ->orderBy('position')
            ->with(['card' => fn ($q) => $q->with(['customer', 'labels', 'checklists.items'])->withCount('comments')])
            ->get()
            ->filter(fn (CardPlacement $placement): bool => $placement->card !== null)
            ->map(fn (CardPlacement $placement): array => $this->shapeListCard($placement->card, $placement))
            ->values()
            ->all();
    }

    public function findCard(int $cardId): ?array
    {
        $card = Card::query()
            ->with(['list.board', 'customer', 'labels', 'members', 'checklists.items'])
            ->withCount('comments')
            ->find($cardId);

        if ($card === null) {
            return null;
        }

        $mirroredOnto = BoardList::query()
            ->whereIn('id', $card->mirrorPlacements->pluck('board_list_id'))
            ->pluck('name')
            ->all();

        $board = $card->list?->board;
        [$done, $total] = $card->checklistProgress();

        return [
            'id' => $card->id,
            'title' => $card->title,
            'description' => $card->description,
            'due_on' => $card->due_on?->toDateString(),
            'due_state' => $card->dueState(),
            'is_complete' => $card->isComplete(),
            'is_archived' => $card->isArchived(),
            'board' => $board?->name,
            'board_slug' => $board?->slug,
            'list' => $card->list?->name,
            'customer' => $card->customer === null ? null : ['id' => $card->customer->id, 'name' => $card->customer->name],
            'labels' => $card->labels->map(fn (Label $label): array => [
                'id' => $label->id, 'name' => $label->name, 'colour' => $label->colour,
            ])->all(),
            'members' => $card->members->map(fn ($member): array => ['id' => $member->id, 'name' => $member->name])->all(),
            'mirrored_onto' => $mirroredOnto,
            'checklist' => ['done' => $done, 'total' => $total],
            'comment_count' => $card->comments_count,
            'url' => $this->cardUrl($board, $card->id),
        ];
    }

    /**
     * Cards due within `$days`, deduplicated across every board through
     * `CardPlacement::scopeOnCanvas()` — a card mirrored onto two boards is
     * still one card, and counting it twice would overstate how much work is
     * outstanding, which is the one figure this method exists to get right.
     */
    public function cardsDueSoon(int $days = 30, int $limit = 20): array
    {
        return $this->dueQuery($days)
            ->orderBy('due_on')
            ->limit(max(1, $limit))
            ->with('list.board')
            ->get()
            ->map(fn (Card $card): array => $this->shapeCardSummary($card))
            ->all();
    }

    public function countDueSoon(int $days = 30): int
    {
        return $this->dueQuery($days)->count();
    }

    public function cardsOverdue(int $limit = 20): array
    {
        return $this->overdueQuery()
            ->orderBy('due_on')
            ->limit(max(1, $limit))
            ->with('list.board')
            ->get()
            ->map(fn (Card $card): array => $this->shapeCardSummary($card))
            ->all();
    }

    public function countOverdue(): int
    {
        return $this->overdueQuery()->count();
    }

    /**
     * The distinct cards currently drawn on some board — the base every
     * cross-board method here starts from, so "on canvas" is decided once.
     */
    private function onCanvasCards(): Builder
    {
        return Card::query()->whereIn(
            'id',
            CardPlacement::query()->onCanvas()->select('card_id'),
        );
    }

    private function dueQuery(int $days): Builder
    {
        $today = now()->startOfDay();

        return $this->onCanvasCards()
            ->active()
            ->whereNull('completed_at')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>=', $today->toDateString())
            ->whereDate('due_on', '<=', $today->copy()->addDays(max(0, $days))->toDateString());
    }

    private function overdueQuery(): Builder
    {
        return $this->onCanvasCards()
            ->active()
            ->whereNull('completed_at')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->startOfDay()->toDateString());
    }

    private function shapeBoard(Board $board): array
    {
        return [
            'id' => $board->id,
            'slug' => $board->slug,
            'name' => $board->name,
            'colour' => $board->colour,
            'company_id' => $board->company_id,
            'is_archived' => $board->isArchived(),
        ];
    }

    private function shapeListCard(Card $card, CardPlacement $placement): array
    {
        [$done, $total] = $card->checklistProgress();

        return [
            'id' => $card->id,
            'title' => $card->title,
            'description' => $card->description,
            'due_on' => $card->due_on?->toDateString(),
            'due_state' => $card->dueState(),
            'is_complete' => $card->isComplete(),
            'is_archived' => $card->isArchived(),
            'is_origin' => $placement->isOrigin(),
            'position' => (string) $placement->position,
            'customer' => $card->customer === null ? null : ['id' => $card->customer->id, 'name' => $card->customer->name],
            'labels' => $card->labels->map(fn (Label $label): array => [
                'id' => $label->id, 'name' => $label->name, 'colour' => $label->colour,
            ])->all(),
            'checklist' => ['done' => $done, 'total' => $total],
            'comment_count' => $card->comments_count ?? $card->comments()->count(),
            'url' => $this->cardUrl($card->list?->board, $card->id),
        ];
    }

    private function shapeCardSummary(Card $card): array
    {
        $board = $card->list?->board;

        return [
            'id' => $card->id,
            'title' => $card->title,
            'board' => $board?->name ?? '—',
            'board_slug' => $board?->slug,
            'list' => $card->list?->name ?? '—',
            'due_on' => $card->due_on?->toDateString(),
            'due_state' => $card->dueState(),
            'is_archived' => $card->isArchived(),
            'url' => $this->cardUrl($board, $card->id),
        ];
    }

    private function cardUrl(?Board $board, int $cardId): string
    {
        return $board === null
            ? route('projects.boards')
            : route('projects.boards', ['board' => $board->slug, 'card' => $cardId]);
    }
}
