<?php

namespace Modules\Blog\Providers;

use Livewire\Livewire;
use Modules\Blog\Models\Article;
use Modules\Blog\Services\WordPressPublisher;
use Modules\Core\Support\MorphMap;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Blog is a client of Social, and the arrow only points one way.
 *
 * Everything this module publishes goes out through Social's spine: a WordPress
 * site is a `social_accounts` row, a published article is a `post_targets` row,
 * and the one-minute `social:publish-due` cron, the atomic claim, the
 * forward-only status, the per-target error and the media pipeline all apply to
 * it without a line being written for any of them. See the docblock on
 * `Modules\Social\Support\Networks::WORDPRESS` for the argument.
 *
 * The consequence for this file is the rule below, and it is worth stating
 * because it is the thing that would rot first:
 *
 * - Blog may depend on Social.
 * - **Social may not depend on Blog.** There is no `Modules\Blog\…` import
 *   anywhere under `Modules/Social`, and there must never be one. Social's own
 *   provider registers five drivers and knows nothing about a sixth; this
 *   provider is what adds it, from the outside, through the extension point
 *   `Publishing::extend()` was built to be.
 *
 * That is why the driver lives here rather than beside the other five. A network
 * whose driver needs a title, a slug and a taxonomy is not a social network, and
 * putting `WordPressPublisher` in `Modules/Social/app/Services/Publishers` would
 * have meant Social importing Blog's idea of an article the first time the
 * driver wanted anything from it.
 */
class BlogServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Blog';

    protected string $nameLower = 'blog';

    /** @var string[] */
    protected array $commands = [];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        /*
         * Add the WordPress driver to Social's registry from outside Social.
         *
         * `callAfterResolving()` rather than a bare `$this->app->resolving()`,
         * and the difference is not cosmetic. `Publishing` is bound as a
         * singleton in `SocialServiceProvider::register()`, and the container
         * fires resolving callbacks **only on the first resolution** — every
         * later `make()` returns the cached instance and skips them entirely.
         * So a `resolving()` callback registered after something has already
         * asked for the registry never runs, and the symptom is a WordPress
         * target failing with 'Kargah has no driver for WordPress' on a machine
         * where the same code works. `callAfterResolving()` covers both halves:
         * it registers the callback *and* applies it immediately when the
         * abstract has already been resolved.
         *
         * `BlogModuleTest::test_the_wordpress_driver_is_registered_in_socials_registry()`
         * asserts this from the container rather than by constructing the
         * driver, because constructing it proves the class exists and nothing
         * about whether anything would ever find it.
         */
        $this->callAfterResolving(Publishing::class, function (Publishing $publishing): void {
            $publishing->extend(Networks::WORDPRESS, fn (): WordPressPublisher => new WordPressPublisher);
        });
    }

    public function boot(): void
    {
        parent::boot();

        // An alias, not a class name. Rows in Core's `links` table and in
        // `activities` outlive refactors — see Modules\Core\Support\MorphMap.
        MorphMap::register([
            'blog_article' => Article::class,
        ]);

        $this->bootLivewireNamespace();
    }

    /**
     * Register `blog::` as a Livewire component namespace, from this module.
     *
     * Every other module in Kargah is listed in `config/livewire.php`, and the
     * comment above that list says each module owns its components "so a module
     * can be dropped in or removed without touching the app shell" — which the
     * list itself then contradicts. `Livewire::addNamespace()` is the API that
     * actually delivers it, and it does exactly what the config loop does:
     * `LivewireServiceProvider` walks `component_namespaces` and calls this
     * method plus the two view registrations below.
     *
     * Doing it here rather than in config is also what lets this module be
     * verified on its own. Adding the config entry as well is harmless — the
     * calls are idempotent, both write the same path — so the main thread can
     * add it for consistency without anything here needing to change.
     */
    private function bootLivewireNamespace(): void
    {
        $path = module_path($this->name, 'resources/views/components');

        Livewire::addNamespace($this->nameLower, viewPath: $path);

        if (! is_dir($path)) {
            return;
        }

        // The same two lines `LivewireServiceProvider` runs for a configured
        // namespace: without them `<livewire:blog::…>` resolves but a plain
        // Blade `blog::` view reference does not, and the two disagreeing is a
        // confusing way to find out.
        $this->app['blade.compiler']->anonymousComponentPath($path, $this->nameLower);
        $this->app['view']->addNamespace($this->nameLower, $path);
    }
}
