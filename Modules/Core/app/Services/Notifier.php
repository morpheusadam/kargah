<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Contracts\Notifier as NotifierContract;
use Modules\Core\Contracts\NotificationPreferences as NotificationPreferencesContract;
use Modules\Core\Models\Notification;

/**
 * The implementation behind `Modules\Core\Contracts\Notifier`.
 *
 * Read the contract first; the reasoning lives there. What is worth knowing
 * here is how the two idempotence guarantees are actually kept.
 *
 * **`notify()` with a `dedupe_key` reads before it writes, and catches the
 * write when it loses.** The read is what makes the ordinary second run cheap;
 * the catch is what makes a genuine race correct, because two cron ticks
 * overlapping is the exact situation the key exists for. The database's unique
 * index on (user_id, dedupe_key) is the authority, not the SELECT.
 *
 * **`markRead()` only writes when `read_at` is null.** Anything else would move
 * the timestamp on every page render of a feed that marks rows read as you look
 * at them, and "first read at" would come to mean "last looked at".
 *
 * **Preferences decide whether a row is written at all, not whether it is
 * hidden afterwards.** `user_notifications` is the in-app feed itself — there
 * is no other consumer of it — so a person who has switched an event's
 * "in app" toggle off gets no row rather than an invisible one nobody would
 * ever read. This is a real change in behaviour from before preferences
 * existed, and it is safe precisely because "absent preference" defaults to
 * allowed: every call already in this codebase, and every existing test,
 * notifies a user with no rows in `notification_preferences`, so nothing
 * already written starts being skipped. `email` is not consulted here —
 * nothing in Kargah sends a notification by email yet, and quiet hours,
 * which only ever suppress email, never reach this class at all.
 */
class Notifier implements NotifierContract
{
    /** The only keys `$options` may carry. Anything else is a typo, and typos throw. */
    private const OPTION_KEYS = ['subject', 'body', 'url', 'actor_id', 'dedupe_key'];

    private const MAX_TITLE = 255;

    private const MAX_BODY = 500;

    private const MAX_URL = 255;

    private const MAX_EVENT = 60;

    private const MAX_DEDUPE_KEY = 120;

    public function __construct(private readonly NotificationPreferencesContract $preferences) {}

    public function notify(int $userId, string $event, string $title, array $options = []): array
    {
        $attributes = $this->attributes($event, $title, $options);

        if (! $this->preferences->allows($userId, $event, 'in_app')) {
            return $this->skipped($userId, $attributes);
        }

        $row = $this->write($userId, $event, $title, $attributes);

        return $this->toArray($row);
    }

    public function notifyMany(array $userIds, string $event, string $title, array $options = []): int
    {
        $attributes = $this->attributes($event, $title, $options);

        // Collapsed rather than rejected: "everyone watching this card" routinely
        // contains the same person twice once a watch on the list is folded in.
        $ids = collect($userIds)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        // One query for every recipient, not one per recipient — see the
        // contract's docblock on `channelsForMany()`.
        $channels = $this->preferences->channelsForMany($ids->all(), $event);

        $written = 0;

        foreach ($ids as $userId) {
            if (! ($channels[$userId]['in_app'] ?? true)) {
                continue;
            }

            $before = $this->existing($userId, $attributes['dedupe_key']);

            $this->write($userId, $event, $title, $attributes);

            if ($before === null) {
                $written++;
            }
        }

        return $written;
    }

    public function unreadCount(int $userId): int
    {
        return Notification::query()->forUser($userId)->unread()->count();
    }

    public function recent(int $userId, int $limit = 20, bool $unreadOnly = false): Collection
    {
        return Notification::query()
            ->forUser($userId)
            ->when($unreadOnly, fn ($query) => $query->unread())
            ->with('actor:id,name')
            ->newestFirst()
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Notification $row): array => $this->toArray($row))
            ->values();
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        // Scoped by user in the query, not checked afterwards: an id is not a
        // capability, and the cheapest way to keep that true is never to load a
        // row that belongs to somebody else.
        $row = Notification::query()->forUser($userId)->find($notificationId);

        if ($row === null) {
            return false;
        }

        return $row->markRead();
    }

    public function markAllRead(int $userId): int
    {
        return Notification::query()
            ->forUser($userId)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function prune(int $olderThanDays = 90): int
    {
        $cutoff = now()->subDays(max(0, $olderThanDays));

        return Notification::query()->where('created_at', '<', $cutoff)->delete();
    }

    /* Internals ------------------------------------------------------------- */

    /**
     * Validate `$options` and turn them into columns.
     *
     * Done once per call rather than once per recipient, so `notifyMany()` pays
     * for the morph lookup and the length checks a single time.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function attributes(string $event, string $title, array $options): array
    {
        $unknown = array_diff(array_keys($options), self::OPTION_KEYS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown notification option '.implode(', ', $unknown).'. Accepted: '.implode(', ', self::OPTION_KEYS).'.'
            );
        }

        if (trim($event) === '') {
            throw new InvalidArgumentException('A notification needs an event.');
        }

        if (mb_strlen($event) > self::MAX_EVENT) {
            throw new InvalidArgumentException('An event name may not exceed '.self::MAX_EVENT.' characters.');
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('A notification needs a title.');
        }

        $subject = $options['subject'] ?? null;

        if ($subject !== null && ! $subject instanceof Model) {
            throw new InvalidArgumentException('The `subject` option must be an Eloquent model or null.');
        }

        $body = $this->optionalString($options, 'body');
        $url = $this->optionalString($options, 'url');
        $dedupeKey = $this->optionalString($options, 'dedupe_key');

        if ($url !== null && mb_strlen($url) > self::MAX_URL) {
            // Truncating a URL would store a link that goes somewhere else.
            throw new InvalidArgumentException('A notification url may not exceed '.self::MAX_URL.' characters.');
        }

        if ($dedupeKey !== null && mb_strlen($dedupeKey) > self::MAX_DEDUPE_KEY) {
            throw new InvalidArgumentException('A dedupe key may not exceed '.self::MAX_DEDUPE_KEY.' characters.');
        }

        $actorId = $options['actor_id'] ?? null;

        if ($actorId !== null && ! is_int($actorId)) {
            throw new InvalidArgumentException('The `actor_id` option must be an integer or null.');
        }

        return [
            // getMorphClass() against the enforced map: an unregistered model
            // throws here rather than writing a class name that a later rename
            // would orphan.
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'event' => $event,
            'title' => Str::limit(trim($title), self::MAX_TITLE - 1, '…'),
            'body' => $body === null ? null : Str::limit($body, self::MAX_BODY - 1, '…'),
            'url' => $url,
            'actor_id' => $actorId,
            'dedupe_key' => $dedupeKey,
        ];
    }

    /**
     * A string option, trimmed, with '' treated as absent.
     *
     * @param  array<string, mixed>  $options
     */
    private function optionalString(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The `'.$key.'` option must be a string or null.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** The row a dedupe key already claimed for this user, if there is one. */
    private function existing(int $userId, ?string $dedupeKey): ?Notification
    {
        if ($dedupeKey === null) {
            return null;
        }

        return Notification::query()
            ->forUser($userId)
            ->where('dedupe_key', $dedupeKey)
            ->first();
    }

    /**
     * Write one row, or hand back the one the dedupe key already claimed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(int $userId, string $event, string $title, array $attributes): Notification
    {
        $dedupeKey = $attributes['dedupe_key'];

        if ($existing = $this->existing($userId, $dedupeKey)) {
            return $existing;
        }

        try {
            return Notification::query()->create([...$attributes, 'user_id' => $userId]);
        } catch (QueryException $e) {
            // Lost a race with a concurrent tick. The unique index is the
            // authority; the SELECT above is only the fast path.
            if ($dedupeKey === null) {
                throw $e;
            }

            $existing = $this->existing($userId, $dedupeKey);

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * The stable array shape the contract promises.
     *
     * @return array<string, mixed>
     */
    private function toArray(Notification $row): array
    {
        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'event' => (string) $row->event,
            'title' => (string) $row->title,
            'body' => $row->body,
            'url' => $row->url,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'actor_name' => $row->relationLoaded('actor') ? $row->actor?->name : null,
            'is_read' => $row->isRead(),
            'read_at' => $row->read_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'dedupe_key' => $row->dedupe_key,
        ];
    }

    /**
     * What `notify()` returns when a preference refused the write.
     *
     * Same shape as `toArray()` so a caller destructuring the result does
     * not need a special case, but `id` is `null` — there is no row, and
     * `null` is the one value nothing already written could ever carry, so
     * it cannot be confused with a real notification.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function skipped(int $userId, array $attributes): array
    {
        return [
            'id' => null,
            'user_id' => $userId,
            'event' => $attributes['event'],
            'title' => $attributes['title'],
            'body' => $attributes['body'],
            'url' => $attributes['url'],
            'subject_type' => $attributes['subject_type'],
            'subject_id' => $attributes['subject_id'],
            'actor_id' => $attributes['actor_id'],
            'actor_name' => null,
            'is_read' => false,
            'read_at' => null,
            'created_at' => null,
            'dedupe_key' => $attributes['dedupe_key'],
        ];
    }
}
