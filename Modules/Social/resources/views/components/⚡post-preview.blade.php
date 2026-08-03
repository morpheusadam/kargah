<?php

use Livewire\Attributes\Reactive;
use Livewire\Component;
use Modules\Social\Support\Networks;

/**
 * One network's rendering of the draft.
 *
 * The composer owns the text; this component only decides how that text looks
 * once the network has had its way with it. Truncation is shown in place —
 * everything past the limit is struck through — so you can see exactly which
 * sentence gets lost rather than reading a red number and guessing.
 *
 * Every property is `#[Reactive]` because the parent is the source of truth for
 * all of them. Nothing here is editable and nothing here is persisted.
 */
new class extends Component
{
    #[Reactive]
    public string $networkKey = '';

    #[Reactive]
    public string $handle = '';

    #[Reactive]
    public string $body = '';

    #[Reactive]
    public bool $overridden = false;

    public function with(): array
    {
        $limit = Networks::limit($this->networkKey);
        $length = mb_strlen($this->body);

        return [
            'label' => Networks::label($this->networkKey),
            'icon' => Networks::icon($this->networkKey),
            'limit' => $limit,
            'length' => $length,
            'kept' => mb_substr($this->body, 0, $limit),
            'cut' => $length > $limit ? mb_substr($this->body, $limit) : '',
            'over' => max(0, $length - $limit),
            // How many posts the text would need if it were split rather than
            // cut. Only Bluesky and Mastodon make that a normal thing to do.
            'parts' => $limit > 0 ? (int) ceil(max($length, 1) / $limit) : 1,
        ];
    }
};

?>

<div class="kt-card overflow-hidden">

    <div class="kt-card-header py-3">
        <div class="flex items-center gap-2 min-w-0">
            <i class="ki-filled {{ $icon }} text-base text-muted-foreground"></i>
            <h3 class="kt-card-title text-sm">{{ $label }}</h3>
            @if ($overridden)
                <span class="kt-badge kt-badge-sm kt-badge-outline" title="This network uses its own text">Custom text</span>
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($over > 0)
                <span class="kt-badge kt-badge-sm kt-badge-destructive">{{ $over }} over</span>
            @else
                <span class="text-xs text-muted-foreground">{{ $length }} / {{ number_format($limit) }}</span>
            @endif
        </div>
    </div>

    <div class="kt-card-content p-4">

        @if ($length === 0)
            <div class="flex flex-col items-center py-8 text-center">
                <i class="ki-filled ki-notepad-edit text-2xl text-muted-foreground mb-2"></i>
                <p class="text-xs text-secondary-foreground">Start writing to see the {{ $label }} preview.</p>
            </div>
        @else

            @switch($networkKey)

                {{-- Telegram: a channel message bubble, sender name then body, time bottom-right --}}
                @case('telegram')
                    <div class="rounded-xl bg-accent/40 p-3">
                        <div class="max-w-[92%] rounded-2xl rounded-bl-sm bg-info/10 border border-info/20 px-3.5 py-2.5">
                            <div class="text-xs font-semibold text-info mb-1">{{ $handle }}</div>
                            <div class="text-sm leading-relaxed text-mono">
                                <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                            </div>
                            <div class="text-[10px] text-muted-foreground text-end mt-1.5">
                                {{ now()->format('H:i') }} <i class="ki-filled ki-check text-info"></i>
                            </div>
                        </div>
                    </div>
                    @break

                {{-- LinkedIn: post card with author block, body, then the reaction bar --}}
                @case('linkedin')
                    <div class="rounded-xl border border-border">
                        <div class="flex items-center gap-2.5 p-3">
                            <span class="inline-flex items-center justify-center size-10 rounded-full bg-muted shrink-0">
                                <i class="ki-filled ki-profile-circle text-lg text-muted-foreground"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-mono truncate">{{ $handle }}</div>
                                <div class="text-[11px] text-muted-foreground">Now · <i class="ki-filled ki-users text-[10px]"></i></div>
                            </div>
                        </div>
                        <div class="px-3 pb-3 text-sm leading-relaxed text-mono">
                            <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                        </div>
                        <div class="flex items-center justify-around border-t border-border px-2 py-1.5 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-like-shapes"></i> Like</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-messages"></i> Comment</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-arrow-two-diagonals"></i> Repost</span>
                            <span class="inline-flex items-center gap-1.5"><i class="ki-filled ki-paper-plane"></i> Send</span>
                        </div>
                    </div>
                    @break

                {{-- Bluesky: tight single post, handle inline, action row underneath --}}
                @case('bluesky')
                    <div class="rounded-xl border border-border p-3">
                        <div class="flex gap-2.5">
                            <span class="inline-flex items-center justify-center size-10 rounded-full bg-muted shrink-0">
                                <i class="ki-filled ki-profile-circle text-lg text-muted-foreground"></i>
                            </span>
                            <div class="min-w-0 grow">
                                <div class="flex items-center gap-1.5 text-sm">
                                    <span class="text-muted-foreground truncate">{{ $handle }} · now</span>
                                </div>
                                <div class="text-sm leading-relaxed text-mono mt-0.5">
                                    <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                                </div>
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

                {{-- Mastodon: a status card, handle above the body, boost row below --}}
                @case('mastodon')
                    <div class="rounded-xl border border-border p-3">
                        <div class="flex items-center gap-2.5 mb-2">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                <i class="ki-filled ki-profile-circle text-base text-muted-foreground"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-mono truncate">{{ $handle }}</div>
                                <div class="text-[11px] text-muted-foreground">Now · Public</div>
                            </div>
                        </div>
                        <div class="text-sm leading-relaxed text-mono">
                            <span class="whitespace-pre-wrap">{{ $kept }}</span>@if ($cut)<span class="whitespace-pre-wrap line-through text-destructive/70 bg-destructive/10 rounded-sm">{{ $cut }}</span>@endif
                        </div>
                        <div class="flex items-center gap-5 mt-3 text-xs text-muted-foreground">
                            <span><i class="ki-filled ki-messages"></i></span>
                            <span><i class="ki-filled ki-arrows-circle"></i></span>
                            <span><i class="ki-filled ki-heart"></i></span>
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
                        {{ number_format($limit) }}-character {{ $label }} limit, and {{ $label }} will refuse the post
                        rather than trim it.
                        @if (in_array($networkKey, ['bluesky', 'mastodon'], true))
                            Splitting it across {{ $parts }} posts would keep the whole thing.
                        @else
                            Shorten it here, or give {{ $label }} its own text.
                        @endif
                    </p>
                </div>
            @endif

        @endif

    </div>
</div>
