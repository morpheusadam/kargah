<?php

namespace Modules\Social\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Support\MorphMap;
use Modules\Social\Console\CheckTokenExpiry;
use Modules\Social\Console\CurateDaily;
use Modules\Social\Console\PublishDue;
use Modules\Social\Console\RefreshTokens;
use Modules\Social\Console\SyncNotifications;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Models\SocialNotification;
use Modules\Social\Services\Publishers\BlueskyPublisher;
use Modules\Social\Services\Publishers\DiscordPublisher;
use Modules\Social\Services\Publishers\FacebookPagePublisher;
use Modules\Social\Services\Publishers\InstagramPublisher;
use Modules\Social\Services\Publishers\LemmyPublisher;
use Modules\Social\Services\Publishers\LinkedInPublisher;
use Modules\Social\Services\Publishers\MastodonPublisher;
use Modules\Social\Services\Publishers\RedditPublisher;
use Modules\Social\Services\Publishers\SlackPublisher;
use Modules\Social\Services\Publishers\TelegramPublisher;
use Modules\Social\Services\Publishers\ThreadsPublisher;
use Modules\Social\Services\Publishers\TumblrPublisher;
use Modules\Social\Services\Publishers\VkPublisher;
use Modules\Social\Services\Publishers\XPublisher;
use Modules\Social\Services\Publishers\YouTubePublisher;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SocialServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Social';

    protected string $nameLower = 'social';

    /** @var string[] */
    protected array $commands = [
        PublishDue::class,
        SyncNotifications::class,
        CheckTokenExpiry::class,
        RefreshTokens::class,
        CurateDaily::class,
    ];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        /*
         * The driver registry, as a singleton.
         *
         * Singleton so that a driver swapped in a test's setUp is the same
         * registry the command, the job and the Livewire page resolve.
         * Factories rather than instances so a network nobody publishes to is
         * never built — and so a test that swaps a network for a fake never
         * constructs the real driver for it at all, which is what keeps
         * `Http::preventStrayRequests()` from being the only thing standing
         * between the suite and somebody's timeline.
         */
        $this->app->singleton(Publishing::class, function (): Publishing {
            $publishing = new Publishing;

            $publishing->extend(Networks::MASTODON, fn () => new MastodonPublisher);
            $publishing->extend(Networks::BLUESKY, fn () => new BlueskyPublisher);
            $publishing->extend(Networks::LINKEDIN, fn () => new LinkedInPublisher);
            $publishing->extend(Networks::TELEGRAM, fn () => new TelegramPublisher);
            $publishing->extend(Networks::DISCORD, fn () => new DiscordPublisher);
            $publishing->extend(Networks::X, fn () => new XPublisher);
            $publishing->extend(Networks::FACEBOOK_PAGE, fn () => new FacebookPagePublisher);
            $publishing->extend(Networks::INSTAGRAM, fn () => new InstagramPublisher);
            $publishing->extend(Networks::THREADS, fn () => new ThreadsPublisher);
            $publishing->extend(Networks::YOUTUBE, fn () => new YouTubePublisher);
            $publishing->extend(Networks::SLACK, fn () => new SlackPublisher);
            $publishing->extend(Networks::TUMBLR, fn () => new TumblrPublisher);
            $publishing->extend(Networks::VK, fn () => new VkPublisher);
            $publishing->extend(Networks::REDDIT, fn () => new RedditPublisher);
            $publishing->extend(Networks::LEMMY, fn () => new LemmyPublisher);

            return $publishing;
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names. Rows in Core's `links` table and in
        // `activities` outlive refactors, and a fully-qualified class name in
        // either column becomes an orphan the moment a model moves — see
        // Modules\Core\Support\MorphMap.
        MorphMap::register([
            'social_account' => SocialAccount::class,
            'social_post' => Post::class,
            'post_target' => PostTarget::class,
            'social_notification' => SocialNotification::class,
        ]);

        $this->bootTokenUpkeep();
        $this->bootDailyCuration();
    }

    /**
     * The daily story, chosen once a day and scheduled by the settings row.
     *
     * 🔴 **The hour comes from `curation_settings.curate_at_utc`, and it has to be
     * early.** The earliest posting window of the day is LinkedIn's weekday
     * morning, 08:00 in Tehran, which is 04:30 UTC. A curator that ran later than
     * that would choose a story for a window that had already closed and LinkedIn
     * would silently never be posted to. 01:30 UTC — 05:00 in Tehran — is the
     * shipped default, and it is also clear of every other entry on Kargah's
     * schedule, including the 03:00 database backup.
     *
     * Read from the database rather than hardcoded because the owner asked for all
     * of this to be adjustable from the settings pages, and a posting time that
     * needs a deploy to change is not adjustable.
     *
     * `withoutOverlapping()` because a run reads forty endpoints and can outlast
     * its minute, and two runs would both try to write the same story — the second
     * would lose on `curated_stories.url_key`, which is a caught exception rather
     * than a problem, but there is no reason to invite it.
     *
     * The command publishes nothing; `social:publish-due` does that when each
     * post's own hour arrives.
     */
    private function bootDailyCuration(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // A settings read at boot, wrapped: the scheduler is resolved during
            // `artisan migrate` on a database that does not have this table yet,
            // and an exception there would break the migration that creates it.
            try {
                $at = CurationSetting::current()->curate_at_utc;
            } catch (\Throwable) {
                $at = '01:30';
            }

            $schedule->command('social:curate-daily')
                ->dailyAt(preg_match('/^\d{2}:\d{2}$/', (string) $at) === 1 ? $at : '01:30')
                ->withoutOverlapping();
        });
    }

    /**
     * Keeping connected accounts connected: renew what can be renewed, then warn
     * about what is left.
     *
     * Two commands, both dispatched from cron, neither doing the work inline in
     * the scheduler itself — same pattern as
     * `Modules\Core\Providers\CoreServiceProvider::bootNotifications()` and
     * `Modules\Project\Providers\ProjectServiceProvider::bootDueCardSweep()`.
     * Scheduled here rather than in `routes/console.php` for the same reason
     * those two are: that file is shared across every module working today,
     * and these entries need nothing from it. `withoutOverlapping()` on both
     * because two ticks of either would race — the check over the dedupe key
     * `Notifier::notifyMany()` is built to survive but there is no reason to
     * invite, and the refresh over the credential itself, where a second run
     * arriving mid-write would be trading a token that has already been traded.
     *
     * 🔴 **The refresh runs first, and the ten minutes between them are the
     * point.** `social:refresh-tokens` renews Instagram and Threads around the
     * thirty-day mark, so on an install whose cron has just been repaired the
     * same morning can hold both a refreshable token and a warning about it.
     * Refreshing first means the warning that follows is only ever about a
     * credential that genuinely could not be saved. Reversed, the owner would be
     * told to go and paste a new token ten minutes before Kargah quietly fetched
     * one.
     *
     * Daily, not per-minute like `social:publish-due`: `token_expires_at`
     * moves in units of days for every network that has one at all, so a
     * tighter tick would only mean checking a clock that has not moved.
     */
    private function bootTokenUpkeep(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('social:refresh-tokens')
                ->dailyAt('08:05')
                ->withoutOverlapping();

            $schedule->command('social:check-token-expiry')
                ->dailyAt('08:15')
                ->withoutOverlapping();
        });
    }
}
