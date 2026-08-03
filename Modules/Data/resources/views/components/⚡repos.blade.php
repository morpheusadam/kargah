<?php

use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Models\Repository;

/**
 * GitHub repositories, read from the local mirror.
 *
 * The page never calls GitHub. `data:sync-repos` runs from the scheduler and
 * fills this table; a render that waited on someone else's API would be down
 * whenever they were. The resync button runs the same command by hand, which is
 * the only reason a request here ever leaves the server.
 */
new
#[Title('GitHub Repos — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $search = '';

    #[Url]
    public string $sort = 'pushed';

    public function with(): array
    {
        return [
            'sorts' => ['pushed' => 'Recently pushed', 'created' => 'Newest', 'stars' => 'Most starred', 'name' => 'Name'],
            'repos' => Repository::query()->search($this->search)->sorted($this->sort)->get(),
            'connected' => $this->hasToken(),
            'lastSynced' => Repository::query()->max('synced_at'),
            // Whole class strings in a map. Never `bg-{$colour}-500`: the
            // Tailwind scanner reads this file as text and cannot see a class
            // that is assembled at run time.
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

    private function hasToken(): bool
    {
        $token = config('data.github.token');

        return is_string($token) && trim($token) !== '';
    }

    /**
     * Pull the mirror up to date.
     *
     * Runs the scheduled command rather than duplicating its logic, so the
     * button and the cron entry can never drift apart in what they do.
     */
    public function resync(): void
    {
        if (! $this->hasToken()) {
            $this->toastWarning(
                'No GitHub token is configured',
                'Add GITHUB_TOKEN to .env with `repo` scope and this button will pull your repositories.'
            );

            return;
        }

        $before = Repository::query()->count();
        $exit = Artisan::call('data:sync-repos');
        $after = Repository::query()->count();

        if ($exit !== 0) {
            $this->toastError('The sync did not finish', trim(Artisan::output()) ?: 'GitHub refused the request.');

            return;
        }

        $this->toastSuccess(
            'Mirror is up to date',
            $after === $before
                ? $after.' '.str('repository')->plural($after).', nothing new since the last run.'
                : ($after - $before).' new '.str('repository')->plural($after - $before).', '.$after.' in total.'
        );
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">GitHub repositories</h1>
            <p class="text-sm text-secondary-foreground mt-1">Your projects, mirrored here so Data holds everything.</p>
        </div>
        <button wire:click="resync" wire:loading.attr="disabled" wire:target="resync"
                class="kt-btn {{ $connected ? 'kt-btn-outline' : 'kt-btn-primary' }} gap-2">
            <span wire:loading.remove wire:target="resync" class="inline-flex items-center gap-2">
                <i class="ki-filled ki-github"></i> Resync
            </span>
            <span wire:loading wire:target="resync" class="inline-flex items-center gap-2">
                <i class="ki-filled ki-loading animate-spin"></i> Syncing…
            </span>
        </button>
    </div>

    @unless ($connected)
        <div class="kt-card bg-info/5 border-info/30">
            <div class="kt-card-content flex items-start gap-3 p-4">
                <i class="ki-filled ki-information-2 text-info text-lg mt-0.5 shrink-0"></i>
                <div class="text-sm text-secondary-foreground">
                    <strong class="text-mono">No GitHub token is configured.</strong>
                    Add <code class="text-xs px-1 py-0.5 rounded bg-muted">GITHUB_TOKEN</code> with
                    <code class="text-xs px-1 py-0.5 rounded bg-muted">repo</code> scope to <code class="text-xs px-1 py-0.5 rounded bg-muted">.env</code>
                    and the nightly sync will fill this page. Everything below is whatever is already in the mirror.
                </div>
            </div>
        </div>
    @endunless

    <div class="flex flex-wrap items-center gap-2">
        <div class="kt-input max-w-[260px]">
            <i class="ki-filled ki-magnifier text-muted-foreground"></i>
            <input type="text" placeholder="Find a repository…" aria-label="Find a repository"
                   wire:model.live.debounce.300ms="search">
        </div>
        <select class="kt-select max-w-[190px]" wire:model.live="sort" aria-label="Sort repositories">
            @foreach ($sorts as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        @if ($lastSynced)
            <span class="text-xs text-muted-foreground ms-auto">Mirror last written {{ \Illuminate\Support\Carbon::parse($lastSynced)->diffForHumans() }}</span>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        @forelse ($repos as $r)
            <div class="kt-card" wire:key="repo-{{ $r->id }}">
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('data.repo-show', ['repo' => $r->id]) }}" wire:navigate
                           class="text-base font-semibold text-primary hover:underline truncate">{{ $r->shortName() }}</a>
                        <div class="flex items-center gap-1.5 shrink-0">
                            @if ($r->is_archived)
                                <span class="kt-badge kt-badge-sm kt-badge-warning">Archived</span>
                            @endif
                            <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $r->is_private ? 'Private' : 'Public' }}</span>
                        </div>
                    </div>

                    <p class="text-sm text-secondary-foreground line-clamp-2">{{ $r->description ?? '—' }}</p>

                    <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground pt-2">
                        @if ($r->language)
                            <span class="inline-flex items-center gap-1.5">
                                <span class="size-2.5 rounded-full {{ $langColour[$r->language] ?? 'bg-muted-foreground' }}"></span>
                                {{ $r->language }}
                            </span>
                        @endif
                        <span class="inline-flex items-center gap-1"><i class="ki-filled ki-star text-sm"></i>{{ $r->stars }}</span>
                        <span class="inline-flex items-center gap-1"><i class="ki-filled ki-arrow-two-diagonals text-sm"></i>{{ $r->forks }}</span>
                        <span class="ms-auto">{{ $r->pushed_at ? 'Pushed '.$r->pushed_at->diffForHumans() : 'Never pushed' }}</span>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full kt-card">
                <div class="kt-card-content flex flex-col items-center py-14 text-center gap-3">
                    <i class="ki-filled ki-github text-4xl text-muted-foreground"></i>
                    <p class="text-sm text-secondary-foreground">
                        {{ $search !== '' ? 'No repository matches that search.' : 'The mirror is empty.' }}
                    </p>
                    @if ($search === '')
                        <button wire:click="resync" wire:loading.attr="disabled" wire:target="resync" class="kt-btn kt-btn-primary gap-2">
                            <span wire:loading.remove wire:target="resync" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-github"></i> Pull from GitHub
                            </span>
                            <span wire:loading wire:target="resync" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Syncing…
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        @endforelse
    </div>
</div>
