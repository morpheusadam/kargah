<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Services\InvoiceIssuer;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * Invoice builder, writing to the database.
 *
 * One component serves both `accounting.invoice-create` and
 * `accounting.invoice-edit`; the only difference is whether a route parameter
 * arrived. Three things are worth knowing before changing anything.
 *
 * **An issued invoice is not editable here, at all.** Not disabled fields, not
 * a confirmation — the form is not rendered. Issuing froze an exchange rate
 * onto the row and an issued invoice never changes its numbers; the correction
 * for a mistake is a void and a new invoice. `InvoiceIssuer` refuses with a
 * `DomainException`, and this page catches it and says so rather than letting a
 * 500 out.
 *
 * **Every figure goes through `Money`.** Livewire hands form input back as
 * strings, and they stay strings all the way to the column: a quantity is
 * '8.5', never 8.5. The totals under the table are computed on the server on
 * each keystroke because that arithmetic is the same arithmetic the invoice
 * will be stored with, and a second implementation in JavaScript would be a
 * second implementation to be wrong.
 *
 * **A missing invoice is a page, not a 404.** The route parameter is resolved
 * by hand rather than by model binding, so a link to an invoice that has since
 * been deleted explains itself instead of dead-ending.
 *
 * 🔴 **The KDV zero-rating is a decision this page records, never one it
 * makes.** An invoice to a foreign client *may* qualify as an export of
 * services under exemption code 302, but only if four cumulative conditions all
 * hold, and whether they do is a judgement for the operator and their mali
 * müşavir. So the control appears only where it could plausibly apply — a buyer
 * that is not a domestic Turkish company — it starts off, every condition has
 * to be confirmed one at a time before it can be applied, and
 * `exemptionCode()` re-checks all of that on the server before anything is
 * written. Inferring "the client is abroad, therefore zero-rated" would be
 * software answering a tax question on somebody's behalf, and getting it wrong
 * is the operator's liability, not Kargah's.
 */
new
#[Title('Invoice builder — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** How long a new invoice gives the client to pay. */
    private const PAYMENT_DAYS = 30;

    /** Null on the create route, the invoice key on the edit route. */
    public ?int $invoiceId = null;

    /** The route named an invoice and the invoice is not there. */
    public bool $missing = false;

    public string $number = '';

    public ?string $customerId = null;

    public ?string $companyId = null;

    public string $currency = Currencies::USD;

    public string $issuedOn = '';

    public string $dueOn = '';

    /**
     * The period the work itself covers, which is not the period the invoice
     * covers.
     *
     * Both optional and both blank on a new draft. An invoice for a fixed piece
     * of work — "full website SEO", start to finish — has a start and an end;
     * one for an hour of consultancy has neither, and the overwhelming majority
     * of the rows already in the book have neither. Blank means blank: nothing
     * is inferred from the issue date, because a period Kargah invented is a
     * period the client did not agree to.
     */
    public string $startsOn = '';

    public string $endsOn = '';

    /** A percentage, as a decimal string: '20' is twenty per cent. */
    public string $taxPercent = '0';

    /**
     * The KDV exemption the operator has applied, or null for the standard rate.
     *
     * Null by default and null on every new draft. Nothing sets this except a
     * person clicking through the checklist below.
     */
    public ?string $kdvExemptionCode = null;

    /**
     * Which of an exemption's conditions the operator has confirmed, by code
     * then by the condition's position in the configured list.
     *
     * @var array<string, array<int, bool>>
     */
    public array $kdvConfirmed = [];

    public string $notes = '';

    public string $terms = '';

    /**
     * The lines being edited.
     *
     * `id` is carried so an edit updates the row rather than replacing it —
     * a card billed as a line is joined to it through Core's `links` table, and
     * deleting the line to recreate it would quietly drop that link.
     *
     * `tasks` is the scope of the line, held here as the text of a textarea —
     * one item per line — and turned into the `list<string>` the column stores
     * at the moment of the write, by `taskLines()`. It carries no price and no
     * quantity: the figure on an invoice is the line's own, and a price beside a
     * task would be a second, contradictory total.
     *
     * @var array<int, array{id: ?int, description: string, tasks: string, quantity: string, unit_price: string}>
     */
    public array $items = [];

    private ?Invoice $resolved = null;

    private ?Company $companyCache = null;

    private ?string $companyCacheKey = null;

    public function mount(?string $invoice = null): void
    {
        if ($invoice === null) {
            $this->startNewDraft();

            return;
        }

        $found = Invoice::query()->with('lines')->find($invoice);

        if ($found === null) {
            $this->missing = true;

            return;
        }

        $this->invoiceId = (int) $found->getKey();
        $this->resolved = $found;

        $this->fillFrom($found);
    }

    private function startNewDraft(): void
    {
        $this->number = $this->nextNumber();
        $this->issuedOn = today()->toDateString();
        $this->dueOn = today()->addDays(self::PAYMENT_DAYS)->toDateString();
        $this->terms = 'Payment due within '.self::PAYMENT_DAYS.' days of the invoice date.';
        $this->items = [$this->blankLine()];
    }

    private function fillFrom(Invoice $invoice): void
    {
        $this->number = (string) $invoice->number;
        $this->customerId = $invoice->customer_id === null ? null : (string) $invoice->customer_id;
        $this->companyId = $invoice->company_id === null ? null : (string) $invoice->company_id;
        $this->currency = $invoice->currency;
        $this->issuedOn = $invoice->issued_on?->toDateString() ?? today()->toDateString();
        $this->dueOn = $invoice->due_on?->toDateString() ?? today()->addDays(self::PAYMENT_DAYS)->toDateString();

        // No fallback for these two, unlike the pair above. A missing issue date
        // has an obvious answer; a missing work period does not, and filling one
        // in would make every invoice in the book claim a period nobody set.
        $this->startsOn = $invoice->starts_on?->toDateString() ?? '';
        $this->endsOn = $invoice->ends_on?->toDateString() ?? '';

        $this->taxPercent = $this->trimZeros((string) $invoice->tax_percent);
        $this->notes = (string) $invoice->notes;
        $this->terms = (string) $invoice->terms;

        // A stored code is the record that somebody confirmed every condition,
        // so reopening the draft shows them confirmed rather than making the
        // operator tick through a decision they already made.
        $this->kdvExemptionCode = $invoice->kdv_exemption_code ?: null;

        if ($this->kdvExemptionCode !== null) {
            $this->kdvConfirmed[$this->kdvExemptionCode] = array_fill(
                0,
                count($this->conditionsFor($this->kdvExemptionCode)),
                true,
            );
        }

        $this->items = $invoice->lines->map(fn (InvoiceLine $line): array => [
            'id' => (int) $line->id,
            'description' => (string) $line->description,
            // `taskList()` and not the raw cast: reopening a draft shows the
            // list as it will be read, so a stray blank line somebody typed
            // last week does not come back as an empty row in the textarea.
            'tasks' => implode("\n", $line->taskList()),
            'quantity' => $this->trimZeros((string) $line->quantity),
            'unit_price' => $this->trimZeros((string) $line->unit_price),
        ])->all();

        if ($this->items === []) {
            $this->items = [$this->blankLine()];
        }
    }

    /**
     * The next number in the book.
     *
     * Read in PHP rather than as `MAX()` in SQL because the recurring generator
     * raises numbers of its own shape — `INV-R7-20260901` — and a string
     * maximum across both shapes is whichever sorts last, not whichever is
     * highest. Trashed numbers count: the unique index still holds them.
     */
    private function nextNumber(): string
    {
        $highest = 0;

        foreach (Invoice::withTrashed()->pluck('number') as $number) {
            if (preg_match('/^INV-(\d+)$/', (string) $number, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'INV-'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    private function blankLine(): array
    {
        return ['id' => null, 'description' => '', 'tasks' => '', 'quantity' => '1', 'unit_price' => '0'];
    }

    /**
     * The textarea's text as the column's `list<string>`, or null for nothing.
     *
     * 🔴 Null and not `[]`, and never `['']`. The column is nullable precisely
     * so "this line has no scope" and "this line has a scope that happens to be
     * empty" are different rows, and an array holding one empty string would
     * render an empty bullet on the document. Blanks are dropped here as well as
     * in `InvoiceLine::taskList()` — the model cleans on the way out because it
     * has to cope with whatever is already stored; this cleans on the way in so
     * nothing new needs cleaning.
     *
     * `\R` rather than `\n`, because a browser submits a textarea with CRLF and
     * splitting on `\n` alone leaves a carriage return on the end of every item.
     *
     * @return list<string>|null
     */
    private function taskLines(mixed $value): ?array
    {
        $lines = preg_split('/\R/', (string) $value);

        $lines = array_values(array_filter(
            array_map(trim(...), $lines === false ? [] : $lines),
            static fn (string $task): bool => $task !== '',
        ));

        return $lines === [] ? null : $lines;
    }

    /* Reading the invoice ---------------------------------------------------- */

    public function invoice(): ?Invoice
    {
        if ($this->invoiceId === null) {
            return null;
        }

        return $this->resolved ??= Invoice::query()->with(['lines', 'customer', 'company'])->find($this->invoiceId);
    }

    public function isIssued(): bool
    {
        return $this->invoice()?->isIssued() ?? false;
    }

    /* KDV exemption ------------------------------------------------------------ */

    /**
     * The buyer, when one has been named.
     *
     * Read fresh from the selected id rather than from the loaded invoice, so
     * changing the company on the form changes what the page offers on the same
     * keystroke instead of on the next save.
     */
    private function selectedCompany(): ?Company
    {
        if ($this->companyId === null || $this->companyId === '') {
            return null;
        }

        // Memoised per request and keyed on the id, because the totals preview
        // asks whether the exemption applies on every keystroke and one query
        // per keystroke per line is not a preview, it is a load test.
        if ($this->companyCacheKey !== $this->companyId) {
            $this->companyCacheKey = $this->companyId;
            $this->companyCache = Company::query()->find((int) $this->companyId);
        }

        return $this->companyCache;
    }

    /**
     * The exemptions this invoice could plausibly be raised under.
     *
     * 🔴 Empty unless the buyer is a company that is **not** domestic. Condition
     * two of the export-of-services exemption is that the client's residence or
     * business centre is abroad, and that is the one condition Kargah can read
     * off a record rather than having to ask. Where it plainly fails — a
     * domestic Turkish buyer — the control is not rendered at all, because a
     * disabled control is an invitation to look for the way round it. Where
     * Kargah has no company at all it also stays hidden: "billed to a person,
     * not a company" is not evidence of anything, and offering a zero-rating on
     * no evidence is the failure this whole design exists to prevent.
     *
     * Kargah still decides nothing. This narrows *where the question may be
     * asked*; the answer is four confirmations by a person.
     *
     * @return array<string, array{label: string, conditions: list<string>}>
     */
    public function offeredExemptions(): array
    {
        $company = $this->selectedCompany();

        if ($company === null || $company->is_domestic) {
            return [];
        }

        $configured = config('accounting.tax.kdv_exemptions');

        return is_array($configured) ? $configured : [];
    }

    /** @return list<string> */
    private function conditionsFor(string $code): array
    {
        $configured = config('accounting.tax.kdv_exemptions.'.$code.'.conditions');

        return is_array($configured) ? array_values($configured) : [];
    }

    /** Has this condition been confirmed? Used by the template, one checkbox at a time. */
    public function confirmed(string $code, int $index): bool
    {
        return (bool) ($this->kdvConfirmed[$code][$index] ?? false);
    }

    /** Every condition, one by one. Anything less and the exemption cannot be applied. */
    public function allConfirmed(string $code): bool
    {
        $conditions = $this->conditionsFor($code);

        if ($conditions === []) {
            return false;
        }

        foreach (array_keys($conditions) as $index) {
            if (! $this->confirmed($code, $index)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The code that will actually be written, re-derived from scratch.
     *
     * 🔴 The single authority, and the reason the checkboxes are only a user
     * interface. Livewire state arrives from the browser and can be tampered
     * with; this re-reads the configured codes, re-reads whether the buyer is
     * foreign, and re-counts the confirmations, so the only way to get '302'
     * into the column is for all of it to be true on the server at the moment
     * of the write. Anything else is null, which means the standard rate.
     */
    private function exemptionCode(): ?string
    {
        if ($this->kdvExemptionCode === null) {
            return null;
        }

        if (! array_key_exists($this->kdvExemptionCode, $this->offeredExemptions())) {
            return null;
        }

        return $this->allConfirmed($this->kdvExemptionCode) ? $this->kdvExemptionCode : null;
    }

    public function applyExemption(string $code): void
    {
        if ($this->refuseWhenIssued()) {
            return;
        }

        if (! array_key_exists($code, $this->offeredExemptions())) {
            $this->toastError(
                'That exemption does not apply here',
                'The zero rate is only offered when the invoice is billed to a company outside Turkey.',
            );

            return;
        }

        if (! $this->allConfirmed($code)) {
            $this->toastError(
                'Confirm each condition first',
                'The exemption applies only if all four hold at once. Tick each one you have confirmed.',
            );

            return;
        }

        $this->kdvExemptionCode = $code;

        // Zero-rated means the rate is zero, not that the amount happens to be.
        // Leaving 20 in the box would put a figure on screen that the document
        // contradicts, and the document is what a tax office reads.
        $this->taxPercent = '0';
    }

    public function removeExemption(): void
    {
        if ($this->refuseWhenIssued()) {
            return;
        }

        $this->kdvExemptionCode = null;
    }

    /** Unticking a condition withdraws the exemption, rather than leaving a stale one applied. */
    public function updatedKdvConfirmed(): void
    {
        if ($this->kdvExemptionCode !== null && ! $this->allConfirmed($this->kdvExemptionCode)) {
            $this->kdvExemptionCode = null;
        }
    }

    /** Changing the buyer can take the question away entirely. */
    public function updatedCompanyId(): void
    {
        if ($this->kdvExemptionCode !== null && ! array_key_exists($this->kdvExemptionCode, $this->offeredExemptions())) {
            $this->kdvExemptionCode = null;
        }
    }

    /* Money ------------------------------------------------------------------- */

    /**
     * A decimal string, or the fallback when the box does not hold one.
     *
     * A half-typed number is ordinary — '1.' and '' both arrive while someone
     * is still typing. Neither is a reason to throw, and neither is a reason to
     * reach for a float to paper over it.
     */
    private function decimal(mixed $value, string $fallback = '0'): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? $value : $fallback;
    }

    /** Trailing zeros off a stored `decimal(20,6)`, so a box reads '8.5' and not '8.500000'. */
    private function trimZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /** The currency being edited in, falling back rather than throwing on a tampered select. */
    private function safeCurrency(): string
    {
        return in_array($this->currency, Currencies::supported(), true) ? $this->currency : Currencies::USD;
    }

    private function lineMoney(array $item): BrickMoney
    {
        return Money::lineTotal(
            $this->decimal($item['quantity'] ?? '0'),
            $this->decimal($item['unit_price'] ?? '0'),
            $this->safeCurrency(),
        );
    }

    private function subtotal(): BrickMoney
    {
        return Money::sum(
            array_map(fn (array $item): BrickMoney => $this->lineMoney($item), $this->items),
            $this->safeCurrency(),
        );
    }

    /** The rate that will actually be stored: zero whenever an exemption holds. */
    private function effectiveTaxPercent(): string
    {
        return $this->exemptionCode() === null ? $this->decimal($this->taxPercent) : '0';
    }

    private function taxAmount(): BrickMoney
    {
        return Money::percentageOf($this->subtotal(), $this->effectiveTaxPercent());
    }

    private function total(): BrickMoney
    {
        return $this->subtotal()->plus($this->taxAmount(), Money::ROUNDING);
    }

    private function show(BrickMoney $money): string
    {
        return Money::format(Money::toStorage($money), $this->safeCurrency());
    }

    /* The view ---------------------------------------------------------------- */

    public function with(): array
    {
        $invoice = $this->invoice();

        return [
            'invoice' => $invoice,
            'customers' => Customer::query()->active()->with('company')->orderBy('name')->get(),
            'companies' => Company::query()->active()->orderBy('name')->get(),
            'currencies' => Currencies::supported(),
            'symbol' => Currencies::symbol($this->safeCurrency()),
            'lineTotals' => array_map(fn (array $item): string => $this->show($this->lineMoney($item)), $this->items),
            'totals' => [
                'subtotal' => $this->show($this->subtotal()),
                'tax' => $this->show($this->taxAmount()),
                'total' => $this->show($this->total()),
            ],
            'storedLines' => $invoice?->lines ?? collect(),

            'exemptions' => $this->offeredExemptions(),
            'appliedExemption' => $this->exemptionCode(),
            'kdvPercent' => (string) config('accounting.tax.kdv_percent', '20'),
            'reportingCurrency' => InvoiceIssuer::reportingCurrency(),
        ];
    }

    /**
     * Every rule this page enforces, in one place.
     *
     * The work period and the line scope are here rather than on `#[Validate]`
     * attributes, and deliberately. `endsOn`'s rule is conditional — it exists
     * only while a start date does — and an attribute is a constant, so half the
     * pair would have had to live here anyway and the other half three hundred
     * lines away. `Livewire\...\HandlesValidation::getRules()` merges the two
     * sources rather than choosing between them, so an attribute *would* have
     * fired: `save()` calls `validate()` with no argument, and it is passing an
     * explicit rules array that replaces everything. This does not.
     *
     * 🔴 `after_or_equal:startsOn` is applied only when a start date was given.
     * Laravel resolves the referenced field through `date_create()`, and
     * `date_create('')` is *now* rather than a failure — so an unconditional
     * rule would have quietly rejected any work that finished before today on an
     * invoice with no start date at all.
     */
    protected function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:40', Rule::unique('invoices', 'number')->ignore($this->invoiceId)],
            'currency' => ['required', Rule::in(Currencies::supported())],
            'issuedOn' => ['required', 'date'],
            'dueOn' => ['required', 'date', 'after_or_equal:issuedOn'],
            'startsOn' => ['nullable', 'date'],
            'endsOn' => ['nullable', 'date', Rule::when($this->startsOn !== '', ['after_or_equal:startsOn'])],
            'taxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'customerId' => ['nullable', 'exists:customers,id'],
            'companyId' => ['nullable', 'exists:companies,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            // The scope of a line, as typed: optional, and long enough for a
            // realistic list without being long enough to be a second invoice.
            'items.*.tasks' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'number' => 'invoice number',
            'issuedOn' => 'issue date',
            'dueOn' => 'due date',
            'startsOn' => 'work start date',
            'endsOn' => 'work end date',
            'taxPercent' => 'tax rate',
            'customerId' => 'client',
            'companyId' => 'company',
        ];
    }

    /* Line editing -------------------------------------------------------------- */

    public function addLine(): void
    {
        if ($this->refuseWhenIssued()) {
            return;
        }

        $this->items[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        if ($this->refuseWhenIssued()) {
            return;
        }

        if (count($this->items) <= 1) {
            $this->toastError('Line kept', 'An invoice needs at least one line.');

            return;
        }

        if (! array_key_exists($index, $this->items)) {
            $this->toastError('Line not found', 'Reload the page and try again.');

            return;
        }

        unset($this->items[$index]);

        $this->items = array_values($this->items);
    }

    public function moveLine(int $index, int $direction): void
    {
        if ($this->refuseWhenIssued()) {
            return;
        }

        $target = $index + $direction;

        if (! array_key_exists($index, $this->items) || ! array_key_exists($target, $this->items)) {
            $this->toastError(
                'Line not moved',
                'That line is already at the '.($direction < 0 ? 'top' : 'bottom').'.',
            );

            return;
        }

        [$this->items[$index], $this->items[$target]] = [$this->items[$target], $this->items[$index]];
    }

    /* Saving and issuing --------------------------------------------------------- */

    /**
     * The one guard every write goes through.
     *
     * The template does not render the form for an issued invoice, so reaching
     * this means the invoice was issued in another tab while this one was open.
     * The wording is `InvoiceIssuer`'s, because it is the same rule.
     */
    private function refuseWhenIssued(): bool
    {
        $invoice = $this->invoice();

        if ($invoice === null || ! $invoice->isIssued()) {
            return false;
        }

        $this->toastError(
            $invoice->number.' has been issued',
            'An issued invoice never changes its numbers. Void it and raise a credit note instead.',
        );

        return true;
    }

    public function save(): void
    {
        if ($this->refuseWhenIssued()) {
            return;
        }

        $this->validate();

        $invoice = $this->persist();

        $this->toastSuccess($invoice->number.' saved', 'Still a draft, and still editable.');
    }

    /**
     * Write the draft and its lines, then let the service total it.
     *
     * `InvoiceIssuer::recalculate()` owns the arithmetic on the way into the
     * database, so the figures on screen and the figures in the columns come
     * from the same code rather than from two implementations that agree today.
     */
    private function persist(): Invoice
    {
        $invoice = DB::transaction(function (): Invoice {
            $invoice = $this->invoice() ?? new Invoice;

            $invoice->forceFill([
                'number' => trim($this->number),
                'customer_id' => $this->customerId === null || $this->customerId === '' ? null : (int) $this->customerId,
                'company_id' => $this->companyId === null || $this->companyId === '' ? null : (int) $this->companyId,
                'status' => 'draft',
                'currency' => $this->safeCurrency(),
                // `exemptionCode()` re-derives the zero-rating on the server;
                // `effectiveTaxPercent()` is what it implies. Written together
                // so the column and the rate can never disagree.
                'kdv_exemption_code' => $this->exemptionCode(),
                'tax_percent' => $this->effectiveTaxPercent(),
                'issued_on' => $this->issuedOn,
                'due_on' => $this->dueOn,
                // Blank stays null. An empty string in a date column is not a
                // date, and it would read back as 1970 on a page that has to be
                // defensible to an accountant.
                'starts_on' => $this->startsOn === '' ? null : $this->startsOn,
                'ends_on' => $this->endsOn === '' ? null : $this->endsOn,
                'notes' => $this->notes === '' ? null : $this->notes,
                'terms' => $this->terms === '' ? null : $this->terms,
                'created_by' => $invoice->exists ? $invoice->created_by : auth()->id(),
            ])->save();

            $this->syncLines($invoice);

            return $invoice;
        });

        $this->invoiceId = (int) $invoice->getKey();
        $this->resolved = null;

        $recalculated = app(InvoiceIssuer::class)->recalculate($this->invoice());

        // Ids for the rows that were just created, so the next save updates
        // them instead of dropping and recreating them.
        $this->fillFrom($recalculated->load('lines'));

        return $recalculated;
    }

    /**
     * Match the stored lines to the edited ones.
     *
     * Updated where an id came back, created where it did not, and deleted only
     * where a line the invoice used to have is no longer on the form. Wiping
     * every line and reinserting would be shorter and would orphan every link
     * pointing at one.
     */
    private function syncLines(Invoice $invoice): void
    {
        $kept = [];

        foreach (array_values($this->items) as $index => $item) {
            $attributes = [
                'description' => trim((string) $item['description']),
                // `?? ''` because a caller — a test, or an older payload from a
                // tab opened before this shipped — can hand over a line with no
                // `tasks` key at all, and that is a line with no scope.
                'tasks' => $this->taskLines($item['tasks'] ?? ''),
                'quantity' => $this->decimal($item['quantity'] ?? '0'),
                'unit_price' => $this->decimal($item['unit_price'] ?? '0'),
                'amount' => Money::toStorage($this->lineMoney($item)),
                // The same fractional ordering the boards use, spaced so a line
                // can be dropped between two others without renumbering.
                'position' => (string) BigDecimal::of('1024')
                    ->multipliedBy($index + 1)
                    ->toScale(10, RoundingMode::Down),
            ];

            $existing = $item['id'] === null
                ? null
                : InvoiceLine::query()->where('invoice_id', $invoice->id)->find($item['id']);

            if ($existing === null) {
                $kept[] = InvoiceLine::query()->create($attributes + ['invoice_id' => $invoice->id])->id;

                continue;
            }

            $existing->forceFill($attributes)->save();

            $kept[] = $existing->id;
        }

        InvoiceLine::query()
            ->where('invoice_id', $invoice->id)
            ->whereNotIn('id', $kept === [] ? [0] : $kept)
            ->delete();
    }

    /**
     * Issue it: the moment the numbers stop being able to change.
     *
     * The draft is saved first, so what is frozen is what is on screen. The
     * `DomainException` is the service refusing to issue something twice, which
     * is a sentence a person can act on rather than a 500.
     */
    public function issue(): void
    {
        if ($this->refuseWhenIssued()) {
            return;
        }

        $this->validate();

        $invoice = $this->persist();

        try {
            // No currency argument: the owner's reporting currency is a setting
            // in `config/accounting.php`, not something this page decides. It
            // used to pass a constant of USD, which meant every invoice ever
            // issued froze a dollar figure whatever the owner actually declares
            // in — and the lira reports were near-empty as a result.
            $issued = app(InvoiceIssuer::class)->issue($invoice);
        } catch (DomainException $e) {
            $this->resolved = null;

            $this->toastError('This invoice was not issued', $e->getMessage());

            return;
        }

        $this->flashToast('success', $issued->number.' issued', $this->frozenSummary($issued));

        $this->redirectRoute('accounting.invoice-show', ['invoice' => $issued->id], navigate: true);
    }

    /** What was frozen, said out loud, because a rate nobody can see is a rate nobody can defend. */
    private function frozenSummary(Invoice $invoice): string
    {
        $on = $invoice->issued_on?->format('j F Y') ?? 'today';

        if ($invoice->reporting_rate === null) {
            return 'No rate was available for '.$on.', so the reporting figure is left blank rather than invented.';
        }

        if ($invoice->reporting_currency === $invoice->currency) {
            return $invoice->formattedTotal().', already in the reporting currency. The numbers can no longer change.';
        }

        return 'Frozen at '.$invoice->reporting_rate.' '.$invoice->currency.'/'.$invoice->reporting_currency
            .' as at '.$on.'. The numbers can no longer change.';
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($missing)

        {{-- The route named an invoice that is not there. --}}
        <div class="kt-card">
            <div class="kt-card-content p-10 flex flex-col items-center text-center gap-3">
                <i class="ki-filled ki-bill text-3xl text-muted-foreground"></i>
                <h1 class="text-lg font-semibold text-mono">That invoice is not here</h1>
                <p class="text-sm text-secondary-foreground max-w-[420px]">
                    It was deleted, or the link points at a number this install has never had.
                </p>
                <a href="{{ route('accounting.invoices') }}" wire:navigate class="kt-btn kt-btn-primary gap-2 mt-2">
                    <i class="ki-filled ki-arrow-left"></i> Back to invoices
                </a>
            </div>
        </div>

    @elseif ($this->isIssued())

        {{--
            Issued. The form is not rendered at all — an issued invoice never
            changes its numbers, and a disabled field is an invitation to look
            for the way round it.
        --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('accounting.invoices') }}" wire:navigate
                       class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to invoices" aria-label="Back to invoices">
                        <i class="ki-filled ki-black-left text-sm"></i>
                    </a>
                    <h1 class="text-xl font-semibold text-mono">{{ $invoice->number }}</h1>
                    <span class="kt-badge kt-badge-sm kt-badge-info">Issued</span>
                </div>
                <p class="text-sm text-secondary-foreground mt-1">Issued invoices are read-only. This is what it says.</p>
            </div>
            <a href="{{ route('accounting.invoice-show', ['invoice' => $invoice->id]) }}" wire:navigate
               class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-eye"></i> Open the invoice
            </a>
        </div>

        <div class="kt-card border-info/30 bg-info/10">
            <div class="kt-card-content p-4 flex items-start gap-3">
                <span class="inline-flex items-center justify-center size-9 rounded-lg bg-info/15 text-info shrink-0">
                    <i class="ki-filled ki-lock text-lg"></i>
                </span>
                <div>
                    <div class="text-sm font-semibold text-mono">This invoice cannot be edited</div>
                    <p class="text-xs text-secondary-foreground mt-1 max-w-[640px] leading-relaxed">
                        It was issued on {{ $invoice->issued_on?->format('j F Y') ?? 'an earlier date' }}, which froze the
                        exchange rate it depends on. A rate that moves next week must not alter what this invoice says, so
                        nothing here changes again. To correct it, void it on the invoice page and raise a new one.
                    </p>
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">What it says</h3>
                <span class="text-sm text-muted-foreground">{{ $invoice->currency }}</span>
            </div>
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="min-w-[260px]">Description</th>
                                <th class="w-[90px] text-end">Qty</th>
                                <th class="w-[140px] text-end">Unit price</th>
                                <th class="w-[140px] text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($storedLines as $line)
                                <tr wire:key="stored-{{ $line->id }}">
                                    <td class="text-mono">{{ $line->description }}</td>
                                    <td class="text-end text-secondary-foreground">{{ $line->quantity }}</td>
                                    <td class="text-end text-secondary-foreground whitespace-nowrap">
                                        {{ \Modules\Accounting\Support\Money::format((string) $line->unit_price, $invoice->currency) }}
                                    </td>
                                    <td class="text-end font-medium text-mono whitespace-nowrap">{{ $line->formattedAmount() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="kt-card-footer justify-end">
                <span class="text-sm text-secondary-foreground me-3">Total</span>
                <span class="text-lg font-semibold text-mono">{{ $invoice->formattedTotal() }}</span>
            </div>
        </div>

    @else

        {{-- Heading --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('accounting.invoices') }}" wire:navigate
                       class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to invoices" aria-label="Back to invoices">
                        <i class="ki-filled ki-black-left text-sm"></i>
                    </a>
                    <h1 class="text-xl font-semibold text-mono">
                        {{ $invoiceId ? 'Edit '.$number : 'New invoice' }}
                    </h1>
                    <span class="kt-badge kt-badge-sm kt-badge-outline">Draft</span>
                </div>
                <p class="text-sm text-secondary-foreground mt-1">Build the document, check the totals, then issue it.</p>
            </div>

            <div class="flex items-center gap-2">
                @if ($invoiceId)
                    <a href="{{ route('accounting.invoice-pdf', ['invoice' => $invoiceId]) }}" target="_blank"
                       class="kt-btn kt-btn-outline gap-2">
                        <i class="ki-filled ki-document"></i> Preview PDF
                    </a>
                @endif
                <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                        class="kt-btn kt-btn-outline gap-2">
                    <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-cloud-download"></i> Save draft
                    </span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
                <button wire:click="issue" wire:loading.attr="disabled" wire:target="issue"
                        class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="issue" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-paper-plane"></i> Issue invoice
                    </span>
                    <span wire:loading wire:target="issue" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Issuing…
                    </span>
                </button>
            </div>
        </div>

        <div class="kt-card border-warning/30 bg-warning/10">
            <div class="kt-card-content p-4 flex items-start gap-3">
                <span class="inline-flex items-center justify-center size-9 rounded-lg bg-warning/15 text-warning shrink-0">
                    <i class="ki-filled ki-information-2 text-lg"></i>
                </span>
                <p class="text-xs text-secondary-foreground leading-relaxed max-w-[720px]">
                    Issuing freezes the exchange rate for the issue date onto the invoice and makes it read-only. Save as
                    often as you like first — a draft can be changed, an issued invoice cannot.
                </p>
            </div>
        </div>

        {{-- Invoice details --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Invoice details</h3>
            </div>
            <div class="kt-card-content p-5">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_customer">Bill to</label>
                        <select id="invoice_customer" wire:model.live="customerId"
                                class="kt-select @error('customerId') border-destructive @enderror">
                            <option value="">No named contact</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">
                                    {{ $customer->name }}@if ($customer->company) — {{ $customer->company->name }}@endif
                                </option>
                            @endforeach
                        </select>
                        @error('customerId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_company">Company</label>
                        <select id="invoice_company" wire:model.live="companyId"
                                class="kt-select @error('companyId') border-destructive @enderror">
                            <option value="">Billed to the person, not a company</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">
                                    {{ $company->name }}{{ $company->is_domestic ? ' — domestic' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="kt-form-description mt-1">
                            A domestic Turkish company makes the lira equivalent and the TCMB rate compulsory on the invoice.
                        </p>
                        @error('companyId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_number">Invoice number</label>
                        <input id="invoice_number" type="text" wire:model.blur="number"
                               class="kt-input @error('number') border-destructive @enderror">
                        @error('number')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_currency">Currency</label>
                        <select id="invoice_currency" wire:model.live="currency"
                                class="kt-select @error('currency') border-destructive @enderror">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}">{{ $code }} — {{ \Modules\Accounting\Support\Currencies::symbol($code) }}</option>
                            @endforeach
                        </select>
                        @error('currency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_issued">Issue date</label>
                        <input id="invoice_issued" type="date" wire:model.blur="issuedOn"
                               class="kt-input @error('issuedOn') border-destructive @enderror">
                        <p class="kt-form-description mt-1">The date the rate is taken from when you issue.</p>
                        @error('issuedOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_due">Due date</label>
                        <input id="invoice_due" type="date" wire:model.blur="dueOn"
                               class="kt-input @error('dueOn') border-destructive @enderror">
                        @error('dueOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    {{--
                        The period the work covers, which is not the period the
                        invoice covers. Both optional, both blank unless somebody
                        fills them in, and left out of the document entirely when
                        they are — an invoice for an hour of consultancy has no
                        period, and a blank one printed as a dash is noise.
                    --}}
                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_starts">Work starts</label>
                        <input id="invoice_starts" type="date" wire:model.blur="startsOn"
                               class="kt-input @error('startsOn') border-destructive @enderror">
                        <p class="kt-form-description mt-1">Optional. The day the work itself begins.</p>
                        @error('startsOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_ends">Work ends</label>
                        <input id="invoice_ends" type="date" wire:model.blur="endsOn"
                               class="kt-input @error('endsOn') border-destructive @enderror">
                        <p class="kt-form-description mt-1">Optional, and never before the start.</p>
                        @error('endsOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                </div>
            </div>
        </div>

        {{-- Line items --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Line items</h3>
                <span class="text-sm text-muted-foreground">
                    {{ count($items) }} {{ count($items) === 1 ? 'row' : 'rows' }}
                </span>
            </div>

            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="w-[70px]">Order</th>
                                <th class="min-w-[260px]">Description</th>
                                <th class="w-[110px] text-end">Qty</th>
                                <th class="w-[150px] text-end">Unit price</th>
                                <th class="w-[140px] text-end">Line total</th>
                                <th class="w-[56px]"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $i => $item)
                                <tr wire:key="line-{{ $i }}-{{ $item['id'] ?? 'new' }}">
                                    <td>
                                        <div class="flex items-center gap-0.5">
                                            <button wire:click="moveLine({{ $i }}, -1)" @disabled($i === 0)
                                                    class="kt-btn kt-btn-icon kt-btn-ghost size-7 disabled:opacity-30"
                                                    title="Move up" aria-label="Move row up">
                                                <i class="ki-filled ki-up text-xs"></i>
                                            </button>
                                            <button wire:click="moveLine({{ $i }}, 1)" @disabled($i === count($items) - 1)
                                                    class="kt-btn kt-btn-icon kt-btn-ghost size-7 disabled:opacity-30"
                                                    title="Move down" aria-label="Move row down">
                                                <i class="ki-filled ki-down text-xs"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        {{--
                                            The description carries the price; the
                                            list under it carries the work. Deliberately
                                            one cell and not two rows — the scope belongs
                                            to this line and nothing else, and a separate
                                            row would sit between two priced lines looking
                                            like a third.
                                        --}}
                                        <div class="flex flex-col gap-1.5">
                                            <input type="text" wire:model.blur="items.{{ $i }}.description"
                                                   placeholder="What are you billing for?"
                                                   aria-label="Line {{ $i + 1 }} description"
                                                   class="kt-input kt-input-sm w-full @error('items.'.$i.'.description') border-destructive @enderror">

                                            <label class="kt-form-label text-xs" for="line_{{ $i }}_tasks">
                                                What this covers
                                            </label>
                                            <textarea id="line_{{ $i }}_tasks" rows="3"
                                                      wire:model.blur="items.{{ $i }}.tasks"
                                                      aria-label="What line {{ $i + 1 }} covers"
                                                      placeholder="Keyword research and a mapped target list&#10;On-page fixes across every template&#10;Technical audit: speed, crawl, structured data"
                                                      class="kt-textarea kt-textarea-sm w-full @error('items.'.$i.'.tasks') border-destructive @enderror"></textarea>
                                            <p class="kt-form-description">
                                                Optional. One item per line, and no prices among them — the figure
                                                beside this line is the price for all of it.
                                            </p>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" inputmode="decimal"
                                               wire:model.live.debounce.400ms="items.{{ $i }}.quantity"
                                               aria-label="Line {{ $i + 1 }} quantity"
                                               class="kt-input kt-input-sm w-full text-end @error('items.'.$i.'.quantity') border-destructive @enderror">
                                    </td>
                                    <td>
                                        <div class="kt-input-group">
                                            <span class="kt-input-addon kt-input-addon-sm">{{ $symbol }}</span>
                                            <input type="text" inputmode="decimal"
                                                   wire:model.live.debounce.400ms="items.{{ $i }}.unit_price"
                                                   aria-label="Line {{ $i + 1 }} unit price"
                                                   class="kt-input kt-input-sm w-full text-end @error('items.'.$i.'.unit_price') border-destructive @enderror">
                                        </div>
                                    </td>
                                    <td class="text-end font-medium text-mono whitespace-nowrap">
                                        {{ $lineTotals[$i] }}
                                    </td>
                                    <td class="text-end">
                                        <button wire:click="removeLine({{ $i }})" @disabled(count($items) === 1)
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive disabled:opacity-30"
                                                title="Remove row" aria-label="Remove row">
                                            <i class="ki-filled ki-trash text-sm"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="kt-card-footer flex-wrap gap-3">
                <button wire:click="addLine" wire:loading.attr="disabled" wire:target="addLine"
                        class="kt-btn kt-btn-ghost kt-btn-sm gap-2 text-primary">
                    <span wire:loading.remove wire:target="addLine" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-plus"></i> Add line
                    </span>
                    <span wire:loading wire:target="addLine" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Adding…
                    </span>
                </button>
                @error('items')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
                @foreach ($errors->get('items.*') as $messages)
                    <span class="text-xs text-destructive">{{ $messages[0] }}</span>
                @endforeach
            </div>
        </div>

        {{-- Notes and totals --}}
        <div class="grid grid-cols-12 gap-5 items-start">

            <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">

                {{--
                    The KDV zero-rating.

                    Rendered only where it could plausibly apply — a buyer that
                    is a company outside Turkey — because a control that is
                    always there teaches people to reach for it. The conditions
                    are the control, not fine print beside it: nothing can be
                    applied until each one has been confirmed on its own, and
                    `exemptionCode()` re-checks every part of that on the server
                    before the column is written.
                --}}
                @if ($exemptions !== [])
                    <div class="kt-card">
                        <div class="kt-card-header">
                            <h3 class="kt-card-title">KDV — zero-rating an export of services</h3>
                            <span class="kt-badge kt-badge-sm {{ $appliedExemption ? 'kt-badge-success' : 'kt-badge-outline' }}">
                                {{ $appliedExemption ? 'Applied — code '.$appliedExemption : 'Not applied' }}
                            </span>
                        </div>
                        <div class="kt-card-content p-5 flex flex-col gap-5">
                            @foreach ($exemptions as $code => $exemption)
                                <div class="flex flex-col gap-4" wire:key="kdv-{{ $code }}">

                                    <p class="text-sm text-secondary-foreground leading-relaxed">
                                        This invoice is billed to a company outside Turkey, so it <em>may</em> be
                                        raised at a KDV rate of zero under exemption code {{ $code }},
                                        {{ $exemption['label'] }} — instead of the standard {{ $kdvPercent }}% on
                                        professional services. It qualifies only if every one of the following holds
                                        at the same time. Confirm each one you have checked with your mali müşavir.
                                        Kargah records the decision; it does not make it, and it will not apply the
                                        zero rate just because the client is abroad.
                                    </p>

                                    <div class="flex flex-col gap-2.5">
                                        @foreach ($exemption['conditions'] as $i => $condition)
                                            <label class="flex items-start gap-2.5 cursor-pointer"
                                                   wire:key="kdv-{{ $code }}-{{ $i }}">
                                                <input type="checkbox" class="kt-checkbox mt-0.5"
                                                       wire:model.live="kdvConfirmed.{{ $code }}.{{ $i }}">
                                                <span class="text-sm text-secondary-foreground leading-relaxed">
                                                    {{ $condition }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>

                                    @if ($appliedExemption === (string) $code)
                                        <div class="rounded-lg border border-success/30 bg-success/10 p-4 flex flex-col gap-3">
                                            <p class="text-sm text-mono">
                                                <i class="ki-filled ki-shield-tick text-success"></i>
                                                Zero-rated under code {{ $code }}. This invoice carries no KDV, and
                                                the document states the exemption and its code so a tax office can
                                                read it.
                                            </p>
                                            <div>
                                                <button wire:click="removeExemption"
                                                        class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                                                    <i class="ki-filled ki-arrow-circle-left"></i>
                                                    Charge KDV at the standard rate instead
                                                </button>
                                            </div>
                                        </div>
                                    @else
                                        <div class="flex flex-wrap items-center gap-3">
                                            <button wire:click="applyExemption('{{ $code }}')"
                                                    @disabled(! $this->allConfirmed((string) $code))
                                                    class="kt-btn kt-btn-sm kt-btn-primary gap-2 disabled:opacity-40">
                                                <i class="ki-filled ki-shield-tick"></i>
                                                Apply the zero rate under code {{ $code }}
                                            </button>
                                            <span class="text-xs text-muted-foreground">
                                                @if ($this->allConfirmed((string) $code))
                                                    Applying this sets the tax rate on this invoice to zero.
                                                @else
                                                    Confirm every condition above before this can be applied.
                                                @endif
                                            </span>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="kt-card-footer">
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                None of this is tax advice, and Kargah has not checked any of it against the Gelir
                                İdaresi Başkanlığı's own publications. The conditions are quoted so you can confirm
                                them with your mali müşavir; if any one of them fails, the standard rate applies
                                instead. What is stored is which exemption you applied, and that you applied it.
                            </p>
                        </div>
                    </div>
                @endif

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Notes</h3></div>
                    <div class="kt-card-content p-5">
                        <textarea wire:model.blur="notes" rows="4" class="kt-textarea w-full"
                                  aria-label="Invoice notes"
                                  placeholder="Anything the client should read alongside the figures."></textarea>
                        <p class="kt-form-description mt-2">Shown on the invoice, under the totals.</p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Payment terms</h3></div>
                    <div class="kt-card-content p-5">
                        <textarea wire:model.blur="terms" rows="3" class="kt-textarea w-full"
                                  aria-label="Payment terms"
                                  placeholder="When and how you expect to be paid."></textarea>
                        <p class="kt-form-description mt-2">Carried onto the document as written.</p>
                    </div>
                </div>
            </div>

            <div class="col-span-12 lg:col-span-5">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Totals</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">

                        <div class="flex items-center justify-between text-sm">
                            <span class="text-secondary-foreground">Subtotal</span>
                            <span class="font-medium text-mono">{{ $totals['subtotal'] }}</span>
                        </div>

                        @if ($appliedExemption)
                            {{--
                                No input at all once an exemption is applied. A
                                zero-rated invoice has a rate of zero, and a box
                                that still accepted 20 would put a figure on
                                screen that the document contradicts.
                            --}}
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-medium text-mono">Tax rate</div>
                                    <div class="text-xs text-muted-foreground mt-0.5">
                                        Zero-rated under exemption code {{ $appliedExemption }}
                                    </div>
                                </div>
                                <span class="text-sm font-medium text-mono w-[110px] text-end">{{ $totals['tax'] }}</span>
                            </div>
                        @else
                            <div class="flex items-center justify-between gap-3">
                                <label class="kt-form-label" for="invoice_tax">Tax rate</label>
                                <div class="flex items-center gap-3">
                                    <div class="kt-input-group max-w-[140px]">
                                        <input id="invoice_tax" type="text" inputmode="decimal"
                                               wire:model.live.debounce.400ms="taxPercent"
                                               class="kt-input kt-input-sm text-end @error('taxPercent') border-destructive @enderror">
                                        <span class="kt-input-addon kt-input-addon-sm">%</span>
                                    </div>
                                    <span class="text-sm font-medium text-mono w-[110px] text-end">{{ $totals['tax'] }}</span>
                                </div>
                            </div>
                            @error('taxPercent')<span class="text-xs text-destructive -mt-2 text-end">{{ $message }}</span>@enderror
                        @endif

                        <div class="flex items-center justify-between border-t border-border pt-4">
                            <span class="text-sm font-medium text-mono">Amount due</span>
                            <span class="text-2xl font-semibold text-mono" wire:loading.class="opacity-50">
                                {{ $totals['total'] }}
                            </span>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Totals recalculate on the server as you type, through the same code that stores them.
                            {{ $currency }} is the currency this invoice is issued in, and issuing freezes what it
                            is worth in {{ $reportingCurrency }} — the reporting currency set in
                            <span class="text-mono">config/accounting.php</span>.
                        </p>

                    </div>
                </div>
            </div>

        </div>

    @endif
</div>
