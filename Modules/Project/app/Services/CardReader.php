<?php

namespace Modules\Project\Services;

use Illuminate\Support\Collection;
use Modules\Project\Contracts\CardReader as CardReaderContract;
use Modules\Project\Models\Card;

class CardReader implements CardReaderContract
{
    public function forCustomer(int $customerId, bool $includeArchived = false): Collection
    {
        return Card::query()
            ->with('list.board')
            ->where('customer_id', $customerId)
            ->unless($includeArchived, fn ($q) => $q->active())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Card $card): array => [
                'id' => $card->id,
                'title' => $card->title,
                'board' => $card->list?->board?->name ?? '—',
                'list' => $card->list?->name ?? '—',
                'due_on' => $card->due_on?->toDateString(),
                'due_state' => $card->dueState(),
                'is_archived' => $card->isArchived(),
                'url' => $card->list?->board
                    ? route('projects.boards', ['board' => $card->list->board->slug, 'card' => $card->id])
                    : route('projects.boards'),
            ]);
    }

    public function countForCustomer(int $customerId): int
    {
        return Card::query()->where('customer_id', $customerId)->active()->count();
    }

    public function assignToCustomer(int $cardId, ?int $customerId): bool
    {
        $card = Card::query()->find($cardId);

        if ($card === null) {
            return false;
        }

        $card->forceFill(['customer_id' => $customerId])->save();

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event($customerId === null ? 'card.customer_cleared' : 'card.customer_set')
            ->log($customerId === null ? 'detached from its customer' : 'attached to a customer');

        return true;
    }
}
