<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Project\Database\Factories\CardCommentFactory;

class CardComment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['card_id', 'created_by', 'body'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every emoji anybody has put on this comment, one row per person per
     * emoji. Grouping them into the chips a reader sees is the caller's job —
     * the relation stays the flat rows so a count, a "did I react", and the
     * grouped tally all come off the same single load.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class)->orderBy('created_at');
    }

    protected static function newFactory(): CardCommentFactory
    {
        return CardCommentFactory::new();
    }
}
