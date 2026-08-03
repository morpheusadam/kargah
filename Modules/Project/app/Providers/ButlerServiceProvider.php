<?php

namespace Modules\Project\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Support\MorphMap;
use Modules\Project\Butler\Butler;
use Modules\Project\Butler\Hooks;
use Modules\Project\Models\ButlerRule;

/**
 * Butler's own provider, kept out of `ProjectServiceProvider` on purpose: the
 * whole automation layer switches on and off from one line, and reading that
 * line should not mean reading past the module's card-numbering and due-sweep
 * wiring to find it.
 *
 * 🔴 **This provider is not registered anywhere yet.** `Modules/Project/module.json`
 * lists exactly one provider and that file belongs to nobody this session; the
 * alternative single line lives in `ProjectServiceProvider::$providers`, which
 * this task may read but not edit. Both are in the final report. Until one of
 * them is added, the model hooks are dormant — the rules table, the builder
 * page and the buttons all exist and the buttons still work (they call the
 * engine directly), but nothing fires on its own.
 *
 * `Butler` is a **singleton** and that is load-bearing, not tidiness: the loop
 * guard is instance state, and a guard the caller can get a fresh copy of stops
 * nothing. See `Butler`'s own docblock.
 */
class ButlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Butler::class);
    }

    public function boot(): void
    {
        // An alias, not a class name — the same rule the module's other morph
        // rows follow. A rule is a subject an activity entry or a notification
        // can point at, and those rows outlive refactors.
        MorphMap::register(['butler_rule' => ButlerRule::class]);

        Hooks::register();
    }
}
