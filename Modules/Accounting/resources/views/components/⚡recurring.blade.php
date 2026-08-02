<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Recurring invoice schedules.
 *
 * A schedule is a template plus a cadence; the run itself is a queued job the
 * backend owns. Everything on this page is about the template — which client, how
 * much, how often, and whether the schedule is armed. Pausing is deliberately a
 * switch rather than a delete, because a paused retainer usually comes back.
 *
 * The schedules array is seeded in mount() rather than with(), so toggling a switch
 * survives the round trip. That is component state, not persistence.
 */
new
#[Title('Recurring invoices — Kargah')]
class extends Component
{
    public const CURRENCIES = ['USD' => '$', 'GBP' => '£', 'EUR' => '€'];

    #[Url]
    public string $filter = 'all';

    /** @var array<int, array<string, mixed>> */
    public array $schedules = [];

    public bool $showForm = false;

    #[Validate('required|string')]
    public string $formClient = 'northwind';

    #[Validate('required|string|max:120')]
    public string $formTitle = '';

    #[Validate('required|numeric|min:0')]
    public string $formAmount = '0.00';

    #[Validate('required|string|size:3')]
    public string $formCurrency = 'USD';

    #[Validate('required|string')]
    public string $formCadence = 'monthly';

    #[Validate('required|date')]
    public string $formStartsOn = '2026-09-01';

    #[Validate('required|string')]
    public string $formEnds = 'never';

    public bool $formAutoSend = true;

    public function mount(): void
    {
        $this->schedules = [
            ['id' => 1, 'client' => 'Northwind Ltd',   'title' => 'Retainer — product design',      'amount' => 2400.00, 'currency' => 'USD', 'cadence' => 'monthly',   'nextRun' => '01 Sep 2026', 'issued' => 11, 'active' => true],
            ['id' => 2, 'client' => 'Acme Studio',     'title' => 'Hosting and maintenance',        'amount' => 320.00,  'currency' => 'USD', 'cadence' => 'monthly',   'nextRun' => '05 Sep 2026', 'issued' => 7,  'active' => true],
            ['id' => 3, 'client' => 'Bluepeak',        'title' => 'Quarterly support block',        'amount' => 1750.00, 'currency' => 'EUR', 'cadence' => 'quarterly', 'nextRun' => '01 Oct 2026', 'issued' => 3,  'active' => true],
            ['id' => 4, 'client' => 'Harbour & Finch', 'title' => 'Weekly content sprint',          'amount' => 480.00,  'currency' => 'GBP', 'cadence' => 'weekly',    'nextRun' => '—',          'issued' => 22, 'active' => false],
            ['id' => 5, 'client' => 'Meridian Design', 'title' => 'Annual licence and handover',    'amount' => 4200.00, 'currency' => 'USD', 'cadence' => 'yearly',    'nextRun' => '14 Jan 2027', 'issued' => 1,  'active' => true],
        ];
    }

    public function with(): array
    {
        $rows = array_values(array_filter($this->schedules, function (array $s): bool {
            return $this->filter === 'all'
                || ($this->filter === 'active' && $s['active'])
                || ($this->filter === 'paused' && ! $s['active']);
        }));

        $active = array_filter($this->schedules, fn (array $s) => $s['active']);

        return [
            'tabs' => ['all' => 'All', 'active' => 'Active', 'paused' => 'Paused'],
            'rows' => array_map(fn (array $s) => $s + ['formatted' => $this->money((float) $s['amount'], $s['currency'])], $rows),
            'cadences' => [
                'weekly' => 'Every week',
                'fortnightly' => 'Every two weeks',
                'monthly' => 'Every month',
                'quarterly' => 'Every quarter',
                'yearly' => 'Every year',
            ],
            'clients' => [
                'northwind' => 'Northwind Ltd',
                'acme' => 'Acme Studio',
                'bluepeak' => 'Bluepeak',
                'harbour' => 'Harbour & Finch',
                'meridian' => 'Meridian Design',
            ],
            'currencies' => ['USD' => 'USD — US dollar', 'GBP' => 'GBP — Pound sterling', 'EUR' => 'EUR — Euro'],
            'endings' => ['never' => 'Never', 'date' => 'On a date', 'count' => 'After a number of invoices'],
            'summary' => [
                ['label' => 'Active schedules', 'value' => (string) count($active), 'tone' => 'text-mono'],
                ['label' => 'Monthly recurring revenue', 'value' => $this->money($this->monthlyValue(), 'USD'), 'tone' => 'text-success'],
                ['label' => 'Next run', 'value' => $this->nextRun(), 'tone' => 'text-primary'],
            ],
            'formSymbol' => self::CURRENCIES[$this->formCurrency] ?? '$',
        ];
    }

    /* ---- Schedule state. UI state today, a database write tomorrow. ---- */

    public function toggleSchedule(int $id): void
    {
        foreach ($this->schedules as $index => $schedule) {
            if ($schedule['id'] === $id) {
                $this->schedules[$index]['active'] = ! $schedule['active'];
            }
        }

        // Backend phase arms or disarms the queued job here.
    }

    public function openForm(): void
    {
        $this->resetValidation();

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function createSchedule(): void
    {
        $this->validate();

        // Persistence lands in the backend phase; the modal then closes on success.
    }

    public function runNow(int $id): void
    {
        // Issues the next invoice ahead of its scheduled date.
    }

    /* ---- Money ---- */

    protected function money(float $amount, string $currency = 'USD'): string
    {
        return (self::CURRENCIES[$currency] ?? '$') . number_format($amount, 2);
    }

    /** Everything normalised to a monthly figure so the headline number means something. */
    protected function monthlyValue(): float
    {
        $perMonth = ['weekly' => 4.33, 'fortnightly' => 2.17, 'monthly' => 1, 'quarterly' => 1 / 3, 'yearly' => 1 / 12];

        $total = 0.0;

        foreach ($this->schedules as $schedule) {
            if (! $schedule['active']) {
                continue;
            }

            $total += ((float) $schedule['amount']) * ($perMonth[$schedule['cadence']] ?? 1);
        }

        return round($total, 2);
    }

    protected function nextRun(): string
    {
        foreach ($this->schedules as $schedule) {
            if ($schedule['active'] && $schedule['nextRun'] !== '—') {
                return $schedule['nextRun'];
            }
        }

        return '—';
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Heading --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Recurring invoices</h1>
            <p class="text-sm text-secondary-foreground mt-1">Set a retainer once and stop remembering to bill it.</p>
        </div>
        <button wire:click="openForm" class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> New schedule
        </button>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        @foreach ($summary as $s)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="text-sm text-secondary-foreground">{{ $s['label'] }}</div>
                    <div class="text-2xl font-semibold mt-1 {{ $s['tone'] }}">{{ $s['value'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Schedules --}}
    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <div class="flex gap-1">
                @foreach ($tabs as $key => $label)
                    <button wire:click="$set('filter', '{{ $key }}')"
                            class="kt-btn kt-btn-sm {{ $filter === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <span class="text-sm text-muted-foreground">{{ count($rows) }} {{ count($rows) === 1 ? 'schedule' : 'schedules' }}</span>
        </div>

        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[220px]">Client</th>
                            <th class="min-w-[200px]">Template</th>
                            <th class="w-[130px] text-end">Amount</th>
                            <th class="w-[150px]">Cadence</th>
                            <th class="w-[130px]">Next run</th>
                            <th class="w-[110px]">Enabled</th>
                            <th class="w-[60px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="schedule-{{ $row['id'] }}" class="{{ $row['active'] ? '' : 'opacity-60' }}">
                                <td>
                                    <div class="flex items-center gap-3 min-w-0">
                                        <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary/10 text-primary text-xs font-semibold shrink-0">
                                            {{ strtoupper(substr($row['client'], 0, 2)) }}
                                        </span>
                                        <span class="font-medium text-mono truncate">{{ $row['client'] }}</span>
                                    </div>
                                </td>
                                <td class="text-secondary-foreground">
                                    {{ $row['title'] }}
                                    <span class="block text-xs text-muted-foreground mt-0.5">{{ $row['issued'] }} issued so far</span>
                                </td>
                                <td class="text-end font-medium text-mono whitespace-nowrap">{{ $row['formatted'] }}</td>
                                <td>
                                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $cadences[$row['cadence']] ?? $row['cadence'] }}</span>
                                </td>
                                <td class="{{ $row['nextRun'] === '—' ? 'text-muted-foreground' : 'text-secondary-foreground' }}">
                                    {{ $row['nextRun'] }}
                                </td>
                                <td>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" class="kt-switch kt-switch-sm"
                                               wire:click="toggleSchedule({{ $row['id'] }})"
                                               wire:loading.attr="disabled" wire:target="toggleSchedule({{ $row['id'] }})"
                                               @checked($row['active'])
                                               aria-label="{{ $row['active'] ? 'Pause' : 'Enable' }} the {{ $row['client'] }} schedule">
                                        <span class="text-xs {{ $row['active'] ? 'text-success' : 'text-muted-foreground' }}">
                                            {{ $row['active'] ? 'On' : 'Paused' }}
                                        </span>
                                    </label>
                                </td>
                                <td class="text-end">
                                    <div data-kt-dropdown="true" data-kt-dropdown-trigger="click" data-kt-dropdown-placement="bottom-end">
                                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" data-kt-dropdown-toggle="true"
                                                title="Schedule actions" aria-label="Schedule actions">
                                            <i class="ki-filled ki-dots-vertical text-sm"></i>
                                        </button>
                                        <div class="kt-dropdown-menu w-[200px]" data-kt-dropdown-menu="true">
                                            <div class="p-2 flex flex-col gap-1">
                                                <button wire:click="runNow({{ $row['id'] }})" class="kt-btn kt-btn-ghost justify-start gap-2">
                                                    <i class="ki-filled ki-rocket"></i> Issue now
                                                </button>
                                                <a href="{{ route('accounting.invoice-create') }}" wire:navigate class="kt-btn kt-btn-ghost justify-start gap-2">
                                                    <i class="ki-filled ki-pencil"></i> Edit template
                                                </a>
                                                <button wire:click="toggleSchedule({{ $row['id'] }})" class="kt-btn kt-btn-ghost justify-start gap-2">
                                                    <i class="ki-filled ki-{{ $row['active'] ? 'pause' : 'play' }}"></i>
                                                    {{ $row['active'] ? 'Pause schedule' : 'Resume schedule' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="flex flex-col items-center justify-center text-center py-14">
                                        <i class="ki-filled ki-arrows-circle text-3xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground mb-4">
                                            {{ $filter === 'all' ? 'No recurring schedules yet — set one up for anything you bill on a rhythm.' : 'Nothing matches this filter.' }}
                                        </p>
                                        <button wire:click="openForm" class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                            <i class="ki-filled ki-plus"></i> New schedule
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

    {{-- Create schedule --}}
    <div class="kt-modal kt-modal-center z-50 {{ $showForm ? 'open' : '' }}"
         role="dialog" aria-modal="true" aria-labelledby="recurring_form_title">

        <div class="kt-modal-backdrop" wire:click="closeForm"></div>

        <div class="kt-modal-content max-w-[560px] w-full">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title" id="recurring_form_title">New recurring schedule</h3>
                <button wire:click="closeForm" class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Close" aria-label="Close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>

            <div class="kt-modal-body max-h-[70vh]">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="recurring_client">Client</label>
                        <select id="recurring_client" wire:model.live="formClient"
                                class="kt-select @error('formClient') border-destructive @enderror">
                            @foreach ($clients as $key => $name)
                                <option value="{{ $key }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('formClient')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="recurring_title">What is being billed</label>
                        <input id="recurring_title" type="text" wire:model.blur="formTitle"
                               placeholder="Retainer — product design"
                               class="kt-input @error('formTitle') border-destructive @enderror">
                        @error('formTitle')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_amount">Amount</label>
                        <div class="kt-input-group">
                            <span class="kt-input-addon">{{ $formSymbol }}</span>
                            <input id="recurring_amount" type="number" step="0.01" min="0"
                                   wire:model.live.debounce.400ms="formAmount"
                                   class="kt-input text-end @error('formAmount') border-destructive @enderror">
                        </div>
                        @error('formAmount')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_currency">Currency</label>
                        <select id="recurring_currency" wire:model.live="formCurrency"
                                class="kt-select @error('formCurrency') border-destructive @enderror">
                            @foreach ($currencies as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('formCurrency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_cadence">Cadence</label>
                        <select id="recurring_cadence" wire:model.live="formCadence"
                                class="kt-select @error('formCadence') border-destructive @enderror">
                            @foreach ($cadences as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('formCadence')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_start">First invoice on</label>
                        <input id="recurring_start" type="date" wire:model="formStartsOn"
                               class="kt-input @error('formStartsOn') border-destructive @enderror">
                        @error('formStartsOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="recurring_ends">Ends</label>
                        <select id="recurring_ends" wire:model.live="formEnds"
                                class="kt-select @error('formEnds') border-destructive @enderror">
                            @foreach ($endings as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('formEnds')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="sm:col-span-2 flex items-start justify-between gap-4 border-t border-border pt-4">
                        <div class="min-w-0">
                            <label class="kt-form-label" for="recurring_autosend">Send automatically</label>
                            <p class="kt-form-description mt-1">Off means each run lands in Drafts for you to check first.</p>
                        </div>
                        <input id="recurring_autosend" type="checkbox" class="kt-switch shrink-0" wire:model.live="formAutoSend">
                    </div>

                </div>
            </div>

            <div class="kt-modal-footer">
                <button wire:click="closeForm" class="kt-btn kt-btn-ghost">Cancel</button>
                <button wire:click="createSchedule" wire:loading.attr="disabled" wire:target="createSchedule"
                        class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="createSchedule" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-check"></i> Create schedule
                    </span>
                    <span wire:loading wire:target="createSchedule" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Creating…
                    </span>
                </button>
            </div>
        </div>
    </div>

</div>
