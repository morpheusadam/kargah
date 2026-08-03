<?php

namespace App\Providers;

use App\Console\Commands\DisableTwoFactor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named here rather than found by scanning: `bootstrap/app.php` passes
        // `commands: routes/console.php`, which registers that *file* and
        // leaves `app/Console/Commands` undiscovered, so a class dropped in
        // that directory is not a command until something says so.
        $this->commands([
            DisableTwoFactor::class,
        ]);
    }
}
