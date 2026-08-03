<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Services\Delivery\PreFlight;
use Modules\Mailbox\Support\Senders;

/**
 * Delivery providers.
 *
 * Sending never goes through the host's own mail server. Each provider is a
 * driver with its own daily quota, health score and sending domain, and the
 * router picks one per message — by remaining quota, then health, then the
 * priority set here.
 */
new
#[Title('Providers — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Which driver the 'add provider' menu is offering, or null when it is shut. */
    public bool $adding = false;

    public function with(): array
    {
        $preFlight = app(PreFlight::class);

        $providers = DeliveryProvider::query()
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            // The window is rolled on read as well as on send, so a page opened
            // the morning after a campaign shows today's allowance rather than
            // yesterday's spent one.
            ->each(fn (DeliveryProvider $p) => $p->rollQuotaWindow());

        return [
            'providers' => $providers,
            'problems' => $providers->mapWithKeys(
                fn (DeliveryProvider $p): array => [$p->id => $preFlight->providerProblems($p)],
            ),
            // Only drivers that are not already set up. Two Brevo accounts is a
            // real setup, but offering it as the first suggestion is not.
            'available' => collect(Senders::all())
                ->reject(fn (array $meta, string $driver): bool => $providers->contains('driver', $driver))
                ->all(),
        ];
    }

    public function startAdding(): void
    {
        $this->adding = true;
    }

    public function cancelAdding(): void
    {
        $this->adding = false;
    }

    /**
     * Create the row and go straight to its settings.
     *
     * A provider is useless until it has credentials, so there is no 'created'
     * state worth staying on this page for. The row is written first because
     * the credentials form needs something to attach to, and it is created
     * switched off so a half-configured provider cannot be picked by the router
     * in the minute between here and the save.
     */
    public function add(string $driver): void
    {
        if (! Senders::has($driver)) {
            $this->toastError('Unknown provider', 'Kargah has no driver called '.$driver.'.');

            return;
        }

        $provider = DeliveryProvider::query()->create([
            'name' => Senders::label($driver),
            'driver' => $driver,
            'is_active' => false,
            'priority' => (int) (DeliveryProvider::query()->max('priority') ?? 0) + 1,
        ]);

        $this->adding = false;

        $this->flashToast(
            'success',
            Senders::label($driver).' added',
            'It is switched off until its credentials and sending domain are filled in.',
        );

        $this->redirect(route('mail.provider-edit', $provider->id), navigate: true);
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Delivery providers</h1>
            <p class="text-sm text-secondary-foreground mt-1">Sending is routed across these, never through the host.</p>
        </div>
        <button class="kt-btn kt-btn-primary gap-2" wire:click="startAdding">
            <i class="ki-filled ki-plus"></i> Add provider
        </button>
    </div>

    <div class="kt-card bg-warning/5 border-warning/30">
        <div class="kt-card-content flex items-start gap-3 p-4">
            <i class="ki-filled ki-information-2 text-warning text-lg mt-0.5 shrink-0"></i>
            <div class="text-sm text-secondary-foreground">
                <strong class="text-mono">Keep SPF under 10 DNS lookups.</strong>
                Each provider you add to a single sending domain costs 1–3 lookups. Give marketing and
                transactional traffic separate subdomains so one campaign cannot damage the other's reputation.
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        @forelse ($providers as $p)
            @php $quota = $p->daily_quota; @endphp
            <div class="kt-card" wire:key="provider-{{ $p->id }}">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled {{ $p->icon() }} text-xl"></i>
                            </span>
                            <div>
                                <div class="font-semibold text-mono">{{ $p->label() }}</div>
                                <div class="text-xs text-muted-foreground">Priority {{ $p->priority }}</div>
                            </div>
                        </div>
                        @if (! $p->is_active)
                            <span class="kt-badge kt-badge-sm kt-badge-outline">Switched off</span>
                        @elseif ($problems[$p->id] === [])
                            <span class="kt-badge kt-badge-sm kt-badge-success">Ready</span>
                        @else
                            <span class="kt-badge kt-badge-sm kt-badge-warning">Needs setting up</span>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 text-sm">
                        <i class="ki-filled ki-map text-muted-foreground text-base"></i>
                        <span class="text-secondary-foreground truncate">{{ $p->sending_domain ?: '—' }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="kt-badge kt-badge-sm {{ $p->spf_verified ? 'kt-badge-success' : 'kt-badge-destructive' }}">
                            SPF {{ $p->spf_verified ? 'verified' : 'missing' }}
                        </span>
                        <span class="kt-badge kt-badge-sm {{ $p->dkim_verified ? 'kt-badge-success' : 'kt-badge-destructive' }}">
                            DKIM {{ $p->dkim_verified ? 'verified' : 'missing' }}
                        </span>
                    </div>

                    <div>
                        <div class="flex items-center justify-between text-xs mb-1.5">
                            <span class="text-muted-foreground">Daily quota</span>
                            <span class="text-mono font-medium">
                                {{ $p->sent_today }} / {{ $quota > 0 ? $quota : 'unmetered' }}
                            </span>
                        </div>
                        <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                            <div class="h-full bg-primary rounded-full"
                                 style="width: {{ $quota > 0 ? min(100, $p->sent_today / $quota * 100) : 0 }}%"></div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <span class="text-xs text-muted-foreground">
                            Health · {{ $p->bounce_count }} {{ str('bounce')->plural($p->bounce_count) }},
                            {{ $p->complaint_count }} {{ str('complaint')->plural($p->complaint_count) }}
                        </span>
                        <span class="text-sm font-medium {{ $p->health_score >= 80 ? 'text-success' : 'text-warning' }}">{{ $p->health_score }}%</span>
                    </div>

                    @if ($problems[$p->id] !== [])
                        <p class="text-xs text-warning leading-relaxed">{{ $problems[$p->id][0] }}</p>
                    @endif

                    <a href="{{ route('mail.provider-edit', $p->id) }}" class="kt-btn kt-btn-outline w-full justify-center gap-2">
                        <i class="ki-filled ki-setting-2"></i> Configure
                    </a>

                </div>
            </div>
        @empty
            <div class="lg:col-span-3 kt-card">
                <div class="kt-card-content flex flex-col items-center justify-center text-center py-14">
                    <i class="ki-filled ki-router text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">
                        No delivery provider is set up, so nothing can be sent yet.
                    </p>
                    <button class="kt-btn kt-btn-primary gap-2 mt-4" wire:click="startAdding">
                        <i class="ki-filled ki-plus"></i> Add provider
                    </button>
                </div>
            </div>
        @endforelse
    </div>

    {{-- Choosing a provider to add --}}
    <div class="kt-modal kt-modal-center z-50 {{ $adding ? 'open' : '' }}" role="dialog" aria-modal="true" aria-label="Add a delivery provider">
        <div class="kt-modal-backdrop" wire:click="cancelAdding"></div>

        <div class="kt-modal-content max-w-[620px] w-full">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title">Add a delivery provider</h3>
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" wire:click="cancelAdding" title="Close" aria-label="Close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>

            <div class="kt-modal-body flex flex-col gap-2">
                @forelse ($available as $driver => $meta)
                    <button wire:click="add('{{ $driver }}')" wire:key="add-{{ $driver }}"
                            class="kt-btn kt-btn-ghost justify-start w-full h-auto py-3 text-start">
                        <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 {{ $meta['tone'] }} shrink-0">
                            <i class="ki-filled {{ $meta['icon'] }} text-lg"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block font-medium text-mono">{{ $meta['label'] }}</span>
                            <span class="block text-xs text-muted-foreground whitespace-normal">{{ $meta['summary'] }}</span>
                        </span>
                    </button>
                @empty
                    <p class="text-sm text-secondary-foreground py-6 text-center">
                        Every provider Kargah supports is already set up.
                    </p>
                @endforelse
            </div>

            <div class="kt-modal-footer">
                <button class="kt-btn kt-btn-ghost" wire:click="cancelAdding">Cancel</button>
            </div>
        </div>
    </div>
</div>
