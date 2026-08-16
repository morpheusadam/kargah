<?php

namespace Modules\Site\Providers;

use Livewire\Livewire;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Site is a client of Social, and the arrow only points one way.
 *
 * The same rule `Modules\Blog` states and for the same reason: the WordPress
 * credential lives in `social_accounts`, so this module imports
 * `Modules\Social\Models\SocialAccount` and `Modules\Social\Support\Networks`,
 * and nothing under `Modules/Social` may ever import `Modules\Site\…`.
 *
 * Where it differs from Blog is that this module registers no publisher. Blog
 * exists to *send* an article to a site; this one exists to *operate* the site
 * — read what is on it, change it, upload to it, purge its cache. Those are
 * different verbs against the same credential, which is why they are different
 * modules rather than more pages on Blog: deleting `Modules/Site` should take
 * the whole idea of managing somebody's website with it and leave publishing
 * exactly as it was.
 */
class SiteServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Site';

    protected string $nameLower = 'site';

    /** @var string[] */
    protected array $commands = [];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->bootLivewireNamespace();
    }

    /**
     * Register `site::` as a Livewire component namespace, from this module.
     *
     * Copied deliberately from `BlogServiceProvider` rather than adding another
     * entry to `config/livewire.php`: a module that registers its own namespace
     * can be dropped in or removed without editing the app shell, which is what
     * the comment above that config list has always claimed and what the list
     * itself contradicts. The calls are idempotent, so adding the config entry
     * as well would be harmless.
     */
    private function bootLivewireNamespace(): void
    {
        $path = module_path($this->name, 'resources/views/components');

        Livewire::addNamespace($this->nameLower, viewPath: $path);

        if (! is_dir($path)) {
            return;
        }

        $this->app['blade.compiler']->anonymousComponentPath($path, $this->nameLower);
        $this->app['view']->addNamespace($this->nameLower, $path);
    }
}
