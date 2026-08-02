<?php

use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One post, seen from every network it landed on.
 *
 * The same draft ends up worded slightly differently per network, so the copy
 * shown here is what each network actually holds, not the composer draft.
 */
new
#[Title('Post — Kargah')]
class extends Component
{
    public string $post = '';

    public string $range = '7d';

    public function mount(string $post = '1'): void
    {
        $this->post = $post;
    }

    /** @return array<string, array{label: string, icon: string, colour: string}> */
    public function networks(): array
    {
        return [
            'telegram'  => ['label' => 'Telegram',  'icon' => 'ki-paper-plane',        'colour' => '#0088cc'],
            'linkedin'  => ['label' => 'LinkedIn',  'icon' => 'ki-abstract-41', 'colour' => '#0a66c2'],
            'x'         => ['label' => 'X',         'icon' => 'ki-abstract-39', 'colour' => '#3f4254'],
            'instagram' => ['label' => 'Instagram', 'icon' => 'ki-instagram',   'colour' => '#e1306c'],
        ];
    }

    public function with(): array
    {
        $publishedAt = Carbon::today()->subDays(2)->setTime(10, 5);

        $deliveries = [
            [
                'network' => 'linkedin',
                'state' => 'sent',
                'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:7218904113364',
                'sentAt' => $publishedAt->copy(),
                'body' => "Shipped the drag-and-drop board in Kargah this week. Cards keep their order after a refresh, which sounds trivial until you try it without a full page reload.\n\nIt is Livewire 4 single-file components plus a thin Sortable wrapper — no SPA, no build step to babysit. Runs on a small shared host and still feels instant.\n\nWriting up how the ordering works next. Happy to share the code if anyone wants a look.",
                'metrics' => ['impressions' => 3180, 'reactions' => 142, 'comments' => 19, 'shares' => 8, 'clicks' => 96],
                'series' => [420, 980, 640, 380, 290, 260, 210],
            ],
            [
                'network' => 'x',
                'state' => 'sent',
                'url' => 'https://x.com/morpheusadam/status/1817440938201',
                'sentAt' => $publishedAt->copy()->addMinutes(1),
                'body' => "Shipped drag-and-drop boards in Kargah. Cards keep their order after a refresh — Livewire 4 plus a thin Sortable wrapper, no SPA, no build step. Runs on a small shared host and still feels instant.",
                'metrics' => ['impressions' => 1240, 'reactions' => 63, 'comments' => 7, 'shares' => 11, 'clicks' => 38],
                'series' => [510, 300, 170, 110, 70, 50, 30],
            ],
            [
                'network' => 'telegram',
                'state' => 'sent',
                'url' => 'https://t.me/kargah_buildlog/218',
                'sentAt' => $publishedAt->copy()->addMinutes(2),
                'body' => "Build log — drag-and-drop boards are in.\n\nCards keep their order after a refresh. Livewire 4 single-file components plus a thin Sortable wrapper. No SPA, no build step.\n\nCode walk-through coming this week.",
                'metrics' => ['impressions' => 512, 'reactions' => 24, 'comments' => 3, 'shares' => 6, 'clicks' => 41],
                'series' => [190, 120, 80, 45, 30, 28, 19],
            ],
            [
                'network' => 'instagram',
                'state' => 'failed',
                'url' => null,
                'sentAt' => null,
                'body' => "Shipped drag-and-drop boards in Kargah this week. Swipe for the before and after.",
                'metrics' => ['impressions' => null, 'reactions' => null, 'comments' => null, 'shares' => null, 'clicks' => null],
                'series' => [],
            ],
        ];

        $totals = [];
        foreach (['impressions', 'reactions', 'comments', 'shares', 'clicks'] as $metric) {
            $totals[$metric] = array_sum(array_map(
                fn (array $d): int => (int) ($d['metrics'][$metric] ?? 0),
                $deliveries,
            ));
        }

        return [
            'networks' => $this->networks(),
            'deliveries' => $deliveries,
            'totals' => $totals,
            'publishedAt' => $publishedAt,
            'metricLabels' => [
                'impressions' => ['label' => 'Impressions', 'icon' => 'ki-eye'],
                'reactions' => ['label' => 'Reactions', 'icon' => 'ki-heart'],
                'comments' => ['label' => 'Comments', 'icon' => 'ki-messages'],
                'shares' => ['label' => 'Shares', 'icon' => 'ki-arrow-two-diagonals'],
                'clicks' => ['label' => 'Clicks', 'icon' => 'ki-cursor'],
            ],
            'days' => collect(range(0, 6))->map(fn (int $i): string => $publishedAt->copy()->addDays($i)->format('D'))->all(),
        ];
    }

    public function retry(string $network): void
    {
        // Re-queues the failed delivery for this network. Backend work.
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost" title="Back to posts" aria-label="Back to posts">
                    <i class="ki-filled ki-arrow-left"></i>
                </a>
                <span class="text-xs text-muted-foreground">Post #{{ $post }}</span>
            </div>
            <h1 class="text-xl font-semibold text-mono">Drag-and-drop board write-up</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Published {{ $publishedAt->format('j M Y, H:i') }} to {{ count($deliveries) }} networks.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <select class="kt-select max-w-[160px]" wire:model.live="range" aria-label="Reporting range">
                <option value="24h">First 24 hours</option>
                <option value="7d">First 7 days</option>
                <option value="all">All time</option>
            </select>
            <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-copy"></i> Post again
            </a>
        </div>
    </div>

    {{-- Totals across every network --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-5">
        @foreach ($metricLabels as $key => $m)
            <div class="kt-card">
                <div class="kt-card-content p-4">
                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                        <i class="ki-filled {{ $m['icon'] }} text-sm"></i> {{ $m['label'] }}
                    </div>
                    <div class="text-2xl font-semibold text-mono mt-1.5">{{ number_format($totals[$key]) }}</div>
                    <div class="text-xs text-muted-foreground">across all networks</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Per-network engagement --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
        @foreach ($deliveries as $d)
            @php $n = $networks[$d['network']]; @endphp
            <div class="kt-card" wire:key="delivery-{{ $d['network'] }}">

                <div class="kt-card-header">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                            <i class="ki-filled {{ $n['icon'] }} text-lg text-secondary-foreground"></i>
                        </span>
                        <div class="min-w-0">
                            <h3 class="kt-card-title">{{ $n['label'] }}</h3>
                            <p class="text-xs text-muted-foreground">
                                {{ $d['sentAt'] ? 'Sent ' . $d['sentAt']->format('j M, H:i') : 'Never sent' }}
                            </p>
                        </div>
                    </div>
                    @if ($d['state'] === 'sent')
                        <a href="{{ $d['url'] }}" target="_blank" rel="noopener"
                           class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 shrink-0">
                            <i class="ki-filled ki-exit-right-corner text-sm"></i> Open live post
                        </a>
                    @else
                        <span class="kt-badge kt-badge-sm kt-badge-destructive shrink-0">Failed</span>
                    @endif
                </div>

                <div class="kt-card-content p-4 flex flex-col gap-4">

                    {{-- Metrics --}}
                    <div class="grid grid-cols-3 sm:grid-cols-5 gap-3">
                        @foreach ($metricLabels as $key => $m)
                            <div>
                                <div class="text-xs text-muted-foreground flex items-center gap-1">
                                    <i class="ki-filled {{ $m['icon'] }} text-xs"></i> {{ $m['label'] }}
                                </div>
                                <div class="text-lg font-semibold text-mono">
                                    {{ $d['metrics'][$key] === null ? '—' : number_format($d['metrics'][$key]) }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Impressions over time --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-secondary-foreground">Impressions by day</span>
                            <span class="text-xs text-muted-foreground">{{ $range === '24h' ? 'first 24 hours' : ($range === '7d' ? 'first 7 days' : 'all time') }}</span>
                        </div>
                        @if ($d['series'])
                            @php $peak = max($d['series']) ?: 1; @endphp
                            <div class="flex items-end gap-1.5 h-24">
                                @foreach ($d['series'] as $i => $value)
                                    <div class="grow flex flex-col items-center gap-1 min-w-0">
                                        <div class="w-full rounded-t-sm"
                                             style="height: {{ max(4, (int) round($value / $peak * 80)) }}px; background-color: {{ $n['colour'] }}"
                                             title="{{ $days[$i] }}: {{ number_format($value) }} impressions"></div>
                                        <span class="text-[10px] text-muted-foreground">{{ $days[$i] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-muted-foreground">No data — the post never reached {{ $n['label'] }}.</p>
                        @endif
                    </div>

                    {{-- The copy this network holds --}}
                    <div class="border-t border-border pt-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-secondary-foreground">
                                {{ $d['state'] === 'sent' ? 'Published copy' : 'Copy that was queued' }}
                            </span>
                            <span class="text-xs text-muted-foreground">{{ mb_strlen($d['body']) }} characters</span>
                        </div>
                        <p class="text-sm text-mono whitespace-pre-wrap leading-relaxed">{{ $d['body'] }}</p>
                    </div>

                    @if ($d['state'] === 'failed')
                        <div class="flex flex-wrap items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-3">
                            <i class="ki-filled ki-information-2 text-destructive text-base mt-0.5 shrink-0"></i>
                            <p class="text-sm text-secondary-foreground grow min-w-0">
                                Instagram Graph API returned 190 — the access token expired. Reconnect the account, then retry.
                            </p>
                            <div class="flex items-center gap-2 shrink-0">
                                <a href="{{ route('social.account-connect') }}?network=instagram" wire:navigate
                                   class="kt-btn kt-btn-sm kt-btn-outline">Reconnect</a>
                                <button wire:click="retry('{{ $d['network'] }}')" wire:loading.attr="disabled"
                                        class="kt-btn kt-btn-sm kt-btn-primary gap-1.5">
                                    <span wire:loading.remove wire:target="retry">Retry</span>
                                    <span wire:loading wire:target="retry" class="inline-flex items-center gap-1.5">
                                        <i class="ki-filled ki-loading animate-spin"></i> Retrying…
                                    </span>
                                </button>
                            </div>
                        </div>
                    @endif

                </div>
            </div>
        @endforeach
    </div>
</div>
