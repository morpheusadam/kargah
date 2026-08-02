<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Suppression list.
 *
 * One list, shared by every provider. A hard bounce reported by Brevo blocks
 * that address on SES and Mailgun too, because the mailbox does not exist
 * whichever account tries it. Removing an entry is therefore a deliberate act,
 * not a tidy-up.
 */
new
#[Title('Suppression list — Kargah')]
class extends Component
{
    #[Url]
    public string $reason = 'all';

    #[Url]
    public string $provider = 'all';

    public string $search = '';

    public ?string $confirming = null;

    public function with(): array
    {
        return [
            'reasons' => [
                'all'          => 'All reasons',
                'hard_bounce'  => 'Hard bounce',
                'complaint'    => 'Complaint',
                'unsubscribe'  => 'Unsubscribe',
                'manual'       => 'Added manually',
            ],
            'providerFilters' => [
                'all'     => 'All providers',
                'brevo'   => 'Brevo',
                'ses'     => 'Amazon SES',
                'mailgun' => 'Mailgun',
                'resend'  => 'Resend',
                'smtp2go' => 'SMTP2GO',
            ],
            'counts' => [
                ['label' => 'Hard bounces', 'value' => 41, 'icon' => 'ki-cross-circle',  'tone' => 'text-destructive'],
                ['label' => 'Complaints',   'value' => 7,  'icon' => 'ki-shield-cross',  'tone' => 'text-destructive'],
                ['label' => 'Unsubscribes', 'value' => 63, 'icon' => 'ki-minus-circle',  'tone' => 'text-warning'],
                ['label' => 'Added by you', 'value' => 5,  'icon' => 'ki-user-tick',     'tone' => 'text-secondary-foreground'],
            ],
            'entries' => [
                [
                    'email' => 'contact@brightlab.example', 'reason' => 'hard_bounce', 'provider' => 'Amazon SES',
                    'date' => '22 Jul 2026', 'campaign' => 'Resume — design agencies UK',
                    'detail' => '550 5.1.1 Requested action not taken: mailbox unavailable',
                ],
                [
                    'email' => 'team@harbourside.example', 'reason' => 'complaint', 'provider' => 'Mailgun',
                    'date' => '23 Jul 2026', 'campaign' => 'Resume — design agencies UK',
                    'detail' => 'Feedback loop report from the recipient\'s mailbox provider',
                ],
                [
                    'email' => 'mail@oldfold.example', 'reason' => 'hard_bounce', 'provider' => 'Brevo',
                    'date' => '02 Jul 2026', 'campaign' => 'Follow-up #1',
                    'detail' => '550 5.1.10 Recipient address rejected: user unknown',
                ],
                [
                    'email' => 'info@quietfox.example', 'reason' => 'unsubscribe', 'provider' => 'Brevo',
                    'date' => '22 Jul 2026', 'campaign' => 'Resume — design agencies UK',
                    'detail' => 'One-click opt-out via the List-Unsubscribe header',
                ],
                [
                    'email' => 'accounts@vellum-press.example', 'reason' => 'manual', 'provider' => '—',
                    'date' => '18 Jul 2026', 'campaign' => '—',
                    'detail' => 'Asked by phone not to be emailed again',
                ],
                [
                    'email' => 'hello@driftworks.example', 'reason' => 'hard_bounce', 'provider' => 'Amazon SES',
                    'date' => '14 Jul 2026', 'campaign' => 'Resume — startups DE',
                    'detail' => '550 5.4.1 Recipient address rejected: access denied',
                ],
                [
                    'email' => 'studio@paperkite.example', 'reason' => 'unsubscribe', 'provider' => 'Mailgun',
                    'date' => '11 Jul 2026', 'campaign' => 'Follow-up #1',
                    'detail' => 'Clicked the unsubscribe link in the message footer',
                ],
            ],
            'badge' => [
                'hard_bounce' => ['class' => 'kt-badge-destructive', 'label' => 'Hard bounce'],
                'complaint'   => ['class' => 'kt-badge-destructive', 'label' => 'Complaint'],
                'unsubscribe' => ['class' => 'kt-badge-outline',     'label' => 'Unsubscribe'],
                'manual'      => ['class' => 'kt-badge-warning',     'label' => 'Manual'],
            ],
            'warnings' => [
                'hard_bounce' => 'The mailbox does not exist. Sending to it again produces another hard bounce, and a bounce rate over 2% is where providers start suspending accounts.',
                'complaint'   => 'This person marked a message as spam. Emailing them again is the single fastest way to lose a sending account, and in the UK and EU it also breaches their withdrawal of consent.',
                'unsubscribe' => 'This person asked to stop receiving mail. Re-adding them without fresh consent breaches PECR and GDPR, whatever the list says.',
                'manual'      => 'You added this address yourself. Removing it makes the address mailable again straight away.',
            ],
        ];
    }

    public function confirmRemoval(string $email): void
    {
        $this->confirming = $email;
    }

    public function cancelRemoval(): void
    {
        $this->confirming = null;
    }

    public function remove(string $email): void
    {
        // Deletes the suppression entry and writes an audit record naming who did it.
        $this->confirming = null;
    }

    public function addManually(string $email): void
    {
        // Inserts a manual suppression entry.
    }

    public function exportCsv(): void
    {
        // Streams the whole list so it can be uploaded to a provider's own suppression store.
    }
};

?>

<div class="flex flex-col gap-5">

    @php $entry = collect($entries)->firstWhere('email', $confirming); @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Suppression list</h1>
            <p class="text-sm text-secondary-foreground mt-1">Addresses no campaign may touch, on any provider.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('mail.contacts') }}" class="kt-btn kt-btn-ghost gap-2">
                <i class="ki-filled ki-arrow-left"></i> Contacts
            </a>
            <button class="kt-btn kt-btn-outline gap-2" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                <span wire:loading.remove wire:target="exportCsv" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-exit-down"></i> Export
                </span>
                <span wire:loading wire:target="exportCsv" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Preparing…
                </span>
            </button>
            <button class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> Suppress an address
            </button>
        </div>
    </div>

    <div class="kt-card bg-destructive/5 border-destructive/30">
        <div class="kt-card-content flex items-start gap-3 p-4">
            <i class="ki-filled ki-shield-cross text-destructive text-lg mt-0.5 shrink-0"></i>
            <div class="text-sm text-secondary-foreground">
                <strong class="text-mono">Hard bounces and complaints are permanent.</strong>
                They are recorded once by whichever provider carried the message and then honoured by all of them.
                Removing an entry puts the address back into every future send.
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-5">
        @foreach ($counts as $c)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                        <i class="ki-filled {{ $c['icon'] }} {{ $c['tone'] }}"></i>
                        {{ $c['label'] }}
                    </div>
                    <div class="text-2xl font-semibold text-mono mt-2">{{ $c['value'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <div class="kt-input max-w-[260px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search addresses…" wire:model.live.debounce.300ms="search">
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select class="kt-select max-w-[180px]" wire:model.live="reason" aria-label="Filter by reason">
                    @foreach ($reasons as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select class="kt-select max-w-[180px]" wire:model.live="provider" aria-label="Filter by provider">
                    @foreach ($providerFilters as $key => $label)
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
                            <th class="min-w-[260px]">Address</th>
                            <th class="w-[150px]">Reason</th>
                            <th class="w-[140px]">Reported by</th>
                            <th class="w-[130px]">Date</th>
                            <th class="min-w-[200px]">From campaign</th>
                            <th class="w-[110px] text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $e)
                            <tr wire:loading.class="opacity-50" wire:target="reason,provider,search">
                                <td>
                                    <div class="font-medium text-mono">{{ $e['email'] }}</div>
                                    <div class="text-xs text-muted-foreground truncate max-w-[380px]">{{ $e['detail'] }}</div>
                                </td>
                                <td>
                                    <span class="kt-badge kt-badge-sm {{ $badge[$e['reason']]['class'] }}">
                                        {{ $badge[$e['reason']]['label'] }}
                                    </span>
                                </td>
                                <td class="text-secondary-foreground">{{ $e['provider'] }}</td>
                                <td class="text-secondary-foreground">{{ $e['date'] }}</td>
                                <td class="text-secondary-foreground truncate">{{ $e['campaign'] }}</td>
                                <td class="text-end">
                                    <button wire:click="confirmRemoval('{{ $e['email'] }}')"
                                            class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1.5"
                                            title="Remove {{ $e['email'] }} from the suppression list">
                                        <i class="ki-filled ki-trash text-sm"></i> Remove
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="flex flex-col items-center justify-center text-center py-14">
                                        <i class="ki-filled ki-shield-tick text-4xl text-success mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">Nothing is suppressed. Every contact is mailable.</p>
                                        <button class="kt-btn kt-btn-primary gap-2 mt-4">
                                            <i class="ki-filled ki-plus"></i> Suppress an address
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Removal confirmation --}}
    <div class="kt-modal kt-modal-center z-50 {{ $confirming ? 'open' : '' }}" role="dialog" aria-modal="true" aria-label="Confirm removal">
        <div class="kt-modal-backdrop" wire:click="cancelRemoval"></div>

        <div class="kt-modal-content max-w-[520px] w-full">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title">Remove from the suppression list?</h3>
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" wire:click="cancelRemoval"
                        title="Close" aria-label="Close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>

            <div class="kt-modal-body flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center justify-center size-10 rounded-lg bg-destructive/10 text-destructive shrink-0">
                        <i class="ki-filled ki-shield-cross text-lg"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-mono truncate">{{ $entry['email'] ?? '—' }}</div>
                        <div class="text-xs text-muted-foreground">
                            {{ $entry ? $badge[$entry['reason']]['label'] : '—' }}
                            @if ($entry && $entry['provider'] !== '—')
                                · reported by {{ $entry['provider'] }}
                            @endif
                            @if ($entry) · {{ $entry['date'] }} @endif
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                    <p class="text-sm text-secondary-foreground leading-relaxed">
                        {{ $entry ? $warnings[$entry['reason']] : 'Select an entry to see what removing it means.' }}
                    </p>
                </div>

                @if ($entry)
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Original response: <code class="text-xs">{{ $entry['detail'] }}</code>
                    </p>
                @endif
            </div>

            <div class="kt-modal-footer">
                <button class="kt-btn kt-btn-ghost" wire:click="cancelRemoval">Keep it suppressed</button>
                <button class="kt-btn kt-btn-primary bg-destructive border-destructive gap-2"
                        wire:click="remove('{{ $entry['email'] ?? '' }}')"
                        wire:loading.attr="disabled" wire:target="remove">
                    <span wire:loading.remove wire:target="remove" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-trash"></i> Remove anyway
                    </span>
                    <span wire:loading wire:target="remove" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Removing…
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
