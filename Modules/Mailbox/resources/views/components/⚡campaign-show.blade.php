<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Jobs\SendCampaignChunk;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Services\Delivery\CampaignSender;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Campaign report.
 *
 * The per-provider table is the part worth reading. When a campaign is spread
 * across providers — which is what happens the moment one of them runs out of
 * quota — one of them will always carry a worse bounce or complaint rate than
 * the others, and that is the signal to drop its share before the whole sending
 * domain picks up a reputation problem.
 *
 * Every figure here is counted from `campaign_recipients` rather than read from
 * the campaign's own columns. Those columns are a summary that a killed worker
 * can leave a minute out of date; the rows cannot be wrong.
 */
new
#[Title('Campaign report — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    public string $campaign = '';

    #[Url]
    public string $status = 'all';

    public string $search = '';

    public function mount(string $campaign = ''): void
    {
        $this->campaign = $campaign;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * The campaign this page is about, or null.
     *
     * Null is a real answer rather than a 404. The report is linked to from
     * places that may outlive the campaign — a bookmark, an old toast — and a
     * page that says 'this campaign is gone' is more use than an error screen.
     */
    private function record(): ?Campaign
    {
        return $this->campaign === '' ? null : Campaign::query()->with('provider')->find($this->campaign);
    }

    public function with(): array
    {
        $campaign = $this->record();

        if ($campaign === null) {
            return [
                'campaign' => null,
                'metrics' => [],
                'meta' => [],
                'providerRows' => [],
                'recipients' => CampaignRecipient::query()->whereRaw('1 = 0')->paginate(25),
                'statuses' => ['all' => 'All'] + CampaignRecipient::statuses(),
                'problems' => [],
            ];
        }

        $counts = $campaign->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = max(1, (int) $counts->sum());
        $sent = (int) $counts->get(CampaignRecipient::SENT, 0);
        $bounced = (int) $counts->get(CampaignRecipient::BOUNCED, 0);
        $complained = (int) $counts->get(CampaignRecipient::COMPLAINED, 0);
        $failed = (int) $counts->get(CampaignRecipient::FAILED, 0);
        $suppressed = (int) $counts->get(CampaignRecipient::SUPPRESSED, 0);
        $outstanding = (int) $counts->get(CampaignRecipient::PENDING, 0) + (int) $counts->get(CampaignRecipient::CLAIMED, 0);

        $rate = fn (int $n): string => number_format($n / $total * 100, 2).'%';

        return [
            'campaign' => $campaign,
            'meta' => [
                ['label' => 'Provider', 'value' => $campaign->provider?->label() ?? '—'],
                ['label' => 'From', 'value' => $campaign->provider?->from_email ?? '—'],
                ['label' => 'Subject', 'value' => $campaign->subject],
                ['label' => 'Started', 'value' => $campaign->started_at?->format('j M Y, H:i') ?? '—'],
                ['label' => 'Finished', 'value' => $campaign->finished_at?->format('j M Y, H:i') ?? '—'],
                ['label' => 'Scheduled', 'value' => $campaign->scheduled_for?->format('j M Y, H:i') ?? '—'],
            ],
            'metrics' => [
                ['label' => 'Recipients', 'value' => (string) $counts->sum(), 'rate' => '—', 'icon' => 'ki-users', 'tone' => 'text-secondary-foreground'],
                ['label' => 'Sent', 'value' => (string) ($sent + $bounced + $complained), 'rate' => $rate($sent + $bounced + $complained), 'icon' => 'ki-paper-plane', 'tone' => 'text-success'],
                ['label' => 'Waiting', 'value' => (string) $outstanding, 'rate' => $rate($outstanding), 'icon' => 'ki-time', 'tone' => 'text-warning'],
                ['label' => 'Bounces', 'value' => (string) $bounced, 'rate' => $rate($bounced), 'icon' => 'ki-cross-circle', 'tone' => 'text-destructive'],
                ['label' => 'Complaints', 'value' => (string) $complained, 'rate' => $rate($complained), 'icon' => 'ki-shield-cross', 'tone' => 'text-destructive'],
                ['label' => 'Suppressed', 'value' => (string) $suppressed, 'rate' => $rate($suppressed), 'icon' => 'ki-minus-circle', 'tone' => 'text-warning'],
                ['label' => 'Failed', 'value' => (string) $failed, 'rate' => $rate($failed), 'icon' => 'ki-information-2', 'tone' => 'text-destructive'],
            ],
            'providerRows' => $campaign->providerBreakdown(),
            'statuses' => ['all' => 'All'] + CampaignRecipient::statuses(),
            'recipients' => $campaign->recipients()
                ->with('provider')
                ->when($this->status !== 'all', fn ($q) => $q->withStatus($this->status))
                ->search($this->search)
                ->orderBy('id')
                ->paginate(25),
            'problems' => app(\Modules\Mailbox\Services\Delivery\PreFlight::class)->problems($campaign),
            'failedCount' => $failed,
        ];
    }

    /** Start, or say exactly what stops it. */
    public function startSending(): void
    {
        $campaign = $this->record();

        if ($campaign === null) {
            return;
        }

        $problems = app(CampaignSender::class)->start($campaign);

        if ($problems !== []) {
            $this->toastError(
                'This campaign cannot go out yet',
                app(\Modules\Mailbox\Services\Delivery\PreFlight::class)->refusal($problems),
            );

            return;
        }

        SendCampaignChunk::dispatch($campaign->id);

        $this->toastSuccess(
            'Sending has begun',
            $campaign->outstandingCount().' '.str('recipient')->plural($campaign->outstandingCount())
            .' to go, in chunks, as the queue runs.',
        );
    }

    /** Stop after the chunk that is already queued. */
    public function pause(): void
    {
        $campaign = $this->record();

        if ($campaign === null || ! $campaign->isSending()) {
            return;
        }

        $campaign->forceFill(['status' => Campaign::PAUSED])->save();

        $this->toastWarning(
            'Paused',
            $campaign->outstandingCount().' '.str('recipient')->plural($campaign->outstandingCount())
            .' left untouched. A chunk already queued may still finish.',
        );
    }

    /**
     * Put the failed recipients back in the queue.
     *
     * Deliberate, and only ever a person's decision: a failed row may be a dead
     * address or it may be a message that already went out from a worker that
     * was killed before it could say so.
     */
    public function retryFailed(): void
    {
        $campaign = $this->record();

        if ($campaign === null) {
            return;
        }

        $requeued = app(CampaignSender::class)->requeueFailed($campaign);

        if ($requeued === 0) {
            $this->toastInfo('Nothing to retry', 'No recipient in this campaign is marked failed.');

            return;
        }

        SendCampaignChunk::dispatch($campaign->id);

        $this->toastSuccess(
            $requeued.' '.str('recipient')->plural($requeued).' queued again',
            'They will go out on the next tick, through whichever provider has quota then.',
        );
    }

    /** The recipient table with its per-recipient outcome, as a file. */
    public function exportCsv(): ?StreamedResponse
    {
        $campaign = $this->record();

        if ($campaign === null) {
            return null;
        }

        $rows = $campaign->recipients()->with('provider')->orderBy('id')->get();

        $this->toastSuccess(
            'Exported '.$rows->count().' '.str('recipient')->plural($rows->count()),
            'One row per person, with the provider that carried it and whatever came back.',
        );

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['email', 'name', 'status', 'carried_by', 'message_id', 'sent_at', 'error']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->email,
                    $row->name,
                    $row->status,
                    $row->provider?->label(),
                    $row->message_id,
                    $row->sent_at?->toDateTimeString(),
                    $row->error,
                ]);
            }

            fclose($handle);
        }, 'campaign-'.$campaign->id.'-recipients.csv', ['Content-Type' => 'text/csv']);
    }
};

?>

<div class="flex flex-col gap-5">

    @if (! $campaign)
        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center justify-center text-center py-16">
                <i class="ki-filled ki-paper-plane text-4xl text-muted-foreground mb-3"></i>
                <h1 class="text-lg font-semibold text-mono">This campaign is no longer here</h1>
                <p class="text-sm text-secondary-foreground mt-1">It may have been deleted since the link was made.</p>
                <a href="{{ route('mail.campaigns') }}" class="kt-btn kt-btn-primary gap-2 mt-4">
                    <i class="ki-filled ki-arrow-left"></i> Back to campaigns
                </a>
            </div>
        </div>
    @else

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-mono">{{ $campaign->name }}</h1>
                <span class="kt-badge kt-badge-sm {{ $campaign->badge() }}">{{ $campaign->statusLabel() }}</span>
                <span class="kt-badge kt-badge-sm kt-badge-outline">#{{ $campaign->id }}</span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">What happened to every message this campaign put on the wire.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('mail.campaigns') }}" class="kt-btn kt-btn-ghost gap-2">
                <i class="ki-filled ki-arrow-left"></i> Campaigns
            </a>
            @if ($campaign->isEditable())
                <a href="{{ route('mail.campaign-edit', $campaign->id) }}" class="kt-btn kt-btn-ghost gap-2">
                    <i class="ki-filled ki-pencil"></i> Edit
                </a>
            @endif
            <button class="kt-btn kt-btn-outline gap-2" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                <span wire:loading.remove wire:target="exportCsv" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-exit-down"></i> Export CSV
                </span>
                <span wire:loading wire:target="exportCsv" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Preparing…
                </span>
            </button>
            @if ($failedCount > 0)
                <button class="kt-btn kt-btn-outline gap-2" wire:click="retryFailed">
                    <i class="ki-filled ki-arrows-circle"></i> Retry {{ $failedCount }} failed
                </button>
            @endif
            @if ($campaign->isSending())
                <button class="kt-btn kt-btn-outline gap-2" wire:click="pause">
                    <i class="ki-filled ki-time"></i> Pause
                </button>
            @elseif ($campaign->status !== \Modules\Mailbox\Models\Campaign::SENT)
                <button class="kt-btn kt-btn-primary gap-2" wire:click="startSending" wire:loading.attr="disabled" wire:target="startSending">
                    <span wire:loading.remove wire:target="startSending" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-paper-plane"></i> Start sending
                    </span>
                    <span wire:loading wire:target="startSending" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Starting…
                    </span>
                </button>
            @endif
        </div>
    </div>

    @if ($problems !== [] && $campaign->status !== \Modules\Mailbox\Models\Campaign::SENT)
        <div class="kt-card bg-destructive/5 border-destructive/30">
            <div class="kt-card-content flex items-start gap-3 p-4">
                <i class="ki-filled ki-shield-cross text-destructive text-lg mt-0.5 shrink-0"></i>
                <div class="text-sm text-secondary-foreground">
                    <strong class="text-mono">The pre-flight refuses this campaign.</strong>
                    <ul class="list-disc ps-5 mt-2 flex flex-col gap-1">
                        @foreach ($problems as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

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
                            <th class="w-[110px] text-end">Bounced</th>
                            <th class="w-[110px] text-end">Failed</th>
                            <th class="w-[130px] text-end">Complaint rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($providerRows as $p)
                            @php $complaintRate = $p['carried'] ? ($p['complained'] / $p['carried']) * 100 : 0; @endphp
                            <tr wire:key="carrier-{{ $p['id'] ?? 'none' }}">
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
                                <td class="text-end {{ $p['bounced'] > 0 ? 'text-destructive' : '' }}">{{ $p['bounced'] }}</td>
                                <td class="text-end {{ $p['failed'] > 0 ? 'text-destructive' : '' }}">{{ $p['failed'] }}</td>
                                <td class="text-end {{ $complaintRate > 0.1 ? 'text-destructive' : 'text-success' }}">
                                    {{ number_format($complaintRate, 2) }}%
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
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

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
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
                    Hard bounces and complaints go straight onto the shared suppression list and are never retried
                    on any provider. Anything marked failed stayed on this campaign and can be queued again above.
                </div>
            </div>
        </div>

        {{-- Recipients --}}
        <div class="xl:col-span-2 kt-card">
            <div class="kt-card-header flex-wrap gap-3">
                <h3 class="kt-card-title">Recipients</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="kt-input max-w-[220px]">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input type="text" placeholder="Search recipients…" wire:model.live.debounce.300ms="search">
                    </div>
                    <select class="kt-select max-w-[170px]" wire:model.live="status" aria-label="Filter by status">
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
                                <th class="w-[140px]">Carried by</th>
                                <th class="w-[140px]">Status</th>
                                <th class="w-[150px]">Last event</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recipients as $r)
                                <tr wire:key="recipient-{{ $r->id }}" wire:loading.class="opacity-50" wire:target="status,search">
                                    <td>
                                        <div class="font-medium text-mono">{{ $r->email }}</div>
                                        @if ($r->error)
                                            <div class="text-xs text-destructive truncate max-w-[320px]">{{ $r->error }}</div>
                                        @elseif ($r->name)
                                            <div class="text-xs text-muted-foreground">{{ $r->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-secondary-foreground">{{ $r->provider?->label() ?? '—' }}</td>
                                    <td><span class="kt-badge kt-badge-sm {{ $r->badge() }}">{{ $r->statusLabel() }}</span></td>
                                    <td class="text-secondary-foreground">
                                        {{ ($r->sent_at ?? $r->failed_at ?? $r->claimed_at)?->format('j M, H:i') ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <div class="flex flex-col items-center justify-center text-center py-10">
                                            <i class="ki-filled ki-users text-4xl text-muted-foreground mb-3"></i>
                                            <p class="text-sm text-secondary-foreground">
                                                @if ($status !== 'all' || trim($search) !== '')
                                                    No recipient matches that filter.
                                                @else
                                                    This campaign has no recipients yet.
                                                @endif
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($recipients->hasPages())
                <div class="kt-card-footer">{{ $recipients->links() }}</div>
            @endif
        </div>
    </div>

    @endif
</div>
