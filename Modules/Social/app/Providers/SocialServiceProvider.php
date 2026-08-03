<?php

namespace Modules\Social\Providers;

use Modules\Core\Support\MorphMap;
use Modules\Social\Console\PublishDue;
use Modules\Social\Console\SyncNotifications;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Models\SocialNotification;
use Modules\Social\Services\Publishers\BlueskyPublisher;
use Modules\Social\Services\Publishers\LinkedInPublisher;
use Modules\Social\Services\Publishers\MastodonPublisher;
use Modules\Social\Services\Publishers\TelegramPublisher;
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
    }
}
