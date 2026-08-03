<?php

namespace Modules\Project\Console;

use Illuminate\Console\Command;
use Modules\Project\Models\BoardList;
use Modules\Project\Services\CardService;

/**
 * Spread every list's card placements evenly again.
 *
 * The fractional ordering halves the gap between two neighbours on every
 * insertion in the same place, so it cannot go on for ever. `CardService`
 * rebalances the one list it needs to, on demand; this exists so the whole
 * database can be swept from cron or by hand.
 *
 * It counts placements rather than cards, because a card mirrored onto three
 * lists has three positions and each one is spread in its own list.
 *
 * Running it twice changes nothing the second time, which is the property that
 * makes it safe to schedule.
 */
class RebalancePositions extends Command
{
    protected $signature = 'project:rebalance {--board= : Limit to one board slug}';

    protected $description = 'Space out card placements so new cards always have room between them';

    public function handle(CardService $cards): int
    {
        $lists = BoardList::query()
            ->when($this->option('board'), fn ($q, $slug) => $q->whereHas(
                'board',
                fn ($b) => $b->where('slug', $slug),
            ))
            ->get();

        if ($lists->isEmpty()) {
            $this->components->warn('No lists to rebalance.');

            return self::SUCCESS;
        }

        $rows = 0;

        foreach ($lists as $list) {
            $rows += $cards->rebalance($list);
        }

        $this->components->info(
            'Rebalanced '.$rows.' '.str('placement')->plural($rows).' across '.$lists->count().' '.str('list')->plural($lists->count()).'.',
        );

        return self::SUCCESS;
    }
}
