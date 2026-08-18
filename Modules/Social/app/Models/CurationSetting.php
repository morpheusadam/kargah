<?php

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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
        'notify_enabled',
        // `notify_bot_token` is the name everything outside this class uses; the
        // `_encrypted` column is deliberately absent from `$fillable` so a form
        // cannot mass-assign past the accessor that does the encrypting.
        'notify_bot_token',
        'notify_chat_id',
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
            'notify_enabled' => 'boolean',
            'notify_bot_token_encrypted' => 'encrypted',
        ];
    }

    /** The raw column is never rendered, and neither is the value read off it. */
    protected $hidden = [
        'notify_bot_token_encrypted',
        'notify_bot_token',
    ];

    /**
     * The bot token, encrypted on the way in.
     *
     * 🔴 **The encryption happens inside the setter, not by returning the raw
     * column name.** `Attribute::make(set: fn ($v) => ['..._encrypted' => $v])` is
     * the form Laravel's documentation shows, and a mutator's return value is
     * merged straight into the raw attribute array — so the `encrypted` cast never
     * runs and the token is written in clear text. It fails silently and looks
     * right. This is the form that works, and it is the same one
     * `Modules\Social\Models\SocialAccount::credentials()` uses for the same
     * reason; see project-guaid/DECISIONS.md under "Phase 4 — Mailbox" for the
     * full account of how that was found.
     */
    protected function notifyBotToken(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => is_string($this->notify_bot_token_encrypted) && $this->notify_bot_token_encrypted !== ''
                ? $this->notify_bot_token_encrypted
                : null,
            set: fn (?string $value): array => [
                'notify_bot_token_encrypted' => $value === null || trim($value) === ''
                    ? null
                    : Crypt::encryptString(trim($value)),
            ],
        );
    }

    /** Whether a post can actually be announced: switched on, with both halves set. */
    public function canNotify(): bool
    {
        return $this->notify_enabled
            && $this->notify_bot_token !== null
            && trim((string) $this->notify_chat_id) !== '';
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
