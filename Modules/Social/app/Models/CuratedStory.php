<?php

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Social\Services\Curation\Story;

/**
 * A story the curator has already considered — published or refused.
 *
 * Both halves matter. The published ones stop the channel running the same
 * article twice; the refused ones stop tomorrow's run spending another request
 * from a daily quota re-asking a model about an article every feed is still
 * carrying.
 */
class CuratedStory extends Model
{
    protected $table = 'curated_stories';

    protected $fillable = [
        'uid',
        'url_key',
        'title',
        'url',
        'source_label',
        'publisher',
        'score',
        'sources_count',
        'chosen_on',
        'was_skipped',
        'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'sources_count' => 'integer',
            'chosen_on' => 'date',
            'was_skipped' => 'boolean',
        ];
    }

    /**
     * The posts this story became — one per network, at its own hour.
     *
     * A pivot rather than a column on `posts`: see the migration's docblock for
     * why nothing is added to that table.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'curated_story_posts')
            ->withPivot('network')
            ->withTimestamps();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('was_skipped', false);
    }

    /**
     * Whether this story has been seen before, by either of its two keys.
     *
     * The URL key is the one that catches real duplicates — the same article
     * syndicated to two feeds differs only in tracking parameters — and the uid
     * catches the case where an outlet moves an article to a new address but keeps
     * its guid.
     */
    public static function alreadySeen(Story $story): bool
    {
        return static::query()
            ->where('url_key', $story->urlKey())
            ->orWhere('uid', $story->uid)
            ->exists();
    }
}
