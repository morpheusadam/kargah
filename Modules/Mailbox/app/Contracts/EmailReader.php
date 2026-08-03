<?php

namespace Modules\Mailbox\Contracts;

use Illuminate\Support\Collection;

/**
 * What other modules may know about emails.
 *
 * Accounting wants a client page to show the correspondence behind an invoice;
 * Project wants a card to show the message it came from. Neither may touch
 * `Modules\Mailbox\Models\Email` — reaching into another module's Eloquent
 * model is the thing that turns a modular monolith back into a monolith. They
 * get plain arrays through this instead, so a column renamed inside Mailbox
 * cannot break a page inside Accounting.
 *
 * `preview` is already flattened here rather than handed over as a body: a
 * client page shows a line, and shipping `body_html` across the boundary would
 * make every consumer responsible for sanitising it.
 */
interface EmailReader
{
    /**
     * A customer's messages, newest first.
     *
     * @return Collection<int, array{
     *     id: int, subject: string, from_name: ?string, from_email: ?string,
     *     preview: string, received_at: ?string, is_read: bool, is_starred: bool,
     *     has_attachments: bool, folder: string, url: string
     * }>
     */
    public function forCustomer(int $customerId, int $limit = 20): Collection;

    /** How many messages a customer has sent, past the limit above. */
    public function countForCustomer(int $customerId): int;
}
