<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * GitHub repositories.
 *
 * Backend phase pulls these from the GitHub REST API with a personal access
 * token and caches them locally, so the page never blocks on a network call.
 */
new
#[Title('GitHub Repos — Kargah')]
class extends Component
{
    public string $search = '';

    public string $sort = 'pushed';

    public bool $connected = false;

    public function with(): array
    {
        return [
            'sorts' => ['pushed' => 'Recently pushed', 'created' => 'Newest', 'stars' => 'Most starred', 'name' => 'Name'],
            'repos' => $this->connected ? [] : [
                ['name' => 'kargah',     'desc' => 'Freelance workspace: boards, mail, accounting, data.', 'lang' => 'PHP',        'stars' => 0, 'forks' => 0, 'private' => false, 'pushed' => 'today'],
                ['name' => 'moonwalker', 'desc' => 'Floating panel tooling.',                              'lang' => 'TypeScript', 'stars' => 0, 'forks' => 0, 'private' => false, 'pushed' => '2 weeks ago'],
            ],
            'langColor' => [
                'PHP' => 'bg-indigo-500', 'TypeScript' => 'bg-blue-500',
                'JavaScript' => 'bg-yellow-400', 'Python' => 'bg-green-500',
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">GitHub repositories</h1>
            <p class="text-sm text-secondary-foreground mt-1">Your projects, mirrored here so Data holds everything.</p>
        </div>
        <button class="kt-btn {{ $connected ? 'kt-btn-outline' : 'kt-btn-primary' }} gap-2">
            <i class="ki-filled ki-github"></i>
            {{ $connected ? 'Resync' : 'Connect GitHub' }}
        </button>
    </div>

    @unless ($connected)
        <div class="kt-card bg-info/5 border-info/30">
            <div class="kt-card-content flex items-start gap-3 p-4">
                <i class="ki-filled ki-information-2 text-info text-lg mt-0.5 shrink-0"></i>
                <div class="text-sm text-secondary-foreground">
                    <strong class="text-mono">Not connected yet.</strong>
                    Add a GitHub personal access token with <code class="text-xs px-1 py-0.5 rounded bg-muted">repo</code> scope
                    to pull your repositories. Showing sample rows until then.
                </div>
            </div>
        </div>
    @endunless

    <div class="flex flex-wrap items-center gap-2">
        <div class="kt-input max-w-[260px]">
            <i class="ki-filled ki-magnifier text-muted-foreground"></i>
            <input type="text" placeholder="Find a repository…" wire:model.live.debounce.300ms="search">
        </div>
        <select class="kt-select max-w-[190px]" wire:model.live="sort">
            @foreach ($sorts as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        @foreach ($repos as $r)
            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <a href="#" class="text-base font-semibold text-primary hover:underline truncate">{{ $r['name'] }}</a>
                        <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">{{ $r['private'] ? 'Private' : 'Public' }}</span>
                    </div>

                    <p class="text-sm text-secondary-foreground line-clamp-2">{{ $r['desc'] }}</p>

                    <div class="flex items-center gap-4 text-xs text-muted-foreground pt-2">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2.5 rounded-full {{ $langColor[$r['lang']] ?? 'bg-muted-foreground' }}"></span>
                            {{ $r['lang'] }}
                        </span>
                        <span class="inline-flex items-center gap-1"><i class="ki-filled ki-star text-sm"></i>{{ $r['stars'] }}</span>
                        <span class="inline-flex items-center gap-1"><i class="ki-filled ki-arrow-two-diagonals text-sm"></i>{{ $r['forks'] }}</span>
                        <span class="ms-auto">Updated {{ $r['pushed'] }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
