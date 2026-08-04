<?php

use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Console\GenerateRecurringExpenses;
use Modules\Accounting\Console\GenerateRecurringInvoices;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\RecurringExpense;
use Modules\Accounting\Models\RecurringInvoice;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * Everything that happens on a rhythm: money coming in, and money going out.
 *
 * Two tables, `recurring_invoices` and `recurring_expenses`, on one page,
 * because they are the same idea pointed in opposite directions. A freelancer
 * who has to remember a retainer and a hosting bill in two different places
 * ends up remembering neither, and a forgotten standing cost quietly flatters
 * every profit figure it is missing from.
 *
 * **Invoices are raised as drafts. Expenses are recorded outright.** That is
 * not an inconsistency — it is the difference between the two. Issuing an
 * invoice freezes a rate against a client and is a decision a person makes with
 * the document in front of them; nothing equivalent is being decided about a
 * hosting bill, which left the account whether or not anybody opened Kargah
 * that morning. `GenerateRecurringExpenses`' docblock argues this at length.
 *
 * "Raise now" and "Record now" both run exactly the code the scheduled job
 * runs, deliberately, so an impatient second click is as harmless as a second
 * cron run: an invoice occurrence is claimed by the number derived from it, an
 * expense occurrence by a conditional update on `next_run_on`.
 *
 * Pausing is a flag rather than a delete on both halves, because a paused
 * retainer and a cancelled subscription both usually come back, and what they
 * have already produced has to keep making sense.
 *
 * **A schedule holds no exchange rate.** The one figure this page shows that
 * crosses a currency — the yearly cost of a standing commitment — is shown per
 * currency and never added across them, for the reason `Money` exists.
 */
new
#[Title('Recurring — Kargah')]
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

    /* The cost half ----------------------------------------------------------- */

    // Every property below is prefixed `cost`. Two forms live on this page and
    // Livewire flattens both into one component state, so `vendor` and
    // `formTitle` sharing a namespace is the shape a cross-wired form arrives
    // in — the invoice form's currency quietly following the expense form's.

    public bool $costFormOpen = false;

    /** Null while creating, the schedule key while editing. */
    public ?int $costEditingId = null;

    public string $costVendor = '';

    public string $costCategory = 'Hosting';

    public string $costDescription = '';

    public string $costCurrency = Currencies::USD;

    /** A decimal string from the input to the column; never a float. */
    public string $costAmount = '';

    public string $costCadence = 'monthly';

    public string $costNextRunOn = '';

    /** Blank keeps whatever day the schedule starts on. */
    public string $costDayOfMonth = '';

    public ?string $costCompanyId = null;

    public bool $costBillable = false;

    public bool $costActive = true;

    private ?Collection $resolvedCostSchedules = null;

    public function mount(): void
    {
        $this->resetForm();
        $this->resetCostForm();
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

    /* Reading the cost schedules ----------------------------------------------- */

    /**
     * Every standing cost, active first, then by the date it next lands.
     *
     * Read whole rather than filtered: the summary above the table counts
     * active, paused and due out of the same collection, and three tabs' worth
     * of filtering on a list that is realistically a dozen rows would cost three
     * queries to save nothing.
     */
    private function costSchedules(): Collection
    {
        return $this->resolvedCostSchedules ??= RecurringExpense::query()
            ->with('company')
            ->orderByDesc('is_active')
            ->orderBy('next_run_on')
            ->get();
    }

    /**
     * What the standing costs come to in a year, one figure per currency.
     *
     * 🔴 Never one figure. Adding a dollar subscription to a lira one needs a
     * rate, and a rate needs a date and a source before anybody can argue with
     * the result — so two currencies get two numbers and the card says which is
     * which. Added through `Money`, in PHP, because a SQL `SUM()` of a decimal
     * column on SQLite is a sum of doubles.
     *
     * @return list<array{currency: string, formatted: string}>
     */
    private function yearlyCost(Collection $active): array
    {
        return $active
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency): array => [
                'currency' => $currency,
                'formatted' => Money::format(
                    Money::toStorage(Money::sum(
                        $group->map(fn (RecurringExpense $schedule): BrickMoney => $schedule->annualisedAmount()),
                        $currency,
                    )),
                    $currency,
                ),
            ])
            ->values()
            ->all();
    }

    /** The three cards above the costs table. */
    private function costSummary(Collection $schedules): array
    {
        $active = $schedules->filter(fn (RecurringExpense $schedule): bool => $schedule->is_active);
        $paused = $schedules->count() - $active->count();
        $dueNow = $active->filter(fn (RecurringExpense $schedule): bool => $schedule->isDue())->count();
        $next = $active->min('next_run_on');

        return [
            [
                'label' => 'Standing costs',
                'value' => (string) $active->count(),
                'detail' => $paused === 0 ? 'None paused.' : $paused.' paused.',
                'tone' => 'text-mono',
            ],
            [
                'label' => 'Due to record',
                'value' => (string) $dueNow,
                'detail' => $dueNow === 0
                    ? 'Nothing is waiting on the job.'
                    : 'The next run will record '.$dueNow.' '.str('expense')->plural($dueNow).'.',
                'tone' => $dueNow === 0 ? 'text-mono' : 'text-warning',
            ],
            [
                'label' => 'Next cost recorded',
                'value' => $next === null ? '—' : Carbon::parse($next)->format('j M Y'),
                'detail' => $next === null
                    ? 'No active cost schedule to run.'
                    : 'Each expense freezes its own rate on the day it lands.',
                'tone' => 'text-primary',
            ],
        ];
    }

    public function with(): array
    {
        $counts = $this->counts();

        $dueNow = RecurringInvoice::query()->due()->count();
        $next = $this->nextRun();

        $costSchedules = $this->costSchedules();

        return [
            'costSchedules' => $costSchedules,
            'costSummary' => $this->costSummary($costSchedules),
            'yearlyCost' => $this->yearlyCost(
                $costSchedules->filter(fn (RecurringExpense $schedule): bool => $schedule->is_active),
            ),
            'costCategories' => $this->categoryOptions(),
            'costSymbol' => Currencies::symbol(
                in_array($this->costCurrency, Currencies::supported(), true) ? $this->costCurrency : Currencies::USD,
            ),
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

    /**
     * The categories a cost schedule may be filed under.
     *
     * Whatever the expenses table already uses, merged with the list Kargah
     * ships and with whatever the cost schedules already use. A category the
     * owner invented on the expense form should not have to be invented a
     * second time here — and a category that only exists on a schedule would
     * otherwise disappear from this list the moment the schedule was edited.
     *
     * @return list<string>
     */
    private function categoryOptions(): array
    {
        return collect(RecurringExpense::CATEGORIES)
            ->merge(Expense::query()->whereNotNull('category')->distinct()->pluck('category'))
            ->merge(RecurringExpense::query()->whereNotNull('category')->distinct()->pluck('category'))
            ->unique()
            ->sort()
            ->values()
            ->all();
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

    /* Standing costs ------------------------------------------------------------ */

    private function resetCostForm(): void
    {
        $this->costEditingId = null;
        $this->costVendor = '';
        $this->costCategory = 'Hosting';
        $this->costDescription = '';
        $this->costCurrency = Currencies::USD;
        $this->costAmount = '';
        $this->costCadence = 'monthly';
        $this->costNextRunOn = today()->addMonthNoOverflow()->toDateString();
        $this->costDayOfMonth = '';
        $this->costCompanyId = null;
        $this->costBillable = false;
        $this->costActive = true;
    }

    public function openCostForm(?int $id = null): void
    {
        $this->resetValidation();
        $this->resetCostForm();

        if ($id !== null) {
            $schedule = RecurringExpense::query()->find($id);

            if ($schedule === null) {
                $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

                return;
            }

            $this->costEditingId = (int) $schedule->getKey();
            $this->costVendor = (string) $schedule->vendor;
            $this->costCategory = $schedule->category ?: 'Other';
            $this->costDescription = (string) $schedule->description;
            $this->costCurrency = $schedule->currency;
            // Stored at six decimals; the form shows the currency's own.
            $this->costAmount = $this->displayAmount((string) $schedule->amount, $schedule->currency);
            $this->costCadence = $schedule->cadence;
            $this->costNextRunOn = $schedule->next_run_on->toDateString();
            $this->costDayOfMonth = $schedule->day_of_month === null ? '' : (string) $schedule->day_of_month;
            $this->costCompanyId = $schedule->company_id === null ? null : (string) $schedule->company_id;
            $this->costBillable = (bool) $schedule->is_billable;
            $this->costActive = (bool) $schedule->is_active;
        }

        $this->costFormOpen = true;
    }

    public function closeCostForm(): void
    {
        $this->costFormOpen = false;
    }

    /**
     * Validated against an explicit set rather than `rules()`.
     *
     * `$this->validate()` with no argument validates *every* rule the component
     * declares, and this page declares two forms' worth. Saving a retainer would
     * fail on a blank vendor field the person never saw.
     */
    private function costRules(): array
    {
        return [
            'costVendor' => ['required', 'string', 'max:190'],
            'costCategory' => ['required', 'string', 'max:60'],
            'costDescription' => ['nullable', 'string', 'max:2000'],
            'costCurrency' => ['required', Rule::in(Currencies::supported())],
            'costAmount' => ['required', 'numeric', 'gt:0'],
            'costCadence' => ['required', Rule::in(array_keys(RecurringExpense::CADENCES))],
            'costNextRunOn' => ['required', 'date'],
            'costDayOfMonth' => ['nullable', 'integer', 'min:1', 'max:31'],
            'costCompanyId' => ['nullable', 'exists:companies,id'],
        ];
    }

    private function costAttributes(): array
    {
        return [
            'costVendor' => 'vendor',
            'costCategory' => 'category',
            'costDescription' => 'description',
            'costCurrency' => 'currency',
            'costAmount' => 'amount',
            'costCadence' => 'cadence',
            'costNextRunOn' => 'first expense date',
            'costDayOfMonth' => 'day of the month',
            'costCompanyId' => 'company',
        ];
    }

    public function saveCostSchedule(): void
    {
        $this->validate($this->costRules(), [], $this->costAttributes());

        $currency = in_array($this->costCurrency, Currencies::supported(), true) ? $this->costCurrency : Currencies::USD;

        $attributes = [
            'company_id' => $this->costCompanyId === null || $this->costCompanyId === '' ? null : (int) $this->costCompanyId,
            'vendor' => trim($this->costVendor),
            'category' => $this->costCategory,
            'description' => trim($this->costDescription) === '' ? null : trim($this->costDescription),
            'currency' => $currency,
            // A string from the input to the column. Casting it to build the
            // stored value is the one step that would throw away precision.
            'amount' => Money::toStorage(Money::of(RecurringExpense::decimal($this->costAmount, '0'), $currency)),
            'is_billable' => $this->costBillable,
            'cadence' => $this->costCadence,
            'day_of_month' => $this->costDayOfMonth === '' ? null : (int) $this->costDayOfMonth,
            'next_run_on' => $this->costNextRunOn,
            'is_active' => $this->costActive,
        ];

        $schedule = $this->costEditingId === null
            ? RecurringExpense::query()->create($attributes + ['created_by' => auth()->id()])
            : tap(RecurringExpense::query()->findOrFail($this->costEditingId))->update($attributes);

        $this->costFormOpen = false;
        $this->resolvedCostSchedules = null;

        $schedule->refresh();

        $this->toastSuccess(
            $schedule->vendor.($this->costEditingId === null ? ' added' : ' updated'),
            $schedule->formattedAmount().' '.strtolower($schedule->cadenceLabel())
            .' — '.$schedule->formattedAnnualised().' a year. Next recorded on '
            .$schedule->next_run_on->format('j F Y').'.',
        );

        $this->costEditingId = null;
    }

    public function toggleCostSchedule(int $id): void
    {
        $schedule = RecurringExpense::query()->find($id);

        if ($schedule === null) {
            $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

            return;
        }

        $schedule->forceFill(['is_active' => ! $schedule->is_active])->save();

        $this->resolvedCostSchedules = null;

        $this->toastSuccess(
            $schedule->is_active ? $schedule->vendor.' resumed' : $schedule->vendor.' paused',
            $schedule->is_active
                ? 'The next expense is recorded on '.$schedule->next_run_on->format('j F Y').'.'
                : 'It records nothing until you resume it. What it has already recorded is untouched.',
        );
    }

    /**
     * Record the next occurrence now, ahead of its date.
     *
     * The same code path the scheduled job takes, so a second impatient click is
     * as harmless as a second cron run: the occurrence is claimed by a
     * conditional update and the loser writes nothing.
     *
     * **One period at a time**, exactly as `raiseNow()` does. Recording early
     * moves the schedule on, and without this guard a double click would record
     * next month's hosting bill as well — a cost that has not happened yet,
     * sitting in this month's profit and loss.
     */
    public function recordNow(int $id): void
    {
        $schedule = RecurringExpense::query()->find($id);

        if ($schedule === null) {
            $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

            return;
        }

        if (! $schedule->is_active) {
            $this->toastError(
                $schedule->vendor.' is paused',
                'Resume it first — a paused schedule records nothing, by design.',
            );

            return;
        }

        if ($schedule->next_run_on->isAfter($schedule->advanceFrom(today()))) {
            $this->toastError(
                'Nothing to record yet',
                'The next expense is due on '.$schedule->next_run_on->format('j F Y')
                .'. Recording early brings one period forward, not two.',
            );

            return;
        }

        $recorded = app(GenerateRecurringExpenses::class)->generate($schedule, $schedule->next_run_on);

        $this->resolvedCostSchedules = null;
        $schedule->refresh();

        if ($recorded === []) {
            $this->toastSuccess(
                'Nothing to record',
                'That occurrence has already been recorded. The next one is due on '
                .$schedule->next_run_on->format('j F Y').'.',
            );

            return;
        }

        $expense = $recorded[0];

        $this->toastSuccess(
            $expense->formattedAmount().' to '.$expense->vendor.' recorded',
            $expense->reporting_amount === null
                ? 'Dated '.$expense->spent_on->format('j F Y').'. No '.$expense->currency
                    .' to USD rate is on file for that day, so the reporting figure is blank rather than guessed.'
                : 'Dated '.$expense->spent_on->format('j F Y').', reported as '
                    .$expense->formattedReporting().' at '.$expense->reporting_rate.'. It is on the expenses page now.',
        );
    }

    /**
     * Stop a standing cost for good.
     *
     * Soft deleted, and the expenses it has already recorded are not touched —
     * not detached, not soft-deleted, not reversed. They are money that really
     * left the account, and a subscription being cancelled today does not make
     * last March's payment for it untrue. Nothing cascades because nothing
     * points: a generated expense carries no reference to the schedule, which is
     * the same rule the invoice side follows with its raised drafts.
     */
    public function deleteCostSchedule(int $id): void
    {
        $schedule = RecurringExpense::query()->find($id);

        if ($schedule === null) {
            $this->toastError('That schedule is gone', 'It was deleted while this page was open.');

            return;
        }

        $schedule->delete();

        $this->resolvedCostSchedules = null;

        if ($this->costEditingId === $id) {
            $this->costFormOpen = false;
            $this->costEditingId = null;
        }

        $this->toastSuccess(
            $schedule->vendor.' deleted',
            'It records nothing further. Every expense it already recorded stays exactly as it is.',
        );
    }

    /**
     * What deleting a cost schedule does, said before the click rather than
     * after. The same sentence for every schedule, because the consequence is
     * the same for every schedule — the vendor's name is already in the
     * question above it.
     */
    public function costRemovalConsequence(): string
    {
        return 'It will record nothing further. Every expense it has already recorded stays exactly where it is — '
            .'that money really left. Nothing in Kargah brings the schedule back.';
    }

    /** A stored six-decimal amount, back at the currency's own scale for a form field. */
    private function displayAmount(string $stored, string $currency): string
    {
        return (string) Money::fromStorage($stored, $currency)
            ->getAmount()
            ->toScale(Currencies::minorUnit($currency), Money::ROUNDING);
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
            <h1 class="text-xl font-semibold text-mono">Recurring</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Set a retainer and a hosting bill once, and stop remembering either of them.
            </p>
        </div>
        {{--
            `flex-wrap` because three buttons do not fit a 375px screen. The
            outer heading row already wraps; this inner group did not, so at
            375px it ended at 417px and the page scrolled sideways — measured
            in Chrome, not inferred.
        --}}
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('accounting.invoices') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-bill"></i> Invoices
            </a>
            <a href="{{ route('accounting.expenses') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-wallet"></i> Expenses
            </a>
            <button wire:click="openForm" class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New invoice schedule
            </button>
        </div>
    </div>

    {{-- Money coming in --}}
    <h2 class="text-sm font-medium text-secondary-foreground uppercase tracking-wide">Money coming in</h2>

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
                                        {{-- Named and consequential, never "Are you sure?". This is a
                                             financial record and the person needs to know that the
                                             drafts it already raised are not going anywhere. --}}
                                        <button wire:click="deleteSchedule({{ $schedule->id }})"
                                                wire:loading.attr="disabled" wire:target="deleteSchedule({{ $schedule->id }})"
                                                wire:confirm="Delete the {{ $schedule->title }} schedule?&#10;&#10;It raises nothing further. Every draft it has already raised stays exactly where it is. Nothing in Kargah brings the schedule back."
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

    {{-- Money going out --}}
    <h2 class="text-sm font-medium text-secondary-foreground uppercase tracking-wide mt-2">Money going out</h2>

    {{-- Cost summary. The yearly figure is one number per currency and never a
         single total: adding a dollar subscription to a lira one needs a rate,
         and a rate needs a date and a source before anyone can argue with it. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        @foreach ($costSummary as $card)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="text-sm text-secondary-foreground">{{ $card['label'] }}</div>
                    <div class="text-2xl font-semibold mt-1 {{ $card['tone'] }}">{{ $card['value'] }}</div>
                    <div class="text-xs text-muted-foreground mt-1">{{ $card['detail'] }}</div>
                </div>
            </div>
        @endforeach

        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="text-sm text-secondary-foreground">Committed for a year</div>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 mt-1">
                    @forelse ($yearlyCost as $total)
                        <span class="text-2xl font-semibold text-mono">{{ $total['formatted'] }}</span>
                    @empty
                        <span class="text-2xl font-semibold text-muted-foreground">—</span>
                    @endforelse
                </div>
                <div class="text-xs text-muted-foreground mt-1">
                    @if ($yearlyCost === [])
                        Nothing standing yet.
                    @else
                        An estimate, one figure per currency — never added together.
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Standing costs --}}
    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <h3 class="kt-card-title">Standing costs</h3>
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-sm text-muted-foreground">
                    Recorded as real expenses — a bill you paid is not a draft.
                </span>
                <button wire:click="openCostForm" class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                    <i class="ki-filled ki-plus"></i> New cost schedule
                </button>
            </div>
        </div>

        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[200px]">Vendor</th>
                            <th class="min-w-[180px]">What it is</th>
                            <th class="w-[150px] text-end">Each time</th>
                            <th class="w-[150px] text-end">A year</th>
                            <th class="w-[150px]">Cadence</th>
                            <th class="w-[130px]">Next run</th>
                            <th class="w-[120px]">Enabled</th>
                            <th class="w-[130px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($costSchedules as $cost)
                            <tr wire:key="cost-{{ $cost->id }}" class="{{ $cost->is_active ? '' : 'opacity-60' }}">
                                <td>
                                    <div class="flex items-center gap-3 min-w-0">
                                        <span class="inline-flex items-center justify-center size-8 rounded-lg bg-destructive/10 text-destructive shrink-0">
                                            <i class="ki-filled ki-wallet text-sm"></i>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block font-medium text-mono truncate">{{ $cost->vendor }}</span>
                                            @if ($cost->company)
                                                <span class="block text-xs text-muted-foreground truncate">
                                                    For {{ $cost->company->name }}
                                                </span>
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td class="text-secondary-foreground">
                                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $cost->category ?: 'Uncategorised' }}</span>
                                    <span class="block text-xs text-muted-foreground mt-0.5">
                                        @if ($cost->is_billable)
                                            Recoverable from the client —
                                        @endif
                                        @if ($cost->last_run_on)
                                            last recorded {{ $cost->last_run_on->format('j M Y') }}
                                        @else
                                            nothing recorded yet
                                        @endif
                                    </span>
                                </td>
                                <td class="text-end whitespace-nowrap">
                                    <span class="font-medium text-mono">{{ $cost->formattedAmount() }}</span>
                                    <span class="block text-xs text-muted-foreground mt-0.5">{{ $cost->currency }}</span>
                                </td>
                                <td class="text-end whitespace-nowrap text-secondary-foreground">
                                    {{ $cost->formattedAnnualised() }}
                                </td>
                                <td>
                                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $cost->cadenceLabel() }}</span>
                                </td>
                                <td class="{{ $cost->isDue() ? 'text-warning' : 'text-secondary-foreground' }}">
                                    {{ $cost->next_run_on->format('j M Y') }}
                                    @if ($cost->isDue())
                                        <span class="block text-xs">Due now</span>
                                    @endif
                                </td>
                                <td>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" class="kt-switch kt-switch-sm"
                                               wire:click="toggleCostSchedule({{ $cost->id }})"
                                               wire:loading.attr="disabled" wire:target="toggleCostSchedule({{ $cost->id }})"
                                               @checked($cost->is_active)
                                               aria-label="{{ $cost->is_active ? 'Pause' : 'Resume' }} the {{ $cost->vendor }} schedule">
                                        <span class="text-xs {{ $cost->is_active ? 'text-success' : 'text-muted-foreground' }}">
                                            {{ $cost->is_active ? 'On' : 'Paused' }}
                                        </span>
                                    </label>
                                </td>
                                <td class="text-end">
                                    <div class="flex items-center justify-end gap-1">
                                        <button wire:click="recordNow({{ $cost->id }})"
                                                wire:loading.attr="disabled" wire:target="recordNow({{ $cost->id }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                title="Record this expense now" aria-label="Record this expense now">
                                            <i class="ki-filled ki-rocket text-sm"></i>
                                        </button>
                                        <button wire:click="openCostForm({{ $cost->id }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                title="Edit schedule" aria-label="Edit schedule">
                                            <i class="ki-filled ki-pencil text-sm"></i>
                                        </button>
                                        {{-- The consequence in full, because the expenses this has
                                             already recorded are money that really left and nobody
                                             should have to guess whether they go with it. --}}
                                        <button wire:click="deleteCostSchedule({{ $cost->id }})"
                                                wire:loading.attr="disabled" wire:target="deleteCostSchedule({{ $cost->id }})"
                                                wire:confirm="Delete the {{ $cost->vendor }} schedule?&#10;&#10;{{ $this->costRemovalConsequence() }}"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive"
                                                title="Delete schedule" aria-label="Delete schedule">
                                            <i class="ki-filled ki-trash text-sm"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="flex flex-col items-center justify-center text-center py-14">
                                        <i class="ki-filled ki-wallet text-3xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground mb-4">
                                            No standing costs yet — hosting, domains, the design tool, the accountant's
                                            monthly fee. The ones nobody wants to type twelve times a year.
                                        </p>
                                        <button wire:click="openCostForm" class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                            <i class="ki-filled ki-plus"></i> New cost schedule
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

    {{-- The cost form. A second modal, its own `open` flag, its own field
         prefix: two forms in one Livewire component share one state, and a
         shared field name is the shape a cross-wired form arrives in. --}}
    <div class="kt-modal kt-modal-center z-50 {{ $costFormOpen ? 'open' : '' }}"
         role="dialog" aria-modal="true" aria-labelledby="cost_form_title">

        <div class="kt-modal-backdrop" wire:click="closeCostForm"></div>

        <div class="kt-modal-content max-w-[720px] w-full">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title" id="cost_form_title">
                    {{ $costEditingId ? 'Edit standing cost' : 'New standing cost' }}
                </h3>
                <button wire:click="closeCostForm" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                        title="Close" aria-label="Close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>

            <div class="kt-modal-body max-h-[70vh] kt-scrollable-y">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="cost_vendor">Who gets paid</label>
                        <input id="cost_vendor" type="text" wire:model.blur="costVendor"
                               placeholder="Hetzner, Figma, the accountant…"
                               class="kt-input @error('costVendor') border-destructive @enderror">
                        @error('costVendor')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="cost_category">Category</label>
                        <select id="cost_category" wire:model.live="costCategory"
                                class="kt-select @error('costCategory') border-destructive @enderror">
                            @foreach ($costCategories as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                        @error('costCategory')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="cost_currency">Currency</label>
                        <select id="cost_currency" wire:model.live="costCurrency"
                                class="kt-select @error('costCurrency') border-destructive @enderror">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @error('costCurrency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="cost_amount">Amount each time</label>
                        <div class="kt-input-group">
                            <span class="kt-input-addon">{{ $costSymbol }}</span>
                            <input id="cost_amount" type="text" inputmode="decimal" placeholder="0.00"
                                   wire:model.blur="costAmount"
                                   class="kt-input text-end @error('costAmount') border-destructive @enderror">
                        </div>
                        @error('costAmount')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="cost_cadence">Cadence</label>
                        <select id="cost_cadence" wire:model.live="costCadence"
                                class="kt-select @error('costCadence') border-destructive @enderror">
                            @foreach ($cadences as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('costCadence')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="cost_next">Next payment on</label>
                        <input id="cost_next" type="date" wire:model.blur="costNextRunOn"
                               class="kt-input @error('costNextRunOn') border-destructive @enderror">
                        @error('costNextRunOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    @if ($costCadence !== 'weekly')
                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="cost_day">Charged on this day of the month</label>
                            <input id="cost_day" type="text" inputmode="numeric" wire:model.blur="costDayOfMonth"
                                   placeholder="Blank keeps the starting day"
                                   class="kt-input @error('costDayOfMonth') border-destructive @enderror">
                            <p class="kt-form-description mt-1">
                                Clamped to the length of the month, so the 31st still lands in February.
                            </p>
                            @error('costDayOfMonth')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    @endif

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="cost_description">What it is for</label>
                        <textarea id="cost_description" rows="2" wire:model.blur="costDescription"
                                  class="kt-textarea w-full @error('costDescription') border-destructive @enderror"
                                  placeholder="Copied onto every expense this records. Future you will want to know."></textarea>
                        @error('costDescription')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label" for="cost_company">Company</label>
                        <select id="cost_company" wire:model.live="costCompanyId"
                                class="kt-select @error('costCompanyId') border-destructive @enderror">
                            <option value="">No company — this is a cost of the business</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                        @error('costCompanyId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="sm:col-span-2 flex items-start justify-between gap-4 border-t border-border pt-4">
                        <div class="min-w-0">
                            <label class="kt-form-label" for="cost_billable">The client agreed to cover this</label>
                            <p class="kt-form-description mt-1">
                                Every expense this records is marked recoverable and stays unbilled until an invoice
                                actually carries it — which is the money most easily forgotten.
                            </p>
                        </div>
                        <input id="cost_billable" type="checkbox" class="kt-switch shrink-0" wire:model.live="costBillable">
                    </div>

                    <div class="sm:col-span-2 flex items-start justify-between gap-4 border-t border-border pt-4">
                        <div class="min-w-0">
                            <label class="kt-form-label" for="cost_active">Armed</label>
                            <p class="kt-form-description mt-1">
                                Off means it records nothing until you switch it back on. What it has already recorded
                                is untouched either way.
                            </p>
                        </div>
                        <input id="cost_active" type="checkbox" class="kt-switch shrink-0" wire:model.live="costActive">
                    </div>

                    <p class="sm:col-span-2 text-xs text-muted-foreground border-t border-border pt-4">
                        The schedule holds no exchange rate. Each expense it records freezes its own, on its own date,
                        exactly as one you type by hand does — and if no rate is on file for that day the expense is
                        still recorded, with the converted figure left blank rather than guessed.
                    </p>

                </div>
            </div>

            <div class="kt-modal-footer">
                <button wire:click="closeCostForm" class="kt-btn kt-btn-ghost">Cancel</button>
                <button wire:click="saveCostSchedule" wire:loading.attr="disabled" wire:target="saveCostSchedule"
                        class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="saveCostSchedule" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-check"></i> {{ $costEditingId ? 'Save cost' : 'Add cost' }}
                    </span>
                    <span wire:loading wire:target="saveCostSchedule" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
            </div>
        </div>
    </div>

</div>
