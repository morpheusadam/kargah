<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Support\Senders;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Suppression list.
 *
 * One list, shared by every provider. A hard bounce reported by Brevo blocks
 * that address on SES and Mailgun too, because the mailbox does not exist
 * whichever account tries it. Removing an entry is therefore a deliberate act,
 * not a tidy-up — which is why the confirmation dialog names the reason and
 * says what putting the address back into circulation costs.
 */
new
#[Title('Suppression list — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Url]
    public string $reason = 'all';

    #[Url]
    public string $provider = 'all';

    public string $search = '';

    /** The address whose removal is being confirmed, or null when the dialog is shut. */
    public ?string $confirming = null;

    public bool $adding = false;

    #[Validate('required|email|max:190')]
    public string $newEmail = '';

    #[Validate('required|string|max:500')]
    public string $newDetail = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedReason(): void
    {
        $this->resetPage();
    }

    public function updatedProvider(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $counts = Suppression::query()
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        return [
            'reasons' => ['all' => 'All reasons'] + Suppression::reasons(),
            // Only the sources actually on the list. A filter offering five
            // providers when the install has one is a filter that always
            // returns nothing.
            'providerFilters' => ['all' => 'All providers'] + $this->sourcesInUse(),
            'counts' => [
                ['label' => 'Hard bounces', 'value' => (int) $counts->get(Suppression::HARD_BOUNCE, 0), 'icon' => 'ki-cross-circle', 'tone' => 'text-destructive'],
                ['label' => 'Complaints', 'value' => (int) $counts->get(Suppression::COMPLAINT, 0), 'icon' => 'ki-shield-cross', 'tone' => 'text-destructive'],
                ['label' => 'Unsubscribes', 'value' => (int) $counts->get(Suppression::UNSUBSCRIBE, 0), 'icon' => 'ki-minus-circle', 'tone' => 'text-warning'],
                ['label' => 'Added by you', 'value' => (int) $counts->get(Suppression::MANUAL, 0), 'icon' => 'ki-user-tick', 'tone' => 'text-secondary-foreground'],
            ],
            'entries' => $this->query()->paginate(25),
            'entry' => $this->confirming === null ? null : Suppression::query()->where('email', $this->confirming)->first(),
            'warnings' => [
                Suppression::HARD_BOUNCE => 'The mailbox does not exist. Sending to it again produces another hard bounce, and a bounce rate over 2% is where providers start suspending accounts.',
                Suppression::COMPLAINT => 'This person marked a message as spam. Emailing them again is the single fastest way to lose a sending account, and in the UK and EU it also breaches their withdrawal of consent.',
                Suppression::UNSUBSCRIBE => 'This person asked to stop receiving mail. Re-adding them without fresh consent breaches PECR and GDPR, whatever the list says.',
                Suppression::INVALID => 'The address never parsed as an email address. Removing it lets the next import put it back.',
                Suppression::MANUAL => 'You added this address yourself. Removing it makes the address mailable again straight away.',
            ],
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Suppression> */
    private function query()
    {
        return Suppression::query()
            ->when($this->reason !== 'all', fn ($q) => $q->forReason($this->reason))
            ->when($this->provider !== 'all', fn ($q) => $q->where('source', $this->provider))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('email', 'like', '%'.trim($this->search).'%'))
            ->recent();
    }

    /**
     * The sources that actually appear on the list, labelled.
     *
     * A driver name gets the provider's own label; anything else — `one-click`,
     * `import` — is Kargah's own word for how the address arrived and reads
     * better than the raw string.
     *
     * @return array<string, string>
     */
    private function sourcesInUse(): array
    {
        $sources = Suppression::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source');

        $labels = [];

        foreach ($sources as $source) {
            $labels[$source] = Senders::has($source) ? Senders::label($source) : ucfirst(str_replace('-', ' ', $source));
        }

        return $labels;
    }

    // Opening and dismissing the dialogs speak for themselves.

    public function confirmRemoval(string $email): void
    {
        $this->confirming = $email;
    }

    public function cancelRemoval(): void
    {
        $this->confirming = null;
    }

    public function startAdding(): void
    {
        $this->reset('newEmail', 'newDetail');
        $this->resetValidation();
        $this->adding = true;
    }

    public function cancelAdding(): void
    {
        $this->adding = false;
    }

    /**
     * Take an address off the list.
     *
     * Deleted rather than flagged, because the suppression list answers one
     * question — 'may I send to this address' — and a row that means 'no longer
     * blocked' is a row somebody will one day read as 'blocked'.
     */
    public function remove(string $email): void
    {
        $entry = Suppression::query()->where('email', $email)->first();

        $this->confirming = null;

        if ($entry === null) {
            $this->toastWarning('Nothing to remove', $email.' is not on the suppression list.');

            return;
        }

        $reason = $entry->reasonLabel();

        $entry->delete();

        $this->toastSuccess(
            $email.' is mailable again',
            'It was suppressed as a '.mb_strtolower($reason).' and will be included in the next campaign it appears in.',
        );
    }

    /** Block an address somebody asked to be left alone by some other route. */
    public function addManually(): void
    {
        $this->validate();

        $email = Suppression::normalise($this->newEmail);
        $existing = Suppression::query()->where('email', $email)->first();

        Suppression::block($email, Suppression::MANUAL, 'manual', trim($this->newDetail));

        $this->adding = false;
        $this->reset('newEmail', 'newDetail');

        $this->toastSuccess(
            $existing === null ? $email.' is now suppressed' : $email.' was already suppressed',
            $existing === null
                ? 'No campaign on any provider will send to it again.'
                : 'It was on the list as a '.mb_strtolower($existing->reasonLabel()).'; the reason now says you added it.',
        );
    }

    /**
     * The whole list, as a file.
     *
     * Streamed rather than built in memory, because this is the file that gets
     * uploaded to a provider's own suppression store and it is the one page in
     * Mailbox whose export can be tens of thousands of rows. The filter is
     * applied, so 'export the complaints' is one select and one press rather
     * than a spreadsheet afterwards.
     */
    public function exportCsv(): StreamedResponse
    {
        $rows = $this->query()->get();
        $name = 'suppression-list-'.now()->format('Y-m-d').'.csv';

        $this->toastSuccess(
            'Exported '.$rows->count().' '.str('address')->plural($rows->count()),
            'Upload it to a provider\'s own suppression store so their retries stop as well.',
        );

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['email', 'reason', 'reported_by', 'suppressed_at', 'detail']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->email,
                    $row->reason,
                    $row->source,
                    $row->suppressed_at?->toDateTimeString(),
                    $row->detail,
                ]);
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv']);
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Suppression list</h1>
            <p class="text-sm text-secondary-foreground mt-1">Addresses no campaign may touch, on any provider.</p>
        </div>
        {{-- `flex-wrap`: at 375px this group ended at 396px and took the page
             sideways with it. Same shape and same fix as ⚡recurring. --}}
        <div class="flex flex-wrap items-center gap-2">
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
            <button class="kt-btn kt-btn-primary gap-2" wire:click="startAdding">
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
                            <th class="w-[110px] text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $e)
                            <tr wire:key="suppression-{{ $e->id }}" wire:loading.class="opacity-50" wire:target="reason,provider,search">
                                <td>
                                    <div class="font-medium text-mono">{{ $e->email }}</div>
                                    <div class="text-xs text-muted-foreground truncate max-w-[380px]">{{ $e->detail ?: '—' }}</div>
                                </td>
                                <td>
                                    <span class="kt-badge kt-badge-sm {{ $e->badge() }}">{{ $e->reasonLabel() }}</span>
                                </td>
                                <td class="text-secondary-foreground">
                                    {{ $e->source ? ($providerFilters[$e->source] ?? $e->source) : '—' }}
                                </td>
                                <td class="text-secondary-foreground">{{ $e->suppressed_at?->format('d M Y') ?? '—' }}</td>
                                <td class="text-end">
                                    <button wire:click="confirmRemoval('{{ $e->email }}')"
                                            class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1.5"
                                            title="Remove {{ $e->email }} from the suppression list">
                                        <i class="ki-filled ki-trash text-sm"></i> Remove
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="flex flex-col items-center justify-center text-center py-14">
                                        <i class="ki-filled ki-shield-tick text-4xl text-success mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">Nothing is suppressed. Every contact is mailable.</p>
                                        <button class="kt-btn kt-btn-primary gap-2 mt-4" wire:click="startAdding">
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

        @if ($entries->hasPages())
            <div class="kt-card-footer">{{ $entries->links() }}</div>
        @endif
    </div>

    {{-- Adding by hand --}}
    <div class="kt-modal kt-modal-center z-50 {{ $adding ? 'open' : '' }}" role="dialog" aria-modal="true" aria-label="Suppress an address">
        <div class="kt-modal-backdrop" wire:click="cancelAdding"></div>

        <div class="kt-modal-content max-w-[520px] w-full">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title">Suppress an address</h3>
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" wire:click="cancelAdding" title="Close" aria-label="Close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>

            <div class="kt-modal-body flex flex-col gap-4">
                <div>
                    <label class="kt-form-label" for="suppress-email">Address</label>
                    <div class="kt-input @error('newEmail') border-destructive @enderror">
                        <input id="suppress-email" type="email" placeholder="somebody@example.com" wire:model="newEmail">
                    </div>
                    @error('newEmail')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                </div>

                <div>
                    <label class="kt-form-label" for="suppress-detail">Why</label>
                    <textarea id="suppress-detail" class="kt-textarea @error('newDetail') border-destructive @enderror" rows="3"
                              placeholder="Asked by phone not to be emailed again" wire:model="newDetail"></textarea>
                    @error('newDetail')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    <p class="text-xs text-muted-foreground mt-1">
                        Recorded against the address. It is what the next person sees before deciding whether to remove it.
                    </p>
                </div>
            </div>

            <div class="kt-modal-footer">
                <button class="kt-btn kt-btn-ghost" wire:click="cancelAdding">Cancel</button>
                <button class="kt-btn kt-btn-primary gap-2" wire:click="addManually"
                        wire:loading.attr="disabled" wire:target="addManually">
                    <span wire:loading.remove wire:target="addManually" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-shield-cross"></i> Suppress it
                    </span>
                    <span wire:loading wire:target="addManually" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
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
                        <div class="text-sm font-medium text-mono truncate">{{ $entry?->email ?? '—' }}</div>
                        <div class="text-xs text-muted-foreground">
                            {{ $entry?->reasonLabel() ?? '—' }}
                            @if ($entry?->source)
                                · reported by {{ $providerFilters[$entry->source] ?? $entry->source }}
                            @endif
                            @if ($entry?->suppressed_at) · {{ $entry->suppressed_at->format('d M Y') }} @endif
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                    <p class="text-sm text-secondary-foreground leading-relaxed">
                        {{ $entry ? ($warnings[$entry->reason] ?? 'Removing this address puts it back into every future send.') : 'Select an entry to see what removing it means.' }}
                    </p>
                </div>

                @if ($entry?->detail)
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Original response: <code class="text-xs">{{ $entry->detail }}</code>
                    </p>
                @endif
            </div>

            <div class="kt-modal-footer">
                <button class="kt-btn kt-btn-ghost" wire:click="cancelRemoval">Keep it suppressed</button>
                <button class="kt-btn kt-btn-primary bg-destructive border-destructive gap-2"
                        wire:click="remove('{{ $entry?->email ?? '' }}')"
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
