<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Database\Factories\NotificationFactory;

/**
 * One thing somebody is being told, in the in-app feed.
 *
 * Nothing outside Core may touch this class. Other modules go through
 * `Modules\Core\Contracts\Notifier`, which hands back arrays — see the docblock
 * there for why.
 *
 * This is **not** Laravel's `Illuminate\Notifications\DatabaseNotification`, and
 * it is not the `Notification` facade; both are reached by their own
 * fully-qualified names and nothing here imports either. The class name is
 * unambiguous inside this namespace, but the *table* is `user_notifications`
 * rather than `notifications`, because the framework already owns that name and
 * `App\Models\User` uses `Notifiable`. The migration's docblock has the full
 * reasoning; the short version is that the two shapes cannot be reconciled and
 * the trait cannot be dropped, so this table moved.
 *
 * Rows are immutable except for `read_at`, so there is no `updated_at` and no
 * soft delete. `title`, `body` and `url` are whatever the calling module
 * rendered at the time; Core does not resolve `subject` to draw a row, which is
 * why a notification about a deleted card still displays.
 */
class Notification extends Model
{
    use HasFactory;

    /** Not `notifications` — Laravel's own database-channel table has that name. */
    protected $table = 'user_notifications';

    /** A notification is written once and read; it is never edited. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'event',
        'title',
        'body',
        'url',
        'actor_id',
        'read_at',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The card, invoice, email or post this is about — or nothing.
     *
     * Present so Core can offer it, not because the feed needs it: the row is
     * drawn entirely from the denormalised columns.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The person who caused it, when a person did. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** Newest first, with `id` breaking ties so a cursor has a total order. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Idempotent: an already-read notification keeps the moment it was first
     * read, and this returns the same value however many times it is called.
     *
     * @return bool true once the row is read, which it always is afterwards
     */
    public function markRead(): bool
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }

        return true;
    }

    /**
     * Put it back in the unread pile.
     *
     * Also idempotent, for the same reason: calling it on an already-unread row
     * writes nothing.
     *
     * @return bool false once the row is unread, which it always is afterwards
     */
    public function markUnread(): bool
    {
        if ($this->read_at !== null) {
            $this->forceFill(['read_at' => null])->save();
        }

        return false;
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
