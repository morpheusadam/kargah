<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Console\GenerateRecurringInvoices;
use Modules\Accounting\Models\RecurringInvoice;
use Modules\Accounting\Support\Currencies;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * Recurring schedules, reading from and writing to `recurring_invoices`.
 *
 * A schedule is a template plus a cadence. It raises **drafts** and never
 * issues: issuing freezes an exchange rate onto an invoice and that is a
 * decision a person makes with the document in front of them, not one a cron
 * job makes at half past nine.
 *
 * "Raise now" runs exactly the same code as the scheduled job, deliberately.
 * It claims the same occurrence by the same key, so an impatient second click
 * is as harmless as a second cron run — the invoice number is derived from the
 * schedule and the occurrence date, and there is only one of those.
 *
 * Pausing is a flag rather than a delete, because a paused retainer usually
 * comes back and the drafts it has already raised have to keep making sense.
 */
new
#[Title('Recurring invoices — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public const TABS = ['all' => 'All', 'active' => 'Active', 'paused' => 'Paused'];

    #[Url]
    public string $filter = 'all';

    public bool $formOpen = false;

    /** Null while creating, the schedule key while editing. */
    public ?int $editingId = null;

    public ?string $formCustomerId = null;

    public ?string $formCompanyId = null;

    public string $formTitle = '';

    public string $formCurrency = Currencies::USD;

    /** A percentage as a decimal string: '20' is twenty per cent. */
    public string $formTaxPercent = '0';

    public string $formCadence = 'monthly';

    public string $formNextRunOn = '';

    /** Blank keeps whatever day the schedule starts on. */
    public string $formDayOfMonth = '';

    /** @var array<int, array{description: string, quantity: string, unit_price: string}> */
    public array $formLines = [];

    public string $formNotes = '';

    public string $formTerms = '';

    public bool $formActive = true;

    private ?Collection $resolvedSchedules = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    /* Reading the schedules --------------------------------------------------- */

    private function schedules(): Collection
    {
        return $this->resolvedSchedules ??= RecurringInvoice::query()
            ->with(['customer', 'company'])
            ->when($this->filter === 'active', fn ($query) => $query->active())
            ->when($this->filter === 'paused', fn ($query) => $query->paused())
            ->orderByDesc('is_active')
            ->orderBy('next_run_on')
            ->get();
    }

    private function counts(): array
    {
        return [
            'all' => RecurringInvoice::query()->count(),
            'active' => RecurringInvoice::query()->active()->count(),
            'paused' => RecurringInvoice::query()->paused()->count(),
        ];
    }

    /** The soonest date an active schedule will raise something. */
    private function nextRun(): ?Carbon
    {
        $date = RecurringInvoice::query()->active()->min('next_run_on');

        return $date === null ? null : Carbon::parse($date);
    }

    public function with(): array
    {
        $counts = $this->counts();

        $dueNow = RecurringInvoice::query()->due()->count();
        $next = $this->nextRun();

        return [
            'tabs' => self::TABS,
            'counts' => $counts,
            'schedules' => $this->schedules(),
            'cadences' => RecurringInvoice::CADENCES,
            'currencies' => Currencies::supported(),
            'customers' => Customer::query()->active()->with('company')->orderBy('name')->get(),
            'companies' => Company::query()->active()->orderBy('name')->get(),
            'formSymbol' => Currencies::symbol(
                in_array($this->formCurrency, Currencies::supported(), true) ? $this->formCurrency : Currencies::USD,
            ),
            'summary' => [
                [
                    'label' => 'Active schedules',
                    'value' => (string) $counts['active'],
                    'detail' => $counts['paused'] === 0
                        ? 'None paused.'
                        : $counts['paused'].' paused.',
                    'tone' => 'text-mono',
                ],
                [
                    'label' => 'Due to raise a draft',
                    'value' => (string) $dueNow,
                    'detail' => $dueNow === 0
                        ? 'Nothing is waiting on the job.'
                        : 'The next run will raise '.$dueNow.' '.str('draft')->plural($dueNow).'.',
                    'tone' => $dueNow === 0 ? 'text-mono' : 'text-warning',
                ],
                [
                    'label' => 'Next run',
                    'value' => $next?->format('j M Y') ?? '—',
                    'detail' => $next === null
                        ? 'No active schedule to run.'
                        : 'Drafts only — nothing is ever issued automatically.',
                    'tone' => 'text-primary',
                ],
            ],
        ];
    }

    public function billedTo(RecurringInvoice $schedule): string
    {
        return $schedule->company?->name ?? $schedule->customer?->name ?? 'No client on this schedule';
    }

    /* The form ---------------------------------------------------------------- */

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->formCustomerId = null;
        $this->formCompanyId = null;
        $this->formTitle = '';
        $this->formCurrency = Currencies::USD;
        $this->formTaxPercent = '0';
        $this->formCadence = 'monthly';
        $this->formNextRunOn = today()->addMonthNoOverflow()->toDateString();
        $this->formDayOfMonth = '';
        $this->formLines = [$this->blankLine()];
        $this->formNotes = '';
        $this->formTerms = 'Payment due within 30 days of the invoice date.';
        $this->formActive = true;
    }

    private function blankLine(): array
    {
        return ['description' => '', 'quantity' => '1', 'unit_price' => '0'];
    }

    public function openForm(?int $id = null): void
    {
        $this->resetValidation();
        $this->resetForm();

        if ($id !== null) {
            $schedule = RecurringInvoice::query()->find($id);

            if ($schedule === null) {
                $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

                return;
            }

            $this->editingId = (int) $schedule->getKey();
            $this->formCustomerId = $schedule->customer_id === null ? null : (string) $schedule->customer_id;
            $this->formCompanyId = $schedule->company_id === null ? null : (string) $schedule->company_id;
            $this->formTitle = (string) $schedule->title;
            $this->formCurrency = $schedule->currency;
            $this->formTaxPercent = $this->trimZeros((string) $schedule->tax_percent);
            $this->formCadence = $schedule->cadence;
            $this->formNextRunOn = $schedule->next_run_on->toDateString();
            $this->formDayOfMonth = $schedule->day_of_month === null ? '' : (string) $schedule->day_of_month;
            $this->formLines = $schedule->templateLines();
            $this->formNotes = (string) $schedule->notes;
            $this->formTerms = (string) $schedule->terms;
            $this->formActive = (bool) $schedule->is_active;
        }

        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }

    public function addFormLine(): void
    {
        $this->formLines[] = $this->blankLine();
    }

    public function removeFormLine(int $index): void
    {
        if (count($this->formLines) <= 1) {
            $this->toastError('Line kept', 'A schedule needs at least one line to bill.');

            return;
        }

        unset($this->formLines[$index]);

        $this->formLines = array_values($this->formLines);
    }

    protected function rules(): array
    {
        return [
            'formTitle' => ['required', 'string', 'max:120'],
            'formCurrency' => ['required', Rule::in(Currencies::supported())],
            'formTaxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'formCadence' => ['required', Rule::in(array_keys(RecurringInvoice::CADENCES))],
            'formNextRunOn' => ['required', 'date'],
            'formDayOfMonth' => ['nullable', 'integer', 'min:1', 'max:31'],
            'formCustomerId' => ['nullable', 'exists:customers,id'],
            'formCompanyId' => ['nullable', 'exists:companies,id'],
            'formLines' => ['required', 'array', 'min:1'],
            'formLines.*.description' => ['required', 'string', 'max:255'],
            'formLines.*.quantity' => ['required', 'numeric', 'min:0'],
            'formLines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'formTitle' => 'name',
            'formCurrency' => 'currency',
            'formTaxPercent' => 'tax rate',
            'formCadence' => 'cadence',
            'formNextRunOn' => 'first invoice date',
            'formDayOfMonth' => 'day of the month',
            'formCustomerId' => 'client',
            'formCompanyId' => 'company',
        ];
    }

    public function saveSchedule(): void
    {
        $this->validate();

        $attributes = [
            'customer_id' => $this->formCustomerId === null || $this->formCustomerId === '' ? null : (int) $this->formCustomerId,
            'company_id' => $this->formCompanyId === null || $this->formCompanyId === '' ? null : (int) $this->formCompanyId,
            'title' => trim($this->formTitle),
            'currency' => $this->formCurrency,
            'tax_percent' => RecurringInvoice::decimal($this->formTaxPercent, '0'),
            'cadence' => $this->formCadence,
            'day_of_month' => $this->formDayOfMonth === '' ? null : (int) $this->formDayOfMonth,
            'next_run_on' => $this->formNextRunOn,
            'lines' => array_map(fn (array $line): array => [
                'description' => trim((string) $line['description']),
                'quantity' => RecurringInvoice::decimal($line['quantity'] ?? '1', '1'),
                'unit_price' => RecurringInvoice::decimal($line['unit_price'] ?? '0', '0'),
            ], array_values($this->formLines)),
            'notes' => trim($this->formNotes) === '' ? null : trim($this->formNotes),
            'terms' => trim($this->formTerms) === '' ? null : trim($this->formTerms),
            'is_active' => $this->formActive,
        ];

        $schedule = $this->editingId === null
            ? RecurringInvoice::query()->create($attributes + ['created_by' => auth()->id()])
            : tap(RecurringInvoice::query()->findOrFail($this->editingId))->update($attributes);

        $this->formOpen = false;
        $this->resolvedSchedules = null;

        $schedule->refresh();

        $this->toastSuccess(
            $schedule->title.($this->editingId === null ? ' created' : ' updated'),
            $schedule->formattedTotal().' '.strtolower($schedule->cadenceLabel())
            .', next on '.$schedule->next_run_on->format('j F Y').'. Raised as a draft.',
        );

        $this->editingId = null;
    }

    /* Running ------------------------------------------------------------------ */

    public function toggleSchedule(int $id): void
    {
        $schedule = RecurringInvoice::query()->find($id);

        if ($schedule === null) {
            $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

            return;
        }

        $schedule->forceFill(['is_active' => ! $schedule->is_active])->save();

        $this->resolvedSchedules = null;

        $this->toastSuccess(
            $schedule->is_active ? $schedule->title.' resumed' : $schedule->title.' paused',
            $schedule->is_active
                ? 'The next draft is due on '.$schedule->next_run_on->format('j F Y').'.'
                : 'It raises nothing until you resume it. What it has already raised is untouched.',
        );
    }

    /**
     * Raise the next occurrence now, ahead of its date.
     *
     * The same code path the scheduled job takes, run against the schedule's
     * own next occurrence — so an impatient second click is as harmless as a
     * second cron run.
     *
     * **One period at a time.** Raising early moves the schedule on, so the
     * occurrence after it is more than a full period away and this refuses to
     * bring that one forward too. Without the guard a double click bills a
     * client for next month as well, which is a footgun rather than a feature.
     */
    public function raiseNow(int $id): void
    {
        $schedule = RecurringInvoice::query()->find($id);

        if ($schedule === null) {
            $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

            return;
        }

        if (! $schedule->is_active) {
            $this->toastError(
                $schedule->title.' is paused',
                'Resume it first — a paused schedule raises nothing, by design.',
            );

            return;
        }

        if ($schedule->next_run_on->isAfter($schedule->advanceFrom(today()))) {
            $this->toastError(
                'Nothing to raise yet',
                'The next draft is due on '.$schedule->next_run_on->format('j F Y')
                .'. Raising early brings one period forward, not two.',
            );

            return;
        }

        $raised = app(GenerateRecurringInvoices::class)->generate($schedule, $schedule->next_run_on);

        $this->resolvedSchedules = null;
        $schedule->refresh();

        if ($raised === []) {
            $this->toastSuccess(
                'Nothing to raise',
                'That occurrence has already been raised. The next one is due on '
                .$schedule->next_run_on->format('j F Y').'.',
            );

            return;
        }

        $invoice = $raised[0];

        $this->toastSuccess(
            $invoice->number.' raised as a draft',
            $invoice->formattedTotal().' for '.$this->billedTo($schedule).'. It is not issued — open it, check it, then issue it.',
        );
    }

    /**
     * Stop a schedule for good.
     *
     * Soft deleted, so what it raised keeps its provenance. The invoices
     * themselves are ordinary invoices and are not touched.
     */
    public function deleteSchedule(int $id): void
    {
        $schedule = RecurringInvoice::query()->find($id);

        if ($schedule === null) {
            $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

            return;
        }

        $schedule->delete();

        $this->resolvedSchedules = null;

        if ($this->editingId === $id) {
            $this->formOpen = false;
            $this->editingId = null;
        }

        $this->toastSuccess(
            $schedule->title.' deleted',
            'It raises nothing further. Every draft it already raised is untouched.',
        );
    }

    public function filterBy(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::TABS) ? $filter : 'all';
        $this->resolvedSchedules = null;
    }

    private function trimZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
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
        <div class="flex items-center gap-2">
            <a href="{{ route('accounting.invoices') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-bill"></i> Invoices
            </a>
            <button wire:click="openForm" class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New schedule
            </button>
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        @foreach ($summary as $card)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="text-sm text-secondary-foreground">{{ $card['label'] }}</div>
                    <div class="text-2xl font-semibold mt-1 {{ $card['tone'] }}">{{ $card['value'] }}</div>
                    <div class="text-xs text-muted-foreground mt-1">{{ $card['detail'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Schedules --}}
    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <div class="flex gap-1">
                @foreach ($tabs as $key => $label)
                    <button wire:click="filterBy('{{ $key }}')"
                            class="kt-btn kt-btn-sm gap-1.5 {{ $filter === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                        {{ $label }}
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
            <span class="text-sm text-muted-foreground">
                Every run raises a draft. Issuing stays a decision you make.
            </span>
        </div>

        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[200px]">Client</th>
                            <th class="min-w-[200px]">Template</th>
                            <th class="w-[140px] text-end">Next draft</th>
                            <th class="w-[150px]">Cadence</th>
                            <th class="w-[130px]">Next run</th>
                            <th class="w-[120px]">Enabled</th>
                            <th class="w-[130px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schedules as $schedule)
                            <tr wire:key="schedule-{{ $schedule->id }}" class="{{ $schedule->is_active ? '' : 'opacity-60' }}">
                                <td>
                                    <div class="flex items-center gap-3 min-w-0">
                                        <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary/10 text-primary text-xs font-semibold shrink-0">
                                            {{ $schedule->company?->initials() ?? $schedule->customer?->initials() ?? '—' }}
                                        </span>
                                        <span class="font-medium text-mono truncate">{{ $this->billedTo($schedule) }}</span>
                                    </div>
                                </td>
                                <td class="text-secondary-foreground">
                                    {{ $schedule->title }}
                                    <span class="block text-xs text-muted-foreground mt-0.5">
                                        {{ count($schedule->templateLines()) }}
                                        {{ count($schedule->templateLines()) === 1 ? 'line' : 'lines' }}
                                        @if ($schedule->last_run_on)
                                            — last raised {{ $schedule->last_run_on->format('j M Y') }}
                                        @else
                                            — nothing raised yet
                                        @endif
                                    </span>
                                </td>
                                <td class="text-end whitespace-nowrap">
                                    <span class="font-medium text-mono">{{ $schedule->formattedTotal() }}</span>
                                    <span class="block text-xs text-muted-foreground mt-0.5">{{ $schedule->currency }}</span>
                                </td>
                                <td>
                                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $schedule->cadenceLabel() }}</span>
                                </td>
                                <td class="{{ $schedule->isDue() ? 'text-warning' : 'text-secondary-foreground' }}">
                                    {{ $schedule->next_run_on->format('j M Y') }}
                                    @if ($schedule->isDue())
                                        <span class="block text-xs">Due now</span>
                                    @endif
                                </td>
                                <td>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" class="kt-switch kt-switch-sm"
                                               wire:click="toggleSchedule({{ $schedule->id }})"
                                               wire:loading.attr="disabled" wire:target="toggleSchedule({{ $schedule->id }})"
                                               @checked($schedule->is_active)
                                               aria-label="{{ $schedule->is_active ? 'Pause' : 'Resume' }} the {{ $schedule->title }} schedule">
                                        <span class="text-xs {{ $schedule->is_active ? 'text-success' : 'text-muted-foreground' }}">
                                            {{ $schedule->is_active ? 'On' : 'Paused' }}
                                        </span>
                                    </label>
                                </td>
                                <td class="text-end">
                                    <div class="flex items-center justify-end gap-1">
                                        <button wire:click="raiseNow({{ $schedule->id }})"
                                                wire:loading.attr="disabled" wire:target="raiseNow({{ $schedule->id }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                title="Raise the next draft now" aria-label="Raise the next draft now">
                                            <i class="ki-filled ki-rocket text-sm"></i>
                                        </button>
                                        <button wire:click="openForm({{ $schedule->id }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                title="Edit schedule" aria-label="Edit schedule">
                                            <i class="ki-filled ki-pencil text-sm"></i>
                                        </button>
                                        <button wire:click="deleteSchedule({{ $schedule->id }})"
                                                wire:loading.attr="disabled" wire:target="deleteSchedule({{ $schedule->id }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive"
                                                title="Delete schedule" aria-label="Delete schedule">
                                            <i class="ki-filled ki-trash text-sm"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="flex flex-col items-center justify-center text-center py-14">
                                        <i class="ki-filled ki-arrows-circle text-3xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground mb-4">
                                            {{ $filter === 'all'
                                                ? 'No recurring schedules yet — set one up for anything you bill on a rhythm.'
                                                : 'Nothing matches this filter.' }}
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

    {{-- The form. State-driven, never KTUI: the morph strips a class KTUI added. --}}
    <div class="kt-modal kt-modal-center z-50 {{ $formOpen ? 'open' : '' }}"
         role="dialog" aria-modal="true" aria-labelledby="recurring_form_title">

        <div class="kt-modal-backdrop" wire:click="closeForm"></div>

        <div class="kt-modal-content max-w-[720px] w-full">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title" id="recurring_form_title">
                    {{ $editingId ? 'Edit schedule' : 'New recurring schedule' }}
                </h3>
                <button wire:click="closeForm" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                        title="Close" aria-label="Close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>

            <div class="kt-modal-body max-h-[70vh] kt-scrollable-y">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_customer">Client</label>
                        <select id="recurring_customer" wire:model.live="formCustomerId"
                                class="kt-select @error('formCustomerId') border-destructive @enderror">
                            <option value="">No named contact</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">
                                    {{ $customer->name }}@if ($customer->company) — {{ $customer->company->name }}@endif
                                </option>
                            @endforeach
                        </select>
                        @error('formCustomerId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_company">Company</label>
                        <select id="recurring_company" wire:model.live="formCompanyId"
                                class="kt-select @error('formCompanyId') border-destructive @enderror">
                            <option value="">Billed to the person, not a company</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">
                                    {{ $company->name }}{{ $company->is_domestic ? ' — domestic' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('formCompanyId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="recurring_title">What is being billed</label>
                        <input id="recurring_title" type="text" wire:model.blur="formTitle"
                               placeholder="Retainer — product design"
                               class="kt-input @error('formTitle') border-destructive @enderror">
                        @error('formTitle')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_currency">Currency</label>
                        <select id="recurring_currency" wire:model.live="formCurrency"
                                class="kt-select @error('formCurrency') border-destructive @enderror">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @error('formCurrency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="recurring_tax">Tax rate</label>
                        <div class="kt-input-group">
                            <input id="recurring_tax" type="text" inputmode="decimal" wire:model.blur="formTaxPercent"
                                   class="kt-input text-end @error('formTaxPercent') border-destructive @enderror">
                            <span class="kt-input-addon">%</span>
                        </div>
                        @error('formTaxPercent')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
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
                        <label class="kt-form-label" for="recurring_next">Next invoice on</label>
                        <input id="recurring_next" type="date" wire:model.blur="formNextRunOn"
                               class="kt-input @error('formNextRunOn') border-destructive @enderror">
                        @error('formNextRunOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    @if ($formCadence !== 'weekly')
                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <label class="kt-form-label" for="recurring_day">Bill on this day of the month</label>
                            <input id="recurring_day" type="text" inputmode="numeric" wire:model.blur="formDayOfMonth"
                                   placeholder="Blank keeps the day the schedule starts on"
                                   class="kt-input max-w-[220px] @error('formDayOfMonth') border-destructive @enderror">
                            <p class="kt-form-description mt-1">
                                Clamped to the length of the month, so the 31st still bills in February.
                            </p>
                            @error('formDayOfMonth')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    @endif

                    {{-- The line template --}}
                    <div class="sm:col-span-2 flex flex-col gap-3 border-t border-border pt-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-mono">Lines</div>
                                <p class="kt-form-description mt-1">Copied onto every draft this schedule raises.</p>
                            </div>
                            <button wire:click="addFormLine" class="kt-btn kt-btn-ghost kt-btn-sm gap-2 text-primary">
                                <i class="ki-filled ki-plus"></i> Add line
                            </button>
                        </div>

                        @foreach ($formLines as $i => $line)
                            <div class="flex flex-wrap items-start gap-2" wire:key="form-line-{{ $i }}">
                                <input type="text" wire:model.blur="formLines.{{ $i }}.description"
                                       placeholder="What are you billing for?"
                                       aria-label="Line {{ $i + 1 }} description"
                                       class="kt-input kt-input-sm grow min-w-[180px] @error('formLines.'.$i.'.description') border-destructive @enderror">
                                <input type="text" inputmode="decimal" wire:model.blur="formLines.{{ $i }}.quantity"
                                       aria-label="Line {{ $i + 1 }} quantity"
                                       class="kt-input kt-input-sm w-[80px] text-end @error('formLines.'.$i.'.quantity') border-destructive @enderror">
                                <div class="kt-input-group w-[150px]">
                                    <span class="kt-input-addon kt-input-addon-sm">{{ $formSymbol }}</span>
                                    <input type="text" inputmode="decimal" wire:model.blur="formLines.{{ $i }}.unit_price"
                                           aria-label="Line {{ $i + 1 }} unit price"
                                           class="kt-input kt-input-sm text-end @error('formLines.'.$i.'.unit_price') border-destructive @enderror">
                                </div>
                                <button wire:click="removeFormLine({{ $i }})" @disabled(count($formLines) === 1)
                                        class="kt-btn kt-btn-icon kt-btn-ghost size-8 text-destructive disabled:opacity-30"
                                        title="Remove line" aria-label="Remove line">
                                    <i class="ki-filled ki-trash text-sm"></i>
                                </button>
                            </div>
                        @endforeach

                        @foreach ($errors->get('formLines.*') as $messages)
                            <span class="text-xs text-destructive">{{ $messages[0] }}</span>
                        @endforeach
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="recurring_notes">Notes on every draft</label>
                        <textarea id="recurring_notes" rows="2" wire:model.blur="formNotes" class="kt-textarea w-full"
                                  placeholder="Anything the client should read alongside the figures."></textarea>
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="recurring_terms">Payment terms</label>
                        <textarea id="recurring_terms" rows="2" wire:model.blur="formTerms" class="kt-textarea w-full"
                                  placeholder="When and how you expect to be paid."></textarea>
                    </div>

                    <div class="sm:col-span-2 flex items-start justify-between gap-4 border-t border-border pt-4">
                        <div class="min-w-0">
                            <label class="kt-form-label" for="recurring_active">Armed</label>
                            <p class="kt-form-description mt-1">
                                Off means the schedule raises nothing until you switch it back on.
                            </p>
                        </div>
                        <input id="recurring_active" type="checkbox" class="kt-switch shrink-0" wire:model.live="formActive">
                    </div>

                </div>
            </div>

            <div class="kt-modal-footer">
                <button wire:click="closeForm" class="kt-btn kt-btn-ghost">Cancel</button>
                <button wire:click="saveSchedule" wire:loading.attr="disabled" wire:target="saveSchedule"
                        class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="saveSchedule" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-check"></i> {{ $editingId ? 'Save schedule' : 'Create schedule' }}
                    </span>
                    <span wire:loading wire:target="saveSchedule" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
            </div>
        </div>
    </div>

</div>
