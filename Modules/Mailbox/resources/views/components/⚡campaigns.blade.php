<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

new
#[Title('Campaigns — Kargah')]
class extends Component
{
    // Nothing on this page toasts yet. The status filter is a `$set` and the
    // table re-draws under it, so confirming the click would only be noise —
    // the trait is here for the actions the backend phase adds.
    use InteractsWithToasts;

    public string $filter = 'all';

    public function with(): array
    {
        return [
            'filters' => ['all' => 'All', 'draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent'],
            'campaigns' => [
                ['id' => 1, 'name' => 'Resume — design agencies UK', 'list' => 'Agencies UK', 'recipients' => 240, 'sent' => 240, 'opens' => '38%', 'clicks' => '9%', 'bounces' => '1.2%', 'status' => 'sent',      'when' => 'Jul 22'],
                ['id' => 2, 'name' => 'Follow-up #1',                'list' => 'Agencies UK', 'recipients' => 186, 'sent' => 186, 'opens' => '31%', 'clicks' => '6%', 'bounces' => '0.5%', 'status' => 'sent',      'when' => 'Jul 29'],
                ['id' => 3, 'name' => 'Resume — startups DE',        'list' => 'Startups DE', 'recipients' => 310, 'sent' => 0,   'opens' => '—',   'clicks' => '—',  'bounces' => '—',    'status' => 'scheduled', 'when' => 'Aug 05'],
                ['id' => 4, 'name' => 'Newsletter draft',            'list' => '—',           'recipients' => 0,   'sent' => 0,   'opens' => '—',   'clicks' => '—',  'bounces' => '—',    'status' => 'draft',     'when' => '—'],
            ],
            'badge' => [
                'draft' => 'kt-badge-outline', 'scheduled' => 'kt-badge-info',
                'sending' => 'kt-badge-warning', 'sent' => 'kt-badge-success',
            ],
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
                            <th class="w-[140px]">List</th>
                            <th class="w-[110px] text-end">Recipients</th>
                            <th class="w-[90px] text-end">Opens</th>
                            <th class="w-[90px] text-end">Clicks</th>
                            <th class="w-[100px] text-end">Bounces</th>
                            <th class="w-[110px]">Status</th>
                            <th class="w-[100px]">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $c)
                            <tr>
                                <td>
                                    <a href="{{ route('mail.campaign-show', $c['id']) }}" class="font-medium text-mono hover:text-primary">
                                        {{ $c['name'] }}
                                    </a>
                                </td>
                                <td class="text-secondary-foreground">{{ $c['list'] }}</td>
                                <td class="text-end">{{ $c['recipients'] ?: '—' }}</td>
                                <td class="text-end">{{ $c['opens'] }}</td>
                                <td class="text-end">{{ $c['clicks'] }}</td>
                                <td class="text-end">{{ $c['bounces'] }}</td>
                                <td><span class="kt-badge kt-badge-sm {{ $badge[$c['status']] }}">{{ ucfirst($c['status']) }}</span></td>
                                <td class="text-secondary-foreground">{{ $c['when'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
