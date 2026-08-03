<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Mailbox\Database\Factories\EmailThreadFactory;

/**
 * A conversation, held together by `In-Reply-To` rather than by a subject line.
 *
 * `participants`, `last_message_at` and `message_count` are all derivable from
 * the messages, and are stored anyway: the inbox draws a list of threads, and
 * recomputing three aggregates per row is the difference between one query and
 * one query per thread. `refreshCounters()` is the single place that writes
 * them, so they cannot drift in three directions at once.
 */
class EmailThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject',
        'participants',
        'last_message_at',
        'message_count',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class)->orderBy('received_at');
    }

    /** Threads with something unread in them — what the inbox badges. */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereHas('emails', fn (Builder $q) => $q->where('is_read', false));
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at');
    }

    /**
     * Recompute the stored aggregates from the messages themselves.
     *
     * Called after a sync chunk and by the seeder. Deliberately not a model
     * event: a chunk of two hundred messages should write this once per thread,
     * not once per message.
     */
    public function refreshCounters(): static
    {
        $emails = $this->emails()->get();

        $participants = $emails
            ->pluck('from_email')
            ->filter()
            ->map(fn (string $address): string => mb_strtolower($address))
            ->unique()
            ->values()
            ->all();

        $this->forceFill([
            'participants' => $participants,
            'message_count' => $emails->count(),
            'last_message_at' => $emails->max('received_at'),
        ])->save();

        return $this;
    }

    protected static function newFactory(): EmailThreadFactory
    {
        return EmailThreadFactory::new();
    }
}
