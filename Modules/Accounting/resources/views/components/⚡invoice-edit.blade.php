<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Invoice builder.
 *
 * One component serves both `accounting.invoice-create` and `accounting.invoice-edit`;
 * the only difference is whether a route parameter arrived. Line items live in
 * component state, so subtotal, discount, tax and total recalculate on the server on
 * every keystroke — that arithmetic is the contract the backend has to honour later,
 * so it is written here once rather than duplicated in JavaScript.
 */
new
#[Title('Invoice builder — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Currency codes this workspace can invoice in. */
    public const CURRENCIES = [
        'USD' => ['symbol' => '$', 'label' => 'USD — US dollar'],
        'GBP' => ['symbol' => '£', 'label' => 'GBP — Pound sterling'],
        'EUR' => ['symbol' => '€', 'label' => 'EUR — Euro'],
    ];

    /** Null on the create route, the invoice key on the edit route. */
    public ?string $invoiceId = null;

    #[Validate('required|string')]
    public string $clientKey = 'northwind';

    #[Validate('required|string|max:32')]
    public string $number = 'INV-0042';

    #[Validate('required|date')]
    public string $issuedOn = '2026-08-02';

    #[Validate('required|date')]
    public string $dueOn = '2026-09-01';

    #[Validate('required|string|size:3')]
    public string $currency = 'USD';

    /** @var array<int, array{description: string, qty: string, price: string}> */
    #[Validate('required|array|min:1')]
    public array $items = [
        ['description' => 'Design system audit and component inventory', 'qty' => '1', 'price' => '1200.00'],
        ['description' => 'Landing page build — Blade and Tailwind',      'qty' => '18', 'price' => '85.00'],
        ['description' => 'Client training session',                       'qty' => '2', 'price' => '150.00'],
    ];

    /** Kept as strings so an emptied input never breaks a typed property. */
    #[Validate('required|numeric|min:0')]
    public string $discount = '0';

    #[Validate('required|numeric|min:0|max:100')]
    public string $taxRate = '0';

    public string $notes = 'Thanks for the work — happy to pick up the analytics dashboard next quarter.';

    public string $terms = 'Payment due within 30 days. Bank transfer preferred; details on request.';

    public function mount(?string $invoice = null): void
    {
        $this->invoiceId = $invoice;

        if ($invoice !== null) {
            // Backend phase loads the stored invoice here. The fixture below keeps the
            // editor showing a realistic in-progress document in the meantime.
            $this->number = 'INV-00' . $invoice;
        }
    }

    public function with(): array
    {
        return [
            'clients' => [
                'northwind' => ['name' => 'Northwind Ltd',    'contact' => 'Sam Okafor',  'email' => 'sam@northwind.example',  'address' => "42 Bevis Marks\nLondon EC3A 7BA\nUnited Kingdom"],
                'acme'      => ['name' => 'Acme Studio',      'contact' => 'Rita Vance',  'email' => 'rita@acme.example',      'address' => "1180 Folsom Street\nSan Francisco, CA 94103\nUnited States"],
                'bluepeak'  => ['name' => 'Bluepeak',         'contact' => 'Jonas Reyes', 'email' => 'jonas@bluepeak.example', 'address' => "Torstraße 61\n10119 Berlin\nGermany"],
                'harbour'   => ['name' => 'Harbour & Finch',  'contact' => 'Nadia Cole',  'email' => 'nadia@harbourfinch.example', 'address' => "8 Quay Street\nBristol BS1 2JL\nUnited Kingdom"],
            ],
            'currencies' => self::CURRENCIES,
            'symbol' => $this->symbol(),
            'lineTotals' => array_map(fn (array $item) => $this->money($this->lineTotal($item)), $this->items),
            'totals' => [
                'subtotal' => $this->money($this->subtotal()),
                'discount' => '−' . $this->money($this->discountAmount()),
                'taxable' => $this->money($this->taxable()),
                'tax' => $this->money($this->taxAmount()),
                'total' => $this->money($this->total()),
            ],
        ];
    }

    /* ---- Line item editing. Pure UI state, so these do the work now. ---- */

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'qty' => '1', 'price' => '0.00'];

        $this->toastSuccess('Line added', 'The invoice now has ' . count($this->items) . ' lines.');
    }

    public function removeItem(int $index): void
    {
        if (count($this->items) <= 1) {
            $this->toastError('Line kept', 'An invoice needs at least one line.');

            return;
        }

        unset($this->items[$index]);

        $this->items = array_values($this->items);

        $this->toastSuccess('Line removed', 'Total is now ' . $this->money($this->total()) . '.');
    }

    public function moveItem(int $index, int $direction): void
    {
        $target = $index + $direction;

        if ($target < 0 || $target >= count($this->items)) {
            $this->toastError('Line not moved', 'That line is already at the ' . ($direction < 0 ? 'top' : 'bottom') . '.');

            return;
        }

        [$this->items[$index], $this->items[$target]] = [$this->items[$target], $this->items[$index]];

        $this->toastSuccess('Line moved', 'Now at position ' . ($target + 1) . ' of ' . count($this->items) . '.');
    }

    /* ---- Actions the backend will implement. Signatures are final. ---- */

    public function saveDraft(): void
    {
        $this->validate();

        // Persistence lands in the backend phase.
        $this->toastInfo('Not connected yet', 'Saving a draft lands with the backend phase. Nothing was stored.');
    }

    public function preview(): void
    {
        // Renders the document view once invoices are stored.
        $this->toastInfo('Not connected yet', 'The preview opens once invoices are stored.');
    }

    public function send(): void
    {
        $this->validate();

        // Queues the invoice email once the mail transport is wired.
        $this->toastInfo('Not connected yet', 'Sending an invoice lands with the backend phase. Nothing was emailed.');
    }

    /* ---- Money. Every figure on this page goes through money(). ---- */

    protected function symbol(): string
    {
        return self::CURRENCIES[$this->currency]['symbol'] ?? '$';
    }

    protected function money(float $amount): string
    {
        return $this->symbol() . number_format($amount, 2);
    }

    protected function lineTotal(array $item): float
    {
        return round(((float) ($item['qty'] ?? 0)) * ((float) ($item['price'] ?? 0)), 2);
    }

    protected function subtotal(): float
    {
        return round(array_sum(array_map(fn (array $item) => $this->lineTotal($item), $this->items)), 2);
    }

    protected function discountAmount(): float
    {
        return round(min(max((float) $this->discount, 0), $this->subtotal()), 2);
    }

    protected function taxable(): float
    {
        return round($this->subtotal() - $this->discountAmount(), 2);
    }

    protected function taxAmount(): float
    {
        return round($this->taxable() * ((float) $this->taxRate / 100), 2);
    }

    protected function total(): float
    {
        return round($this->taxable() + $this->taxAmount(), 2);
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Heading --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('accounting.invoices') }}" wire:navigate
                   class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to invoices" aria-label="Back to invoices">
                    <i class="ki-filled ki-black-left text-sm"></i>
                </a>
                <h1 class="text-xl font-semibold text-mono">
                    {{ $invoiceId ? 'Edit invoice' : 'New invoice' }}
                </h1>
                <span class="kt-badge kt-badge-sm kt-badge-outline">Draft</span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">Build the document, check the totals, then send it.</p>
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft"
                    class="kt-btn kt-btn-outline gap-2">
                <span wire:loading.remove wire:target="saveDraft" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-cloud-download"></i> Save draft
                </span>
                <span wire:loading wire:target="saveDraft" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
            <button wire:click="preview" wire:loading.attr="disabled" wire:target="preview"
                    class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-eye"></i> Preview
            </button>
            <button wire:click="send" wire:loading.attr="disabled" wire:target="send"
                    class="kt-btn kt-btn-primary gap-2">
                <span wire:loading.remove wire:target="send" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-paper-plane"></i> Send invoice
                </span>
                <span wire:loading wire:target="send" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Sending…
                </span>
            </button>
        </div>
    </div>

    {{-- Invoice details --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Invoice details</h3>
        </div>
        <div class="kt-card-content p-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">

                <div class="flex flex-col gap-1.5 xl:col-span-2">
                    <label class="kt-form-label" for="invoice_client">Bill to</label>
                    <select id="invoice_client" wire:model.live="clientKey"
                            class="kt-select @error('clientKey') border-destructive @enderror">
                        @foreach ($clients as $key => $c)
                            <option value="{{ $key }}">{{ $c['name'] }} — {{ $c['contact'] }}</option>
                        @endforeach
                    </select>
                    @error('clientKey')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror

                    @php $picked = $clients[$clientKey] ?? null; @endphp
                    @if ($picked)
                        <div class="mt-2 rounded-lg bg-muted/50 border border-border px-4 py-3">
                            <div class="text-sm font-medium text-mono">{{ $picked['name'] }}</div>
                            <div class="text-xs text-secondary-foreground mt-0.5">{{ $picked['email'] }}</div>
                            <div class="text-xs text-muted-foreground mt-1.5 whitespace-pre-line leading-relaxed">{{ $picked['address'] }}</div>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-5">
                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_number">Invoice number</label>
                        <input id="invoice_number" type="text" wire:model="number"
                               class="kt-input @error('number') border-destructive @enderror">
                        @error('number')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="invoice_currency">Currency</label>
                        <select id="invoice_currency" wire:model.live="currency"
                                class="kt-select @error('currency') border-destructive @enderror">
                            @foreach ($currencies as $code => $cur)
                                <option value="{{ $code }}">{{ $cur['label'] }}</option>
                            @endforeach
                        </select>
                        @error('currency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="kt-form-label" for="invoice_issued">Issue date</label>
                    <input id="invoice_issued" type="date" wire:model="issuedOn"
                           class="kt-input @error('issuedOn') border-destructive @enderror">
                    @error('issuedOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="kt-form-label" for="invoice_due">Due date</label>
                    <input id="invoice_due" type="date" wire:model="dueOn"
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
            <span class="text-sm text-muted-foreground">{{ count($items) }} {{ count($items) === 1 ? 'row' : 'rows' }}</span>
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
                            <th class="w-[130px] text-end">Line total</th>
                            <th class="w-[56px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $i => $item)
                            <tr wire:key="line-{{ $i }}">
                                <td>
                                    <div class="flex items-center gap-0.5">
                                        <button wire:click="moveItem({{ $i }}, -1)" @disabled($i === 0)
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7 disabled:opacity-30"
                                                title="Move up" aria-label="Move row up">
                                            <i class="ki-filled ki-up text-xs"></i>
                                        </button>
                                        <button wire:click="moveItem({{ $i }}, 1)" @disabled($i === count($items) - 1)
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7 disabled:opacity-30"
                                                title="Move down" aria-label="Move row down">
                                            <i class="ki-filled ki-down text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" wire:model="items.{{ $i }}.description"
                                           placeholder="What are you billing for?"
                                           class="kt-input kt-input-sm w-full">
                                </td>
                                <td>
                                    <input type="number" step="0.25" min="0"
                                           wire:model.live.debounce.400ms="items.{{ $i }}.qty"
                                           class="kt-input kt-input-sm w-full text-end">
                                </td>
                                <td>
                                    <div class="kt-input-group">
                                        <span class="kt-input-addon kt-input-addon-sm">{{ $symbol }}</span>
                                        <input type="number" step="0.01" min="0"
                                               wire:model.live.debounce.400ms="items.{{ $i }}.price"
                                               class="kt-input kt-input-sm w-full text-end">
                                    </div>
                                </td>
                                <td class="text-end font-medium text-mono whitespace-nowrap">
                                    {{ $lineTotals[$i] }}
                                </td>
                                <td class="text-end">
                                    <button wire:click="removeItem({{ $i }})" @disabled(count($items) === 1)
                                            class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive disabled:opacity-30"
                                            title="Remove row" aria-label="Remove row">
                                        <i class="ki-filled ki-trash text-sm"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="flex flex-col items-center justify-center text-center py-10">
                                        <i class="ki-filled ki-questionnaire-tablet text-3xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground mb-4">An invoice needs at least one line before it can be sent.</p>
                                        <button wire:click="addItem" class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                            <i class="ki-filled ki-plus"></i> Add a line
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="kt-card-footer">
            <button wire:click="addItem" wire:loading.attr="disabled" wire:target="addItem"
                    class="kt-btn kt-btn-ghost kt-btn-sm gap-2 text-primary">
                <span wire:loading.remove wire:target="addItem" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-plus"></i> Add line
                </span>
                <span wire:loading wire:target="addItem" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Adding…
                </span>
            </button>
            @error('items')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
        </div>
    </div>

    {{-- Notes and totals --}}
    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Notes</h3></div>
                <div class="kt-card-content p-5">
                    <textarea wire:model="notes" rows="4" class="kt-textarea w-full"
                              placeholder="Anything the client should read alongside the figures."></textarea>
                    <p class="kt-form-description mt-2">Shown on the invoice, under the totals.</p>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Payment terms</h3></div>
                <div class="kt-card-content p-5">
                    <textarea wire:model="terms" rows="3" class="kt-textarea w-full"
                              placeholder="When and how you expect to be paid."></textarea>
                    <p class="kt-form-description mt-2">Reused on the next invoice for this client.</p>
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
                        <label class="kt-form-label" for="invoice_discount">Discount</label>
                        <div class="flex items-center gap-3">
                            <div class="kt-input-group max-w-[140px]">
                                <span class="kt-input-addon kt-input-addon-sm">{{ $symbol }}</span>
                                <input id="invoice_discount" type="number" step="0.01" min="0"
                                       wire:model.live.debounce.400ms="discount"
                                       class="kt-input kt-input-sm text-end @error('discount') border-destructive @enderror">
                            </div>
                            <span class="text-sm font-medium text-mono w-[96px] text-end">{{ $totals['discount'] }}</span>
                        </div>
                    </div>
                    @error('discount')<span class="text-xs text-destructive -mt-2 text-end">{{ $message }}</span>@enderror

                    <div class="flex items-center justify-between text-sm border-t border-border pt-4">
                        <span class="text-secondary-foreground">Taxable amount</span>
                        <span class="font-medium text-mono">{{ $totals['taxable'] }}</span>
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <label class="kt-form-label" for="invoice_tax">Tax rate</label>
                        <div class="flex items-center gap-3">
                            <div class="kt-input-group max-w-[140px]">
                                <input id="invoice_tax" type="number" step="0.5" min="0" max="100"
                                       wire:model.live.debounce.400ms="taxRate"
                                       class="kt-input kt-input-sm text-end @error('taxRate') border-destructive @enderror">
                                <span class="kt-input-addon kt-input-addon-sm">%</span>
                            </div>
                            <span class="text-sm font-medium text-mono w-[96px] text-end">{{ $totals['tax'] }}</span>
                        </div>
                    </div>
                    @error('taxRate')<span class="text-xs text-destructive -mt-2 text-end">{{ $message }}</span>@enderror

                    <div class="flex items-center justify-between border-t border-border pt-4">
                        <span class="text-sm font-medium text-mono">Amount due</span>
                        <span class="text-2xl font-semibold text-mono" wire:loading.class="opacity-50">
                            {{ $totals['total'] }}
                        </span>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Totals recalculate as you type. {{ $currency }} is the currency this invoice is issued in.
                    </p>

                </div>
            </div>
        </div>

    </div>
</div>
