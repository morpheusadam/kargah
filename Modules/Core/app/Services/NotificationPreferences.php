<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Contracts\NotificationPreferences as NotificationPreferencesContract;
use Modules\Core\Models\NotificationPreference;
use Modules\Core\Models\NotificationSetting;
use Modules\Core\Support\NotificationEvents;

/**
 * The implementation behind `Modules\Core\Contracts\NotificationPreferences`.
 *
 * Read the contract first. What is worth knowing here is how each guarantee
 * it promises is actually kept:
 *
 * - "Absent means default" is kept by never seeding a row and always falling
 *   back to `NotificationEvents` when a query comes back empty — there is no
 *   place in this class that treats a missing row as `false`.
 * - `channelsForMany()` is the one query `Notifier::notifyMany()` needs
 *   regardless of how many users it is telling something.
 * - `inQuietHours()` does the wrap-around and timezone arithmetic once, in
 *   PHP, so `allows()` and any future email sender share one answer to "is it
 *   quiet right now" rather than each re-deriving it.
 * - `save()` only writes a row whose value actually changed, so calling it
 *   twice with the same form state moves no `updated_at` and creates nothing.
 */
class NotificationPreferences implements NotificationPreferencesContract
{
    private const CHANNELS = ['in_app', 'email'];

    public function allows(int $userId, string $event, string $channel, ?\DateTimeInterface $at = null): bool
    {
        $this->assertChannel($channel);

        $row = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event', $event)
            ->first(['in_app', 'email']);

        $allowed = $row !== null
            ? (bool) $row->{$channel}
            : NotificationEvents::defaultFor($event, $channel);

        if (! $allowed) {
            return false;
        }

        // Quiet hours are a delivery-time concern, and there is no delivery
        // to defer for the in-app feed — it is a page the user is already
        // looking at, or is not. Only email is ever suppressed by them.
        if ($channel === 'email' && $this->inQuietHours($userId, $at)) {
            return false;
        }

        return true;
    }

    public function channelsForMany(array $userIds, string $event): array
    {
        $ids = collect($userIds)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = NotificationPreference::query()
            ->whereIn('user_id', $ids)
            ->where('event', $event)
            ->get(['user_id', 'in_app', 'email'])
            ->keyBy('user_id');

        $default = [
            'in_app' => NotificationEvents::defaultFor($event, 'in_app'),
            'email' => NotificationEvents::defaultFor($event, 'email'),
        ];

        return $ids->mapWithKeys(function (int $id) use ($rows, $default): array {
            $row = $rows->get($id);

            $channels = $row !== null
                ? ['in_app' => (bool) $row->in_app, 'email' => (bool) $row->email]
                : $default;

            return [$id => $channels];
        })->all();
    }

    public function forUser(int $userId): array
    {
        $rows = NotificationPreference::query()
            ->where('user_id', $userId)
            ->get(['event', 'in_app', 'email'])
            ->keyBy('event');

        $result = [];

        foreach (NotificationEvents::all() as $event => $meta) {
            $row = $rows->get($event);

            $result[$event] = $row !== null
                ? ['in_app' => (bool) $row->in_app, 'email' => (bool) $row->email]
                : $meta['default'];
        }

        return $result;
    }

    public function digest(int $userId): string
    {
        return NotificationSetting::query()->where('user_id', $userId)->value('digest')
            ?? NotificationEvents::DEFAULT_DIGEST;
    }

    public function quietHours(int $userId): array
    {
        $row = NotificationSetting::query()
            ->where('user_id', $userId)
            ->first(['quiet_hours_enabled', 'quiet_hours_from', 'quiet_hours_to']);

        return [
            'enabled' => $row !== null ? (bool) $row->quiet_hours_enabled : false,
            'from' => $row?->quiet_hours_from ?? NotificationEvents::DEFAULT_QUIET_FROM,
            'to' => $row?->quiet_hours_to ?? NotificationEvents::DEFAULT_QUIET_TO,
        ];
    }

    public function inQuietHours(int $userId, ?\DateTimeInterface $at = null): bool
    {
        $settings = $this->quietHours($userId);

        if (! $settings['enabled']) {
            return false;
        }

        $now = ($at !== null ? Carbon::instance($at) : Carbon::now())
            ->copy()
            ->setTimezone($this->timezoneFor($userId));

        $nowMinutes = $now->hour * 60 + $now->minute;
        $from = $this->minutesSinceMidnight($settings['from']);
        $to = $this->minutesSinceMidnight($settings['to']);

        if ($from === $to) {
            // A window with the same start and end is not "off for a
            // moment" — nobody sets that up on purpose, and reading it as
            // "quiet around the clock" is the safer of the two guesses.
            return true;
        }

        if ($from < $to) {
            return $nowMinutes >= $from && $nowMinutes < $to;
        }

        // Wraps midnight — 22:00 to 08:00 is the ordinary case, not the edge
        // case. Quiet from `from` through 23:59, and again from 00:00 up to
        // (but not including) `to`.
        return $nowMinutes >= $from || $nowMinutes < $to;
    }

    public function save(
        int $userId,
        array $events,
        string $digest,
        bool $quietHoursEnabled,
        string $quietFrom,
        string $quietTo,
    ): void {
        if (! in_array($digest, NotificationEvents::DIGESTS, true)) {
            throw new InvalidArgumentException(
                'Unknown digest frequency ['.$digest.']. Expected one of: '.implode(', ', NotificationEvents::DIGESTS).'.'
            );
        }

        foreach (['quietFrom' => $quietFrom, 'quietTo' => $quietTo] as $label => $time) {
            if (! $this->isValidTimeOfDay($time)) {
                throw new InvalidArgumentException('The `'.$label.'` option must be H:i, got ['.$time.'].');
            }
        }

        foreach (array_keys($events) as $event) {
            if (! NotificationEvents::exists($event)) {
                throw new InvalidArgumentException('Unknown notification event ['.$event.'].');
            }
        }

        DB::transaction(function () use ($userId, $events, $digest, $quietHoursEnabled, $quietFrom, $quietTo): void {
            foreach ($events as $event => $channels) {
                $inApp = (bool) ($channels['in_app'] ?? true);
                $email = (bool) ($channels['email'] ?? true);

                $row = NotificationPreference::query()->firstOrNew(['user_id' => $userId, 'event' => $event]);

                if ($row->exists && (bool) $row->in_app === $inApp && (bool) $row->email === $email) {
                    // Identical to what is already stored — no write, no
                    // moved `updated_at`. This is what makes saving the same
                    // form twice a true no-op.
                    continue;
                }

                $row->in_app = $inApp;
                $row->email = $email;
                $row->save();
            }

            $settings = NotificationSetting::query()->firstOrNew(['user_id' => $userId]);

            $unchanged = $settings->exists
                && $settings->digest === $digest
                && (bool) $settings->quiet_hours_enabled === $quietHoursEnabled
                && $settings->quiet_hours_from === $quietFrom
                && $settings->quiet_hours_to === $quietTo;

            if ($unchanged) {
                return;
            }

            $settings->digest = $digest;
            $settings->quiet_hours_enabled = $quietHoursEnabled;
            $settings->quiet_hours_from = $quietFrom;
            $settings->quiet_hours_to = $quietTo;
            $settings->save();
        });
    }

    /* Internals ------------------------------------------------------------- */

    private function assertChannel(string $channel): void
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException(
                'Unknown notification channel ['.$channel.']. Expected one of: '.implode(', ', self::CHANNELS).'.'
            );
        }
    }

    /**
     * The user's own timezone, falling back to the application's when the
     * column is empty or holds something a `DateTimeZone` refuses — a rotated
     * preference should not turn "is it quiet right now" into a 500.
     */
    private function timezoneFor(int $userId): string
    {
        $timezone = User::query()->whereKey($userId)->value('timezone');

        if (! is_string($timezone) || $timezone === '') {
            return (string) config('app.timezone', 'UTC');
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            return (string) config('app.timezone', 'UTC');
        }

        return $timezone;
    }

    private function isValidTimeOfDay(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    private function minutesSinceMidnight(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '0');

        return ((int) $hour) * 60 + (int) $minute;
    }
}
