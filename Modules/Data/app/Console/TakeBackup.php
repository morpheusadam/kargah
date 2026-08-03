<?php

namespace Modules\Data\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Data\Models\Backup;
use Modules\Data\Services\DatabaseBackups;

/**
 * Dump the database to a disk outside the web root.
 *
 * Runs from the scheduler nightly. It is deliberately not clever: one run, one
 * row, one artefact, one checksum. A backup system that tries to be smart about
 * what changed is a backup system whose restore path is only exercised on the
 * worst day of the year.
 *
 * An unsupported host — MySQL with no `mysqldump` — is reported and skipped
 * before a row is created, so the history does not fill with failures for
 * something that was never attempted.
 */
class TakeBackup extends Command
{
    protected $signature = 'data:backup {--connection= : The database connection to dump, defaulting to the application default}';

    protected $description = 'Dump the database to the backups disk and record its size and checksum';

    public function handle(DatabaseBackups $backups): int
    {
        $connection = $this->option('connection') ?: (string) config('database.default');

        if ($reason = $backups->unavailableReason($connection)) {
            $this->components->warn('data:backup was skipped. '.$reason);
            Log::warning('data:backup skipped. '.$reason);

            return self::SUCCESS;
        }

        $backup = $backups->run($connection);

        if ($backup->isFailed()) {
            // Non-zero so cron, and whoever reads its mail, learns that last
            // night produced nothing restorable. The row carries the reason.
            $this->components->error('The backup failed: '.$backup->error);
            Log::error('data:backup failed. '.$backup->error);

            return self::FAILURE;
        }

        $this->components->info(
            'Backed up '.$connection.' to '.$backup->path.' on the '.$backup->disk.' disk — '
            .$backup->humanSize().', sha256 '.substr((string) $backup->checksum, 0, 12).'…'
        );

        $this->prune();

        return self::SUCCESS;
    }

    /**
     * Forget the runs nobody will ever look at again.
     *
     * Only rows, never files: deleting an archive is a decision with no undo,
     * and a shared host's disk quota is a smaller problem than a missing
     * backup. The history stays useful without growing without bound.
     */
    private function prune(): void
    {
        $cutoff = now()->subDays(90);

        $removed = Backup::query()
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', [Backup::STATUS_COMPLETE, Backup::STATUS_FAILED])
            ->delete();

        if ($removed > 0) {
            $this->components->info('Pruned '.$removed.' backup '.str('record')->plural($removed).' older than 90 days.');
        }
    }
}
