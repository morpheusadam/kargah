<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Support\Carbon;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\CurationWindow;

/**
 * What time of day each network is posted to, and the random minute inside it.
 *
 * ## Why this exists at all
 *
 * The owner asked for one post a day at a completely random time, inside
 * Instagram's peak hours in Iran. Researching those hours turned up a conflict
 * that changed the design: **Instagram in Iran peaks in the evening and LinkedIn's
 * best hour is a weekday morning.** Persian-language sources put Instagram at
 * 21:00–24:00 IRST, English-language ones at 17:00–21:00; 19:00–23:00 is the band
 * all of them agree on. LinkedIn — the network the owner named as most important —
 * is read before lunch and barely at all in the evening.
 *
 * One shared slot cannot serve both. Choosing the Instagram window would mean
 * deliberately posting to LinkedIn at its worst hour every single day. So each
 * network gets its own `Post` row with its own `scheduled_for`, which the existing
 * schema supports with no migration because `scheduled_for` already lives on the
 * post rather than the target.
 *
 * ## The randomness
 *
 * Uniform across the whole window, drawn once per network per day. Not jitter
 * around a fixed hour — the owner asked for random, and a channel that posts at
 * 21:03, 21:07, 20:58 every evening is a channel that posts at nine.
 *
 * ## Timezones
 *
 * 🔴 **The windows are wall-clock in Tehran and `scheduled_for` is UTC.** This is
 * the one place the two meet, and it is the only place the conversion is allowed
 * to happen. `config('app.timezone')` is UTC and stays UTC. Tehran is +3:30 and has
 * not observed daylight saving since 2022, so the offset is constant today — but
 * the conversion goes through the timezone database anyway, because a country
 * reversing that decision has happened before and would otherwise move every post
 * by an hour with nothing in the code to find.
 */
class Windows
{
    public function __construct(private readonly CurationSetting $settings) {}

    public static function make(): self
    {
        return new self(CurationSetting::current());
    }

    /**
     * When to post to this network on this day, as a UTC instant.
     *
     * `$on` is a date in the curator's own timezone, not a UTC instant — a run at
     * 01:30 UTC is already 05:00 in Tehran, and "today" has to mean the Tehran day
     * or every LinkedIn post would be scheduled for a window that closed before
     * the run started.
     */
    public function slotFor(string $network, ?Carbon $on = null): Carbon
    {
        $timezone = $this->timezone();
        $day = ($on?->copy() ?? Carbon::now($timezone))->setTimezone($timezone)->startOfDay();

        $window = CurationWindow::query()->usable()->where('network', $network)->first();

        [$from, $to] = $this->hoursFor($window, $day);

        $start = $this->at($day, $from);
        $end = $this->at($day, $to);

        // A window whose end is at or before its start is somebody's typo on the
        // settings page rather than a window crossing midnight — nothing in the
        // shipped defaults does, and treating it as a wrap would schedule
        // tomorrow's post today. One minute is a window, and a visible one.
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addMinute();
        }

        $minutes = $start->diffInMinutes($end);

        return $start->copy()
            ->addMinutes(random_int(0, max(0, (int) $minutes)))
            ->setTimezone('UTC');
    }

    /**
     * Whether a network is one the operator has switched off entirely.
     *
     * A network with no row at all is *not* switched off — it falls back to the
     * default window, so connecting a seventeenth account works without a
     * migration. Only an explicit inactive row means no.
     */
    public function isActive(string $network): bool
    {
        $window = CurationWindow::query()->where('network', $network)->first();

        return $window === null || $window->is_active;
    }

    /** The hours that apply on this day, weekday or weekend. */
    private function hoursFor(?CurationWindow $window, Carbon $day): array
    {
        $default = (array) config('social.curation.default_window', []);
        $weekend = in_array($day->isoWeekday(), $this->settings->weekendDays(), true);

        if ($window === null) {
            return [
                (string) ($default['starts_at'] ?? '20:00'),
                (string) ($default['ends_at'] ?? '23:00'),
            ];
        }

        return $window->hoursFor($weekend);
    }

    /** `HH:MM` against a day, tolerating a value somebody typed badly. */
    private function at(Carbon $day, string $time): Carbon
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return $day->copy()->setTime(
            max(0, min(23, $hour)),
            max(0, min(59, $minute)),
        );
    }

    public function timezone(): string
    {
        $timezone = trim((string) $this->settings->timezone);

        // A timezone the database does not recognise would throw inside Carbon at
        // the moment of scheduling, on a cron run, at one in the morning. Falling
        // back is not silently wrong in the way it usually would be: every window
        // is still applied consistently, just in UTC.
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
