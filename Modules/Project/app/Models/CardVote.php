<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Database\Factories\CardVoteFactory;

/**
 * One person's vote on one card.
 *
 * A row exists or it does not; there is nothing on it to edit, which is why
 * there is no `updated_at` — the same shape as `Watcher`. `created_at` is kept
 * because the order votes arrived in is the only thing that could ever make
 * the voter list interesting.
 *
 * No activity log and no notification. A vote is the lightest signal on the
 * board, and a feed entry or a notification for each one would drown the
 * things that actually changed the card.
 */
class CardVote extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['card_id', 'user_id'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): CardVoteFactory
    {
        return CardVoteFactory::new();
    }
}
