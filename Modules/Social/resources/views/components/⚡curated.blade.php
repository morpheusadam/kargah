<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Social\Models\CuratedStory;
use Modules\Social\Support\Networks;

/**
 * What the curator chose each day, and what became of it.
 *
 * **This page exists because of a consequence of the design rather than as a
 * feature in its own right.** One story a day becomes one post per network, each
 * scheduled at a different hour, because the good hour for LinkedIn and the good
 * hour for Instagram in Iran are at opposite ends of the day. On `/social/posts`
 * that arrives as four unrelated rows with nothing saying they were one decision.
 * Here the story is the row and the networks are underneath it.
 *
 * The refused stories are shown, not hidden behind a filter. "Why was there no
 * post yesterday" is the question this page is opened with, and the answer is
 * usually that everything on offer was off-topic — which is a working pipeline,
 * not a broken one, and reads as such only if the refusals are visible.
 */
new
#[Title('Curated — Kargah')]
class extends Component
{
    /** Which stories to show. Survives a refresh, so a filtered view is shareable. */
    #[Url]
    public string $tab = 'published';

    public function with(): array
    {
        $stories = CuratedStory::query()
            ->when($this->tab === 'published', fn ($q) => $q->where('was_skipped', false))
            ->when($this->tab === 'refused', fn ($q) => $q->where('was_skipped', true))
            ->with(['posts.targets.account'])
            ->orderByDesc('chosen_on')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        return [
            'stories' => $stories,
            'catalogue' => Networks::all(),
            'counts' => [
                'published' => CuratedStory::query()->where('was_skipped', false)->count(),
                'refused' => CuratedStory::query()->where('was_skipped', true)->count(),
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Curated</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                The story chosen each day, and the post each network received.
            </p>
        </div>
        <a href="{{ route('social.curation-settings') }}" class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-setting-2"></i> Curation settings
        </a>
    </div>

    <div class="flex items-center gap-2">
        @foreach (['published' => 'Published', 'refused' => 'Refused', 'all' => 'Everything'] as $key => $label)
            <button type="button"
                    class="kt-btn kt-btn-sm {{ $tab === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}"
                    wire:click="$set('tab', '{{ $key }}')">
                {{ $label }}
                @isset ($counts[$key])
                    <span class="kt-badge kt-badge-sm kt-badge-outline ms-1">{{ $counts[$key] }}</span>
                @endisset
            </button>
        @endforeach
    </div>

    <div class="flex flex-col gap-4">
        @forelse ($stories as $story)
            <div class="kt-card" wire:key="story-{{ $story->id }}">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ $story->url }}" target="_blank" rel="noopener"
                               class="block text-sm font-medium text-mono hover:text-primary">
                                {{ $story->title }}
                                <i class="ki-filled ki-arrow-up-right text-xs"></i>
                            </a>
                            <span class="block text-xs text-muted-foreground mt-1">
                                {{ $story->publisher ?? $story->source_label }}
                                · {{ $story->chosen_on?->format('j M Y') }}
                                · {{ $story->sources_count }} {{ str('outlet')->plural($story->sources_count) }}
                            </span>
                        </div>

                        @if ($story->was_skipped)
                            <span class="kt-badge kt-badge-sm kt-badge-warning">Refused</span>
                        @else
                            <span class="kt-badge kt-badge-sm kt-badge-outline">
                                score {{ number_format($story->score, 4) }}
                            </span>
                        @endif
                    </div>

                    @if ($story->was_skipped)
                        <p class="text-sm text-secondary-foreground">
                            {{ $story->skip_reason ?? 'The model judged it outside the channel’s subjects.' }}
                        </p>
                    @else
                        <div class="flex flex-col gap-2">
                            @foreach ($story->posts as $post)
                                @php
                                    $network = $post->pivot->network;
                                    $meta = $catalogue[$network] ?? null;
                                    $target = $post->targets->first();
                                @endphp
                                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-border px-3 py-2">
                                    <span class="flex items-center gap-2 text-sm text-mono min-w-[140px]">
                                        <i class="ki-filled {{ $meta['icon'] ?? 'ki-abstract-26' }} {{ $meta['tone'] ?? '' }}"></i>
                                        {{ $meta['label'] ?? $network }}
                                    </span>

                                    <span class="text-xs text-muted-foreground">
                                        <i class="ki-filled ki-time"></i>
                                        {{ $post->scheduled_for?->format('H:i') ?? '—' }} UTC
                                    </span>

                                    <span class="text-xs text-muted-foreground">
                                        {{ mb_strlen($post->body) }} chars
                                    </span>

                                    @if ($target?->remote_url)
                                        <a href="{{ $target->remote_url }}" target="_blank" rel="noopener"
                                           class="text-xs text-primary hover:underline">
                                            View post <i class="ki-filled ki-arrow-up-right text-[10px]"></i>
                                        </a>
                                    @endif

                                    <span class="ms-auto">
                                        @php
                                            $tone = match ($post->status) {
                                                'published' => 'kt-badge-success',
                                                'failed' => 'kt-badge-destructive',
                                                'partly_failed' => 'kt-badge-warning',
                                                default => 'kt-badge-outline',
                                            };
                                        @endphp
                                        <a href="{{ route('social.post-show', $post) }}"
                                           class="kt-badge kt-badge-sm {{ $tone }}">
                                            {{ str_replace('_', ' ', $post->status) }}
                                        </a>
                                    </span>
                                </div>

                                @if ($target?->error)
                                    <p class="text-xs text-destructive ps-3">{{ $target->error }}</p>
                                @endif
                            @endforeach
                        </div>
                    @endif

                </div>
            </div>
        @empty
            <div class="kt-card">
                <div class="kt-card-content">
                    <div class="flex flex-col items-center text-center gap-3 py-14">
                        <i class="ki-filled ki-book text-3xl text-muted-foreground"></i>
                        <p class="text-sm text-secondary-foreground max-w-md">
                            Nothing has been curated yet. The daily run chooses one story, writes it for each
                            connected network, and schedules each at the hour that network is read.
                        </p>
                        <a href="{{ route('social.curation-settings') }}" class="kt-btn kt-btn-sm kt-btn-primary">
                            Curation settings
                        </a>
                    </div>
                </div>
            </div>
        @endforelse
    </div>

</div>
