<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Provider configuration.
 *
 * Every provider carries its own caps, its own stream and its own DNS records.
 * The records matter more than the caps: an SPF record that pushes the domain
 * past ten DNS lookups fails permerror, and a permerror is treated as no SPF at
 * all by most receivers.
 */
new
#[Title('Provider settings — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $provider = 'brevo';

    public string $driver = 'brevo';

    #[Validate('required|string|min:20')]
    public string $apiKey = 'xkeysib-7f3c9a2e41b8d05c6ea19f4d3b27c8e0-QmT4pLxR';

    public bool $revealKey = false;

    #[Validate('required|string')]
    public string $sendingDomain = 'news.kargah.dev';

    public string $returnPath = 'bounce.news.kargah.dev';

    public int $dailyCap = 300;

    public int $hourlyCap = 120;

    public string $stream = 'marketing';

    public int $priority = 1;

    public bool $enabled = true;

    public function mount(string $provider = 'brevo'): void
    {
        $this->provider = $provider;
    }

    public function with(): array
    {
        return [
            'drivers' => [
                'brevo'   => 'Brevo — REST API',
                'resend'  => 'Resend — REST API',
                'ses'     => 'Amazon SES — API v2',
                'mailgun' => 'Mailgun — REST API',
                'smtp2go' => 'SMTP2GO — SMTP relay',
            ],
            'streams' => [
                'marketing'     => ['label' => 'Marketing',     'note' => 'Campaigns and newsletters. Keep on a subdomain of its own.'],
                'transactional' => ['label' => 'Transactional', 'note' => 'Replies, invoices, password resets. Must never be throttled by a campaign.'],
                'failover'      => ['label' => 'Failover',      'note' => 'Only used when a primary provider is out of quota or returning errors.'],
            ],
            'domains' => [
                'news.kargah.dev' => 'news.kargah.dev — marketing',
                'tx.kargah.dev'   => 'tx.kargah.dev — transactional',
            ],
            'dns' => [
                [
                    'purpose' => 'SPF — authorises the provider to send for this domain',
                    'type'    => 'TXT',
                    'host'    => 'news.kargah.dev',
                    'value'   => 'v=spf1 include:spf.brevo.com include:amazonses.com include:mailgun.org -all',
                    'status'  => 'found',
                    'note'    => 'Uses 7 of the 10 DNS lookups SPF allows. Adding a fourth include would risk a permerror.',
                ],
                [
                    'purpose' => 'DKIM — signs each message so receivers can verify it',
                    'type'    => 'CNAME',
                    'host'    => 'mail._domainkey.news.kargah.dev',
                    'value'   => 'b1.kargah-dev.dkim.brevo.com',
                    'status'  => 'found',
                    'note'    => '2048-bit key. Keep the CNAME rather than a TXT copy so provider key rotation needs no change here.',
                ],
                [
                    'purpose' => 'Return-Path — aligns the bounce address with the From domain',
                    'type'    => 'CNAME',
                    'host'    => 'bounce.news.kargah.dev',
                    'value'   => 'bounce.brevo.com',
                    'status'  => 'found',
                    'note'    => 'Without this, SPF authenticates the provider\'s domain and DMARC alignment fails.',
                ],
                [
                    'purpose' => 'DMARC — tells receivers what to do when checks fail',
                    'type'    => 'TXT',
                    'host'    => '_dmarc.kargah.dev',
                    'value'   => 'v=DMARC1; p=none; rua=mailto:dmarc@kargah.dev; adkim=r; aspf=r',
                    'status'  => 'weak',
                    'note'    => 'p=none only reports. Once the aggregate reports look clean, move to p=quarantine.',
                ],
                [
                    'purpose' => 'Tracking domain — rewrites click and open URLs onto your domain',
                    'type'    => 'CNAME',
                    'host'    => 'click.news.kargah.dev',
                    'value'   => 'track.brevo.com',
                    'status'  => 'missing',
                    'note'    => 'Optional, but a shared tracking domain inherits every other sender\'s reputation.',
                ],
            ],
            'dnsBadge' => [
                'found'   => ['class' => 'kt-badge-success',     'label' => 'Found'],
                'weak'    => ['class' => 'kt-badge-warning',     'label' => 'Needs attention'],
                'missing' => ['class' => 'kt-badge-destructive', 'label' => 'Not found'],
            ],
            'lastTest' => [
                'when'     => '2 Aug 2026, 08:12',
                'outcome'  => 'pass',
                'message'  => 'Authenticated as team@kargah.dev. Account tier allows 300 messages per day, 291 remaining.',
                'latency'  => '412 ms',
            ],
            'usage' => ['today' => 9, 'thisHour' => 0],
        ];
    }

    public function testConnection(): void
    {
        // Calls the provider's account endpoint and records the result.
        // "Last test" above is a fixture, so nothing here may claim a pass.
        $this->toastInfo('Not connected yet', 'The provider is never called until the backend phase.');
    }

    public function verifyDns(): void
    {
        // Re-resolves each record and refreshes the statuses above.
        $this->toastInfo('Not connected yet', 'The DNS statuses shown are fixtures, not a live lookup.');
    }

    public function save(): void
    {
        // Persists the provider record.
        $this->toastInfo('Not connected yet', 'Provider settings are not stored until the backend phase.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-mono">{{ \Illuminate\Support\Str::before($drivers[$driver] ?? 'Provider', ' —') }}</h1>
                <span class="kt-badge kt-badge-sm {{ $enabled ? 'kt-badge-success' : 'kt-badge-outline' }}">
                    {{ $enabled ? 'Enabled' : 'Paused' }}
                </span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">Credentials, caps and the DNS this provider needs on your domain.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('mail.providers') }}" class="kt-btn kt-btn-ghost gap-2">
                <i class="ki-filled ki-arrow-left"></i> Providers
            </a>
            <button class="kt-btn kt-btn-primary gap-2" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-check"></i> Save changes
                </span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

        <div class="xl:col-span-2 flex flex-col gap-5">

            {{-- Connection --}}
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Connection</h3></div>
                <div class="kt-card-content p-5 grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-driver">Driver</label>
                        <select id="provider-driver" class="kt-select" wire:model.live="driver">
                            @foreach ($drivers as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-muted-foreground mt-1">Changing the driver clears the stored credentials.</span>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-key">API key</label>
                        <div class="flex items-center gap-2">
                            <input type="{{ $revealKey ? 'text' : 'password' }}" id="provider-key"
                                   class="kt-input grow font-mono text-xs @error('apiKey') border-destructive @enderror"
                                   wire:model="apiKey" autocomplete="off" spellcheck="false">
                            <button class="kt-btn kt-btn-icon kt-btn-outline shrink-0"
                                    wire:click="$toggle('revealKey')"
                                    title="{{ $revealKey ? 'Hide API key' : 'Reveal API key' }}"
                                    aria-label="{{ $revealKey ? 'Hide API key' : 'Reveal API key' }}">
                                <i class="ki-filled {{ $revealKey ? 'ki-eye-slash' : 'ki-eye' }}"></i>
                            </button>
                        </div>
                        @error('apiKey')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        <span class="text-xs text-muted-foreground mt-1">Stored encrypted; it is never shown again once you navigate away.</span>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-domain">Sending domain</label>
                        <select id="provider-domain" class="kt-select @error('sendingDomain') border-destructive @enderror"
                                wire:model.live="sendingDomain">
                            @foreach ($domains as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('sendingDomain')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-return-path">Custom Return-Path</label>
                        <input type="text" id="provider-return-path" class="kt-input" wire:model="returnPath">
                        <span class="text-xs text-muted-foreground mt-1">Needed for SPF alignment under DMARC.</span>
                    </div>
                </div>
            </div>

            {{-- Limits and routing --}}
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Limits and routing</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="provider-daily">Daily cap</label>
                            <input type="number" id="provider-daily" min="0" step="10" class="kt-input" wire:model.live="dailyCap">
                            <span class="text-xs text-muted-foreground mt-1">{{ $usage['today'] }} used today</span>
                        </div>
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="provider-hourly">Hourly cap</label>
                            <input type="number" id="provider-hourly" min="0" step="10" class="kt-input" wire:model.live="hourlyCap">
                            <span class="text-xs text-muted-foreground mt-1">{{ $usage['thisHour'] }} used this hour</span>
                        </div>
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="provider-priority">Priority</label>
                            <input type="number" id="provider-priority" min="1" max="10" class="kt-input" wire:model.live="priority">
                            <span class="text-xs text-muted-foreground mt-1">1 is tried first.</span>
                        </div>
                    </div>

                    @if ($hourlyCap > 0 && $dailyCap > 0 && $hourlyCap * 24 < $dailyCap)
                        <div class="rounded-lg border border-warning/30 bg-warning/5 p-3 text-xs text-secondary-foreground">
                            The hourly cap limits this provider to {{ number_format($hourlyCap * 24) }} a day, so the daily
                            cap of {{ number_format($dailyCap) }} can never be reached.
                        </div>
                    @endif

                    <div class="flex flex-col gap-2 border-t border-border pt-5">
                        <span class="kt-form-label">Stream</span>
                        @foreach ($streams as $key => $s)
                            <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                                {{ $stream === $key ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                                <input type="radio" class="kt-radio mt-0.5" name="stream" value="{{ $key }}" wire:model.live="stream">
                                <span>
                                    <span class="block text-sm font-medium text-mono">{{ $s['label'] }}</span>
                                    <span class="block text-xs text-muted-foreground mt-1">{{ $s['note'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <label class="flex items-center gap-3 border-t border-border pt-5 cursor-pointer">
                        <input type="checkbox" class="kt-switch" wire:model.live="enabled">
                        <span class="text-sm text-secondary-foreground">
                            Enabled — the router may pick this provider
                        </span>
                    </label>
                </div>
            </div>

            {{-- DNS --}}
            <div class="kt-card">
                <div class="kt-card-header flex-wrap gap-3">
                    <div>
                        <h3 class="kt-card-title">DNS records</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">Add these at your registrar, then re-check.</p>
                    </div>
                    <button class="kt-btn kt-btn-sm kt-btn-outline gap-1.5" wire:click="verifyDns"
                            wire:loading.attr="disabled" wire:target="verifyDns">
                        <span wire:loading.remove wire:target="verifyDns" class="inline-flex items-center gap-1.5">
                            <i class="ki-filled ki-arrows-circle text-sm"></i> Re-check
                        </span>
                        <span wire:loading wire:target="verifyDns" class="inline-flex items-center gap-1.5">
                            <i class="ki-filled ki-loading animate-spin"></i> Resolving…
                        </span>
                    </button>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($dns as $record)
                        <div class="px-5 py-4 flex flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $record['type'] }}</span>
                                <span class="text-sm font-medium text-mono">{{ $record['purpose'] }}</span>
                                <span class="kt-badge kt-badge-sm {{ $dnsBadge[$record['status']]['class'] }}">
                                    {{ $dnsBadge[$record['status']]['label'] }}
                                </span>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-2">
                                <div class="flex items-center gap-2 rounded-lg bg-muted/50 border border-border px-3 py-2 min-w-0">
                                    <code class="text-xs text-secondary-foreground truncate grow" data-copy-source>{{ $record['host'] }}</code>
                                    <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0 js-copy"
                                            data-copy="{{ $record['host'] }}" title="Copy host" aria-label="Copy host">
                                        <i class="ki-filled ki-copy text-xs"></i>
                                    </button>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-muted/50 border border-border px-3 py-2 min-w-0">
                                    <code class="text-xs text-secondary-foreground truncate grow">{{ $record['value'] }}</code>
                                    <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0 js-copy"
                                            data-copy="{{ $record['value'] }}" title="Copy value" aria-label="Copy value">
                                        <i class="ki-filled ki-copy text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <p class="text-xs text-muted-foreground leading-relaxed">{{ $record['note'] }}</p>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center text-center py-12">
                            <i class="ki-filled ki-cloud-change text-4xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">This driver needs no DNS changes.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Side column --}}
        <div class="flex flex-col gap-5 xl:sticky xl:top-24">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Test connection</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <button class="kt-btn kt-btn-outline w-full justify-center gap-2"
                            wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                        <span wire:loading.remove wire:target="testConnection" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-router"></i> Run test
                        </span>
                        <span wire:loading wire:target="testConnection" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Contacting {{ \Illuminate\Support\Str::before($drivers[$driver] ?? 'provider', ' —') }}…
                        </span>
                    </button>

                    <div wire:loading.remove wire:target="testConnection"
                         class="rounded-lg border p-4 {{ $lastTest['outcome'] === 'pass' ? 'border-success/30 bg-success/5' : 'border-destructive/30 bg-destructive/5' }}">
                        <div class="flex items-center gap-2">
                            <i class="ki-filled {{ $lastTest['outcome'] === 'pass' ? 'ki-check-circle text-success' : 'ki-cross-circle text-destructive' }}"></i>
                            <span class="text-sm font-medium text-mono">
                                {{ $lastTest['outcome'] === 'pass' ? 'Connection healthy' : 'Connection failed' }}
                            </span>
                        </div>
                        <p class="text-xs text-secondary-foreground mt-2 leading-relaxed">{{ $lastTest['message'] }}</p>
                        <p class="text-xs text-muted-foreground mt-2">
                            Last run {{ $lastTest['when'] }} · {{ $lastTest['latency'] }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Quota today</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-secondary-foreground">Used</span>
                        <span class="text-mono font-medium">{{ $usage['today'] }} / {{ $dailyCap }}</span>
                    </div>
                    <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                        <div class="h-full bg-primary rounded-full"
                             style="width: {{ $dailyCap > 0 ? min(100, ($usage['today'] / $dailyCap) * 100) : 0 }}%"></div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Counters reset at midnight Europe/London. Anything over the cap waits for the next window.
                    </p>
                </div>
            </div>

            <div class="kt-card border-destructive/30">
                <div class="kt-card-header"><h3 class="kt-card-title text-destructive">Remove provider</h3></div>
                <div class="kt-card-content p-5">
                    <p class="text-xs text-secondary-foreground leading-relaxed">
                        Removing a provider does not clear the suppression entries it reported. Those stay, because the
                        bounce happened whichever account carried the message.
                    </p>
                    <button class="kt-btn kt-btn-outline w-full justify-center gap-2 mt-4 text-destructive border-destructive/30">
                        <i class="ki-filled ki-trash"></i> Remove {{ \Illuminate\Support\Str::before($drivers[$driver] ?? 'provider', ' —') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
{{--
    Kept inside the component's root element on purpose. Livewire renders one
    root node and discards everything after it, so a @push below the closing tag
    never reaches the page.
--}}
@script
<script>
(function () {
    function mount() {
        document.querySelectorAll('.js-copy').forEach(function (el) {
            if (el.dataset.copyBound) return;
            el.dataset.copyBound = '1';

            el.addEventListener('click', function () {
                var value = el.getAttribute('data-copy') || '';
                var icon = el.querySelector('i');

                function flash() {
                    if (!icon) return;
                    icon.classList.remove('ki-copy');
                    icon.classList.add('ki-check');
                    setTimeout(function () {
                        icon.classList.remove('ki-check');
                        icon.classList.add('ki-copy');
                    }, 1200);
                }

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(value).then(flash);
                    return;
                }

                var field = document.createElement('textarea');
                field.value = value;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                try { document.execCommand('copy'); flash(); } catch (e) { /* clipboard unavailable */ }
                document.body.removeChild(field);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', mount);
    document.addEventListener('livewire:navigated', mount);
    if (window.Livewire) Livewire.hook('morph.updated', mount);
    mount();
})();
</script>
@endscript
</div>
