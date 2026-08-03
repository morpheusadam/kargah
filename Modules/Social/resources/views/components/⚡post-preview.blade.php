<?php

use Livewire\Attributes\Reactive;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * One network's rendering of the draft.
 *
 * The composer owns the text; this component only decides how that text looks
 * once the network has had its way with it. Truncation is shown in place —
 * everything past the limit is struck through so you can see exactly which
 * sentence gets lost rather than reading a red number and guessing.
 */
new class extends Component
{
    use InteractsWithToasts;

    #[Reactive]
    public array $network = [];

    #[Reactive]
    public string $body = '';

    /** @var array<int, array{name: string, thumb: string, kind: string, size: string}> */
    #[Reactive]
    public array $media = [];

    #[Reactive]
    public bool $overridden = false;

    public function with(): array
    {
        $limit = (int) ($this->network['limit'] ?? 4096);
        $text = trim($this->body) === '' ? '' : $this->body;
        $length = mb_strlen($text);

        return [
            'limit' => $limit,
            'length' => $length,
            'kept' => mb_substr($text, 0, $limit),
            'cut' => $length > $limit ? mb_substr($text, $limit) : '',
            'over' => max(0, $length - $limit),
            'parts' => $limit > 0 ? (int) ceil(max($length, 1) / $limit) : 1,
            'author' => [
                'name' => 'Adam Morpheus',
                'avatar' => '/assets/media/avatars/300-1.png',
                'headline' => 'Freelance Laravel developer · building Kargah in the open',
                'handle' => '@morpheusadam',
                'channel' => 'Kargah build log',
            ],
        ];
    }
};

?>

<div class="kt-card overflow-hidden" wire:key="preview-{{ $network['key'] ?? 'none' }}">

    <div class="kt-card-header py-3">
        <div class="flex items-center gap-2 min-w-0">
            <i class="ki-filled {{ $network['icon'] ?? 'ki-abstract-26' }} text-base text-muted-foreground"></i>
            <h3 class="kt-card-title text-sm">{{ $network['label'] ?? 'Preview' }}</h3>
            @if ($overridden)
                <span class="kt-badge kt-badge-sm kt-badge-outline" title="This network uses its own text">Custom text</span>
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($over > 0)
                <span class="kt-badge kt-badge-sm kt-badge-destructive">{{ $over }} over</span>
            @else
                <span class="text-xs text-muted-foreground">{{ $length }} / {{ $limit }}</span>
            @endif
        </div>
    </div>

    <div class="kt-card-content p-4">

        @if ($length === 0)
            <div class="flex flex-col items-center py-8 text-center">
                <i class="ki-filled ki-notepad-edit text-2xl text-muted-foreground mb-2"></i>
                <p class="text-xs text-secondary-foreground">Start writing to see the {{ $network['label'] ?? 'network' }} preview.</p>
            </div>
        @else

            @switch($network['key'] ?? '')

                {{-- Telegram: a channel message bubble, sender name then body, time bottom-right --}}
                @case('telegram')
                    <div class="rounded-xl bg-accent/40 p-3">
                        <div class="max-w-[92%] rounded-2xl rounded-bl-sm bg-info/10 border border-info/20 px-3.5 py-2.5">
                            <div class="text-xs font-semibold text-info mb-1">{{ $author['channel'] }}</div>
                            <div class="text-sm leading-relaxed text-mono">
                                <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                            </div>
                            @if ($media)
                                <div class="grid grid-cols-2 gap-1.5 mt-2.5">
                                    @foreach (array_slice($media, 0, 4) as $m)
                                        <img src="{{ $m['thumb'] }}" alt="{{ $m['name'] }}" class="w-full h-20 object-cover rounded-lg">
                                    @endforeach
                                </div>
                            @endif
                            <div class="text-[10px] text-muted-foreground text-end mt-1.5">09:24 <i class="ki-filled ki-check text-info"></i></div>
                        </div>
                    </div>
                    @break

                {{-- LinkedIn: post card with author block, body, then the reaction bar --}}
                @case('linkedin')
                    <div class="rounded-xl border border-border">
                        <div class="flex items-center gap-2.5 p-3">
                            <img src="{{ $author['avatar'] }}" alt="" class="size-10 rounded-full object-cover shrink-0">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-mono truncate">{{ $author['name'] }}</div>
                                <div class="text-xs text-muted-foreground truncate">{{ $author['headline'] }}</div>
                                <div class="text-[11px] text-muted-foreground">Now · <i class="ki-filled ki-users text-[10px]"></i></div>
                            </div>
                        </div>
                        <div class="px-3 pb-3 text-sm leading-relaxed text-mono">
                            <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                        </div>
                        @if ($media)
                            <img src="{{ $media[0]['thumb'] }}" alt="{{ $media[0]['name'] }}" class="w-full h-48 object-cover">
                        @endif
                        <div class="flex items-center justify-around border-t border-border px-2 py-1.5 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-like-shapes"></i> Like</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-messages"></i> Comment</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-arrow-two-diagonals"></i> Repost</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-paper-plane"></i> Send</span>
                        </div>
                    </div>
                    @break

                {{-- X: tight single post, handle inline, action row underneath --}}
                @case('x')
                    <div class="rounded-xl border border-border p-3">
                        <div class="flex gap-2.5">
                            <img src="{{ $author['avatar'] }}" alt="" class="size-10 rounded-full object-cover shrink-0">
                            <div class="min-w-0 grow">
                                <div class="flex items-center gap-1.5 text-sm">
                                    <span class="font-semibold text-mono truncate">{{ $author['name'] }}</span>
                                    <span class="text-muted-foreground truncate">{{ $author['handle'] }} · now</span>
                                </div>
                                <div class="text-sm leading-relaxed text-mono mt-0.5">
                                    <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                                </div>
                                @if ($media)
                                    <img src="{{ $media[0]['thumb'] }}" alt="{{ $media[0]['name'] }}" class="w-full h-40 object-cover rounded-xl border border-border mt-2.5">
                                @endif
                                <div class="flex items-center justify-between max-w-[280px] mt-2.5 text-xs text-muted-foreground">
                                    <span><i class="ki-filled ki-messages"></i></span>
                                    <span><i class="ki-filled ki-arrows-circle"></i></span>
                                    <span><i class="ki-filled ki-heart"></i></span>
                                    <span><i class="ki-filled ki-chart-simple"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    @break

                {{-- Instagram: media first, caption underneath with the username inline --}}
                @case('instagram')
                    <div class="rounded-xl border border-border overflow-hidden">
                        <div class="flex items-center gap-2 p-2.5">
                            <img src="{{ $author['avatar'] }}" alt="" class="size-8 rounded-full object-cover shrink-0">
                            <span class="text-sm font-semibold text-mono">{{ ltrim($author['handle'], '@') }}</span>
                        </div>
                        @if ($media)
                            <img src="{{ $media[0]['thumb'] }}" alt="{{ $media[0]['name'] }}" class="w-full aspect-square object-cover">
                        @else
                            <div class="w-full aspect-square bg-muted flex flex-col items-center justify-center gap-2">
                                <i class="ki-filled ki-picture text-3xl text-muted-foreground"></i>
                                <span class="text-xs text-muted-foreground">Instagram needs an image</span>
                            </div>
                        @endif
                        <div class="flex items-center gap-3 px-2.5 pt-2.5 text-base text-mono">
                            <i class="ki-filled ki-heart"></i>
                            <i class="ki-filled ki-messages"></i>
                            <i class="ki-filled ki-paper-plane"></i>
                        </div>
                        <div class="px-2.5 pb-3 pt-2 text-sm leading-relaxed text-mono">
                            <span class="font-semibold">{{ ltrim($author['handle'], '@') }}</span>
                            <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                        </div>
                    </div>
                    @break

                @default
                    <div class="text-sm text-secondary-foreground whitespace-pre-wrap">{{ $kept }}</div>
            @endswitch

            @if ($over > 0)
                <div class="flex items-start gap-2 mt-3 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2">
                    <i class="ki-filled ki-information-2 text-destructive text-sm mt-0.5 shrink-0"></i>
                    <p class="text-xs text-secondary-foreground">
                        {{ $over }} {{ $over === 1 ? 'character goes' : 'characters go' }} past the
                        {{ number_format($limit) }}-character {{ $network['label'] }} limit and will be cut.
                        @if (($network['key'] ?? '') === 'x')
                            Splitting it into {{ $parts }} posts would keep the whole thing.
                        @else
                            Shorten it here or give {{ $network['label'] }} its own text.
                        @endif
                    </p>
                </div>
            @endif

            @if ($media && ($network['key'] ?? '') !== 'telegram' && count($media) > 1)
                <p class="text-xs text-muted-foreground mt-2">
                    {{ $network['label'] }} shows the first attachment only; {{ count($media) - 1 }} more will be dropped.
                </p>
            @endif

        @endif

    </div>
</div>
