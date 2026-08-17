<?php

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One outlet the curator reads.
 *
 * `label` is unique, and that constraint is load bearing rather than tidy: the
 * ranker's central signal is how many *independent* outlets carry a story, and
 * it groups by this column. Two rows sharing a label would let one publisher
 * count as two outlets agreeing, and the day would go to whichever story that
 * publisher happened to run twice.
 */
class CurationFeed extends Model
{
    protected $table = 'curation_feeds';

    protected $fillable = [
        'label',
        'url',
        'authority',
        'max_age_hours',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'authority' => 'float',
            'max_age_hours' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The feeds a run should read, in the order the settings page lists them. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('label');
    }
}
