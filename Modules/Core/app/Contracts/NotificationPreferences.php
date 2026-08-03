<?php

namespace Modules\Core\Contracts;

/**
 * Whether a person wants to be told about something, and how.
 *
 * `Modules\Core\Services\Notifier` consults this so that turning a switch off
 * on `/settings/notifications` actually does something; nothing else in Core
 * calls it directly except the settings page itself, which reads and writes
 * through here rather than touching `Modules\Core\Models\NotificationPreference`
 * or `Modules\Core\Models\NotificationSetting` — same rule as `Notifier`:
 * arrays out, never models.
 *
 * **Absent always means the documented default, never "off".** A user who has
 * never opened the settings page has no rows in either backing table, and
 * every method here is written so that reading for such a user returns
 * exactly what `Modules\Core\Support\NotificationEvents` says the event
 * defaults to, not a false born from an empty result set.
 *
 * ## Quiet hours
 *
 * Quiet hours **suppress email only**; they never touch the in-app feed and
 * they never defer — a suppressed email is simply never considered sent, not
 * queued for the moment quiet hours end. See `inQuietHours()` and
 * `Notifier::notify()`.
 */
interface NotificationPreferences
{
    /**
     * Whether `$userId` should be told about `$event` on `$channel` right now.
     *
     * `$channel` is `'in_app'` or `'email'`. For `'email'`, this also folds in
     * `inQuietHours()` — a switched-on email preference still answers `false`
     * during the user's quiet hours. `'in_app'` never consults quiet hours.
     *
     * `$at` is the instant to evaluate quiet hours against; `null` means now.
     * Tests pass a fixed instant so a window that wraps midnight can be
     * checked without `travel()`.
     *
     * @throws \InvalidArgumentException if `$channel` is neither
     */
    public function allows(int $userId, string $event, string $channel, ?\DateTimeInterface $at = null): bool;

    /**
     * The in-app/email split for `$event`, for several users in one query.
     *
     * Built for `Notifier::notifyMany()`: telling a board's fifty watchers
     * about one comment must not become fifty reads against this table.
     * Quiet hours are deliberately not folded in here — `notifyMany()` only
     * ever calls this for the `'in_app'` channel, which quiet hours never
     * affect; an email-sending caller must go through `allows()` per user
     * once that exists.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{in_app: bool, email: bool}> keyed by user id;
     *                                                       every id in
     *                                                       `$userIds` is
     *                                                       present
     */
    public function channelsForMany(array $userIds, string $event): array;

    /**
     * Every event `NotificationEvents` knows about, for one user, with
     * defaults filled in for any that have no row. What the settings page
     * renders on `mount()`.
     *
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public function forUser(int $userId): array;

    /** `'instant'`, `'daily'`, `'weekly'` or `'off'`; `NotificationEvents::DEFAULT_DIGEST` if unset. */
    public function digest(int $userId): string;

    /**
     * The quiet-hours window as the user set it, or the documented defaults
     * if they never have. `from`/`to` are `H:i`, local to the user's own
     * timezone — never converted here, only in `inQuietHours()`.
     *
     * @return array{enabled: bool, from: string, to: string}
     */
    public function quietHours(int $userId): array;

    /**
     * Whether `$at` (or now) falls inside `$userId`'s quiet-hours window, in
     * that user's own timezone.
     *
     * A window where `from` is later than `to` — 22:00 to 08:00 — wraps
     * midnight; that is the ordinary case, not an edge case. The window is
     * inclusive of `from` and exclusive of `to`, so the boundary minute at
     * the end of quiet hours is already free to send.
     */
    public function inQuietHours(int $userId, ?\DateTimeInterface $at = null): bool;

    /**
     * Persist everything `/settings/notifications` edits, in one call.
     *
     * A value identical to what is already stored writes nothing — no new
     * row, no changed `updated_at` — so calling this twice with the same
     * form state is a true no-op, not merely one that ends in the same
     * values.
     *
     * @param  array<string, array{in_app: bool, email: bool}>  $events  every
     *                                                                   key
     *                                                                   must
     *                                                                   be a
     *                                                                   known
     *                                                                   event
     *
     * @throws \InvalidArgumentException on an event key `NotificationEvents`
     *                                    does not recognise, an unknown
     *                                    `$digest`, or a quiet-hours time not
     *                                    shaped `H:i`
     */
    public function save(
        int $userId,
        array $events,
        string $digest,
        bool $quietHoursEnabled,
        string $quietFrom,
        string $quietTo,
    ): void;
}
