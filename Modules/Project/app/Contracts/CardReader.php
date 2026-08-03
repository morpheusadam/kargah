<?php

namespace Modules\Project\Contracts;

use Illuminate\Support\Collection;

/**
 * What other modules may know about cards.
 *
 * Accounting wants to show a customer's cards; Mailbox wants to turn an email
 * into one. Neither may touch `Modules\Project\Models\Card` — reaching into
 * another module's Eloquent model is the thing that turns a modular monolith
 * back into a monolith. They get plain arrays through this instead, so a
 * rename inside Project cannot break a page inside Accounting.
 */
interface CardReader
{
    /**
     * Cards belonging to a customer, newest first.
     *
     * @return Collection<int, array{
     *     id: int, title: string, board: string, list: string,
     *     due_on: ?string, due_state: ?string, is_archived: bool, url: string
     * }>
     */
    public function forCustomer(int $customerId, bool $includeArchived = false): Collection;

    /** How many active cards a customer has. */
    public function countForCustomer(int $customerId): int;

    /**
     * Attach a card to a customer. Returns false when the card does not exist.
     */
    public function assignToCustomer(int $cardId, ?int $customerId): bool;
}
