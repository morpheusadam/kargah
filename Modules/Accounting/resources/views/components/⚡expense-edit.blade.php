<?php

use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Services\InvoiceIssuer;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Company;

/**
 * Expense editor.
 *
 * Recording an expense is a thirty-second job, so the form is a single column
 * with the summary beside it — nothing here should need a second visit.
 *
 * **The amount is a string from the input to the column and never becomes a
 * number.** Livewire binds a form field as a string; casting it to build the
 * total is the one step that would throw away precision, and it is the step
 * right before a human reads the figure. Every arithmetic operation goes
 * through `Money`.
 *
 * **The reporting figure is frozen at save**, exactly as an invoice freezes its
 * own, in the currency `config('accounting.reporting_currency')` names — read
 * through `InvoiceIssuer::reportingCurrency()` so there is one place that
 * decides and nothing has to assume. Converting at read time would make last
 * March's cost move every time the lira does. When no rate is available for the
 * date, the figure is left null and the toast says so — an invented rate is
 * worse than a missing one.
 *
 * **Deleting an expense lives here and nowhere else.** The list page links each
 * vendor to this page rather than carrying its own delete: the routine has to
 * reverse a ledger entry and refuse a rebilled cost, and the same twenty lines
 * written twice is the shape money bugs arrive in. If a second delete affordance
 * is ever wanted, extract `delete()` to a service first — do not copy it.
 */
new
#[Title('Record expense — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Categories offered before the database has any of its own. */
    public const CATEGORIES = [
        'Hosting', 'Software', 'Email', 'Domains', 'Hardware', 'Travel', 'Subcontractors', 'Other',
    ];

    /** Set once the expense exists, so a second save updates rather than duplicates. */
    public ?int $expenseId = null;

    #[Validate('required|string|max:190')]
    public string $vendor = '';

    #[Validate('required|date')]
    public string $spentOn = '';

    #[Validate('required|string|max:60')]
    public string $category = 'Hosting';

    #[Validate('nullable|string|max:2000')]
    public string $description = '';

    /**
     * Kept as a string all the way to the column.
     *
     * `float $amount` here would be the whole bug: 71.88 cannot be held exactly
     * in binary, and the error only surfaces once a year of them is added up.
     */
    #[Validate('required|numeric|gt:0')]
    public string $amount = '';

    #[Validate('required|string|max:10')]
    public string $currency = Currencies::USD;

    public bool $billable = false;

    /** A company id as a string, or '' for an expense that belongs to no client. */
    public string $companyId = '';

    #[Validate('nullable|string|max:190')]
    public string $receiptReference = '';

    private ?Collection $resolvedCompanies = null;

    /**
     * Per-request memo for `reportingCurrency()`, so the summary panel and the
     * save that follows it cannot disagree, and an edit does not ask the
     * database the same one-column question on every render.
     */
    private ?string $resolvedReportingCurrency = null;

    /** Whether the last `persist()` inserted or updated. Set there, read by the toasts. */
    private bool $wasCreated = true;

    /**
     * `/expenses/create` passes nothing; an edit route would pass the id. Both
     * land here so the form has one shape whichever way it was reached.
     */
    public function mount(?string $expense = null): void
    {
        $this->spentOn = now()->toDateString();

        if ($expense === null) {
            return;
        }

        $record = Expense::query()->find((int) $expense);

        if ($record === null) {
            $this->toastError('That expense is gone', 'It was deleted while the page was open. This is a fresh one.');

            return;
        }

        $this->expenseId = $record->id;
        $this->vendor = $record->vendor;
        $this->spentOn = $record->spent_on->toDateString();
        $this->category = $record->category ?: 'Other';
        $this->description = (string) $record->description;
        // The stored scale is six; the form shows the currency's own.
        $this->amount = $this->displayAmount((string) $record->amount, $record->currency);
        $this->currency = $record->currency;
        $this->billable = $record->is_billable;
        $this->companyId = (string) ($record->company_id ?? '');
        $this->receiptReference = (string) $record->receipt_reference;
    }

    /* Reading ---------------------------------------------------------------- */

    private function companies(): Collection
    {
        return $this->resolvedCompanies ??= Company::query()->active()->orderBy('name')->get();
    }

    /**
     * What was paid for before, so a monthly bill is two clicks.
     *
     * Real rows, most recent first, one per vendor — a suggestion that is not
     * something the owner actually paid is not a suggestion.
     */
    private function suggestions(): Collection
    {
        return Expense::query()
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->unique('vendor')
            ->reject(fn (Expense $expense): bool => $expense->id === $this->expenseId)
            ->take(4)
            ->values();
    }

    /* Money ------------------------------------------------------------------- */

    /** The typed amount, or zero while it is empty or half-typed. */
    private function typedAmount(): string
    {
        $typed = trim($this->amount);

        return is_numeric($typed) ? $typed : '0';
    }

    private function money(): BrickMoney
    {
        return Money::of($this->typedAmount(), $this->knownCurrency());
    }

    /** A currency the money layer has heard of, whatever the form says. */
    private function knownCurrency(): string
    {
        return Currencies::isKnown($this->currency) ? $this->currency : Currencies::USD;
    }

    /** A stored six-decimal amount, back at the currency's own scale for a form field. */
    private function displayAmount(string $stored, string $currency): string
    {
        return (string) Money::fromStorage($stored, $currency)
            ->getAmount()
            ->toScale(Currencies::minorUnit($currency), Money::ROUNDING);
    }

    /**
     * The currency this expense's reporting figure is expressed in.
     *
     * A new expense takes it from configuration, through the same
     * `InvoiceIssuer::reportingCurrency()` an invoice uses at issue — one place
     * decides, so the two halves of the book cannot drift apart and report
     * income in lira against costs in dollars.
     *
     * 🔴 **An expense that already exists keeps the currency it froze**, even
     * when the setting has changed since. That is the no-backfill rule, and it
     * lives here rather than in `persist()` because the summary panel has to
     * preview the same answer the save will write. `reporting_currency` is the
     * record of what was believed on the day the money left; a book that has
     * run for a while is a *mixed* book — older costs in dollars, newer ones in
     * lira — and that is the normal state, which is why every total on the
     * expenses page groups by it. Without this, correcting a typo on a March
     * expense would silently move it out of the dollar column into the lira
     * one, and no page would say it had moved.
     *
     * An edit still recomputes the *rate* and the *amount* in that frozen
     * currency: those have to follow an edited amount or date. It is the
     * currency that must not drift.
     */
    private function reportingCurrency(): string
    {
        if ($this->resolvedReportingCurrency !== null) {
            return $this->resolvedReportingCurrency;
        }

        $frozen = $this->expenseId === null
            ? null
            : Expense::query()->whereKey($this->expenseId)->value('reporting_currency');

        return $this->resolvedReportingCurrency = is_string($frozen) && Currencies::isKnown($frozen)
            ? $frozen
            : InvoiceIssuer::reportingCurrency();
    }

    /**
     * The reporting figure, frozen at the date the money was spent.
     *
     * 🔴 Which pairs actually resolve, because it decides what comes back null.
     * `rateFor()` will invert a stored pair but will not chain two of them, and
     * it asks for the `market` rate — so TCMB's buying and selling rows, which
     * are stored under their own rate types, are not what this reads. With lira
     * as the reporting currency a lira cost is the identity and a **dollar cost
     * needs Frankfurter's USD/TRY row for that date**. USDT/TRY is fetched too,
     * but only as the optional lira leg of the CoinGecko call — see
     * `CoinGecko::fetch()` — so a stablecoin cost converts on the days that leg
     * was quoted and freezes nothing on the days it was not.
     *
     * Either way the expense is still recorded. A cost that really left the
     * account must never be refused because a rate was missing, and one counted
     * out loud as unconverted is worth far more than one that is not on the
     * book at all.
     *
     * @return array{0: ?string, 1: ?string} the rate and the converted amount, both null when no rate exists
     */
    private function reportingFigures(BrickMoney $amount): array
    {
        $reporting = $this->reportingCurrency();

        if ($this->knownCurrency() === $reporting) {
            return ['1.000000', Money::toStorage($amount)];
        }

        $rate = app(ExchangeRates::class)->rateFor($this->knownCurrency(), $reporting, $this->spentOn);

        if ($rate === null) {
            // Nothing to defend a converted number with, so there is no
            // converted number. The row says the amount and its own currency.
            return [null, null];
        }

        return [$rate, Money::toStorage(Money::convert($amount, $rate, $reporting))];
    }

    /* View --------------------------------------------------------------------- */

    public function with(): array
    {
        $categories = collect(self::CATEGORIES)
            ->merge(Expense::query()->whereNotNull('category')->distinct()->pluck('category'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Read once for the removal panel rather than once per sentence. A blank
        // form has no row behind it and asks the database nothing.
        $existing = $this->expenseId === null
            ? null
            : Expense::query()->with('rebilledOn')->find($this->expenseId);

        return [
            'currencies' => Currencies::supported(),
            'symbol' => Currencies::symbol($this->knownCurrency()),
            'categories' => $categories,
            'companies' => $this->companies(),
            'formattedAmount' => Money::format($this->typedAmount(), $this->knownCurrency()),
            'reportingNote' => $this->reportingNote(),
            'suggestions' => $this->suggestions()->map(fn (Expense $expense): array => [
                'id' => $expense->id,
                'vendor' => $expense->vendor,
                'category' => $expense->category ?: 'Uncategorised',
                'amount' => $expense->formattedAmount(),
                'when' => $expense->spent_on->format('d M Y'),
            ])->all(),
            'editing' => $this->expenseId !== null,

            'saved' => $existing !== null,
            'deletable' => $existing !== null && ! $existing->isRebilled(),
            'deleteHeadline' => $existing === null ? '' : $this->removalHeadline($existing),
            'deleteConsequence' => $existing === null ? '' : $this->removalConsequence($existing),
            'deleteRefusal' => $existing === null || ! $existing->isRebilled()
                ? null
                : $this->rebilledRefusal($existing),
        ];
    }

    /** What the reporting figure will be, said before the expense is saved rather than after. */
    private function reportingNote(): string
    {
        $reporting = $this->reportingCurrency();

        if ($this->knownCurrency() === $reporting) {
            return 'Reported in '.$reporting.' at 1.000000 — it is already the reporting currency.';
        }

        [$rate, $converted] = $this->reportingFigures($this->money());

        if ($rate === null) {
            return 'No '.$this->knownCurrency().' to '.$reporting.' rate is stored for '.$this->spentOn
                .', so the reporting figure will be left blank rather than guessed.';
        }

        return 'Reports as '.Money::format($converted, $reporting).' at '.$rate.', the rate for '.$this->spentOn.'.';
    }

    /* Actions -------------------------------------------------------------------- */

    public function save(): void
    {
        $expense = $this->persist();

        if ($expense === null) {
            return;
        }

        $this->flashToast(
            'success',
            $this->wasCreated ? 'Expense recorded' : 'Expense updated',
            $this->savedDescription($expense),
        );

        $this->redirect(route('accounting.expenses'), navigate: true);
    }

    public function saveAndAddAnother(): void
    {
        $expense = $this->persist();

        if ($expense === null) {
            return;
        }

        $this->toastSuccess(
            $this->wasCreated ? 'Expense recorded' : 'Expense updated',
            $this->savedDescription($expense).' The form is ready for the next one.',
        );

        // Keep the date, currency and category: recording one expense usually
        // means recording three from the same afternoon.
        $this->expenseId = null;
        // The form is a new expense again, so the currency it would freeze is
        // the configured one and not whatever the row just left behind froze.
        $this->resolvedReportingCurrency = null;
        $this->vendor = '';
        $this->description = '';
        $this->amount = '';
        $this->receiptReference = '';
        $this->billable = false;
    }

    /** Writes the row, or returns null having said why it could not. */
    private function persist(): ?Expense
    {
        $this->validate();

        if (! in_array($this->currency, Currencies::supported(), true)) {
            $this->addError('currency', 'Kargah records expenses in '.implode(', ', Currencies::supported()).'.');

            return null;
        }

        $amount = Money::of(trim($this->amount), $this->currency);

        [$rate, $reportingAmount] = $this->reportingFigures($amount);

        $attributes = [
            'company_id' => $this->companyId === '' ? null : (int) $this->companyId,
            'vendor' => trim($this->vendor),
            'category' => $this->category,
            'description' => trim($this->description) === '' ? null : trim($this->description),
            'currency' => $this->currency,
            'amount' => Money::toStorage($amount),
            'reporting_currency' => $this->reportingCurrency(),
            'reporting_rate' => $rate,
            'reporting_amount' => $reportingAmount,
            'is_billable' => $this->billable,
            'spent_on' => $this->spentOn,
            'receipt_reference' => trim($this->receiptReference) === '' ? null : trim($this->receiptReference),
        ];

        $existing = $this->expenseId === null ? null : Expense::query()->find($this->expenseId);

        $this->wasCreated = $existing === null;

        if ($existing === null) {
            $expense = Expense::query()->create($attributes + ['created_by' => auth()->id()]);
            $this->expenseId = $expense->id;

            return $expense;
        }

        $existing->fill($attributes)->save();

        return $existing;
    }

    /** What actually happened, in the words a person would use. */
    private function savedDescription(Expense $expense): string
    {
        $description = $expense->formattedAmount().' to '.$expense->vendor.' on '
            .$expense->spent_on->format('d M Y').'.';

        if ($expense->reporting_amount === null) {
            return $description.' No '.$expense->currency.' to '.$expense->reporting_currency
                .' rate was available for that date, so the reporting figure is blank.';
        }

        if ($expense->is_billable) {
            return $description.' Marked recoverable — it will stay unbilled until an invoice carries it.';
        }

        return $description;
    }

    /* Removing --------------------------------------------------------------------- */

    /**
     * Remove an expense, reversing whatever it posted to the ledger.
     *
     * 🔴 **This is never a hard delete and never a bare soft delete.** An expense
     * may carry a `LedgerEntry` of type `expense`, and `LedgerEntry` refuses both
     * `update()` and `delete()` outright — read its docblock. The only correct
     * removal is: reverse every standing entry with a reason, then soft-delete
     * the row, both inside one transaction. A reversal writes a contra entry
     * rather than a gap, so `LedgerEntry::standing()` stops counting the expense
     * while both rows stay readable as a trail. Half of that done is worse than
     * none of it, which is why the transaction is not optional.
     *
     * Note on what is actually in the database today: nothing in the application
     * writes a `TYPE_EXPENSE` entry — only the seeder and the factory do. So on
     * a fresh install this loop reverses nothing, and on the owner's own seeded
     * database it reverses one entry per expense. Both are handled here rather
     * than assumed, because the moment an expense *does* start posting, a
     * delete written on the assumption that it does not is a silently wrong
     * balance.
     *
     * **A rebilled expense is refused rather than detached.** Once a cost has
     * gone onto an invoice the client has been charged for it, and the expense
     * is the only record of what that line was. Detaching and deleting would
     * leave a charge on an issued invoice with nothing behind it, and would move
     * money out of "already rebilled" reporting without anyone deciding to. The
     * refusal names the invoice, which is the one thing the person needs in
     * order to act.
     */
    public function delete(): void
    {
        if ($this->expenseId === null) {
            $this->toastError('There is nothing to delete', 'This expense has not been saved yet.');

            return;
        }

        $expense = Expense::query()->with('rebilledOn')->find($this->expenseId);

        if ($expense === null) {
            $this->toastError('That expense is gone', 'It was deleted while the page was open.');

            return;
        }

        if ($expense->isRebilled()) {
            $this->toastError('That cost is already on an invoice', $this->rebilledRefusal($expense));

            return;
        }

        $headline = $this->removalHeadline($expense);

        $reversed = DB::transaction(function () use ($expense): int {
            // Re-read inside the transaction: the panel's count was rendered
            // some seconds ago and an entry may have been reversed since.
            $entries = $this->standingEntries($expense);

            foreach ($entries as $entry) {
                $entry->reverse('Expense deleted — '.$expense->vendor.' on '
                    .($expense->spent_on?->format('d M Y') ?? 'an unrecorded date'));
            }

            $expense->delete();

            return $entries->count();
        });

        $this->flashToast(
            'success',
            'Expense deleted',
            $reversed === 0
                ? $headline.' is gone. It had posted nothing to the ledger, so no balance moved.'
                : $headline.' is gone. Its ledger '.($reversed === 1 ? 'entry was' : 'entries were')
                    .' reversed rather than removed, so the balance is back where it was and both rows stay in the trail.',
        );

        $this->redirect(route('accounting.expenses'), navigate: true);
    }

    /**
     * The ledger entries this expense still counts towards.
     *
     * `standing()` is the scope a balance is summed from — it drops reversals
     * and anything already reversed — so an expense whose entry was reversed by
     * hand last month does not get a second contra entry netting back to the
     * original and saying nothing.
     *
     * @return Collection<int, LedgerEntry>
     */
    private function standingEntries(Expense $expense): Collection
    {
        return LedgerEntry::query()->forReference($expense)->standing()->get();
    }

    /** What is about to go, in money and in words. */
    private function removalHeadline(Expense $expense): string
    {
        return $expense->formattedAmount().' to '.$expense->vendor
            .' on '.($expense->spent_on?->format('d F Y') ?? 'an unrecorded date');
    }

    /**
     * What deleting it does, said before the click rather than after.
     *
     * The ledger sentence appears only when there is an entry to reverse.
     * Promising a reversal that will not happen reads as reassurance and is a
     * lie; saying nothing about a ledger that is about to move is worse.
     */
    private function removalConsequence(Expense $expense): string
    {
        if ($this->standingEntries($expense)->isEmpty()) {
            return 'It posted nothing to the ledger, so the expense row is all that goes. '
                .'Nothing in Kargah brings it back.';
        }

        return 'Its ledger entry is reversed rather than removed: the balance goes back to what it was '
            .'before the expense existed, and both rows stay in the trail. '
            .'Nothing in Kargah brings the expense itself back.';
    }

    /** Why a rebilled cost stays, and what to do about it. */
    private function rebilledRefusal(Expense $expense): string
    {
        return 'This cost was rebilled on '.($expense->rebilledOn?->number ?? 'an invoice')
            .', so deleting it would leave a charge on that invoice with nothing behind it. '
            .'Take the line off the invoice first, or correct the expense above instead.';
    }

    public function useSuggestion(int $expenseId): void
    {
        $expense = Expense::query()->find($expenseId);

        if ($expense === null) {
            $this->toastError('That expense is gone', 'It was deleted while the page was open.');

            return;
        }

        $this->vendor = $expense->vendor;
        $this->category = $expense->category ?: $this->category;
        $this->currency = $expense->currency;
        $this->amount = $this->displayAmount((string) $expense->amount, $expense->currency);
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Heading --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('accounting.expenses') }}" wire:navigate
                   class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to expenses" aria-label="Back to expenses">
                    <i class="ki-filled ki-black-left text-sm"></i>
                </a>
                <h1 class="text-xl font-semibold text-mono">{{ $editing ? 'Edit expense' : 'Record expense' }}</h1>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">Log what you spent while the receipt is still in your hand.</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="saveAndAddAnother" wire:loading.attr="disabled" wire:target="saveAndAddAnother"
                    class="kt-btn kt-btn-outline gap-2">
                <span wire:loading.remove wire:target="saveAndAddAnother" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-plus"></i> Save and add another
                </span>
                <span wire:loading wire:target="saveAndAddAnother" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
            <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                    class="kt-btn kt-btn-primary gap-2">
                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-check"></i> Save expense
                </span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Form --}}
        <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Expense</h3></div>
                <div class="kt-card-content p-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <label class="kt-form-label" for="expense_vendor">Vendor</label>
                            <input id="expense_vendor" type="text" wire:model.blur="vendor"
                                   placeholder="Who did you pay?"
                                   class="kt-input @error('vendor') border-destructive @enderror">
                            @error('vendor')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="expense_date">Date</label>
                            <input id="expense_date" type="date" wire:model.live="spentOn"
                                   class="kt-input @error('spentOn') border-destructive @enderror">
                            @error('spentOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="expense_category">Category</label>
                            <select id="expense_category" wire:model.live="category"
                                    class="kt-select @error('category') border-destructive @enderror">
                                @foreach ($categories as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('category')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="expense_amount">Amount</label>
                            <div class="kt-input-group">
                                <span class="kt-input-addon">{{ $symbol }}</span>
                                <input id="expense_amount" type="text" inputmode="decimal"
                                       placeholder="0.00"
                                       wire:model.live.debounce.400ms="amount"
                                       class="kt-input text-end @error('amount') border-destructive @enderror">
                            </div>
                            @error('amount')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="expense_currency">Currency</label>
                            <select id="expense_currency" wire:model.live="currency"
                                    class="kt-select @error('currency') border-destructive @enderror">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                            @error('currency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <label class="kt-form-label" for="expense_description">What it was for</label>
                            <textarea id="expense_description" wire:model.blur="description" rows="3"
                                      class="kt-textarea w-full @error('description') border-destructive @enderror"
                                      placeholder="Future you will want to know."></textarea>
                            @error('description')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Client and rebilling</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="expense_company">Company</label>
                        <select id="expense_company" wire:model.live="companyId" class="kt-select">
                            <option value="">No company — this is a cost of the business</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                        <p class="kt-form-description">
                            An expense against a company shows on that client's page.
                        </p>
                    </div>

                    <div class="flex items-start justify-between gap-4 border-t border-border pt-4">
                        <div class="min-w-0">
                            <label class="kt-form-label" for="expense_billable">The client agreed to cover this</label>
                            <p class="kt-form-description mt-1">
                                A recoverable cost stays unbilled until an invoice actually carries it, which is the
                                money most easily forgotten.
                            </p>
                        </div>
                        <input id="expense_billable" type="checkbox" class="kt-switch shrink-0" wire:model.live="billable">
                    </div>

                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Receipt</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-1.5">
                    <label class="kt-form-label" for="expense_receipt">Receipt reference</label>
                    <input id="expense_receipt" type="text" wire:model.blur="receiptReference"
                           placeholder="e.g. HG-2026-07-981"
                           class="kt-input @error('receiptReference') border-destructive @enderror">
                    @error('receiptReference')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    <p class="kt-form-description">
                        The number on the invoice or the card statement. It is what an accountant asks for first.
                    </p>
                </div>
            </div>

            {{-- Removal. Only on a row that exists: a blank form has nothing to
                 remove, and a delete button beside "Save" on a create page is a
                 misclick waiting to happen. The confirmation names the money and
                 the consequence, never "Are you sure?" — this is an accounting
                 record. Where the expense is already on an invoice there is no
                 `wire:confirm` at all: nothing is going to happen, and a
                 confirmation for an action that will be refused is a lie. --}}
            @if ($saved)
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Remove this expense</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        <p class="text-sm text-secondary-foreground">
                            {{ $deleteRefusal ?? $deleteConsequence }}
                        </p>
                        <div>
                            <button wire:click="delete" wire:loading.attr="disabled" wire:target="delete"
                                    @if ($deletable) wire:confirm="Delete {{ $deleteHeadline }}?&#10;&#10;{{ $deleteConsequence }}" @endif
                                    class="kt-btn kt-btn-destructive kt-btn-sm gap-2">
                                <span wire:loading.remove wire:target="delete" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-trash"></i> Delete expense
                                </span>
                                <span wire:loading wire:target="delete" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Deleting…
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif

        </div>

        {{-- Summary and suggestions --}}
        <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Summary</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Vendor</span>
                        <span class="text-mono font-medium truncate max-w-[60%] text-end">{{ $vendor !== '' ? $vendor : '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Date</span>
                        <span class="text-mono font-medium">{{ $spentOn !== '' ? $spentOn : '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Category</span>
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $category }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Rebilling</span>
                        <span class="text-mono font-medium">{{ $billable ? 'Recoverable from the client' : 'Absorbed' }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-border pt-3">
                        <span class="font-medium text-mono">Total</span>
                        <span class="text-2xl font-semibold text-mono" wire:loading.class="opacity-50">{{ $formattedAmount }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ $reportingNote }}</p>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Paid before</h3></div>
                <div class="kt-card-content p-3 flex flex-col gap-1">
                    @forelse ($suggestions as $suggestion)
                        <button wire:click="useSuggestion({{ $suggestion['id'] }})" wire:key="sug-{{ $suggestion['id'] }}"
                                class="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg text-start transition-colors hover:bg-accent/50">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-mono truncate">{{ $suggestion['vendor'] }}</span>
                                <span class="block text-xs text-muted-foreground">
                                    {{ $suggestion['category'] }} · {{ $suggestion['when'] }}
                                </span>
                            </span>
                            <span class="text-sm text-mono font-medium shrink-0">{{ $suggestion['amount'] }}</span>
                        </button>
                    @empty
                        <p class="text-sm text-muted-foreground text-center py-6">
                            Nothing recorded yet, so there is nothing to copy from.
                        </p>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</div>
