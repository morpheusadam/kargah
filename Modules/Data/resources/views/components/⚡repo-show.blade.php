<?php

use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Contracts\AttachmentService;
use Modules\Data\Models\Repository;

/**
 * One repository, read from the local mirror.
 *
 * Everything on this page is a column in `repositories` or a row in
 * `attachments`. There is no README, no commit list and no deployment feed,
 * because none of those is stored — showing them would mean either calling
 * GitHub during a render or drawing a fixture, and the point of the mirror is
 * that neither happens.
 *
 * A repository that is not in the mirror renders an empty state rather than a
 * 404: the smoke test walks this route on an empty database, and a page that
 * says "not synced yet" is more use than a stack trace either way.
 */
new
#[Title('Repository — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Route parameter — the repository id. */
    public string $repo = '';

    public function mount(string $repo = ''): void
    {
        $this->repo = $repo;
    }

    public function with(): array
    {
        $repository = Repository::query()->find($this->repo);

        return [
            'r' => $repository,
            'files' => $repository === null
                ? collect()
                : app(AttachmentService::class)->forTarget($repository),
            'langColour' => [
                'PHP' => 'bg-indigo-500',
                'TypeScript' => 'bg-blue-500',
                'JavaScript' => 'bg-yellow-400',
                'Python' => 'bg-green-500',
                'Go' => 'bg-cyan-500',
                'Rust' => 'bg-orange-500',
            ],
        ];
    }

    /** Pull the whole mirror up to date, this repository with it. */
    public function resync(): void
    {
        $token = config('data.github.token');

        if (! is_string($token) || trim($token) === '') {
            $this->toastWarning(
                'No GitHub token is configured',
                'Add GITHUB_TOKEN to .env with `repo` scope and this button will refresh the mirror.'
            );

            return;
        }

        $exit = Artisan::call('data:sync-repos');

        $exit === 0
            ? $this->toastSuccess('Mirror refreshed', trim(Artisan::output()))
            : $this->toastError('The sync did not finish', trim(Artisan::output()) ?: 'GitHub refused the request.');
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($r === null)
        <div>
            <a href="{{ route('data.repos') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                <i class="ki-filled ki-left text-xs"></i> Repositories
            </a>
            <h1 class="text-xl font-semibold text-mono mt-1">Repository</h1>
            <p class="text-sm text-secondary-foreground mt-1">Nothing in the mirror under that id.</p>
        </div>

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center gap-3">
                <i class="ki-filled ki-github text-4xl text-muted-foreground"></i>
                <p class="text-sm text-secondary-foreground">
                    This repository has not been synced. Run the sync and it will appear with everything else.
                </p>
                <button wire:click="resync" wire:loading.attr="disabled" wire:target="resync" class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="resync" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-github"></i> Pull from GitHub
                    </span>
                    <span wire:loading wire:target="resync" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Syncing…
                    </span>
                </button>
            </div>
        </div>
    @else
        {{-- Header --}}
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('data.repos') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                    <i class="ki-filled ki-left text-xs"></i> Repositories
                </a>
                <div class="flex flex-wrap items-center gap-2.5 mt-1">
                    <h1 class="text-xl font-semibold text-mono">{{ $r->shortName() }}</h1>
                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $r->is_private ? 'Private' : 'Public' }}</span>
                    @if ($r->is_archived)
                        <span class="kt-badge kt-badge-sm kt-badge-warning">Archived</span>
                    @endif
                    @if ($r->default_branch)
                        <span class="kt-badge kt-badge-sm kt-badge-outline gap-1.5">
                            <i class="ki-filled ki-tree text-[11px]"></i> {{ $r->default_branch }}
                        </span>
                    @endif
                </div>
                <p class="text-sm text-secondary-foreground mt-1">{{ $r->description ?? '—' }}</p>
                <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground mt-2">
                    @if ($r->language)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2.5 rounded-full {{ $langColour[$r->language] ?? 'bg-muted-foreground' }}"></span> {{ $r->language }}
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1"><i class="ki-filled ki-star text-sm"></i>{{ $r->stars }} stars</span>
                    <span class="inline-flex items-center gap-1"><i class="ki-filled ki-arrow-two-diagonals text-sm"></i>{{ $r->forks }} forks</span>
                    <span>{{ $r->pushed_at ? 'Last push '.$r->pushed_at->diffForHumans() : 'Never pushed' }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button wire:click="resync" wire:loading.attr="disabled" wire:target="resync" class="kt-btn kt-btn-outline gap-2">
                    <span wire:loading.remove wire:target="resync" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-arrows-circle"></i> Resync
                    </span>
                    <span wire:loading wire:target="resync" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Syncing…
                    </span>
                </button>
                <a href="{{ $r->html_url ?? 'https://github.com/'.$r->full_name }}" target="_blank" rel="noopener" class="kt-btn kt-btn-primary gap-2">
                    <i class="ki-filled ki-github"></i> Open on GitHub
                </a>
            </div>
        </div>

        {{-- Counts --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
            <div class="kt-card">
                <div class="kt-card-content p-4">
                    <div class="text-xs text-muted-foreground">Open issues</div>
                    <div class="text-2xl font-semibold text-mono mt-1">{{ $r->open_issues }}</div>
                </div>
            </div>
            <div class="kt-card">
                <div class="kt-card-content p-4">
                    <div class="text-xs text-muted-foreground">Stars</div>
                    <div class="text-2xl font-semibold text-mono mt-1">{{ $r->stars }}</div>
                </div>
            </div>
            <div class="kt-card">
                <div class="kt-card-content p-4">
                    <div class="text-xs text-muted-foreground">Forks</div>
                    <div class="text-2xl font-semibold text-mono mt-1">{{ $r->forks }}</div>
                </div>
            </div>
            <div class="kt-card">
                <div class="kt-card-content p-4">
                    <div class="text-xs text-muted-foreground">Files attached</div>
                    <div class="text-2xl font-semibold text-mono mt-1">{{ $files->count() }}</div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

            <div class="xl:col-span-2 flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Attached files</h3>
                        <a href="{{ route('data.files') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-outline gap-1.5">
                            <i class="ki-filled ki-document text-sm"></i> Files
                        </a>
                    </div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[260px]">Name</th>
                                        <th class="w-[110px] text-end">Size</th>
                                        <th class="w-[140px]">Added</th>
                                        <th class="w-[90px] text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($files as $file)
                                        <tr wire:key="repo-file-{{ $file['id'] }}">
                                            <td class="font-medium text-mono break-all">{{ $file['name'] }}</td>
                                            <td class="text-end text-secondary-foreground">{{ $file['size'] }}</td>
                                            <td class="text-secondary-foreground">{{ $file['uploaded_at'] }}</td>
                                            <td class="text-end">
                                                <a href="{{ $file['download_url'] }}" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Download" aria-label="Download">
                                                    <i class="ki-filled ki-exit-down text-sm"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="flex flex-col items-center py-12 text-center gap-2">
                                                    <i class="ki-filled ki-document text-4xl text-muted-foreground"></i>
                                                    <p class="text-sm text-secondary-foreground">
                                                        Nothing attached to this repository yet. Files are attached from the files page.
                                                    </p>
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
                    <div class="kt-card-header"><h3 class="kt-card-title">Details</h3></div>
                    <div class="kt-card-content p-5">
                        <dl class="flex flex-col gap-2.5 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Provider</dt>
                                <dd class="text-secondary-foreground text-end">{{ $r->provider }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Owner</dt>
                                <dd class="text-secondary-foreground text-end">{{ $r->owner() ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Default branch</dt>
                                <dd class="text-secondary-foreground text-end">{{ $r->default_branch ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Last push</dt>
                                <dd class="text-secondary-foreground text-end">{{ $r->pushed_at?->toDateTimeString() ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground shrink-0">Mirror written</dt>
                                <dd class="text-secondary-foreground text-end">{{ $r->synced_at?->diffForHumans() ?? 'never' }}</dd>
                            </div>
                        </dl>
                        <p class="text-xs text-muted-foreground mt-4">
                            The mirror is written only when something actually changed, so this date is the last
                            time GitHub had news, not the last time it was asked.
                        </p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Clone</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-2">
                        <div class="kt-input">
                            <input type="text" readonly value="{{ $r->cloneUrl() }}" aria-label="Clone URL">
                            <button data-copy-text="{{ $r->cloneUrl() }}" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                    title="Copy clone URL" aria-label="Copy clone URL">
                                <i class="ki-filled ki-copy text-sm"></i>
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            SSH, because a password prompt in a deploy script is a deploy script that hangs.
                        </p>
                    </div>
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
