<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Services\Delivery\Delivery;
use Modules\Mailbox\Services\Delivery\PreFlight;
use Modules\Mailbox\Support\Senders;

/**
 * Provider configuration.
 *
 * Every provider carries its own credentials, its own caps and its own DNS
 * records. The records matter more than the caps: an SPF record that pushes the
 * domain past ten DNS lookups fails permerror, and a permerror is treated as no
 * SPF at all by most receivers — which is why the pre-flight refuses to send
 * until SPF and DKIM are both signed off here.
 *
 * **Secrets are never rendered back into this page.** The credential form
 * starts blank whatever is stored, and a blank secret on save means 'leave what
 * is there' rather than 'clear it'. That is the only way a settings page can be
 * safe to open: a value printed into the DOM is a value in the browser's
 * history, in a screenshot and in a Livewire payload.
 */
new
#[Title('Provider settings — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The route parameter: an id, or a driver name for a provider not created yet. */
    public string $provider = '';

    public ?int $providerId = null;

    public string $driver = Senders::BREVO;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('nullable|string|max:190')]
    public string $sendingDomain = '';

    #[Validate('nullable|email|max:190')]
    public string $fromEmail = '';

    #[Validate('nullable|string|max:190')]
    public string $fromName = '';

    /** @var array<string, string> Blank means 'leave the stored value alone'. */
    public array $credentials = [];

    #[Validate('integer|min:0|max:1000000')]
    public int $dailyCap = 0;

    #[Validate('integer|min:0|max:1000000')]
    public int $hourlyCap = 0;

    #[Validate('integer|min:1|max:255')]
    public int $priority = 10;

    public bool $enabled = false;

    public bool $spfVerified = false;

    public bool $dkimVerified = false;

    public bool $revealSecrets = false;

    public function mount(string $provider = ''): void
    {
        $this->provider = $provider;

        $record = $this->resolve($provider);

        if ($record === null) {
            // A driver name that has no row yet — which is exactly what a link
            // from somewhere that pre-dates the provider looks like. The page
            // offers to create it rather than showing a 404.
            $this->driver = Senders::has($provider) ? $provider : Senders::BREVO;
            $this->name = Senders::label($this->driver);

            return;
        }

        $this->fill([
            'providerId' => $record->id,
            'driver' => $record->driver,
            'name' => (string) $record->name,
            'sendingDomain' => (string) $record->sending_domain,
            'fromEmail' => (string) $record->from_email,
            'fromName' => (string) $record->from_name,
            'dailyCap' => $record->daily_quota,
            'hourlyCap' => $record->hourly_quota,
            'priority' => $record->priority,
            'enabled' => $record->is_active,
            'spfVerified' => $record->spf_verified,
            'dkimVerified' => $record->dkim_verified,
        ]);
    }

    /** The row this page edits, by id or by driver name, or null when there is none. */
    private function resolve(string $key): ?DeliveryProvider
    {
        if ($key === '') {
            return null;
        }

        return ctype_digit($key)
            ? DeliveryProvider::query()->find((int) $key)
            : DeliveryProvider::query()->where('driver', $key)->orderBy('id')->first();
    }

    private function record(): ?DeliveryProvider
    {
        return $this->providerId === null ? null : DeliveryProvider::query()->find($this->providerId);
    }

    public function with(): array
    {
        $record = $this->record();
        $meta = Senders::get($this->driver) ?? Senders::get(Senders::BREVO);

        return [
            'record' => $record,
            'meta' => $meta,
            'drivers' => collect(Senders::all())->map(fn (array $m): string => $m['label'].' — '.$m['summary'])->all(),
            'fields' => $meta['credentials'],
            // Which fields already have something stored. The value is never
            // read — only whether there is one — because a secret rendered back
            // into the page is a secret in the browser's history.
            'stored' => collect(Senders::credentialFields($this->driver))
                ->mapWithKeys(fn (string $f): array => [$f => $record?->credential($f) !== null])
                ->all(),
            'problems' => $record === null ? [] : app(PreFlight::class)->providerProblems($record),
            'usage' => [
                'today' => $record?->sent_today ?? 0,
                'thisHour' => $record?->sent_this_hour ?? 0,
            ],
            'webhookUrl' => $record === null ? null : route('mail.webhook', $record),
            'handlesWebhooks' => app(Delivery::class)->webhookHandlerFor($this->driver) !== null,
            'dns' => $this->dnsRecords(),
        ];
    }

    /**
     * The records this provider needs on the sending domain.
     *
     * Written out rather than fetched from the provider's API, because every
     * one of the five publishes them as documentation and none of them exposes
     * them as data an unauthenticated call can read. The host names are derived
     * from what is typed above, so they are copyable the moment the domain is
     * filled in rather than after a save.
     *
     * @return list<array{purpose: string, type: string, host: string, value: string, note: string}>
     */
    private function dnsRecords(): array
    {
        $domain = $this->sendingDomain !== '' ? $this->sendingDomain : 'your-domain.example';

        $spf = match ($this->driver) {
            Senders::BREVO => 'v=spf1 include:spf.brevo.com -all',
            Senders::POSTMARK => 'v=spf1 include:spf.mtasv.net -all',
            Senders::SES => 'v=spf1 include:amazonses.com -all',
            Senders::MAILGUN => 'v=spf1 include:mailgun.org -all',
            default => 'v=spf1 a mx -all',
        };

        return [
            [
                'purpose' => 'SPF — authorises the provider to send for this domain',
                'type' => 'TXT',
                'host' => $domain,
                'value' => $spf,
                'note' => 'One include per provider, and SPF allows ten DNS lookups in total. A fourth provider on the '
                    .'same domain is where a permerror starts, and a permerror counts as no SPF at all.',
            ],
            [
                'purpose' => 'DKIM — signs each message so receivers can verify it',
                'type' => 'CNAME',
                'host' => 'mail._domainkey.'.$domain,
                'value' => 'the value '.Senders::label($this->driver).' shows when you add this domain',
                'note' => 'Keep it as a CNAME rather than a TXT copy, so the provider can rotate the key without '
                    .'anything changing here.',
            ],
            [
                'purpose' => 'Return-Path — aligns the bounce address with the From domain',
                'type' => 'CNAME',
                'host' => 'bounce.'.$domain,
                'value' => 'the bounce host '.Senders::label($this->driver).' gives you',
                'note' => 'Without it SPF authenticates the provider\'s own domain and DMARC alignment fails, which '
                    .'is the commonest reason a technically correct setup still goes to spam.',
            ],
            [
                'purpose' => 'DMARC — tells receivers what to do when checks fail',
                'type' => 'TXT',
                'host' => '_dmarc.'.$domain,
                'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domain,
                'note' => 'p=none only reports. Move to p=quarantine once the aggregate reports come back clean.',
            ],
        ];
    }

    /** Create the row for a driver that has none yet. */
    public function create(): void
    {
        if (! Senders::has($this->driver)) {
            $this->toastError('Unknown provider', 'Kargah has no driver called '.$this->driver.'.');

            return;
        }

        $record = DeliveryProvider::query()->create([
            'name' => $this->name !== '' ? $this->name : Senders::label($this->driver),
            'driver' => $this->driver,
            'is_active' => false,
            'priority' => (int) (DeliveryProvider::query()->max('priority') ?? 0) + 1,
        ]);

        $this->providerId = $record->id;
        $this->enabled = false;

        $this->toastSuccess(
            Senders::label($this->driver).' added',
            'It stays switched off until its credentials and sending domain are filled in.',
        );
    }

    /**
     * Save what was typed, and only what was typed.
     *
     * A blank credential field leaves the stored value alone, which is the only
     * behaviour compatible with never rendering a secret back — a form that
     * starts blank and saves blank would wipe the key every time somebody
     * changed the daily cap.
     */
    public function save(): void
    {
        $this->validate();

        $record = $this->record();

        if ($record === null) {
            $this->toastError('Nothing to save', 'This provider has not been created yet.');

            return;
        }

        $credentials = $record->credentials;

        foreach (Senders::credentialFields($this->driver) as $field) {
            $given = trim((string) ($this->credentials[$field] ?? ''));

            if ($given !== '') {
                $credentials[$field] = $given;
            }
        }

        $record->fill([
            'name' => $this->name,
            'sending_domain' => $this->sendingDomain ?: null,
            'from_email' => $this->fromEmail ?: null,
            'from_name' => $this->fromName ?: null,
            'credentials' => $credentials,
            'daily_quota' => $this->dailyCap,
            'hourly_quota' => $this->hourlyCap,
            'priority' => $this->priority,
            'is_active' => $this->enabled,
            'spf_verified' => $this->spfVerified,
            'dkim_verified' => $this->dkimVerified,
        ])->save();

        $this->credentials = [];

        $missing = $record->missingCredentials();

        if ($missing !== []) {
            $this->toastWarning(
                $record->label().' saved, but it cannot send yet',
                implode(' and ', $missing).' '.(count($missing) === 1 ? 'is' : 'are').' still missing.',
            );

            return;
        }

        $this->toastSuccess(
            $record->label().' saved',
            $this->enabled
                ? 'The router may pick it for the next chunk.'
                : 'It is switched off, so the router will not pick it.',
        );
    }

    /**
     * Say whether this provider could send right now.
     *
     * Deliberately **not** a call to the provider. A page render, or an action
     * behind one, must never wait on somebody else's API — that is the rule in
     * project-guaid/spec/01-architecture.md and it is why nothing in Kargah
     * fetches during a request. What is checked here is everything Kargah can
     * know without leaving the process: the bridge package, the credentials,
     * the from address, the DNS sign-off. That is also every reason a send has
     * ever failed on this machine, because there are no credentials on it to
     * fail any other way.
     */
    public function testConnection(): void
    {
        $record = $this->record();

        if ($record === null) {
            $this->toastError('Nothing to test', 'This provider has not been created yet.');

            return;
        }

        $driver = app(Delivery::class)->driverFor($record->driver);

        if ($driver === null) {
            $this->toastError(
                'No driver',
                'Kargah has no implementation for '.$record->driver.', so nothing can be sent through it.',
            );

            return;
        }

        if ($reason = $driver->unavailableReason($record)) {
            $this->toastError($record->label().' cannot send', $reason);

            return;
        }

        $problems = app(PreFlight::class)->providerProblems($record);

        if ($problems !== []) {
            $this->toastWarning(
                $record->label().' can send, but not a campaign',
                $problems[0].($this->countExtra($problems)),
            );

            return;
        }

        $remaining = $record->rollQuotaWindow()->remainingQuota();

        $this->toastSuccess(
            $record->label().' is ready',
            'Credentials, from address and DNS are all in place. '
            .($remaining === PHP_INT_MAX ? 'No quota is set, so nothing is throttled.' : $remaining.' left in the current window.'),
        );
    }

    /**
     * Resolve the domain's SPF and DKIM and record what was found.
     *
     * A DNS lookup rather than a guess, because 'verified' is what the
     * pre-flight refuses on and a checkbox somebody ticked to get past it is
     * worth nothing. It is a user action rather than part of a render, and it
     * resolves against the local resolver, so it is not the API dependency the
     * architecture rules out.
     *
     * A lookup that fails leaves the flags as they were. An unreachable
     * resolver must not read as 'the records are gone'.
     */
    public function verifyDns(): void
    {
        $record = $this->record();

        if ($record === null || $this->sendingDomain === '') {
            $this->toastError('Nothing to check', 'Fill in the sending domain first.');

            return;
        }

        $spf = $this->hasSpf($this->sendingDomain);
        $dkim = $this->hasDkim($this->sendingDomain);

        if ($spf === null || $dkim === null) {
            $this->toastError(
                'The lookup did not answer',
                'The resolver did not respond for '.$this->sendingDomain.'. Nothing was changed.',
            );

            return;
        }

        $this->spfVerified = $spf;
        $this->dkimVerified = $dkim;

        $record->forceFill([
            'spf_verified' => $spf,
            'dkim_verified' => $dkim,
            'dns_checked_at' => now(),
        ])->save();

        if ($spf && $dkim) {
            $this->toastSuccess('SPF and DKIM are both in place', $this->sendingDomain.' passes the pre-flight.');

            return;
        }

        $this->toastWarning(
            'The domain is not fully signed off',
            ($spf ? '' : 'No SPF record names this provider. ').($dkim ? '' : 'No DKIM key was found at mail._domainkey.'.$this->sendingDomain.'.'),
        );
    }

    /** True, false, or null when the resolver did not answer at all. */
    private function hasSpf(string $domain): ?bool
    {
        $records = @dns_get_record($domain, DNS_TXT);

        if ($records === false) {
            return null;
        }

        foreach ($records as $entry) {
            if (str_starts_with(mb_strtolower((string) ($entry['txt'] ?? '')), 'v=spf1')) {
                return true;
            }
        }

        return false;
    }

    /** True, false, or null when the resolver did not answer at all. */
    private function hasDkim(string $domain): ?bool
    {
        $records = @dns_get_record('mail._domainkey.'.$domain, DNS_CNAME + DNS_TXT);

        return $records === false ? null : $records !== [];
    }

    /** Delete the provider without touching what it reported. */
    public function remove(): void
    {
        $record = $this->record();

        if ($record === null) {
            return;
        }

        $label = $record->label();

        // Soft deleted, so the campaign reports that name it as the carrier
        // keep working — `providerBreakdown()` reads trashed rows for exactly
        // this reason.
        $record->delete();

        $this->flashToast(
            'success',
            $label.' removed',
            'The suppression entries it reported stay, because the bounce happened whichever account carried the message.',
        );

        $this->redirect(route('mail.providers'), navigate: true);
    }

    /** @param  list<string>  $problems */
    private function countExtra(array $problems): string
    {
        return count($problems) > 1 ? ' ('.(count($problems) - 1).' more)' : '';
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-mono">{{ $meta['label'] }}</h1>
                @if ($record)
                    <span class="kt-badge kt-badge-sm {{ $enabled ? 'kt-badge-success' : 'kt-badge-outline' }}">
                        {{ $enabled ? 'Enabled' : 'Switched off' }}
                    </span>
                @else
                    <span class="kt-badge kt-badge-sm kt-badge-outline">Not added yet</span>
                @endif
            </div>
            <p class="text-sm text-secondary-foreground mt-1">Credentials, caps and the DNS this provider needs on your domain.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('mail.providers') }}" class="kt-btn kt-btn-ghost gap-2">
                <i class="ki-filled ki-arrow-left"></i> Providers
            </a>
            @if ($record)
                <button class="kt-btn kt-btn-primary gap-2" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-check"></i> Save changes
                    </span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
            @else
                <button class="kt-btn kt-btn-primary gap-2" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                    <span wire:loading.remove wire:target="create" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-plus"></i> Add {{ $meta['label'] }}
                    </span>
                    <span wire:loading wire:target="create" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Adding…
                    </span>
                </button>
            @endif
        </div>
    </div>

    @if (! $record)
        <div class="kt-card bg-muted/40">
            <div class="kt-card-content flex items-start gap-3 p-4">
                <i class="ki-filled ki-information-2 text-muted-foreground text-lg mt-0.5 shrink-0"></i>
                <div class="text-sm text-secondary-foreground">
                    <strong class="text-mono">{{ $meta['label'] }} is not set up on this install.</strong>
                    {{ $meta['requirement'] }}
                </div>
            </div>
        </div>
    @elseif ($problems !== [])
        <div class="kt-card bg-warning/5 border-warning/30">
            <div class="kt-card-content flex items-start gap-3 p-4">
                <i class="ki-filled ki-information-2 text-warning text-lg mt-0.5 shrink-0"></i>
                <div class="text-sm text-secondary-foreground">
                    <strong class="text-mono">A campaign cannot go out through this provider yet.</strong>
                    <ul class="list-disc ps-5 mt-2 flex flex-col gap-1">
                        @foreach ($problems as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

        <div class="xl:col-span-2 flex flex-col gap-5">

            {{-- Connection --}}
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Connection</h3></div>
                <div class="kt-card-content p-5 grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-driver">Driver</label>
                        <select id="provider-driver" class="kt-select" wire:model.live="driver" @disabled($record !== null)>
                            @foreach ($drivers as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-muted-foreground mt-1">
                            {{ $record ? 'Fixed once the provider exists. Add a second provider rather than changing this one.' : 'Pick before adding; the credential fields follow it.' }}
                        </span>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-name">Name</label>
                        <input type="text" id="provider-name" class="kt-input @error('name') border-destructive @enderror" wire:model="name">
                        @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        <span class="text-xs text-muted-foreground mt-1">How it reads on the campaign report.</span>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-domain">Sending domain</label>
                        <input type="text" id="provider-domain" placeholder="news.example.com"
                               class="kt-input @error('sendingDomain') border-destructive @enderror" wire:model.live.debounce.500ms="sendingDomain">
                        @error('sendingDomain')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        <span class="text-xs text-muted-foreground mt-1">A subdomain of its own, so a campaign cannot damage the invoices.</span>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-from">From address</label>
                        <input type="email" id="provider-from" placeholder="you@news.example.com"
                               class="kt-input @error('fromEmail') border-destructive @enderror" wire:model="fromEmail">
                        @error('fromEmail')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        <span class="text-xs text-muted-foreground mt-1">Replies come back to this mailbox, with a token in the local part.</span>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="provider-from-name">From name</label>
                        <input type="text" id="provider-from-name" class="kt-input" wire:model="fromName">
                    </div>
                </div>
            </div>

            {{-- Credentials --}}
            <div class="kt-card">
                <div class="kt-card-header flex-wrap gap-3">
                    <div>
                        <h3 class="kt-card-title">Credentials</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">Stored encrypted. They are never rendered back into this page.</p>
                    </div>
                    <button class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5" wire:click="$toggle('revealSecrets')"
                            title="{{ $revealSecrets ? 'Hide what you are typing' : 'Show what you are typing' }}">
                        <i class="ki-filled {{ $revealSecrets ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                        {{ $revealSecrets ? 'Hide' : 'Show' }}
                    </button>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <p class="text-xs text-secondary-foreground leading-relaxed">{{ $meta['requirement'] }}</p>

                    @foreach ($fields as $field => $spec)
                        <div class="flex flex-col" wire:key="cred-{{ $driver }}-{{ $field }}">
                            <label class="kt-form-label" for="cred-{{ $field }}">
                                {{ $spec['label'] }}
                                @if (($stored[$field] ?? false))
                                    <span class="kt-badge kt-badge-sm kt-badge-success ms-2">Set</span>
                                @endif
                            </label>
                            <input id="cred-{{ $field }}"
                                   type="{{ $spec['secret'] && ! $revealSecrets ? 'password' : 'text' }}"
                                   class="kt-input {{ $spec['secret'] ? 'font-mono text-xs' : '' }}"
                                   placeholder="{{ ($stored[$field] ?? false) ? 'Leave blank to keep what is stored' : $spec['placeholder'] }}"
                                   autocomplete="off" spellcheck="false"
                                   wire:model="credentials.{{ $field }}">
                            <span class="text-xs text-muted-foreground mt-1">{{ $spec['hint'] }}</span>
                        </div>
                    @endforeach
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
                            <span class="text-xs text-muted-foreground mt-1">{{ $usage['today'] }} used today · 0 means unmetered</span>
                        </div>
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="provider-hourly">Hourly cap</label>
                            <input type="number" id="provider-hourly" min="0" step="10" class="kt-input" wire:model.live="hourlyCap">
                            <span class="text-xs text-muted-foreground mt-1">{{ $usage['thisHour'] }} used this hour</span>
                        </div>
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="provider-priority">Priority</label>
                            <input type="number" id="provider-priority" min="1" max="255" class="kt-input" wire:model.live="priority">
                            <span class="text-xs text-muted-foreground mt-1">1 is tried first, once health is equal.</span>
                        </div>
                    </div>

                    @if ($hourlyCap > 0 && $dailyCap > 0 && $hourlyCap * 24 < $dailyCap)
                        <div class="rounded-lg border border-warning/30 bg-warning/5 p-3 text-xs text-secondary-foreground">
                            The hourly cap limits this provider to {{ number_format($hourlyCap * 24) }} a day, so the daily
                            cap of {{ number_format($dailyCap) }} can never be reached.
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 border-t border-border pt-5">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" class="kt-switch" wire:model.live="spfVerified">
                            <span class="text-sm text-secondary-foreground">SPF is in place for this domain</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" class="kt-switch" wire:model.live="dkimVerified">
                            <span class="text-sm text-secondary-foreground">DKIM is in place for this domain</span>
                        </label>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            The pre-flight refuses to start a campaign without both. Re-check below rather than ticking
                            these by hand — a tick that gets past the pre-flight without the record behind it only moves
                            the failure to the receiving server.
                        </p>
                    </div>

                    <label class="flex items-center gap-3 border-t border-border pt-5 cursor-pointer">
                        <input type="checkbox" class="kt-switch" wire:model.live="enabled">
                        <span class="text-sm text-secondary-foreground">
                            Enabled — the router may pick this provider
                        </span>
                    </label>
                </div>
            </div>

            {{-- Webhook --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <div>
                        <h3 class="kt-card-title">Bounce and complaint callbacks</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">Where {{ $meta['label'] }} tells Kargah an address is dead.</p>
                    </div>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    @if (! $handlesWebhooks)
                        <p class="text-sm text-secondary-foreground">Kargah has no callback handler for this driver.</p>
                    @elseif (! $webhookUrl)
                        <p class="text-sm text-secondary-foreground">The callback URL appears once the provider is added.</p>
                    @else
                        <div class="flex items-center gap-2 rounded-lg bg-muted/50 border border-border px-3 py-2 min-w-0">
                            <code class="text-xs text-secondary-foreground truncate grow">{{ $webhookUrl }}</code>
                            <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0 js-copy"
                                    data-copy="{{ $webhookUrl }}" title="Copy callback URL" aria-label="Copy callback URL">
                                <i class="ki-filled ki-copy text-xs"></i>
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">{{ $meta['webhook']['hint'] }}</p>
                        @if ($meta['webhook']['signature'] === null)
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Append <code>?token=</code> and the webhook secret you set above. Kargah refuses a
                                callback without it, because a callback that writes to the suppression list can silence
                                any address on the system.
                            </p>
                        @endif
                    @endif
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
                    @foreach ($dns as $rec)
                        <div class="px-5 py-4 flex flex-col gap-2" wire:key="dns-{{ $loop->index }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $rec['type'] }}</span>
                                <span class="text-sm font-medium text-mono">{{ $rec['purpose'] }}</span>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-2">
                                <div class="flex items-center gap-2 rounded-lg bg-muted/50 border border-border px-3 py-2 min-w-0">
                                    <code class="text-xs text-secondary-foreground truncate grow">{{ $rec['host'] }}</code>
                                    <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0 js-copy"
                                            data-copy="{{ $rec['host'] }}" title="Copy host" aria-label="Copy host">
                                        <i class="ki-filled ki-copy text-xs"></i>
                                    </button>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg bg-muted/50 border border-border px-3 py-2 min-w-0">
                                    <code class="text-xs text-secondary-foreground truncate grow">{{ $rec['value'] }}</code>
                                    <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0 js-copy"
                                            data-copy="{{ $rec['value'] }}" title="Copy value" aria-label="Copy value">
                                        <i class="ki-filled ki-copy text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <p class="text-xs text-muted-foreground leading-relaxed">{{ $rec['note'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Side column --}}
        <div class="flex flex-col gap-5 xl:sticky xl:top-24">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Check this provider</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <button class="kt-btn kt-btn-outline w-full justify-center gap-2"
                            wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                        <span wire:loading.remove wire:target="testConnection" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-router"></i> Run the checks
                        </span>
                        <span wire:loading wire:target="testConnection" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Checking…
                        </span>
                    </button>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Kargah never contacts a provider from a page. This checks the bridge package, the credentials,
                        the from address and the DNS sign-off — which is every reason a send has ever been refused
                        before it left the process.
                    </p>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Quota today</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-secondary-foreground">Used</span>
                        <span class="text-mono font-medium">{{ $usage['today'] }} / {{ $dailyCap > 0 ? $dailyCap : 'unmetered' }}</span>
                    </div>
                    <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                        <div class="h-full bg-primary rounded-full"
                             style="width: {{ $dailyCap > 0 ? min(100, ($usage['today'] / $dailyCap) * 100) : 0 }}%"></div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        The window rolls when the first message of a new day or hour is routed, so a site whose cron
                        stopped for an afternoon does not come back believing it has spent the day.
                    </p>
                </div>
            </div>

            @if ($record)
                <div class="kt-card border-destructive/30">
                    <div class="kt-card-header"><h3 class="kt-card-title text-destructive">Remove provider</h3></div>
                    <div class="kt-card-content p-5">
                        <p class="text-xs text-secondary-foreground leading-relaxed">
                            Removing a provider does not clear the suppression entries it reported. Those stay, because
                            the bounce happened whichever account carried the message. Campaign reports keep naming it.
                        </p>
                        <button class="kt-btn kt-btn-outline w-full justify-center gap-2 mt-4 text-destructive border-destructive/30"
                                wire:click="remove" wire:confirm="Remove {{ $record->label() }}? Campaigns it carried will still name it.">
                            <i class="ki-filled ki-trash"></i> Remove {{ $record->label() }}
                        </button>
                    </div>
                </div>
            @endif
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
    // A WeakSet rather than a data attribute. Livewire's morph strips any
    // attribute the incoming HTML does not carry, so a `data-bound` flag clears
    // itself on every render and leaves a second listener on the same button —
    // see docs/frontend-conventions.md.
    var bound = new WeakSet();

    function mount() {
        if (! $wire.$el || ! $wire.$el.isConnected) return;

        $wire.$el.querySelectorAll('.js-copy').forEach(function (el) {
            if (bound.has(el)) return;
            bound.add(el);

            el.addEventListener('click', function () {
                var value = el.getAttribute('data-copy') || '';
                var icon = el.querySelector('i');

                function flash() {
                    if (! icon) return;
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

    Livewire.hook('morphed', mount);
    mount();
})();
</script>
@endscript
</div>
