<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Queue and history.
 *
 * One post can land on four networks and fail on one of them, so delivery is
 * tracked per network rather than as a single status on the post.
 */
new
#[Title('Posts — Kargah')]
class extends Component
{
    #[Url]
    public string $tab = 'queued';

    public string $search = '';

    public ?int $expanded = null;

    /** @return array<string, array{label: string, icon: string}> */
    public function networks(): array
    {
        return [
            'telegram'  => ['label' => 'Telegram',  'icon' => 'ki-paper-plane'],
            'linkedin'  => ['label' => 'LinkedIn',  'icon' => 'ki-abstract-41'],
            'x'         => ['label' => 'X',         'icon' => 'ki-abstract-39'],
            'instagram' => ['label' => 'Instagram', 'icon' => 'ki-instagram'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function posts(): array
    {
        return [
            [
                'id' => 1,
                'tab' => 'queued',
                'excerpt' => 'Shipped the drag-and-drop board in Kargah this week. Cards keep their order after a refresh, which sounds trivial until you try it without a full page reload.',
                'time' => 'Tomorrow, 09:30',
                'timeLabel' => 'Scheduled',
                'delivery' => [
                    ['network' => 'linkedin', 'state' => 'pending', 'detail' => 'Queued'],
                    ['network' => 'x',        'state' => 'pending', 'detail' => 'Queued, trimmed to 280'],
                ],
            ],
            [
                'id' => 2,
                'tab' => 'queued',
                'excerpt' => 'Build log: invoice PDF templates now render right-to-left without the layout collapsing. Two evenings and one very stubborn font.',
                'time' => 'Thu, 18:00',
                'timeLabel' => 'Scheduled',
                'delivery' => [
                    ['network' => 'telegram', 'state' => 'pending', 'detail' => 'Queued'],
                ],
            ],
            [
                'id' => 3,
                'tab' => 'published',
                'excerpt' => 'Benchmarked the whole app on a small shared host. Median page render 84ms with no cache warm-up. No SPA needed.',
                'time' => '2 days ago, 10:05',
                'timeLabel' => 'Published',
                'delivery' => [
                    ['network' => 'x',        'state' => 'sent', 'detail' => 'Delivered · 1,240 impressions'],
                    ['network' => 'linkedin', 'state' => 'sent', 'detail' => 'Delivered · 3,180 impressions'],
                ],
            ],
            [
                'id' => 4,
                'tab' => 'published',
                'excerpt' => 'Client onboarding checklist I use before writing a line of code. Saves at least one awkward call per project.',
                'time' => '5 days ago, 09:40',
                'timeLabel' => 'Published',
                'delivery' => [
                    ['network' => 'linkedin', 'state' => 'sent', 'detail' => 'Delivered · 4,620 impressions'],
                    ['network' => 'telegram', 'state' => 'sent', 'detail' => 'Delivered · 512 views'],
                ],
            ],
            [
                'id' => 5,
                'tab' => 'failed',
                'excerpt' => 'Desk setup for the new contract. Two monitors, one of them permanently on the logs.',
                'time' => 'Yesterday, 12:15',
                'timeLabel' => 'Attempted',
                'delivery' => [
                    ['network' => 'telegram',  'state' => 'sent',   'detail' => 'Delivered · 498 views'],
                    ['network' => 'instagram', 'state' => 'failed', 'detail' => 'Rejected by the Graph API'],
                ],
                'error' => 'Instagram Graph API returned 190 — the access token expired on 24 July. Reconnect the account and the queued job will replay.',
                'attempts' => 3,
            ],
            [
                'id' => 6,
                'tab' => 'failed',
                'excerpt' => 'Why I stopped reaching for an SPA on small client projects, and what I reach for instead.',
                'time' => '3 days ago, 09:00',
                'timeLabel' => 'Attempted',
                'delivery' => [
                    ['network' => 'x', 'state' => 'failed', 'detail' => 'Rate limited'],
                ],
                'error' => 'X API returned 429 after 3 attempts. The app-level write limit resets hourly; retry any time after 10:00.',
                'attempts' => 3,
            ],
            [
                'id' => 7,
                'tab' => 'drafts',
                'excerpt' => 'Half-written thread about keeping a freelance invoice ledger in SQLite and never regretting it.',
                'time' => 'Edited 4 hours ago',
                'timeLabel' => 'Draft',
                'delivery' => [
                    ['network' => 'x',        'state' => 'draft', 'detail' => 'Not scheduled'],
                    ['network' => 'linkedin', 'state' => 'draft', 'detail' => 'Not scheduled'],
                ],
            ],
        ];
    }

    public function with(): array
    {
        $posts = $this->posts();
        $search = trim(mb_strtolower($this->search));

        $rows = array_values(array_filter($posts, function (array $p) use ($search): bool {
            if ($p['tab'] !== $this->tab) {
                return false;
            }

            return $search === '' || str_contains(mb_strtolower($p['excerpt']), $search);
        }));

        $counts = [];
        foreach (['queued', 'published', 'failed', 'drafts'] as $tab) {
            $counts[$tab] = count(array_filter($posts, fn (array $p): bool => $p['tab'] === $tab));
        }

        return [
            'networks' => $this->networks(),
            'rows' => $rows,
            'counts' => $counts,
            'tabs' => [
                'queued' => ['label' => 'Queued', 'icon' => 'ki-time'],
                'published' => ['label' => 'Published', 'icon' => 'ki-check-circle'],
                'failed' => ['label' => 'Failed', 'icon' => 'ki-cross-circle'],
                'drafts' => ['label' => 'Drafts', 'icon' => 'ki-notepad-edit'],
            ],
            'states' => [
                'sent'    => ['label' => 'Delivered', 'badge' => 'kt-badge-success', 'icon' => 'ki-check-circle'],
                'pending' => ['label' => 'Queued',    'badge' => 'kt-badge-warning', 'icon' => 'ki-time'],
                'failed'  => ['label' => 'Failed',    'badge' => 'kt-badge-destructive', 'icon' => 'ki-cross-circle'],
                'draft'   => ['label' => 'Draft',     'badge' => 'kt-badge-outline', 'icon' => 'ki-notepad-edit'],
            ],
        ];
    }

    public function toggleError(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function retry(int $post, ?string $network = null): void
    {
        // Re-queues the delivery job for one network, or all failed ones. Backend work.
    }

    public function cancel(int $post): void
    {
        // Removes the queued job before it fires. Backend work.
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Posts</h1>
            <p class="text-sm text-secondary-foreground mt-1">Track what is waiting, what went out and what did not.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('social.calendar') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-calendar"></i> Calendar
            </a>
            <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New post
            </a>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($tabs as $key => $t)
                <button wire:click="$set('tab', '{{ $key }}')"
                        class="kt-btn kt-btn-sm gap-2 {{ $tab === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                    <i class="ki-filled {{ $t['icon'] }} text-sm"></i>
                    {{ $t['label'] }}
                    <span class="kt-badge kt-badge-sm {{ $tab === $key ? 'kt-badge-outline' : '' }}">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        <div class="kt-input w-full sm:max-w-[260px]">
            <i class="ki-filled ki-magnifier text-muted-foreground"></i>
            <input type="text" placeholder="Search posts…" aria-label="Search posts" wire:model.live.debounce.300ms="search">
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-content p-0">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table kt-card-table align-middle">
                    <thead>
                        <tr>
                            <th class="min-w-[280px]">Post</th>
                            <th class="w-[140px]">Networks</th>
                            <th class="w-[180px]">{{ $tab === 'published' ? 'Published' : ($tab === 'drafts' ? 'Last edited' : 'Scheduled') }}</th>
                            <th class="w-[260px]">Delivery</th>
                            <th class="w-[120px] text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="post-{{ $row['id'] }}">
                                <td>
                                    <a href="{{ route('social.post-show', $row['id']) }}" wire:navigate
                                       class="text-sm text-mono hover:text-primary line-clamp-2 max-w-[420px]">
                                        {{ $row['excerpt'] }}
                                    </a>
                                    @if ($tab === 'failed')
                                        <button wire:click="toggleError({{ $row['id'] }})"
                                                class="text-xs text-destructive inline-flex items-center gap-1 mt-1">
                                            <i class="ki-filled ki-information-2 text-xs"></i>
                                            {{ $expanded === $row['id'] ? 'Hide error' : 'Show error' }}
                                        </button>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        @foreach ($row['delivery'] as $d)
                                            <span class="inline-flex items-center justify-center size-7 rounded-md bg-muted"
                                                  title="{{ $networks[$d['network']]['label'] }} — {{ $states[$d['state']]['label'] }}">
                                                <i class="ki-filled {{ $networks[$d['network']]['icon'] }} text-sm text-secondary-foreground"></i>
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="text-sm text-mono">{{ $row['time'] }}</div>
                                    <div class="text-xs text-muted-foreground">{{ $row['timeLabel'] }}</div>
                                </td>
                                <td>
                                    <div class="flex flex-col gap-1">
                                        @foreach ($row['delivery'] as $d)
                                            <div class="flex items-center gap-2">
                                                <span class="kt-badge kt-badge-sm {{ $states[$d['state']]['badge'] }} shrink-0">
                                                    {{ $networks[$d['network']]['label'] }}
                                                </span>
                                                <span class="text-xs text-muted-foreground truncate">{{ $d['detail'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($tab === 'failed')
                                            <button wire:click="retry({{ $row['id'] }})" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-outline gap-1.5">
                                                <span wire:loading.remove wire:target="retry({{ $row['id'] }})">Retry</span>
                                                <span wire:loading wire:target="retry({{ $row['id'] }})" class="inline-flex items-center gap-1.5">
                                                    <i class="ki-filled ki-loading animate-spin"></i> Retrying…
                                                </span>
                                            </button>
                                        @elseif ($tab === 'queued')
                                            <button wire:click="cancel({{ $row['id'] }})"
                                                    class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost text-destructive"
                                                    title="Cancel this post" aria-label="Cancel this post">
                                                <i class="ki-filled ki-cross-circle"></i>
                                            </button>
                                        @endif
                                        <a href="{{ route('social.post-show', $row['id']) }}" wire:navigate
                                           class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                           title="Open post" aria-label="Open post">
                                            <i class="ki-filled ki-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            @if ($tab === 'failed' && $expanded === $row['id'])
                                <tr wire:key="error-{{ $row['id'] }}">
                                    <td colspan="5" class="bg-destructive/5">
                                        <div class="flex flex-wrap items-start gap-3 py-1">
                                            <i class="ki-filled ki-information-2 text-destructive text-base mt-0.5 shrink-0"></i>
                                            <div class="min-w-0 grow">
                                                <p class="text-sm text-mono">{{ $row['error'] }}</p>
                                                <p class="text-xs text-muted-foreground mt-1">
                                                    {{ $row['attempts'] }} attempts · next automatic retry —
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                @foreach ($row['delivery'] as $d)
                                                    @if ($d['state'] === 'failed')
                                                        <button wire:click="retry({{ $row['id'] }}, '{{ $d['network'] }}')"
                                                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5">
                                                            <i class="ki-filled {{ $networks[$d['network']]['icon'] }} text-sm"></i>
                                                            Retry {{ $networks[$d['network']]['label'] }}
                                                        </button>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="flex flex-col items-center py-14 text-center">
                                        <i class="ki-filled {{ $tabs[$tab]['icon'] }} text-4xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">
                                            {{ $search !== '' ? 'No posts match that search.' : 'Nothing in ' . strtolower($tabs[$tab]['label']) . '.' }}
                                        </p>
                                        <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3 gap-2">
                                            <i class="ki-filled ki-plus"></i> New post
                                        </a>
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
