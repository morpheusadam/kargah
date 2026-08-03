<?php

namespace Modules\Platform\Providers;

use Modules\Core\Support\MorphMap;
use Modules\Platform\Http\Middleware\AuthenticateApplicationPassword;
use Modules\Platform\Http\Middleware\RequireScope;
use Modules\Platform\Models\ApplicationPassword;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Platform is the edge.
 *
 * Every other module is a piece of the domain and may be depended on. Platform
 * is what the outside world reaches Kargah through — application passwords
 * today, the HTTP API, the assistant and the CLI on top of them. The boundary
 * rule is therefore the inverse of everyone else's:
 *
 * - Platform may depend on any module's `Contracts` namespace.
 * - Platform may depend on **no** module's `Models`.
 * - **Nothing may depend on Platform.**
 *
 * That is what an API gateway is, and it is the only module allowed to see all
 * the others. If something outside this directory ever imports
 * `Modules\Platform\…`, the dependency is pointing the wrong way.
 */
class PlatformServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Platform';

    protected string $nameLower = 'platform';

    /**
     * Empty on purpose. The API, the assistant and the CLI are later work; this
     * module currently ships no artisan command.
     *
     * @var string[]
     */
    protected array $commands = [];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names: an activity row outlives a refactor, and a
        // fully qualified class name in `subject_type` becomes an orphan the
        // moment a model moves. Core enforces the map from `booted`, after
        // every module has had its turn — Platform's priority is 10, so this
        // runs in time.
        MorphMap::register([
            'application_password' => ApplicationPassword::class,
        ]);

        // Registered here rather than in bootstrap/app.php: a module that needs
        // a middleware should be able to declare one, and the app shell should
        // not have to know that Platform exists. `scope` takes its argument the
        // usual way — `->middleware('scope:project:read')`.
        $router = $this->app['router'];
        $router->aliasMiddleware('app-password', AuthenticateApplicationPassword::class);
        $router->aliasMiddleware('scope', RequireScope::class);
    }
}
