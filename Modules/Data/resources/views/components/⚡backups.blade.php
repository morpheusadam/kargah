<?php

use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Models\Backup;
use Modules\Data\Services\DatabaseBackups;

/**
 * The backup history.
 *
 * Every row here is a run that actually happened, including the ones that
 * failed. A failed run is the most useful row on the page — a history showing
 * only successes is indistinguishable from a history that stopped being written.
 *
 * "Back up now" runs the same command the scheduler runs, rather than a second
 * implementation of it, so the button and the cron entry cannot drift apart.
 */
new
#[Title('Backups — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public function with(): array
    {
        $backups = Backup::query()->orderByDesc('started_at')->orderByDesc('id')->limit(50)->get();
        $lastGood = Backup::query()->complete()->orderByDesc('completed_at')->first();

        return [
            'history' => $backups,
            'lastGood' => $lastGood,
            'totalBytes' => (int) Backup::query()->complete()->sum('size_bytes'),
            'failures' => Backup::query()->failed()->count(),
            'disk' => (string) config('data.backups.disk', 'backups'),
            'unavailable' => app(DatabaseBackups::class)->unavailableReason(),
            // Whole class strings in a map, never assembled from the status.
            'statusBadge' => [
                Backup::STATUS_COMPLETE => 'kt-badge-success',
                Backup::STATUS_RUNNING => 'kt-badge-info',
                Backup::STATUS_PENDING => 'kt-badge-outline',
                Backup::STATUS_FAILED => 'kt-badge-destructive',
            ],
        ];
    }

    public function backUpNow(): void
    {
        $exit = Artisan::call('data:backup');
        $backup = Backup::query()->orderByDesc('id')->first();

        if ($exit !== 0 && $backup?->isFailed()) {
            $this->toastError('The backup failed', $backup->error ?? 'No reason was recorded.');

            return;
        }

        if ($backup === null || ! $backup->isComplete()) {
            // The command skipped rather than ran — an unsupported driver, or
            // MySQL with no mysqldump. Its own message says which.
            $this->toastWarning('Nothing was backed up', trim(Artisan::output()) ?: 'The command declined to run on this host.');

            return;
        }

        $this->toastSuccess(
            'Backed up '.$backup->humanSize(),
            $backup->filename().' is on the '.$backup->disk.' disk, outside the web root.'
        );
    }

    /** Re-hash a stored archive and compare it to what was recorded. */
    public function verify(int $id): void
    {
        $backup = Backup::query()->find($id);

        if ($backup === null) {
            return;
        }

        app(DatabaseBackups::class)->verify($backup)
            ? $this->toastSuccess('Checksum matched', $backup->filename().' is byte for byte what was written.')
            : $this->toastError(
                'Checksum did not match',
                'The archive is missing or has changed since it was written. Do not restore from it.'
            );
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Backups</h1>
            <p class="text-sm text-secondary-foreground mt-1">Taken nightly by cron, stored off the web root, checksummed.</p>
        </div>
        <button wire:click="backUpNow" wire:loading.attr="disabled" wire:target="backUpNow" class="kt-btn kt-btn-primary gap-2">
            <span wire:loading.remove wire:target="backUpNow" class="inline-flex items-center gap-2">
                <i class="ki-filled ki-cloud-download"></i> Back up now
            </span>
            <span wire:loading wire:target="backUpNow" class="inline-flex items-center gap-2">
                <i class="ki-filled ki-loading animate-spin"></i> Dumping…
            </span>
        </button>
    </div>

    @if ($unavailable)
        <div class="kt-card bg-warning/5 border-warning/30">
            <div class="kt-card-content flex items-start gap-3 p-4">
                <i class="ki-filled ki-information-2 text-warning text-lg mt-0.5 shrink-0"></i>
                <div class="text-sm text-secondary-foreground">
                    <strong class="text-mono">Backups cannot run on this host.</strong> {{ $unavailable }}
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="text-xs text-muted-foreground">Last good backup</div>
                <div class="text-2xl font-semibold text-mono mt-1">{{ $lastGood?->completed_at?->diffForHumans() ?? '—' }}</div>
                <div class="text-xs text-secondary-foreground mt-1">{{ $lastGood?->humanSize() ?? 'Nothing has completed yet.' }}</div>
            </div>
        </div>
        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="text-xs text-muted-foreground">Stored</div>
                <div class="text-2xl font-semibold text-mono mt-1">
                    {{ $totalBytes > 0 ? round($totalBytes / 1048576, 1).' MB' : '—' }}
                </div>
                <div class="text-xs text-secondary-foreground mt-1">Across every completed run on the {{ $disk }} disk.</div>
            </div>
        </div>
        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="text-xs text-muted-foreground">Failed runs</div>
                <div class="text-2xl font-semibold text-mono mt-1 {{ $failures > 0 ? 'text-destructive' : '' }}">{{ $failures }}</div>
                <div class="text-xs text-secondary-foreground mt-1">
                    {{ $failures > 0 ? 'Open one to read why.' : 'Nothing has failed.' }}
                </div>
            </div>
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">History</h3>
            <span class="text-xs text-muted-foreground">Scheduled daily at 03:00</span>
        </div>
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[170px]">Started</th>
                            <th class="min-w-[220px]">Archive</th>
                            <th class="w-[110px] text-end">Size</th>
                            <th class="w-[100px]">Took</th>
                            <th class="w-[120px]">Status</th>
                            <th class="w-[150px] text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($history as $h)
                            <tr wire:key="backup-{{ $h->id }}">
                                <td class="font-medium text-mono">{{ $h->started_at?->toDateTimeString() ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('data.backup-show', ['backup' => $h->id]) }}" wire:navigate
                                       class="text-primary hover:underline break-all">{{ $h->filename() }}</a>
                                    @if ($h->isFailed() && $h->error)
                                        <div class="text-xs text-destructive line-clamp-1">{{ $h->error }}</div>
                                    @endif
                                </td>
                                <td class="text-end text-secondary-foreground">{{ $h->humanSize() }}</td>
                                <td class="text-secondary-foreground">{{ $h->duration() ?? '—' }}</td>
                                <td>
                                    <span class="kt-badge kt-badge-sm {{ $statusBadge[$h->status] ?? 'kt-badge-outline' }}">
                                        {{ ucfirst($h->status) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="flex justify-end gap-1">
                                        <button wire:click="verify({{ $h->id }})" wire:loading.attr="disabled" wire:target="verify({{ $h->id }})"
                                                @disabled(! $h->isComplete())
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Verify checksum" aria-label="Verify checksum">
                                            <span wire:loading.remove wire:target="verify({{ $h->id }})"><i class="ki-filled ki-shield-tick text-sm"></i></span>
                                            <span wire:loading wire:target="verify({{ $h->id }})"><i class="ki-filled ki-loading animate-spin text-sm"></i></span>
                                        </button>
                                        @if ($h->isComplete())
                                            <a href="{{ route('data.backup-download', ['backup' => $h->id]) }}"
                                               class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Download" aria-label="Download">
                                                <i class="ki-filled ki-exit-down text-sm"></i>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="flex flex-col items-center py-14 text-center gap-3">
                                        <i class="ki-filled ki-archive text-4xl text-muted-foreground"></i>
                                        <p class="text-sm text-secondary-foreground">No backup has run yet.</p>
                                        <button wire:click="backUpNow" wire:loading.attr="disabled" wire:target="backUpNow" class="kt-btn kt-btn-primary gap-2">
                                            <span wire:loading.remove wire:target="backUpNow" class="inline-flex items-center gap-2">
                                                <i class="ki-filled ki-cloud-download"></i> Back up now
                                            </span>
                                            <span wire:loading wire:target="backUpNow" class="inline-flex items-center gap-2">
                                                <i class="ki-filled ki-loading animate-spin"></i> Dumping…
                                            </span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
