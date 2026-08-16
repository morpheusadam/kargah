<?php

namespace Modules\Site\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Web routes only.
 *
 * No `routes/api.php` here for the same reason Blog has none: the outside world
 * reaches Kargah through Platform, and a module that grew its own API surface
 * would be a second edge with its own idea of authentication.
 */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Site';

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
