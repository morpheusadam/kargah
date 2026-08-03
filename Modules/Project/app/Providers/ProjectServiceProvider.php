<?php

namespace Modules\Project\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Support\MorphMap;
use Modules\Project\Console\NotifyDueCards;
use Modules\Project\Console\RebalancePositions;
use Modules\Project\Contracts\BoardReader as BoardReaderContract;
use Modules\Project\Contracts\CardReader as CardReaderContract;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Observers\CardCommentObserver;
use Modules\Project\Observers\CardObserver;
use Modules\Project\Observers\CardPlacementObserver;
use Modules\Project\Services\BoardReader;
use Modules\Project\Services\CardReader;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ProjectServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Project';

    protected string $nameLower = 'project';

    /** @var string[] */
    protected array $commands = [
        RebalancePositions::class,
        NotifyDueCards::class,
    ];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(CardReaderContract::class, CardReader::class);
        $this->app->bind(BoardReaderContract::class, BoardReader::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names. These rows outlive refactors — see
        // Modules\Core\Support\MorphMap.
        MorphMap::register([
            'board' => Board::class,
            'board_list' => BoardList::class,
            'card' => Card::class,
            'card_comment' => CardComment::class,
        ]);

        // The watching producers. Observers rather than calls from the Blade
        // components, so a comment or a move notifies watchers however it was
        // made — drawer, seeder, API, or later the assistant. See
        // Modules\Project\Observers\CardCommentObserver's own docblock.
        Card::observe(CardObserver::class);
        CardComment::observe(CardCommentObserver::class);
        CardPlacement::observe(CardPlacementObserver::class);

        $this->bootDueCardSweep();
    }

    /**
     * The due-date sweep: one command, dispatched from cron, never doing the
     * work inline in the scheduler itself — same pattern as
     * `Modules\Core\Providers\CoreServiceProvider::bootNotifications()`.
     * `withoutOverlapping()` because two overlapping ticks would otherwise
     * race the same dedupe key, which `Notifier::notify()` is built to
     * survive but there is no reason to invite.
     */
    private function bootDueCardSweep(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('project:notify-due-cards')
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        });
    }
}
