<?php

namespace Modules\Core\Providers;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Console\PruneNotifications;
use Modules\Core\Contracts\CustomerReader as CustomerReaderContract;
use Modules\Core\Contracts\Linker as LinkerContract;
use Modules\Core\Contracts\Notifier as NotifierContract;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Core\Services\CustomerReader;
use Modules\Core\Services\Linker;
use Modules\Core\Services\Notifier;
use Modules\Core\Support\MorphMap;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Core';

    protected string $nameLower = 'core';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(CustomerReaderContract::class, CustomerReader::class);
        $this->app->bind(LinkerContract::class, Linker::class);
        $this->app->bind(NotifierContract::class, Notifier::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->bootNotifications();

        MorphMap::register([
            // `user` is an application model rather than a module one, but the
            // activity log writes it polymorphically as the causer of every
            // entry, so it needs an alias like anything else. Core owns the
            // map, so Core registers it.
            'user' => User::class,
            'company' => Company::class,
            'customer' => Customer::class,
        ]);

        // Feature modules register their own aliases in their boot(); module
        // providers run after Core's because Core has priority 0. Requiring the
        // map is deferred to `booted` so every module has had its turn.
        $this->app->booted(fn () => MorphMap::enforce());
    }

    /**
     * The notification spine: one command and one schedule entry.
     *
     * The `core::` Livewire namespace that the feed page renders under is in
     * `config/livewire.php` alongside the other five modules, not here.
     * `Livewire::addNamespace()` would work — it is keyed, so the two could not
     * conflict — but somebody looking for where a namespace is registered greps
     * that one file, and finding five of six there is worse than a marginal gain
     * in robustness.
     */
    private function bootNotifications(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([PruneNotifications::class]);

        // Weekly, not daily: the retention window is measured in months, so a
        // missed run costs nothing. `withoutOverlapping()` because a first run
        // against a table that has never been pruned can outlast its slot, and
        // a second delete of rows already going is wasted work at best.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('core:prune-notifications')
                ->weeklyOn(0, '04:30')
                ->withoutOverlapping();
        });
    }
}
