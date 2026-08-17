<?php

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * When one network is posted to, and how many hashtags its copy carries.
 *
 * One row per network, because the entire reason this feature schedules per
 * network is that the good hour for LinkedIn and the good hour for Instagram in
 * Iran sit at opposite ends of the day — a single shared window cannot serve
 * both, and choosing one would mean deliberately posting to the other at its
 * worst hour.
 *
 * The times are `HH:MM` wall-clock in `curation_settings.timezone`, never
 * instants. `Windows` turns a row plus a date into one real UTC timestamp, and
 * that is the only place the conversion happens.
 *
 * The weekend pair is nullable, and null means "the weekday window applies at
 * weekends too". That is distinguishable from a weekend pair set to the same
 * hours, which records that somebody looked at it and decided.
 */
class CurationWindow extends Model
{
    protected $table = 'curation_windows';

    protected $fillable = [
        'network',
        'starts_at',
        'ends_at',
        'weekend_starts_at',
        'weekend_ends_at',
        'hashtags_min',
        'hashtags_max',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hashtags_min' => 'integer',
            'hashtags_max' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The pair of times that apply on a given kind of day.
     *
     * @return array{0: string, 1: string}
     */
    public function hoursFor(bool $weekend): array
    {
        if ($weekend && $this->weekend_starts_at !== null && $this->weekend_ends_at !== null) {
            return [$this->weekend_starts_at, $this->weekend_ends_at];
        }

        return [$this->starts_at, $this->ends_at];
    }
}
