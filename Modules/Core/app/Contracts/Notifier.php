<?php

namespace Modules\Core\Contracts;

use Illuminate\Support\Collection;

/**
 * The only way anything tells a person something.
 *
 * Project wants to say a card was commented on; Accounting wants to say an
 * invoice is overdue; Mailbox wants to say a sync failed. None of them owns a
 * notifications table, and none of them may depend on another feature module to
 * borrow one — so the table is Core's, exactly like `links` and `activities`,
 * and this is the door to it.
 *
 * **Arrays out, never models.** A caller in Accounting must not receive a
 * `Modules\Core\Models\Notification`; reaching into another module's Eloquent
 * model is the thing that turns a modular monolith back into a monolith. Every
 * method here returns plain arrays with a stable shape, so a rename inside Core
 * cannot break an invoice page.
 *
 * **Core never learns what a card is.** The subject goes in as a model because
 * that is what the caller already has, and `getMorphClass()` turns it into the
 * alias Core stores — nothing about the caller's class leaks into the table. The
 * `title`, `body` and `url` are rendered *by the caller*, in the caller's
 * language, and stored as text. Core renders a row; it never renders a card.
 *
 * That denormalisation is deliberate twice over: a notification about a card
 * that has since been renamed keeps the name the card had when it happened,
 * which is what you want in a feed of things that already occurred, and a
 * notification whose subject has been deleted still displays.
 *
 * ## `$options`
 *
 * Exactly five keys are accepted. Anything else is an `InvalidArgumentException`
 * — a silently dropped `subject_id` because somebody guessed the key name is a
 * notification that quietly points nowhere.
 *
 * | key          | type            | meaning |
 * | ------------ | --------------- | ------- |
 * | `subject`    | `Model`\|`null` | what it is about; stored as a morph alias |
 * | `body`       | `string`\|`null`| a second line, already rendered |
 * | `url`        | `string`\|`null`| where clicking it goes |
 * | `actor_id`   | `int`\|`null`   | the user who caused it, if a person did |
 * | `dedupe_key` | `string`\|`null`| opt-in idempotence — see below |
 *
 * ## Idempotence
 *
 * Kargah's long operations all run from one cron entry, so every one of them can
 * run twice. Two methods here are built for that:
 *
 * - `notify()` with a `dedupe_key` is a no-op the second time. It returns the
 *   row that already exists, unchanged, with the `created_at` it already had.
 *   A due-date sweep that runs every minute passes `card:41:due_soon` and tells
 *   you once. Without a key, two calls write two rows — the dedupe is opt-in,
 *   because two comments on the same card are two notifications.
 * - `markRead()` on an already-read notification does not move `read_at` and
 *   returns the same value both times.
 *
 * @phpstan-type NotificationArray array{
 *     id: int, user_id: int, event: string, title: string, body: ?string,
 *     url: ?string, subject_type: ?string, subject_id: ?int, actor_id: ?int,
 *     actor_name: ?string, is_read: bool, read_at: ?string,
 *     created_at: ?string, dedupe_key: ?string
 * }
 */
interface Notifier
{
    /**
     * Tell one person one thing.
     *
     * @param  array<string, mixed>  $options  see the table above; unknown keys throw
     * @return array the notification — the existing one when `dedupe_key` matched
     *
     * @throws \InvalidArgumentException on an unknown option key, an empty
     *                                   title, an empty event, or an option of
     *                                   the wrong type
     */
    public function notify(int $userId, string $event, string $title, array $options = []): array;

    /**
     * Tell several people the same thing.
     *
     * The `dedupe_key` applies per user — the unique index is
     * (user_id, dedupe_key) — so telling a board's five watchers about one
     * comment is one call with one key.
     *
     * @param  list<int>  $userIds  duplicates are collapsed
     * @param  array<string, mixed>  $options
     * @return int how many rows were actually written, which is not the same as
     *             how many ids went in once a dedupe key is in play
     */
    public function notifyMany(array $userIds, string $event, string $title, array $options = []): int;

    /** How many a person has not read. Scoped to that person and nobody else. */
    public function unreadCount(int $userId): int;

    /**
     * The newest notifications for one person, newest first.
     *
     * @return Collection<int, array> of arrays, never of models
     */
    public function recent(int $userId, int $limit = 20, bool $unreadOnly = false): Collection;

    /**
     * Mark one notification read.
     *
     * Returns false when the notification does not exist **or belongs to
     * somebody else** — a notification id is not a capability. Returns true on
     * every call after the first, and does not move `read_at`.
     */
    public function markRead(int $notificationId, int $userId): bool;

    /**
     * Clear one person's unread pile.
     *
     * @return int how many rows changed; 0 the second time
     */
    public function markAllRead(int $userId): int;

    /**
     * Hard-delete notifications older than the cutoff, for every user.
     *
     * There is no soft delete here. A notification is not something a person
     * created and is not worth restoring; `core:prune-notifications` runs this
     * from the scheduler.
     *
     * @return int how many rows were deleted; 0 the second time
     */
    public function prune(int $olderThanDays = 90): int;
}
