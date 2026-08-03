<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;

new
#[Title('Campaigns — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'filters' => ['all' => 'All'] + Campaign::statuses(),
            'campaigns' => Campaign::query()
                ->when($this->filter !== 'all', fn ($q) => $q->withStatus($this->filter))
                ->with('provider')
                // Counted rather than read from `campaigns.sent_count`, so the
                // list agrees with the report even in the minute between a
                // chunk finishing and the counters being recomputed.
                ->withCount([
                    'recipients',
                    'recipients as bounced_recipients_count' => fn ($q) => $q->whereIn('status', [
                        CampaignRecipient::BOUNCED,
                        CampaignRecipient::COMPLAINED,
                    ]),
                ])
                ->inReadingOrder()
                ->paginate(20),
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Campaigns</h1>
            <p class="text-sm text-secondary-foreground mt-1">Bulk sends, throttled across your providers.</p>
        </div>
        <a href="{{ route('mail.campaign-create') }}" class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> New campaign
        </a>
    </div>

    <div class="kt-card">
        <div class="kt-card-header">
            <div class="flex gap-1 flex-wrap">
                @foreach ($filters as $key => $label)
                    <button wire:click="$set('filter', '{{ $key }}')"
                            class="kt-btn kt-btn-sm {{ $filter === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[240px]">Campaign</th>
                            <th class="w-[150px]">Provider</th>
                            <th class="w-[110px] text-end">Recipients</th>
                            <th class="w-[90px] text-end">Sent</th>
                            <th class="w-[100px] text-end">Failed</th>
                            <th class="w-[100px] text-end">Bounces</th>
                            <th class="w-[110px]">Status</th>
                            <th class="w-[110px]">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($campaigns as $c)
                            @php $total = $c->recipients_count; @endphp
                            <tr wire:key="campaign-{{ $c->id }}">
                                <td>
                                    <a href="{{ route('mail.campaign-show', $c->id) }}" class="font-medium text-mono hover:text-primary">
                                        {{ $c->name }}
                                    </a>
                                    <div class="text-xs text-muted-foreground truncate max-w-[320px]">{{ $c->subject }}</div>
                                </td>
                                <td class="text-secondary-foreground">{{ $c->provider?->label() ?? '—' }}</td>
                                <td class="text-end">{{ $total ?: '—' }}</td>
                                <td class="text-end">{{ $c->sent_count ?: '—' }}</td>
                                <td class="text-end {{ $c->failed_count > 0 ? 'text-destructive' : '' }}">{{ $c->failed_count ?: '—' }}</td>
                                <td class="text-end">
                                    {{ $total === 0 ? '—' : number_format($c->bounced_recipients_count / max(1, $total) * 100, 1).'%' }}
                                </td>
                                <td><span class="kt-badge kt-badge-sm {{ $c->badge() }}">{{ $c->statusLabel() }}</span></td>
                                <td class="text-secondary-foreground">
                                    {{ ($c->finished_at ?? $c->started_at ?? $c->scheduled_for)?->format('d M') ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="flex flex-col items-center justify-center text-center py-14">
                                        <i class="ki-filled ki-paper-plane text-4xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">
                                            @if ($filter === 'all')
                                                No campaigns yet. A campaign is a subject, a body and a list of people.
                                            @else
                                                No campaign is {{ mb_strtolower($filters[$filter] ?? $filter) }}.
                                            @endif
                                        </p>
                                        <a href="{{ route('mail.campaign-create') }}" class="kt-btn kt-btn-primary gap-2 mt-4">
                                            <i class="ki-filled ki-plus"></i> New campaign
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($campaigns->hasPages())
            <div class="kt-card-footer">{{ $campaigns->links() }}</div>
        @endif
    </div>
</div>
