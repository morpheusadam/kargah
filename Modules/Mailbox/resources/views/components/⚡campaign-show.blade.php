<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Campaign report.
 *
 * The per-provider table is the part worth reading. When a campaign is spread
 * across providers, one of them will always carry a worse bounce or complaint
 * rate than the others, and that is the signal to drop its share before the
 * whole sending domain picks up a reputation problem.
 */
new
#[Title('Campaign report — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $campaign = '1';

    #[Url]
    public string $status = 'all';

    public string $search = '';

    public function mount(string $campaign = '1'): void
    {
        $this->campaign = $campaign;
    }

    public function with(): array
    {
        return [
            'campaignName' => 'Resume — design agencies UK',
            'meta' => [
                ['label' => 'List',      'value' => 'Agencies UK'],
                ['label' => 'From',      'value' => 'Nima Fazlipour <nima@news.kargah.dev>'],
                ['label' => 'Subject',   'value' => 'Freelance front-end capacity from mid-August'],
                ['label' => 'Started',   'value' => '22 Jul 2026, 09:00'],
                ['label' => 'Finished',  'value' => '22 Jul 2026, 11:04'],
                ['label' => 'Send rate', 'value' => '120 per hour'],
            ],
            'metrics' => [
                ['label' => 'Sent',         'value' => '240',  'rate' => '—',     'icon' => 'ki-paper-plane',  'tone' => 'text-secondary-foreground'],
                ['label' => 'Delivered',    'value' => '236',  'rate' => '98.3%', 'icon' => 'ki-check-circle', 'tone' => 'text-success'],
                ['label' => 'Opens',        'value' => '91',   'rate' => '38.6%', 'icon' => 'ki-eye',          'tone' => 'text-primary'],
                ['label' => 'Clicks',       'value' => '21',   'rate' => '8.9%',  'icon' => 'ki-click',        'tone' => 'text-primary'],
                ['label' => 'Bounces',      'value' => '4',    'rate' => '1.7%',  'icon' => 'ki-cross-circle', 'tone' => 'text-destructive'],
                ['label' => 'Complaints',   'value' => '1',    'rate' => '0.42%', 'icon' => 'ki-shield-cross', 'tone' => 'text-destructive'],
                ['label' => 'Unsubscribes', 'value' => '2',    'rate' => '0.85%', 'icon' => 'ki-minus-circle', 'tone' => 'text-warning'],
            ],
            'providerRows' => [
                ['name' => 'Brevo',      'carried' => 120, 'delivered' => 119, 'hard' => 1, 'soft' => 0, 'complaints' => 0, 'opens' => '40.3%', 'share' => 50],
                ['name' => 'Amazon SES', 'carried' => 80,  'delivered' => 78,  'hard' => 2, 'soft' => 0, 'complaints' => 1, 'opens' => '36.9%', 'share' => 33],
                ['name' => 'Mailgun',    'carried' => 40,  'delivered' => 39,  'hard' => 1, 'soft' => 0, 'complaints' => 0, 'opens' => '37.5%', 'share' => 17],
            ],
            'links' => [
                ['url' => 'https://kargah.dev/portfolio',              'unique' => 14, 'total' => 19, 'ctr' => 5.9],
                ['url' => 'https://kargah.dev/cv/nima-fazlipour.pdf',  'unique' => 9,  'total' => 11, 'ctr' => 3.8],
                ['url' => 'https://cal.com/nima/intro',                'unique' => 4,  'total' => 4,  'ctr' => 1.7],
                ['url' => 'https://github.com/morpheusadam',           'unique' => 2,  'total' => 2,  'ctr' => 0.8],
            ],
            'statuses' => [
                'all'        => 'All',
                'clicked'    => 'Clicked',
                'opened'     => 'Opened',
                'delivered'  => 'Delivered',
                'bounced'    => 'Bounced',
                'complained' => 'Complained',
                'unsubscribed' => 'Unsubscribed',
            ],
            'recipients' => [
                ['email' => 'hello@studio-nord.example',   'name' => 'Studio Nord',  'provider' => 'Brevo',      'status' => 'clicked',      'event' => '22 Jul, 09:41'],
                ['email' => 'jobs@pixelforge.example',     'name' => 'Pixelforge',   'provider' => 'Brevo',      'status' => 'opened',       'event' => '22 Jul, 10:02'],
                ['email' => 'studio@northloop.example',    'name' => 'Northloop',    'provider' => 'Amazon SES', 'status' => 'delivered',    'event' => '22 Jul, 09:12'],
                ['email' => 'contact@brightlab.example',   'name' => 'Brightlab',    'provider' => 'Amazon SES', 'status' => 'bounced',      'event' => '22 Jul, 09:13'],
                ['email' => 'team@harbourside.example',    'name' => 'Harbourside',  'provider' => 'Mailgun',    'status' => 'complained',   'event' => '23 Jul, 08:20'],
                ['email' => 'info@quietfox.example',       'name' => 'Quiet Fox',    'provider' => 'Brevo',      'status' => 'unsubscribed', 'event' => '22 Jul, 12:55'],
                ['email' => 'hi@makers-lane.example',      'name' => "Makers' Lane", 'provider' => 'Mailgun',    'status' => 'clicked',      'event' => '22 Jul, 14:07'],
            ],
            'badge' => [
                'clicked'      => 'kt-badge-primary',
                'opened'       => 'kt-badge-info',
                'delivered'    => 'kt-badge-success',
                'bounced'      => 'kt-badge-destructive',
                'complained'   => 'kt-badge-destructive',
                'unsubscribed' => 'kt-badge-outline',
            ],
            'bounceDetail' => [
                'hard' => 4,
                'soft' => 0,
            ],
        ];
    }

    public function exportCsv(): void
    {
        // Streams the recipient table with its per-recipient events.
        $this->toastInfo('Not connected yet', 'The export needs the stored recipient events.');
    }

    public function resendToUnopened(): void
    {
        // Clones the campaign with the non-openers as its audience.
        $this->toastInfo('Not connected yet', 'Re-sending to non-openers lands with the backend phase.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-mono">{{ $campaignName }}</h1>
                <span class="kt-badge kt-badge-sm kt-badge-success">Sent</span>
                <span class="kt-badge kt-badge-sm kt-badge-outline">#{{ $campaign }}</span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">What happened to every message this campaign put on the wire.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('mail.campaigns') }}" class="kt-btn kt-btn-ghost gap-2">
                <i class="ki-filled ki-arrow-left"></i> Campaigns
            </a>
            <button class="kt-btn kt-btn-outline gap-2" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                <span wire:loading.remove wire:target="exportCsv" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-exit-down"></i> Export CSV
                </span>
                <span wire:loading wire:target="exportCsv" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Preparing…
                </span>
            </button>
            <button class="kt-btn kt-btn-primary gap-2" wire:click="resendToUnopened">
                <i class="ki-filled ki-arrows-circle"></i> Follow up non-openers
            </button>
        </div>
    </div>

    {{-- Headline metrics --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4">
        @foreach ($metrics as $m)
            <div class="kt-card">
                <div class="kt-card-content p-4">
                    <div class="flex items-center gap-1.5 text-xs text-secondary-foreground">
                        <i class="ki-filled {{ $m['icon'] }} {{ $m['tone'] }}"></i>
                        {{ $m['label'] }}
                    </div>
                    <div class="text-2xl font-semibold text-mono mt-2">{{ $m['value'] }}</div>
                    <div class="text-xs text-muted-foreground mt-0.5">{{ $m['rate'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

        {{-- Time series --}}
        <div class="xl:col-span-2 kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Activity over time</h3>
                <span class="text-xs text-muted-foreground">Deliveries, opens and clicks per hour</span>
            </div>
            <div class="kt-card-content p-5">
                <div class="min-h-[280px] flex items-center justify-center text-sm text-muted-foreground">
                    Wired to ApexCharts in the backend phase.
                </div>
            </div>
        </div>

        {{-- Campaign facts --}}
        <div class="kt-card">
            <div class="kt-card-header"><h3 class="kt-card-title">Campaign</h3></div>
            <div class="kt-card-content p-5">
                <dl class="flex flex-col gap-3 text-sm">
                    @foreach ($meta as $row)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-secondary-foreground shrink-0">{{ $row['label'] }}</dt>
                            <dd class="text-mono text-end break-words min-w-0">{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="mt-4 pt-4 border-t border-border text-xs text-muted-foreground">
                    {{ $bounceDetail['hard'] }} hard bounce(s) went straight onto the suppression list and will never be
                    retried on any provider. {{ $bounceDetail['soft'] }} soft bounce(s) stay eligible.
                </div>
            </div>
        </div>
    </div>

    {{-- Per-provider breakdown --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Carried by provider</h3>
            <span class="text-xs text-muted-foreground">Complaint rate above 0.1% is where mailbox providers start throttling</span>
        </div>
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[160px]">Provider</th>
                            <th class="w-[150px]">Share</th>
                            <th class="w-[100px] text-end">Carried</th>
                            <th class="w-[110px] text-end">Delivered</th>
                            <th class="w-[110px] text-end">Hard bounce</th>
                            <th class="w-[110px] text-end">Soft bounce</th>
                            <th class="w-[130px] text-end">Complaint rate</th>
                            <th class="w-[100px] text-end">Open rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($providerRows as $p)
                            @php $complaintRate = $p['carried'] ? ($p['complaints'] / $p['carried']) * 100 : 0; @endphp
                            <tr>
                                <td class="font-medium text-mono">{{ $p['name'] }}</td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-full max-w-[80px] rounded-full bg-muted overflow-hidden">
                                            <div class="h-full bg-primary rounded-full" style="width: {{ $p['share'] }}%"></div>
                                        </div>
                                        <span class="text-xs text-muted-foreground shrink-0">{{ $p['share'] }}%</span>
                                    </div>
                                </td>
                                <td class="text-end">{{ $p['carried'] }}</td>
                                <td class="text-end">{{ $p['delivered'] }}</td>
                                <td class="text-end {{ $p['hard'] > 0 ? 'text-destructive' : '' }}">{{ $p['hard'] }}</td>
                                <td class="text-end">{{ $p['soft'] }}</td>
                                <td class="text-end {{ $complaintRate > 0.1 ? 'text-destructive' : 'text-success' }}">
                                    {{ number_format($complaintRate, 2) }}%
                                </td>
                                <td class="text-end">{{ $p['opens'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="flex flex-col items-center justify-center text-center py-10">
                                        <i class="ki-filled ki-router text-4xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">Nothing has left yet, so no provider has carried anything.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Link map --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Link map</h3>
            <span class="text-xs text-muted-foreground">Click-through measured against delivered messages</span>
        </div>
        <div class="kt-card-content p-0 divide-y divide-border">
            @forelse ($links as $l)
                <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                    <div class="grow min-w-0">
                        <div class="text-sm text-mono truncate">{{ $l['url'] }}</div>
                        <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden mt-2 max-w-md">
                            <div class="h-full bg-primary rounded-full" style="width: {{ min(100, $l['ctr'] * 10) }}%"></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-6 shrink-0 text-sm">
                        <div class="text-end">
                            <div class="text-mono font-medium">{{ $l['unique'] }}</div>
                            <div class="text-xs text-muted-foreground">unique</div>
                        </div>
                        <div class="text-end">
                            <div class="text-mono font-medium">{{ $l['total'] }}</div>
                            <div class="text-xs text-muted-foreground">total</div>
                        </div>
                        <div class="text-end w-14">
                            <div class="text-mono font-medium">{{ $l['ctr'] }}%</div>
                            <div class="text-xs text-muted-foreground">CTR</div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center text-center py-12">
                    <i class="ki-filled ki-arrow-up-right text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">No tracked links in this campaign.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Recipients --}}
    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <h3 class="kt-card-title">Recipients</h3>
            <div class="flex flex-wrap items-center gap-2">
                <div class="kt-input max-w-[220px]">
                    <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                    <input type="text" placeholder="Search recipients…" wire:model.live.debounce.300ms="search">
                </div>
                <select class="kt-select max-w-[170px]" wire:model.live="status">
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[240px]">Email</th>
                            <th class="min-w-[150px]">Name</th>
                            <th class="w-[140px]">Carried by</th>
                            <th class="w-[140px]">Status</th>
                            <th class="w-[150px]">Last event</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recipients as $r)
                            <tr wire:loading.class="opacity-50" wire:target="status,search">
                                <td class="font-medium text-mono">{{ $r['email'] }}</td>
                                <td class="text-secondary-foreground">{{ $r['name'] }}</td>
                                <td class="text-secondary-foreground">{{ $r['provider'] }}</td>
                                <td><span class="kt-badge kt-badge-sm {{ $badge[$r['status']] }}">{{ ucfirst($r['status']) }}</span></td>
                                <td class="text-secondary-foreground">{{ $r['event'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="flex flex-col items-center justify-center text-center py-10">
                                        <i class="ki-filled ki-users text-4xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">No recipient matches that filter.</p>
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
