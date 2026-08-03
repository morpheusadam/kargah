<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Database\Factories\CommentReactionFactory;
use Modules\Project\Support\Reactions;

/**
 * One person's one emoji on one comment.
 *
 * `emoji` holds the character itself, not a shortcode — see the migration for
 * why. Which characters are allowed is `Support\Reactions`, checked by whoever
 * writes the row rather than by the column; `name()` here is only so a chip
 * can be labelled without every caller reaching for the support class.
 *
 * Same non-shape as `CardVote`: no `updated_at`, no soft delete, no
 * notification. Reacting to a comment is not news.
 */
class CommentReaction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['card_comment_id', 'user_id', 'emoji'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(CardComment::class, 'card_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What this reaction reads as in a tooltip. */
    public function name(): string
    {
        return Reactions::name($this->emoji);
    }

    protected static function newFactory(): CommentReactionFactory
    {
        return CommentReactionFactory::new();
    }
}
