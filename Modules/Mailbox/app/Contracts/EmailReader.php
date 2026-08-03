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

    /**
     * How many messages across the whole mailbox are unread.
     *
     * Not scoped to a customer, unlike everything else on this contract —
     * mail from an unknown sender is still unread mail, and summing
     * `countForCustomer()` over every known customer would both miss that
     * mail and cost one query per customer for a number that would still be
     * wrong.
     *
     * "Unread" matches `⚡inbox.blade.php`'s own `unreadTotal()` exactly:
     * every folder counts — Inbox, Archive, Sent, Drafts, Junk, Trash — with
     * no exception, because that is the method whose definition this exists
     * not to contradict. `Email::scopeUnread()` is the same `is_read =
     * false` test either page runs.
     */
    public function unreadCount(): int;

    /* One message, a thread, and the mailbox at large ------------------------- */

    /**
     * One message, or null when it does not exist.
     *
     * `body` is plain text, always. The rule at the head of this interface —
     * "shipping `body_html` across the boundary would make every consumer
     * responsible for sanitising it" — is not softened by the consumer being an
     * API rather than a page: a JSON response ends up in somebody's browser
     * just as surely, and the consumer that would have to sanitise it is now
     * one nobody in this repository controls. So the body is flattened here,
     * the same way `Email::preview()` flattens it, and `has_html` says a
     * richer form existed without handing it over.
     *
     * Reading a message does **not** mark it read. `markRead()` is a write and
     * this is a reader; an integration polling the mailbox would otherwise
     * silently empty the owner's unread badge.
     *
     * @return null|array{
     *     id: int, thread_id: ?int, subject: string, from_name: ?string, from_email: ?string,
     *     to: list<string>, cc: list<string>, body: string, has_html: bool,
     *     received_at: ?string, is_read: bool, is_starred: bool,
     *     has_attachments: bool, attachment_count: int, folder: string,
     *     customer: ?array{id: int, name: string}, url: string
     * }
     */
    public function find(int $emailId): ?array;

    /**
     * A conversation: the thread's own aggregates, and its messages oldest
     * first — the order a conversation is read in, which is the opposite of
     * the order an inbox is.
     *
     * Null when no thread has that id. A message with no thread is not an
     * error and not a one-message thread: `find()` already answers for it, and
     * inventing a synthetic thread here would give a caller an id that does
     * not exist and cannot be asked for again.
     *
     * The messages carry `body`, so a caller summarising a thread does it in
     * one call rather than one call per message.
     *
     * @return null|array{
     *     id: int, subject: ?string, participants: list<string>,
     *     message_count: int, last_message_at: ?string,
     *     messages: list<array<string, mixed>>
     * }
     */
    public function thread(int $threadId, int $limit = 50): array|null;

    /**
     * A page of the mailbox, newest first.
     *
     * The listing shape, not the message shape: no `body`, because a page of
     * twenty bodies is a very different response from a page of twenty
     * previews and only one of them belongs in a list.
     *
     * `$folder` is matched as the column stores it and is not validated
     * against a known set — Mailbox takes its folder names from whatever IMAP
     * hands over, and an unknown folder is an empty page rather than an error.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public function paginate(
        ?string $folder = null,
        ?bool $unread = null,
        ?int $customerId = null,
        string $search = '',
        ?string $cursor = null,
        int $perPage = 20,
    ): array;
}
