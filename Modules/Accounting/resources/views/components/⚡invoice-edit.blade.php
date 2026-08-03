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
 */
new
#[Title('Invoice builder — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /**
     * The owner's profit-and-loss currency.
     *
     * Settings will own this. Until they do it is stated in one place rather
     * than assumed in several, and it is the same default `InvoiceIssuer` uses.
     */
    private const REPORTING_CURRENCY = Currencies::USD;

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

    /** A percentage, as a decimal string: '20' is twenty per cent. */
    public string $taxPercent = '0';

    public string $notes = '';

    public string $terms = '';

    /**
     * The lines being edited.
     *
     * `id` is carried so an edit updates the row rather than replacing it —
     * a card billed as a line is joined to it through Core's `links` table, and
     * deleting the line to recreate it would quietly drop that link.
     *
     * @var array<int, array{id: ?int, description: string, quantity: string, unit_price: string}>
     */
    public array $items = [];

    private ?Invoice $resolved = null;

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
        $this->taxPercent = $this->trimZeros((string) $invoice->tax_percent);
        $this->notes = (string) $invoice->notes;
        $this->terms = (string) $invoice->terms;

        $this->items = $invoice->lines->map(fn (InvoiceLine $line): array => [
            'id' => (int) $line->id,
            'description' => (string) $line->description,
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
        return ['id' => null, 'description' => '', 'quantity' => '1', 'unit_price' => '0'];
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

    private function taxAmount(): BrickMoney
    {
        return Money::percentageOf($this->subtotal(), $this->decimal($this->taxPercent));
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
        ];
    }

    protected function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:40', Rule::unique('invoices', 'number')->ignore($this->invoiceId)],
            'currency' => ['required', Rule::in(Currencies::supported())],
            'issuedOn' => ['required', 'date'],
            'dueOn' => ['required', 'date', 'after_or_equal:issuedOn'],
            'taxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'customerId' => ['nullable', 'exists:customers,id'],
            'companyId' => ['nullable', 'exists:companies,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
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
                'tax_percent' => $this->decimal($this->taxPercent),
                'issued_on' => $this->issuedOn,
                'due_on' => $this->dueOn,
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
            $issued = app(InvoiceIssuer::class)->issue($invoice, self::REPORTING_CURRENCY);
        } catch (\DomainException $e) {
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
                                        <input type="text" wire:model.blur="items.{{ $i }}.description"
                                               placeholder="What are you billing for?"
                                               aria-label="Line {{ $i + 1 }} description"
                                               class="kt-input kt-input-sm w-full @error('items.'.$i.'.description') border-destructive @enderror">
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

                        <div class="flex items-center justify-between border-t border-border pt-4">
                            <span class="text-sm font-medium text-mono">Amount due</span>
                            <span class="text-2xl font-semibold text-mono" wire:loading.class="opacity-50">
                                {{ $totals['total'] }}
                            </span>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Totals recalculate on the server as you type, through the same code that stores them.
                            {{ $currency }} is the currency this invoice is issued in.
                        </p>

                    </div>
                </div>
            </div>

        </div>

    @endif
</div>
