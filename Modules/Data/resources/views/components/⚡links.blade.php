<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Links & bots.
 *
 * A single place for every URL that matters: Telegram bots, deployed projects,
 * dashboards, panels. Grouped by kind so you find things by what they are, not
 * by when you saved them.
 */
new
#[Title('Links & Bots — Kargah')]
class extends Component
{
    // Held ready. The only interactive controls are filter buttons driven by
    // $set and a live search box, and both show their result in the grid, so
    // there is no action here worth a toast.
    use InteractsWithToasts;

    #[Url]
    public string $kind = 'all';

    public string $search = '';

    public function with(): array
    {
        return [
            'kinds' => [
                'all'      => ['label' => 'All',       'icon' => 'ki-element-11'],
                'bot'      => ['label' => 'Telegram bots', 'icon' => 'ki-paper-plane'],
                'project'  => ['label' => 'Projects',  'icon' => 'ki-rocket'],
                'panel'    => ['label' => 'Panels',    'icon' => 'ki-setting-2'],
                'resource' => ['label' => 'Resources', 'icon' => 'ki-book'],
            ],
            'links' => [
                ['title' => 'Kargah deploy',      'url' => 'https://kargah.dev',            'kind' => 'project', 'note' => 'Production',        'tags' => ['laravel']],
                ['title' => 'Hostinger hPanel',   'url' => 'https://hpanel.hostinger.com',  'kind' => 'panel',   'note' => 'Shared hosting',    'tags' => ['hosting']],
                ['title' => 'Moonwalker',         'url' => 'https://github.com/morpheusadam/moonwalker', 'kind' => 'project', 'note' => 'Repo', 'tags' => ['tool']],
            ],
            'badge' => [
                'bot' => 'kt-badge-info', 'project' => 'kt-badge-primary',
                'panel' => 'kt-badge-warning', 'resource' => 'kt-badge-outline',
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Links &amp; Bots</h1>
            <p class="text-sm text-secondary-foreground mt-1">Every URL you would otherwise lose in a chat history.</p>
        </div>
        <button class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Add link
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @foreach ($kinds as $key => $k)
            <button wire:click="$set('kind', '{{ $key }}')"
                    class="kt-btn kt-btn-sm gap-2 {{ $kind === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <i class="ki-filled {{ $k['icon'] }} text-sm"></i> {{ $k['label'] }}
            </button>
        @endforeach
        <div class="kt-input max-w-[240px] ms-auto">
            <i class="ki-filled ki-magnifier text-muted-foreground"></i>
            <input type="text" placeholder="Search links…" wire:model.live.debounce.300ms="search">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        @forelse ($links as $l)
            <div class="kt-card group">
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled {{ $kinds[$l['kind']]['icon'] }} text-lg"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-mono truncate">{{ $l['title'] }}</div>
                                <div class="text-xs text-muted-foreground">{{ $l['note'] }}</div>
                            </div>
                        </div>
                        <span class="kt-badge kt-badge-sm {{ $badge[$l['kind']] }} shrink-0">{{ $kinds[$l['kind']]['label'] }}</span>
                    </div>

                    <a href="{{ $l['url'] }}" target="_blank" rel="noopener"
                       class="text-sm text-primary hover:underline truncate">{{ $l['url'] }}</a>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($l['tags'] as $t)
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground">{{ $t }}</span>
                            @endforeach
                        </div>
                        <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Copy"><i class="ki-filled ki-copy text-sm"></i></button>
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Edit"><i class="ki-filled ki-pencil text-sm"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full kt-card">
                <div class="kt-card-content flex flex-col items-center py-14 text-center">
                    <i class="ki-filled ki-arrow-up-right text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">No links saved yet.</p>
                </div>
            </div>
        @endforelse
    </div>
</div>
