<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Accounting\Models\Estimate;
use Modules\Accounting\Models\EstimateLine;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * Estimate builder. The sibling of `⚡invoice-edit.blade.php`.
 *
 * One component serves both `accounting.estimate-create` and
 * `accounting.estimate-edit`; the only difference is whether a route parameter
 * arrived. Line editing, the money handling, the client picker and the currency
 * picker are all the invoice builder's, on purpose — these two pages are the
 * same job at two moments and should not feel like two applications.
 *
 * Four things are worth knowing before changing anything.
 *
 * **Nothing here freezes an exchange rate.** The invoice builder's `issue()`
 * captures the rates in force on the issue date and writes them onto the row,
 * because that is the moment the numbers stop being allowed to move. An estimate
 * has no such moment: nothing has been transacted, and the rate that will matter
 * is the one in force when the invoice is issued, weeks later. Copying the
 * freeze onto this page would put a rate nobody agreed to onto a proposal.
 *
 * **Converting creates a draft invoice and stops.** Issuing consumes a
 * sequential number for good and freezes rates; that is a deliberate act with
 * the invoice in front of you, and it lives in `InvoiceIssuer`. A convert that
 * silently issued would burn a number on a click.
 *
 * **A converted estimate is read-only.** Not disabled fields — the form is not
 * rendered. Its lines are now an invoice's lines, and editing the quote
 * afterwards would leave two documents that disagree with no way to tell which
 * one the client saw.
 *
 * **A missing estimate is a page, not a 404.** The route parameter is resolved
 * by hand rather than by model binding, so a link to an estimate that has since
 * been deleted explains itself instead of dead-ending.
 */
new
#[Title('Estimate builder — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** How long a new quote stays on the table. */
    private const VALID_DAYS = 30;

    /** Null on the create route, the estimate key on the edit route. */
    public ?int $estimateId = null;

    /** The route named an estimate and the estimate is not there. */
    public bool $missing = false;

    public string $number = '';

    public ?string $customerId = null;

    public ?string $companyId = null;

    public string $currency = Currencies::USD;

    public string $status = 'draft';

    public string $validUntil = '';

    public string $notes = '';

    public string $terms = '';

    /**
     * The lines being edited.
     *
     * `id` is carried so an edit updates the row rather than replacing it.
     *
     * @var array<int, array{id: ?int, description: string, quantity: string, unit_price: string}>
     */
    public array $items = [];

    private ?Estimate $resolved = null;

    public function mount(?string $estimate = null): void
    {
        if ($estimate === null) {
            $this->startNewQuote();

            return;
        }

        $found = Estimate::query()->with('lines')->find($estimate);

        if ($found === null) {
            $this->missing = true;

            return;
        }

        $this->estimateId = (int) $found->getKey();
        $this->resolved = $found;

        $this->fillFrom($found);
    }

    private function startNewQuote(): void
    {
        $this->number = Estimate::nextNumber();
        $this->validUntil = today()->addDays(self::VALID_DAYS)->toDateString();
        $this->terms = 'This quote is valid for '.self::VALID_DAYS.' days. '
            .'Payment due within 30 days of the invoice date.';
        $this->items = [$this->blankLine()];
    }

    private function fillFrom(Estimate $estimate): void
    {
        $this->number = (string) $estimate->number;
        $this->customerId = $estimate->customer_id === null ? null : (string) $estimate->customer_id;
        $this->companyId = $estimate->company_id === null ? null : (string) $estimate->company_id;
        $this->currency = $estimate->currency;
        $this->status = $estimate->status;
        $this->validUntil = $estimate->valid_until?->toDateString() ?? '';
        $this->notes = (string) $estimate->notes;
        $this->terms = (string) $estimate->terms;

        $this->items = $estimate->lines->map(fn (EstimateLine $line): array => [
            'id' => (int) $line->id,
            'description' => (string) $line->description,
            'quantity' => $this->trimZeros((string) $line->quantity),
            'unit_price' => $this->trimZeros((string) $line->unit_price),
        ])->all();

        if ($this->items === []) {
            $this->items = [$this->blankLine()];
        }
    }

    private function blankLine(): array
    {
        return ['id' => null, 'description' => '', 'quantity' => '1', 'unit_price' => '0'];
    }

    /* Reading the estimate ---------------------------------------------------- */

    public function estimate(): ?Estimate
    {
        if ($this->estimateId === null) {
            return null;
        }

        return $this->resolved ??= Estimate::query()
            ->with(['lines', 'customer', 'company', 'convertedInvoice'])
            ->find($this->estimateId);
    }

    public function isConverted(): bool
    {
        return $this->estimate()?->isConverted() ?? false;
    }

    /* Money ------------------------------------------------------------------- */

    /**
     * A decimal string, or the fallback when the box does not hold one.
     *
     * A half-typed number is ordinary — '1.' and '' both arrive while somebody
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

    /** The currency being quoted in, falling back rather than throwing on a tampered select. */
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

    private function total(): BrickMoney
    {
        return Money::sum(
            array_map(fn (array $item): BrickMoney => $this->lineMoney($item), $this->items),
            $this->safeCurrency(),
        );
    }

    private function show(BrickMoney $money): string
    {
        return Money::format(Money::toStorage($money), $this->safeCurrency());
    }

    /* The view ---------------------------------------------------------------- */

    public function with(): array
    {
        $estimate = $this->estimate();

        return [
            'estimate' => $estimate,
            'customers' => Customer::query()->active()->with('company')->orderBy('name')->get(),
            'companies' => Company::query()->active()->orderBy('name')->get(),
            'currencies' => Currencies::supported(),
            'statuses' => Estimate::STATUSES,
            'symbol' => Currencies::symbol($this->safeCurrency()),
            'lineTotals' => array_map(fn (array $item): string => $this->show($this->lineMoney($item)), $this->items),
            'total' => $this->show($this->total()),
            'storedLines' => $estimate?->lines ?? collect(),
            'expired' => $estimate?->isExpired() ?? false,
        ];
    }

    protected function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:40', Rule::unique('estimates', 'number')->ignore($this->estimateId)],
            'currency' => ['required', Rule::in(Currencies::supported())],
            'status' => ['required', Rule::in(array_keys(Estimate::STATUSES))],
            // Nullable: a quote with no stated expiry is a real answer, and not
            // the same thing as one that expired today.
            'validUntil' => ['nullable', 'date'],
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
            'number' => 'estimate number',
            'validUntil' => 'valid-until date',
            'customerId' => 'client',
            'companyId' => 'company',
        ];
    }

    /* Line editing -------------------------------------------------------------- */

    public function addLine(): void
    {
        if ($this->refuseWhenConverted()) {
            return;
        }

        $this->items[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        if ($this->refuseWhenConverted()) {
            return;
        }

        if (count($this->items) <= 1) {
            $this->toastError('Line kept', 'An estimate needs at least one line.');

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
        if ($this->refuseWhenConverted()) {
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

    /* Saving and converting ------------------------------------------------------- */

    /**
     * The one guard every write goes through.
     *
     * The template does not render the form for a converted estimate, so
     * reaching this means it was converted in another tab while this one was
     * open.
     */
    private function refuseWhenConverted(): bool
    {
        $estimate = $this->estimate();

        if ($estimate === null || ! $estimate->isConverted()) {
            return false;
        }

        $this->toastError(
            $estimate->number.' has been invoiced',
            'It became invoice '.$estimate->converted_invoice_number.'. Edit that invoice instead — '
            .'changing the quote now would leave two documents that disagree.',
        );

        return true;
    }

    public function save(): void
    {
        if ($this->refuseWhenConverted()) {
            return;
        }

        $this->validate();

        $estimate = $this->persist();

        $this->toastSuccess(
            $estimate->number.' saved',
            'Nothing has been billed. Convert it once the client accepts.',
        );
    }

    /**
     * Write the quote and its lines, then total it from the lines.
     *
     * The total is written by the model rather than from the figure on screen,
     * so the stored number and the displayed number come from the same
     * arithmetic instead of from two implementations that agree today.
     */
    private function persist(): Estimate
    {
        $estimate = DB::transaction(function (): Estimate {
            $estimate = $this->estimate() ?? new Estimate;

            $estimate->forceFill([
                'number' => trim($this->number),
                'customer_id' => $this->customerId === null || $this->customerId === '' ? null : (int) $this->customerId,
                'company_id' => $this->companyId === null || $this->companyId === '' ? null : (int) $this->companyId,
                'status' => $this->status,
                'currency' => $this->safeCurrency(),
                'valid_until' => $this->validUntil === '' ? null : $this->validUntil,
                'notes' => $this->notes === '' ? null : $this->notes,
                'terms' => $this->terms === '' ? null : $this->terms,
                'created_by' => $estimate->exists ? $estimate->created_by : auth()->id(),
            ])->save();

            $this->syncLines($estimate);

            return $estimate;
        });

        $this->estimateId = (int) $estimate->getKey();
        $this->resolved = null;

        $totalled = $this->estimate()->recalculateTotal();

        // Ids for the rows that were just created, so the next save updates
        // them instead of dropping and recreating them.
        $this->fillFrom($totalled->load('lines'));

        return $totalled;
    }

    /**
     * Match the stored lines to the edited ones.
     *
     * Updated where an id came back, created where it did not, and deleted only
     * where a line the estimate used to have is no longer on the form.
     */
    private function syncLines(Estimate $estimate): void
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
                : EstimateLine::query()->where('estimate_id', $estimate->id)->find($item['id']);

            if ($existing === null) {
                $kept[] = EstimateLine::query()->create($attributes + ['estimate_id' => $estimate->id])->id;

                continue;
            }

            $existing->forceFill($attributes)->save();

            $kept[] = $existing->id;
        }

        EstimateLine::query()
            ->where('estimate_id', $estimate->id)
            ->whereNotIn('id', $kept === [] ? [0] : $kept)
            ->delete();
    }

    /**
     * Convert: the point of the whole feature.
     *
     * The quote is saved first, so what is billed is what is on screen. The
     * `DomainException` is the model refusing to convert an estimate that has
     * not been accepted, or refusing to convert one twice — both are sentences a
     * person can act on rather than a 500. `\DomainException` is written with a
     * leading backslash on purpose: a `use` with a non-compound name is fatal
     * inside a Livewire single-file component.
     */
    public function convert(): void
    {
        if ($this->refuseWhenConverted()) {
            return;
        }

        $this->validate();

        $estimate = $this->persist();

        try {
            $invoice = $estimate->convertToInvoice();
        } catch (\DomainException $e) {
            $this->resolved = null;

            $this->toastError('This estimate was not converted', $e->getMessage());

            return;
        }

        $this->flashToast(
            'success',
            $estimate->number.' became '.$invoice->number,
            'A draft, not an issued invoice: nothing has been numbered for good and no rate is frozen yet. '
            .'Set the tax rate, check the dates, then issue it.',
        );

        $this->redirectRoute('accounting.invoice-edit', ['invoice' => $invoice->id], navigate: true);
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($missing)

        {{-- The route named an estimate that is not there. --}}
        <div class="kt-card">
            <div class="kt-card-content p-10 flex flex-col items-center text-center gap-3">
                <i class="ki-filled ki-document text-3xl text-muted-foreground"></i>
                <h1 class="text-lg font-semibold text-mono">That estimate is not here</h1>
                <p class="text-sm text-secondary-foreground max-w-[420px]">
                    It was deleted, or the link points at a number this install has never had.
                </p>
                <a href="{{ route('accounting.estimates') }}" wire:navigate class="kt-btn kt-btn-primary gap-2 mt-2">
                    <i class="ki-filled ki-arrow-left"></i> Back to estimates
                </a>
            </div>
        </div>

    @elseif ($this->isConverted())

        {{--
            Already invoiced. The form is not rendered at all — the lines below
            are an invoice's lines now, and a disabled field is an invitation to
            look for the way round it.
        --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('accounting.estimates') }}" wire:navigate
                       class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to estimates" aria-label="Back to estimates">
                        <i class="ki-filled ki-black-left text-sm"></i>
                    </a>
                    <h1 class="text-xl font-semibold text-mono">{{ $estimate->number }}</h1>
                    <span class="kt-badge kt-badge-sm kt-badge-success">Invoiced</span>
                </div>
                <p class="text-sm text-secondary-foreground mt-1">
                    This quote became invoice {{ $estimate->converted_invoice_number }}. This is what it said.
                </p>
            </div>
            @if ($estimate->convertedInvoice)
                <a href="{{ route('accounting.invoice-show', ['invoice' => $estimate->converted_invoice_id]) }}"
                   wire:navigate class="kt-btn kt-btn-primary gap-2">
                    <i class="ki-filled ki-bill"></i> Open {{ $estimate->converted_invoice_number }}
                </a>
            @endif
        </div>

        <div class="kt-card border-success/30 bg-success/10">
            <div class="kt-card-content p-4 flex items-start gap-3">
                <span class="inline-flex items-center justify-center size-9 rounded-lg bg-success/15 text-success shrink-0">
                    <i class="ki-filled ki-check-circle text-lg"></i>
                </span>
                <div>
                    <div class="text-sm font-semibold text-mono">
                        Converted on {{ $estimate->converted_at?->format('j F Y') ?? 'an earlier date' }}
                    </div>
                    <p class="text-xs text-secondary-foreground mt-1 max-w-[640px] leading-relaxed">
                        An accepted quote is converted once. Converting it again would bill the client twice for the
                        same work, so this page no longer offers it.
                        @if ($estimate->convertedInvoice === null)
                            Invoice {{ $estimate->converted_invoice_number }} has since been deleted; its number stays
                            reserved, so nothing else will ever carry it.
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">What it quoted</h3>
                <span class="text-sm text-muted-foreground">{{ $estimate->currency }}</span>
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
                                        {{ \Modules\Accounting\Support\Money::format((string) $line->unit_price, $estimate->currency) }}
                                    </td>
                                    <td class="text-end font-medium text-mono whitespace-nowrap">{{ $line->formattedAmount() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="kt-card-footer justify-end">
                <span class="text-sm text-secondary-foreground me-3">Quoted total</span>
                <span class="text-lg font-semibold text-mono">{{ $estimate->formattedTotal() }}</span>
            </div>
        </div>

    @else

        {{-- Heading --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('accounting.estimates') }}" wire:navigate
                       class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to estimates" aria-label="Back to estimates">
                        <i class="ki-filled ki-black-left text-sm"></i>
                    </a>
                    <h1 class="text-xl font-semibold text-mono">
                        {{ $estimateId ? 'Edit '.$number : 'New estimate' }}
                    </h1>
                    <span class="kt-badge kt-badge-sm {{ $expired ? 'kt-badge-warning' : 'kt-badge-outline' }}">
                        {{ $expired ? 'Expired' : $statuses[$status] ?? 'Draft' }}
                    </span>
                </div>
                <p class="text-sm text-secondary-foreground mt-1">
                    Quote the work. Nothing is billed until you convert it.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                        class="kt-btn kt-btn-outline gap-2">
                    <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-cloud-download"></i> Save estimate
                    </span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
                @if ($status === 'accepted')
                    <button wire:click="convert" wire:loading.attr="disabled" wire:target="convert"
                            wire:confirm="Turn {{ $number }} into an invoice?&#10;&#10;A draft invoice is created carrying this client, this currency, these lines and these terms. Nothing is issued: no number is consumed for good and no exchange rate is frozen until you issue it yourself.&#10;&#10;An estimate is converted once — after this, {{ $number }} can no longer be edited or converted again."
                            class="kt-btn kt-btn-primary gap-2">
                        <span wire:loading.remove wire:target="convert" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-bill"></i> Convert to invoice
                        </span>
                        <span wire:loading wire:target="convert" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Converting…
                        </span>
                    </button>
                @endif
            </div>
        </div>

        <div class="kt-card border-info/30 bg-info/10">
            <div class="kt-card-content p-4 flex items-start gap-3">
                <span class="inline-flex items-center justify-center size-9 rounded-lg bg-info/15 text-info shrink-0">
                    <i class="ki-filled ki-information-2 text-lg"></i>
                </span>
                <p class="text-xs text-secondary-foreground leading-relaxed max-w-[720px]">
                    An estimate freezes no exchange rate — nothing has been transacted, so there is nothing to freeze.
                    The rate that matters is the one in force on the day you issue the invoice this becomes. Tax is set
                    on that invoice too: whether KDV applies to a job, and whether the export exemption holds, is a
                    judgement made per invoice.
                    @if ($expired)
                        This quote's validity date has passed, which is why it reads as expired — move the date on if it
                        is still open.
                    @endif
                </p>
            </div>
        </div>

        {{-- Estimate details --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Estimate details</h3>
            </div>
            <div class="kt-card-content p-5">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="estimate_customer">Quote to</label>
                        <select id="estimate_customer" wire:model.live="customerId"
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
                        <label class="kt-form-label" for="estimate_company">Company</label>
                        <select id="estimate_company" wire:model.live="companyId"
                                class="kt-select @error('companyId') border-destructive @enderror">
                            <option value="">Quoted to the person, not a company</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">
                                    {{ $company->name }}{{ $company->is_domestic ? ' — domestic' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="kt-form-description mt-1">
                            Carried onto the invoice, where a domestic Turkish buyer makes the lira equivalent compulsory.
                        </p>
                        @error('companyId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="estimate_number">Estimate number</label>
                        <input id="estimate_number" type="text" wire:model.blur="number"
                               class="kt-input @error('number') border-destructive @enderror">
                        <p class="kt-form-description mt-1">
                            Its own sequence. An estimate never takes an invoice number.
                        </p>
                        @error('number')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="estimate_currency">Currency</label>
                        <select id="estimate_currency" wire:model.live="currency"
                                class="kt-select @error('currency') border-destructive @enderror">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}">{{ $code }} — {{ \Modules\Accounting\Support\Currencies::symbol($code) }}</option>
                            @endforeach
                        </select>
                        @error('currency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="estimate_valid_until">Valid until</label>
                        <input id="estimate_valid_until" type="date" wire:model.blur="validUntil"
                               class="kt-input @error('validUntil') border-destructive @enderror">
                        <p class="kt-form-description mt-1">
                            Leave it empty for a quote with no expiry. A date in the past reads as expired.
                        </p>
                        @error('validUntil')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="estimate_status">Status</label>
                        <select id="estimate_status" wire:model.live="status"
                                class="kt-select @error('status') border-destructive @enderror">
                            @foreach ($statuses as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="kt-form-description mt-1">
                            Mark it accepted once the client has said yes — that is what unlocks the conversion.
                        </p>
                        @error('status')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
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
                                               placeholder="What are you quoting for?"
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

        {{-- Notes and total --}}
        <div class="grid grid-cols-12 gap-5 items-start">

            <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Notes</h3></div>
                    <div class="kt-card-content p-5">
                        <textarea wire:model.blur="notes" rows="4" class="kt-textarea w-full"
                                  aria-label="Estimate notes"
                                  placeholder="Assumptions, what is out of scope, anything the client should read alongside the figures."></textarea>
                        <p class="kt-form-description mt-2">Carried onto the invoice when this is converted.</p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Terms</h3></div>
                    <div class="kt-card-content p-5">
                        <textarea wire:model.blur="terms" rows="3" class="kt-textarea w-full"
                                  aria-label="Estimate terms"
                                  placeholder="How long the quote stands, when and how you expect to be paid."></textarea>
                        <p class="kt-form-description mt-2">Carried onto the invoice as written.</p>
                    </div>
                </div>
            </div>

            <div class="col-span-12 lg:col-span-5">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Total</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">

                        <div class="flex items-center justify-between border-b border-border pb-4">
                            <span class="text-sm font-medium text-mono">Quoted</span>
                            <span class="text-2xl font-semibold text-mono" wire:loading.class="opacity-50">
                                {{ $total }}
                            </span>
                        </div>

                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Before tax. The total recalculates on the server as you type, through the same code that
                            stores it. {{ $currency }} is the currency this quote is given in, and the invoice it
                            becomes is raised in the same one.
                        </p>

                        @if ($status === 'accepted')
                            <p class="text-xs text-secondary-foreground leading-relaxed">
                                Accepted. Converting raises a draft invoice with these lines and this total, and marks
                                this quote as invoiced — once.
                            </p>
                        @endif

                    </div>
                </div>
            </div>

        </div>

    @endif
</div>
