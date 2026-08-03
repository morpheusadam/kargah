<?php

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Social\Database\Factories\SocialNotificationFactory;
use Modules\Social\Support\Networks;

/**
 * Something that happened on a network, brought back here.
 *
 * The unique index on (social_account_id, remote_id) is what makes ingestion
 * safe on cron, where a missed run and a doubled run are both normal. Each run
 * asks for the most recent page and writes it with `updateOrCreate` on that
 * pair, so an overlapping page costs nothing.
 *
 * `is_read` is never written by ingestion. It belongs to the person reading the
 * feed, and a sync that reset it would undo their afternoon.
 */
class SocialNotification extends Model
{
    use HasFactory;

    public const MENTION = 'mention';

    public const REPLY = 'reply';

    public const FOLLOW = 'follow';

    public const LIKE = 'like';

    public const REPOST = 'repost';

    protected $fillable = [
        'social_account_id',
        'kind',
        'remote_id',
        'actor_handle',
        'excerpt',
        'url',
        'is_read',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * Newest first, with anything undated last rather than first.
     *
     * A network that gives no timestamp would otherwise sort to the top of the
     * feed forever, which reads as the newest thing and is not.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByRaw('occurred_at is null asc')->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /** How the feed reads the row: 'Rita Vance replied to your post'. */
    public function action(): string
    {
        return match ($this->kind) {
            self::MENTION => 'mentioned you',
            self::REPLY => 'replied to your post',
            self::FOLLOW => 'followed you',
            self::LIKE => 'liked your post',
            self::REPOST => 'reposted you',
            default => 'interacted with you',
        };
    }

    public function networkLabel(): string
    {
        return Networks::label($this->account?->network ?? '');
    }

    protected static function newFactory(): SocialNotificationFactory
    {
        return SocialNotificationFactory::new();
    }
}
