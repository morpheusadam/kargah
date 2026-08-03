<?php

namespace Modules\Project\Providers;

use Modules\Core\Support\MorphMap;
use Modules\Project\Console\RebalancePositions;
use Modules\Project\Contracts\CardReader as CardReaderContract;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Services\CardReader;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ProjectServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Project';

    protected string $nameLower = 'project';

    /** @var string[] */
    protected array $commands = [
        RebalancePositions::class,
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
    }
}
