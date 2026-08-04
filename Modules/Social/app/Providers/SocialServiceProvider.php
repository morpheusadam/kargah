<?php

namespace Modules\Social\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Support\MorphMap;
use Modules\Social\Console\CheckTokenExpiry;
use Modules\Social\Console\PublishDue;
use Modules\Social\Console\SyncNotifications;
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

        $this->bootTokenExpiryCheck();
    }

    /**
     * The token-expiry sweep: one command, dispatched from cron, never doing
     * the work inline in the scheduler itself — same pattern as
     * `Modules\Core\Providers\CoreServiceProvider::bootNotifications()` and
     * `Modules\Project\Providers\ProjectServiceProvider::bootDueCardSweep()`.
     * Scheduled here rather than in `routes/console.php` for the same reason
     * those two are: that file is shared across every module working today,
     * and this entry needs nothing from it. `withoutOverlapping()` because a
     * lookup this cheap has no business running twice at once, and because
     * two overlapping ticks would otherwise race the same dedupe key that
     * `Notifier::notifyMany()` is built to survive but there is no reason to
     * invite.
     *
     * Daily, not per-minute like `social:publish-due`: `token_expires_at`
     * moves in units of days for every network that has one at all, so a
     * tighter tick would only mean checking a clock that has not moved.
     */
    private function bootTokenExpiryCheck(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('social:check-token-expiry')
                ->dailyAt('08:15')
                ->withoutOverlapping();
        });
    }
}
