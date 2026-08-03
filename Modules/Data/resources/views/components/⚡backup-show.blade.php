<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Models\Backup;
use Modules\Data\Services\DatabaseBackups;

/**
 * One backup run, and the restore.
 *
 * The restore is real and it overwrites the live database, which is why it sits
 * behind typing the archive name out in full. Two guards run before a single
 * statement does: the archive must exist, and it must still hash to the checksum
 * recorded when it was written. Restoring a corrupted archive over a working
 * database turns one problem into two.
 *
 * A backup id that is not in the table renders an empty state rather than a 404
 * — the smoke test walks this route on an empty database, and "no such run" is
 * more use than a stack trace either way.
 */
new
#[Title('Backup — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Route parameter — the backup id. */
    public string $backup = '';

    /** The archive name that has to be typed out before the restore unlocks. */
    public string $confirmName = '';

    public function mount(string $backup = ''): void
    {
        $this->backup = $backup;
    }

    public function with(): array
    {
        $record = Backup::query()->find($this->backup);

        return [
            'b' => $record,
            'canRestore' => $record !== null && $record->isComplete() && $this->confirmName === $record->filename(),
            'statusBadge' => [
                Backup::STATUS_COMPLETE => 'kt-badge-success',
                Backup::STATUS_RUNNING => 'kt-badge-info',
                Backup::STATUS_PENDING => 'kt-badge-outline',
                Backup::STATUS_FAILED => 'kt-badge-destructive',
            ],
        ];
    }

    /** Re-hash the archive and compare it to the stored checksum. */
    public function verifyChecksum(): void
    {
        $record = Backup::query()->find($this->backup);

        if ($record === null) {
            return;
        }

        app(DatabaseBackups::class)->verify($record)
            ? $this->toastSuccess('Checksum matched', $record->filename().' is byte for byte what was written.')
            : $this->toastError(
                'Checksum did not match',
                'The archive is missing or has changed since it was written. Do not restore from it.'
            );
    }

    /**
     * Put this archive back over the live database.
     *
     * Everything created since the backup was taken is gone afterwards,
     * including the session this request arrived on — the sessions table is in
     * the archive like everything else, so the next page load is a login.
     */
    public function restore(): void
    {
        $record = Backup::query()->find($this->backup);

        if ($record === null || $this->confirmName !== $record->filename()) {
            $this->toastWarning('Nothing was restored', 'Type the archive name out in full to unlock the restore.');

            return;
        }

        try {
            app(DatabaseBackups::class)->restore($record, (string) config('database.default'));
        } catch (Throwable $e) {
            $this->toastError('Nothing was restored', $e->getMessage());

            return;
        }

        $this->flashToast(
            'success',
            'Restored from '.$record->filename(),
            'The database is now exactly what it was at '.($record->started_at?->toDateTimeString() ?? 'the time of the backup').'.'
        );

        $this->redirectRoute('data.backups', navigate: false);
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($b === null)
        <div>
            <a href="{{ route('data.backups') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                <i class="ki-filled ki-left text-xs"></i> Backups
            </a>
            <h1 class="text-xl font-semibold text-mono mt-1">Backup</h1>
            <p class="text-sm text-secondary-foreground mt-1">No run under that id.</p>
        </div>

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center gap-3">
                <i class="ki-filled ki-archive text-4xl text-muted-foreground"></i>
                <p class="text-sm text-secondary-foreground">This backup is not in the history. It may have been pruned.</p>
                <a href="{{ route('data.backups') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                    <i class="ki-filled ki-archive"></i> Back to the history
                </a>
            </div>
        </div>
    @else
        {{-- Header --}}
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('data.backups') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                    <i class="ki-filled ki-left text-xs"></i> Backups
                </a>
                <div class="flex flex-wrap items-center gap-2.5 mt-1">
                    <h1 class="text-xl font-semibold text-mono break-all">{{ $b->filename() }}</h1>
                    <span class="kt-badge kt-badge-sm {{ $statusBadge[$b->status] ?? 'kt-badge-outline' }}">{{ ucfirst($b->status) }}</span>
                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ ucfirst($b->target) }}</span>
                </div>
                <p class="text-sm text-secondary-foreground mt-1">
                    Started {{ $b->started_at?->toDateTimeString() ?? '—' }}
                    · {{ $b->duration() ?? 'still running' }}
                    · {{ $b->humanSize() }}
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @if ($b->isComplete())
                    <a href="{{ route('data.backup-download', ['backup' => $b->id]) }}" class="kt-btn kt-btn-primary gap-2">
                        <i class="ki-filled ki-exit-down"></i> Download archive
                    </a>
                    <button class="kt-btn kt-btn-outline text-destructive gap-2" data-kt-modal-toggle="#data-restore-modal">
                        <i class="ki-filled ki-arrows-circle"></i> Restore
                    </button>
                @endif
            </div>
        </div>

        @if ($b->isFailed())
            <div class="kt-card bg-destructive/5 border-destructive/30">
                <div class="kt-card-content flex items-start gap-3 p-4">
                    <i class="ki-filled ki-shield-cross text-destructive text-lg mt-0.5 shrink-0"></i>
                    <div class="text-sm text-secondary-foreground">
                        <strong class="text-mono">This run produced nothing.</strong>
                        {{ $b->error ?? 'No reason was recorded, which is itself worth investigating.' }}
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

            <div class="xl:col-span-2 flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">What is in the archive</h3>
                        <span class="text-xs text-muted-foreground">{{ $b->humanSize() }}</span>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">
                        <div class="flex items-center gap-3 rounded-lg border border-border p-3">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                <i class="ki-filled ki-data text-secondary-foreground"></i>
                            </span>
                            <div class="min-w-0 grow">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-medium text-mono truncate">The whole database</span>
                                    <span class="text-sm text-secondary-foreground shrink-0">{{ $b->humanSize() }}</span>
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Boards, invoices, clients, contacts, links — and the vault, still encrypted.
                                </div>
                            </div>
                        </div>

                        <p class="text-sm text-secondary-foreground">
                            The vault travels inside this archive exactly as it sits in the database: encrypted with
                            the application key. An archive without the key is unreadable, which is the good news and
                            the warning in one sentence. Keep <code class="text-xs px-1 py-0.5 rounded bg-muted">APP_KEY</code>
                            somewhere other than the backups disk.
                        </p>

                        <p class="text-sm text-secondary-foreground">
                            Stored files are not in here. They live on the attachments disk and are backed up by copying
                            that directory — a database dump that carried every uploaded PDF would be too large to take
                            nightly, which is the fastest way to end up taking it never.
                        </p>
                    </div>
                </div>
            </div>

            {{-- Sidebar --}}
            <div class="flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Integrity</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        @if ($b->checksum)
                            <div>
                                <div class="text-xs text-muted-foreground mb-1">SHA-256</div>
                                <code class="block text-[11px] leading-relaxed break-all rounded-lg bg-muted p-2.5 text-secondary-foreground">{{ $b->checksum }}</code>
                            </div>
                            <div class="flex items-center gap-2">
                                <button data-copy-text="{{ $b->checksum }}" class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 grow"
                                        title="Copy checksum" aria-label="Copy checksum">
                                    <i class="ki-filled ki-copy text-sm"></i> Copy
                                </button>
                                <button wire:click="verifyChecksum" wire:loading.attr="disabled" wire:target="verifyChecksum"
                                        class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 grow">
                                    <span wire:loading.remove wire:target="verifyChecksum">Re-verify</span>
                                    <span wire:loading wire:target="verifyChecksum"><i class="ki-filled ki-loading animate-spin"></i> Hashing…</span>
                                </button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Re-verifying reads the archive off the disk and hashes it again. It is the difference
                                between a file being there and a file being restorable.
                            </p>
                        @else
                            <p class="text-sm text-secondary-foreground">
                                No checksum was recorded, because no archive was written.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Details</h3></div>
                    <div class="kt-card-content p-5">
                        <dl class="flex flex-col gap-2.5 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Started</dt>
                                <dd class="text-secondary-foreground text-end">{{ $b->started_at?->toDateTimeString() ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Finished</dt>
                                <dd class="text-secondary-foreground text-end">{{ $b->completed_at?->toDateTimeString() ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Disk</dt>
                                <dd class="text-secondary-foreground text-end">{{ $b->disk }}, outside the web root</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Path</dt>
                                <dd class="text-secondary-foreground text-end break-all">{{ $b->path ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Retention</dt>
                                <dd class="text-secondary-foreground text-end">Records pruned after 90 days</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                @if ($b->isComplete())
                    <div class="kt-card bg-destructive/5 border-destructive/30">
                        <div class="kt-card-content p-4 flex items-start gap-3">
                            <i class="ki-filled ki-shield-cross text-destructive text-lg mt-0.5 shrink-0"></i>
                            <div class="text-sm text-secondary-foreground">
                                <strong class="text-mono">Restoring overwrites live data.</strong>
                                Everything created since {{ $b->started_at?->toDateTimeString() }} is replaced by what is
                                inside this archive, including the session you are reading this in.
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Restore confirmation --}}
        <div class="kt-modal" data-kt-modal="true" id="data-restore-modal">
            <div class="kt-modal-content max-w-[520px]">
                <div class="kt-modal-header">
                    <h3 class="kt-modal-title">Restore this backup</h3>
                    <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" data-kt-modal-dismiss="true" title="Close" aria-label="Close">
                        <i class="ki-filled ki-cross text-sm"></i>
                    </button>
                </div>

                <div class="kt-modal-body flex flex-col gap-4">
                    <div class="rounded-lg bg-destructive/5 border border-destructive/30 p-4 flex items-start gap-3">
                        <i class="ki-filled ki-information-2 text-destructive text-lg mt-0.5 shrink-0"></i>
                        <div class="text-sm text-secondary-foreground">
                            <strong class="text-mono">This cannot be undone.</strong>
                            <ul class="mt-2 flex flex-col gap-1.5">
                                <li class="flex items-start gap-2">
                                    <i class="ki-filled ki-arrow-right text-xs mt-1 text-destructive"></i>
                                    <span>Every table is dropped and rebuilt from the archive taken at {{ $b->started_at?->toDateTimeString() }}.</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="ki-filled ki-arrow-right text-xs mt-1 text-destructive"></i>
                                    <span>The archive is hashed first. A mismatch stops the restore before anything is written.</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="ki-filled ki-arrow-right text-xs mt-1 text-destructive"></i>
                                    <span>Sessions are in the database, so you will be signed out.</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="restore-confirm">
                            Type <code class="text-xs px-1 py-0.5 rounded bg-muted">{{ $b->filename() }}</code> to confirm
                        </label>
                        <input id="restore-confirm" type="text" class="kt-input" autocomplete="off"
                               placeholder="{{ $b->filename() }}" wire:model.live.debounce.300ms="confirmName">
                        @if ($confirmName !== '' && ! $canRestore)
                            <span class="text-xs text-destructive mt-1">The name does not match yet.</span>
                        @endif
                    </div>
                </div>

                <div class="kt-modal-footer justify-end gap-2">
                    <button class="kt-btn kt-btn-outline" data-kt-modal-dismiss="true">Cancel</button>
                    <button wire:click="restore" wire:loading.attr="disabled" wire:target="restore"
                            @disabled(! $canRestore)
                            class="kt-btn gap-2 {{ $canRestore ? 'kt-btn-primary bg-destructive border-destructive' : 'kt-btn-outline opacity-60' }}">
                        <span wire:loading.remove wire:target="restore" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-arrows-circle"></i> Restore and overwrite
                        </span>
                        <span wire:loading wire:target="restore" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Restoring…
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @script
    <script>
    (function () {
        if (! window.kargahCopy) {
            window.kargahCopy = function (text) {
                if (! text) return;

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text);
                    return;
                }

                var field = document.createElement('textarea');
                field.value = text;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                document.body.removeChild(field);
            };
        }

        function mount() {
            if (! $wire.$el || ! $wire.$el.isConnected) return;

            $wire.$el.querySelectorAll('[data-copy-text]').forEach(function (button) {
                if (button.onclick) return;

                button.onclick = function () {
                    window.kargahCopy(button.getAttribute('data-copy-text'));
                };
            });
        }

        Livewire.hook('morphed', mount);
        mount();
    })();
    </script>
    @endscript
</div>
