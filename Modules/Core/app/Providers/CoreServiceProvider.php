<?php

namespace Modules\Core\Providers;

use App\Models\User;
use Modules\Core\Contracts\CustomerReader as CustomerReaderContract;
use Modules\Core\Contracts\Linker as LinkerContract;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Core\Services\CustomerReader;
use Modules\Core\Services\Linker;
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
    }

    public function boot(): void
    {
        parent::boot();

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
}
