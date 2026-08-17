<?php

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The daily curator's own settings — one row, and `current()` is the only way in.
 *
 * A settings singleton rather than a config file because the owner has to be
 * able to change all of it from the settings page. `Modules/Social/config/
 * curation.php` still exists and still holds every default, but it is read once
 * by the seeder and never again; anything that reads config at run time here is
 * a bug, because it would answer differently from the page the operator is
 * looking at.
 *
 * `current()` creates the row if it is missing. That is a write on a read path,
 * which is normally worth avoiding, and it is right here for one reason: every
 * caller — the command, the settings page, the windows resolver — needs a
 * complete set of settings to do anything at all, and a null-object with the
 * column defaults would be a second definition of what those defaults are. The
 * database's own defaults are the single definition, so the row is made and the
 * database fills it.
 */
class CurationSetting extends Model
{
    protected $table = 'curation_settings';

    protected $fillable = [
        'is_enabled',
        'timezone',
        'curate_at_utc',
        'weekend_days',
        'max_age_hours',
        'min_summary_length',
        'spare_candidates',
        'hackernews_enabled',
        'hackernews_authority',
        'hackernews_min_points',
        'lobsters_enabled',
        'lobsters_authority',
        'lobsters_min_engagement',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'max_age_hours' => 'integer',
            'min_summary_length' => 'integer',
            'spare_candidates' => 'integer',
            'hackernews_enabled' => 'boolean',
            'hackernews_authority' => 'float',
            'hackernews_min_points' => 'integer',
            'lobsters_enabled' => 'boolean',
            'lobsters_authority' => 'float',
            'lobsters_min_engagement' => 'integer',
        ];
    }

    /**
     * The settings, made if they do not exist yet.
     *
     * 🔴 **The `refresh()` is load bearing and this is not obvious.**
     * `firstOrCreate([])` returns a model built from the attributes it was
     * given — here, none — so every column that the *database* defaults is
     * absent from the instance it hands back. `is_enabled` and
     * `hackernews_enabled` then read as null, which is falsy, and the curator
     * silently behaves as though the operator had switched Hacker News off and
     * the feature on nothing. It was caught by a test expecting two aggregators
     * and receiving none; without that test it would have looked like a quiet
     * news day forever.
     *
     * Reading the row back rather than restating the defaults as model
     * attributes keeps one definition of them — the migration, where each has
     * the paragraph explaining why it is that number.
     *
     * Not memoised on a static property. This application must not assume a
     * fresh process per request — the same reasoning that keeps `Publishing` and
     * `Assistant` free of static state — and a settings row cached for the life
     * of a queue worker is a settings page whose changes appear to be ignored.
     */
    public static function current(): self
    {
        $settings = static::query()->first();

        return $settings ?? static::query()->create([])->refresh();
    }

    /**
     * The weekdays that use the weekend window, as Carbon's ISO numbering.
     *
     * Stored as `4,5` rather than as two booleans or a JSON array because it is
     * a short list a person edits, and because the shape has to survive a
     * country changing its mind about which days those are.
     *
     * @return list<int> Monday 1 … Sunday 7
     */
    public function weekendDays(): array
    {
        $days = [];

        foreach (explode(',', (string) $this->weekend_days) as $piece) {
            $day = (int) trim($piece);

            if ($day >= 1 && $day <= 7 && ! in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        return $days;
    }
}
