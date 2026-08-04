<?php

namespace Modules\Blog\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Web routes only.
 *
 * There is no `routes/api.php` here on purpose: the outside world reaches
 * Kargah through Platform, and a module that grew its own API surface would be
 * a second edge. See `Modules\Platform\Providers\PlatformServiceProvider`.
 */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Blog';

    public function boot(): void
    {
        parent::boot();
    }

    public function map(): void
    {
        $this->mapWebRoutes();
    }

    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }
}
