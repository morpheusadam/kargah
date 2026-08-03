<?php

namespace Modules\Core\Support;

use Illuminate\Support\Collection;

/**
 * The canonical list of events a person can configure, and nothing else's copy of it.
 *
 * `resources/views/pages/settings/⚡notifications.blade.php` and
 * `Modules\Core\Services\NotificationPreferences` both read this class rather
 * than keeping their own list, because the two drifting apart is exactly how
 * a setting stops working silently: an event the notifier fires but the page
 * cannot configure is a preference the user does not know they have, and an
 * event the page shows but the notifier never fires is a switch that does
 * nothing.
 *
 * **The defaults here are what "no row in `notification_preferences`" means.**
 * Nobody is seeded a row for every event on signup — see the migration's
 * docblock — so every reader of that table falls back to `default()` for a
 * user who has never visited the settings page. Get one of these wrong and
 * either a person stops hearing about something they never opted out of, or
 * an unconfigured event silently starts emailing everybody.
 *
 * An event string not in this list is not an error — `Notifier::notify()`
 * may be called with an event nobody has written a settings row for yet, and
 * refusing to tell anyone about it just because the page has not caught up
 * would be worse than the alternative. `defaultFor()` answers `true` for an
 * unknown event on purpose; see `NotificationPreferences::allows()`.
 */
final class NotificationEvents
{
    public const DEFAULT_DIGEST = 'daily';

    /** The only values `NotificationPreferences::save()` accepts for `digest`. */
    public const DIGESTS = ['instant', 'daily', 'weekly', 'off'];

    public const DEFAULT_QUIET_FROM = '22:00';

    public const DEFAULT_QUIET_TO = '08:00';

    /**
     * @return array<string, array{group: string, label: string, default: array{in_app: bool, email: bool}}>
     */
    public static function all(): array
    {
        return [
            'card.due_soon' => [
                'group' => 'Projects',
                'label' => 'A card is due today',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'card.overdue' => [
                'group' => 'Projects',
                'label' => 'A card has gone past its due date',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'card.assigned' => [
                'group' => 'Projects',
                'label' => 'A card is assigned to me',
                'default' => ['in_app' => true, 'email' => false],
            ],
            /*
             * The five below come from watching a card, a list or a board.
             * They are the ones a person opts into, so they default to on
             * in-app and off by email — an inbox filling up with "a card
             * moved" is how someone turns the whole feature off.
             */
            'card.commented' => [
                'group' => 'Projects',
                'label' => 'Someone comments on a card I watch',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'card.due_changed' => [
                'group' => 'Projects',
                'label' => 'The dates change on a card I watch',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'card.moved' => [
                'group' => 'Projects',
                'label' => 'A card I watch moves list',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'card.archived' => [
                'group' => 'Projects',
                'label' => 'A card I watch is archived',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'card.new_in_list' => [
                'group' => 'Projects',
                'label' => 'A card is added to a list or board I watch',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'email.received' => [
                'group' => 'Mail',
                'label' => 'New message in the inbox',
                'default' => ['in_app' => true, 'email' => false],
            ],
            'campaign.completed' => [
                'group' => 'Mail',
                'label' => 'A campaign finishes sending',
                'default' => ['in_app' => true, 'email' => true],
            ],
            'campaign.bounce_spike' => [
                'group' => 'Mail',
                'label' => 'Bounce rate crosses 2%',
                'default' => ['in_app' => true, 'email' => true],
            ],
            'provider.quota_low' => [
                'group' => 'Mail',
                'label' => 'A provider is near its quota',
                'default' => ['in_app' => true, 'email' => true],
            ],
            'invoice.paid' => [
                'group' => 'Accounting',
                'label' => 'An invoice is paid',
                'default' => ['in_app' => true, 'email' => true],
            ],
            'invoice.overdue' => [
                'group' => 'Accounting',
                'label' => 'An invoice goes overdue',
                'default' => ['in_app' => true, 'email' => true],
            ],
            'backup.failed' => [
                'group' => 'Data',
                'label' => 'A backup fails',
                'default' => ['in_app' => true, 'email' => true],
            ],
            /*
             * Both default to email on, unlike the watch-driven project events.
             * A connected account whose token is dying is exactly the thing a
             * person wants told to them when they are not looking at the app,
             * and there is no volume problem: a handful of accounts, a couple of
             * thresholds each.
             */
            'social.token_expiring' => [
                'group' => 'Social',
                'label' => "A connected account's token is expiring soon",
                'default' => ['in_app' => true, 'email' => true],
            ],
            'social.token_expired' => [
                'group' => 'Social',
                'label' => "A connected account's token has already expired",
                'default' => ['in_app' => true, 'email' => true],
            ],
            'post.failed' => [
                'group' => 'Social',
                'label' => 'A scheduled post fails',
                'default' => ['in_app' => true, 'email' => true],
            ],
        ];
    }

    public static function exists(string $event): bool
    {
        return array_key_exists($event, self::all());
    }

    /**
     * What "no row" means for one event on one channel.
     *
     * An event this class has never heard of defaults to allowed — see the
     * class docblock for why refusing is the wrong failure mode here.
     */
    public static function defaultFor(string $event, string $channel): bool
    {
        return self::all()[$event]['default'][$channel] ?? true;
    }

    /**
     * The event list grouped for the settings page, each row carrying its
     * own event key so the template never has to reconstruct it.
     *
     * @return Collection<string, Collection<int, array{group: string, label: string, default: array{in_app: bool, email: bool}, event: string}>>
     */
    public static function grouped(): Collection
    {
        return collect(self::all())
            ->map(fn (array $meta, string $event): array => [...$meta, 'event' => $event])
            ->values()
            ->groupBy('group');
    }
}
