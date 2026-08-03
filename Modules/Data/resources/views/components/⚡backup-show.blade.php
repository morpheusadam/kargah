<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Backup detail and restore.
 *
 * Shows exactly what is inside an archive before anything is done with it, and
 * puts the restore behind a typed confirmation because it overwrites live data.
 */
new
#[Title('Backup — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Route parameter — the backup id. */
    public string $backup = '1';

    /** The archive name the owner must type to unlock the restore. */
    public string $confirmName = '';

    public function mount(string $backup = '1'): void
    {
        $this->backup = $backup;
    }

    /** Static fixtures until the backup runner lands. */
    protected function backups(): array
    {
        return [
            '1' => [
                'name' => 'kargah-2026-08-02-0300.zip',
                'target' => 'Full',
                'created' => '2026-08-02 03:00',
                'finished' => '2026-08-02 03:04',
                'duration' => '3 min 51 s',
                'size' => '86.2 MB',
                'status' => 'ok',
                'trigger' => 'Scheduled (cron, daily 03:00)',
                'disk' => 'Private disk, outside the web root',
                'retention' => 'Kept 30 days, then pruned',
                'checksum' => '9f3c1a7b4d81ec2075b70a5583c1e6f2a10f7b4c2ce9d13877b0d4ac88e2f10',
                'algorithm' => 'SHA-256',
                'verified' => '2026-08-02 03:05',
                'parts' => [
                    ['label' => 'Files',       'icon' => 'ki-folder',   'items' => '412 files',    'bytes' => '68.4 MB', 'percent' => 79],
                    ['label' => 'Database',    'icon' => 'ki-data',     'items' => '38 tables',    'bytes' => '14.1 MB', 'percent' => 16],
                    ['label' => 'Vault',       'icon' => 'ki-lock',     'items' => '23 entries',   'bytes' => '2.8 MB',  'percent' => 3],
                    ['label' => 'Mail archive','icon' => 'ki-sms',      'items' => '1,904 messages','bytes' => '0.9 MB', 'percent' => 2],
                ],
                'manifest' => [
                    ['path' => 'database/database.sqlite',     'note' => 'Boards, invoices, clients, contacts, links', 'bytes' => '14.1 MB'],
                    ['path' => 'storage/private/files/',       'note' => 'Contracts, invoices, brand assets',          'bytes' => '68.4 MB'],
                    ['path' => 'storage/private/vault.enc',    'note' => 'Credential vault, still encrypted',          'bytes' => '2.8 MB'],
                    ['path' => 'storage/private/mail/',        'note' => 'Archived message bodies',                    'bytes' => '0.9 MB'],
                    ['path' => 'manifest.json',                'note' => 'Checksums and versions for every entry',     'bytes' => '42 KB'],
                ],
            ],
            '2' => [
                'name' => 'kargah-db-2026-08-01-0300.zip',
                'target' => 'Database',
                'created' => '2026-08-01 03:00',
                'finished' => '2026-08-01 03:00',
                'duration' => '38 s',
                'size' => '1.4 MB',
                'status' => 'ok',
                'trigger' => 'Scheduled (cron, daily 03:00)',
                'disk' => 'Private disk, outside the web root',
                'retention' => 'Kept 30 days, then pruned',
                'checksum' => '2ce9d13877b0d4ac88e2f109f3c1a7b4d81ec2075b70a5583c1e6f2a10f7b4c',
                'algorithm' => 'SHA-256',
                'verified' => '2026-08-01 03:01',
                'parts' => [
                    ['label' => 'Database', 'icon' => 'ki-data', 'items' => '38 tables', 'bytes' => '1.4 MB', 'percent' => 100],
                ],
                'manifest' => [
                    ['path' => 'database/database.sqlite', 'note' => 'Full schema and rows', 'bytes' => '1.4 MB'],
                    ['path' => 'manifest.json',            'note' => 'Checksums and versions', 'bytes' => '6 KB'],
                ],
            ],
        ];
    }

    public function with(): array
    {
        $all = $this->backups();
        $b = $all[$this->backup] ?? $all['1'];

        return [
            'b' => $b,
            'canRestore' => $this->confirmName === $b['name'],
            'partTone' => [
                'Files' => 'bg-primary',
                'Database' => 'bg-info',
                'Vault' => 'bg-warning',
                'Mail archive' => 'bg-success',
            ],
        ];
    }

    public function download(): void
    {
        // Backend: stream the archive from the private disk with a signed response.

        $this->toastInfo('Download is not available yet', 'Streaming the archive needs the backend.');
    }

    /** Re-hash the archive and compare it to the stored checksum. */
    public function verifyChecksum(): void
    {
        // Backend: hash_file the archive and flag a mismatch.

        // Never "checksum verified" — nothing was hashed, so the archive is
        // neither proven intact nor proven damaged.
        $this->toastInfo('Checksum was not verified', 'Re-hashing the archive needs the backend. The stored value is unchecked.');
    }

    /** Unpack the archive over the live install. */
    public function restore(): void
    {
        // Backend: put the app in maintenance mode, unpack, migrate, then release.

        $this->toastInfo(
            'Restore is not available yet',
            'Nothing was overwritten. A real restore replaces the database, the stored files and the vault with the contents of this archive.'
        );
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('data.backups') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                <i class="ki-filled ki-left text-xs"></i> Backups
            </a>
            <div class="flex flex-wrap items-center gap-2.5 mt-1">
                <h1 class="text-xl font-semibold text-mono break-all">{{ $b['name'] }}</h1>
                <span class="kt-badge kt-badge-sm kt-badge-success">Completed</span>
                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $b['target'] }}</span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">
                Taken {{ $b['created'] }} · {{ $b['duration'] }} · {{ $b['size'] }}
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button wire:click="download" wire:loading.attr="disabled" wire:target="download" class="kt-btn kt-btn-primary gap-2">
                <span wire:loading.remove wire:target="download" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-exit-down"></i> Download archive
                </span>
                <span wire:loading wire:target="download" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Preparing…
                </span>
            </button>
            <button class="kt-btn kt-btn-outline text-destructive gap-2" data-kt-modal-toggle="#data-restore-modal">
                <i class="ki-filled ki-arrows-circle"></i> Restore
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        <div class="xl:col-span-2 flex flex-col gap-5">

            {{-- Size breakdown --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Size breakdown</h3>
                    <span class="text-xs text-muted-foreground">{{ $b['size'] }} compressed</span>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <div class="flex h-2.5 w-full rounded-full overflow-hidden bg-muted">
                        @foreach ($b['parts'] as $p)
                            <div class="h-full {{ $partTone[$p['label']] ?? 'bg-muted-foreground' }}"
                                 style="width: {{ $p['percent'] }}%"
                                 title="{{ $p['label'] }} — {{ $p['bytes'] }}"></div>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($b['parts'] as $p)
                            <div class="flex items-center gap-3 rounded-lg border border-border p-3">
                                <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                    <i class="ki-filled {{ $p['icon'] }} text-secondary-foreground"></i>
                                </span>
                                <div class="min-w-0 grow">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-medium text-mono truncate">{{ $p['label'] }}</span>
                                        <span class="text-sm text-secondary-foreground shrink-0">{{ $p['bytes'] }}</span>
                                    </div>
                                    <div class="text-xs text-muted-foreground">{{ $p['items'] }} · {{ $p['percent'] }}%</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Manifest --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Manifest</h3>
                    <span class="text-xs text-muted-foreground">What the archive contains</span>
                </div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[240px]">Path</th>
                                    <th class="min-w-[240px]">Contents</th>
                                    <th class="w-[110px] text-end">Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($b['manifest'] as $m)
                                    <tr>
                                        <td><code class="text-xs px-1.5 py-0.5 rounded bg-muted text-mono">{{ $m['path'] }}</code></td>
                                        <td class="text-secondary-foreground">{{ $m['note'] }}</td>
                                        <td class="text-end text-secondary-foreground">{{ $m['bytes'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3">
                                            <div class="flex flex-col items-center py-12 text-center gap-2">
                                                <i class="ki-filled ki-archive text-4xl text-muted-foreground"></i>
                                                <p class="text-sm text-secondary-foreground">This archive has no manifest.</p>
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

        {{-- Sidebar --}}
        <div class="flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Integrity</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-center gap-2 text-sm">
                        <i class="ki-filled ki-shield-tick text-success"></i>
                        <span class="text-secondary-foreground">Checksum matched on {{ $b['verified'] }}</span>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground mb-1">{{ $b['algorithm'] }}</div>
                        <code class="block text-[11px] leading-relaxed break-all rounded-lg bg-muted p-2.5 text-secondary-foreground">{{ $b['checksum'] }}</code>
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 grow" title="Copy checksum">
                            <i class="ki-filled ki-copy text-sm"></i> Copy
                        </button>
                        <button wire:click="verifyChecksum" wire:loading.attr="disabled" wire:target="verifyChecksum"
                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 grow">
                            <span wire:loading.remove wire:target="verifyChecksum">Re-verify</span>
                            <span wire:loading wire:target="verifyChecksum"><i class="ki-filled ki-loading animate-spin"></i> Hashing…</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Details</h3></div>
                <div class="kt-card-content p-5">
                    <dl class="flex flex-col gap-2.5 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-muted-foreground shrink-0">Started</dt>
                            <dd class="text-secondary-foreground text-end">{{ $b['created'] }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-muted-foreground shrink-0">Finished</dt>
                            <dd class="text-secondary-foreground text-end">{{ $b['finished'] }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-muted-foreground shrink-0">Trigger</dt>
                            <dd class="text-secondary-foreground text-end">{{ $b['trigger'] }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-muted-foreground shrink-0">Stored</dt>
                            <dd class="text-secondary-foreground text-end">{{ $b['disk'] }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-muted-foreground shrink-0">Retention</dt>
                            <dd class="text-secondary-foreground text-end">{{ $b['retention'] }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="kt-card bg-destructive/5 border-destructive/30">
                <div class="kt-card-content p-4 flex items-start gap-3">
                    <i class="ki-filled ki-shield-cross text-destructive text-lg mt-0.5 shrink-0"></i>
                    <div class="text-sm text-secondary-foreground">
                        <strong class="text-mono">Restoring overwrites live data.</strong>
                        Everything created since {{ $b['created'] }} is replaced by what is inside this archive.
                    </div>
                </div>
            </div>
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
                            @foreach ($b['parts'] as $p)
                                <li class="flex items-start gap-2">
                                    <i class="ki-filled ki-arrow-right text-xs mt-1 text-destructive"></i>
                                    <span>{{ $p['label'] }} is replaced wholesale — {{ $p['items'] }} from {{ $b['created'] }}.</span>
                                </li>
                            @endforeach
                            <li class="flex items-start gap-2">
                                <i class="ki-filled ki-arrow-right text-xs mt-1 text-destructive"></i>
                                <span>The app goes into maintenance mode until the unpack and migrations finish.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="flex flex-col">
                    <label class="kt-form-label" for="restore-confirm">
                        Type <code class="text-xs px-1 py-0.5 rounded bg-muted">{{ $b['name'] }}</code> to confirm
                    </label>
                    <input id="restore-confirm" type="text" class="kt-input" autocomplete="off"
                           placeholder="{{ $b['name'] }}" wire:model.live.debounce.300ms="confirmName">
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
</div>
