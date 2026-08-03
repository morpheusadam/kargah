<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Contracts\Notifier;

/**
 * Keep the feed from becoming an archive.
 *
 * A notification is a thing you either act on or scroll past; three months
 * later it is neither, and a table that only grows makes the one query the bell
 * runs on every page load slower every week. There is no soft delete to fall
 * back on — 02-data-model.md's rule is "soft deletes on anything a person
 * created", and nobody created these — so this is a hard delete and that is the
 * whole design.
 *
 * Weekly rather than daily: the cutoff is measured in months, so a run that is
 * missed costs nothing, and the scheduler already has something on every other
 * daily slot.
 *
 * Running it twice deletes nothing the second time, because the cutoff is a
 * date and the rows past it are gone. That is the property every scheduled
 * command in Kargah has to have.
 */
class PruneNotifications extends Command
{
    protected $signature = 'core:prune-notifications {--days=90 : Delete notifications older than this many days}';

    protected $description = 'Delete in-app notifications older than the retention window';

    public function handle(Notifier $notifier): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->components->error('--days must be at least 1; pruning everything is not a retention policy.');

            return self::FAILURE;
        }

        $deleted = $notifier->prune($days);

        $this->components->info(
            $deleted === 0
                ? 'Nothing older than '.$days.' days.'
                : 'Deleted '.$deleted.' '.str('notification')->plural($deleted).' older than '.$days.' days.',
        );

        return self::SUCCESS;
    }
}
