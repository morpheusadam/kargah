<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Expense editor.
 *
 * Recording an expense is a thirty-second job, so the form is a single column with
 * the receipt drop zone beside it — nothing here should need a second visit. The
 * summary card mirrors the figures back in the chosen currency so a mistyped amount
 * is obvious before it is saved.
 */
new
#[Title('Record expense — Kargah')]
class extends Component
{
    public const CURRENCIES = [
        'USD' => ['symbol' => '$', 'label' => 'USD — US dollar'],
        'GBP' => ['symbol' => '£', 'label' => 'GBP — Pound sterling'],
        'EUR' => ['symbol' => '€', 'label' => 'EUR — Euro'],
    ];

    #[Validate('required|string|max:120')]
    public string $vendor = '';

    #[Validate('required|date')]
    public string $spentOn = '2026-08-02';

    #[Validate('required|string')]
    public string $category = 'Hosting';

    /** Kept as a string so an emptied input never breaks a typed property. */
    #[Validate('required|numeric|min:0')]
    public string $amount = '0.00';

    #[Validate('required|string|size:3')]
    public string $currency = 'USD';

    #[Validate('required|string')]
    public string $method = 'Card';

    public bool $billable = false;

    public string $billableTo = 'northwind';

    public string $notes = '';

    public function with(): array
    {
        return [
            'currencies' => self::CURRENCIES,
            'symbol' => $this->symbol(),
            'categories' => ['Hosting', 'Software', 'Email', 'Domains', 'Hardware', 'Travel', 'Subcontractors', 'Other'],
            'methods' => ['Card', 'Bank transfer', 'PayPal', 'Direct debit', 'Cash'],
            'clients' => [
                'northwind' => 'Northwind Ltd',
                'acme' => 'Acme Studio',
                'bluepeak' => 'Bluepeak',
                'harbour' => 'Harbour & Finch',
            ],
            'formattedAmount' => $this->money((float) $this->amount),
            'suggestions' => [
                ['vendor' => 'Hostinger',  'category' => 'Hosting',  'amount' => '$71.88'],
                ['vendor' => 'KeenThemes', 'category' => 'Software', 'amount' => '$49.00'],
                ['vendor' => 'Namecheap',  'category' => 'Domains',  'amount' => '$28.00'],
            ],
        ];
    }

    /* ---- Actions the backend will implement. Signatures are final. ---- */

    public function save(): void
    {
        $this->validate();

        // Persistence lands in the backend phase.
    }

    public function saveAndAddAnother(): void
    {
        $this->validate();

        // Persistence lands in the backend phase; the form then resets to blank.
    }

    public function useSuggestion(string $vendor, string $category): void
    {
        $this->vendor = $vendor;
        $this->category = $category;
    }

    protected function symbol(): string
    {
        return self::CURRENCIES[$this->currency]['symbol'] ?? '$';
    }

    protected function money(float $amount): string
    {
        return $this->symbol() . number_format($amount, 2);
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
                <h1 class="text-xl font-semibold text-mono">Record expense</h1>
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
                            <input id="expense_date" type="date" wire:model="spentOn"
                                   class="kt-input @error('spentOn') border-destructive @enderror">
                            @error('spentOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="expense_category">Category</label>
                            <select id="expense_category" wire:model.live="category"
                                    class="kt-select @error('category') border-destructive @enderror">
                                @foreach ($categories as $c)
                                    <option value="{{ $c }}">{{ $c }}</option>
                                @endforeach
                            </select>
                            @error('category')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="expense_amount">Amount</label>
                            <div class="kt-input-group">
                                <span class="kt-input-addon">{{ $symbol }}</span>
                                <input id="expense_amount" type="number" step="0.01" min="0"
                                       wire:model.live.debounce.400ms="amount"
                                       class="kt-input text-end @error('amount') border-destructive @enderror">
                            </div>
                            @error('amount')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="expense_currency">Currency</label>
                            <select id="expense_currency" wire:model.live="currency"
                                    class="kt-select @error('currency') border-destructive @enderror">
                                @foreach ($currencies as $code => $cur)
                                    <option value="{{ $code }}">{{ $cur['label'] }}</option>
                                @endforeach
                            </select>
                            @error('currency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <label class="kt-form-label" for="expense_method">Payment method</label>
                            <select id="expense_method" wire:model.live="method"
                                    class="kt-select @error('method') border-destructive @enderror">
                                @foreach ($methods as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </select>
                            @error('method')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Rebilling</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label class="kt-form-label" for="expense_billable">Bill this back to a client</label>
                            <p class="kt-form-description mt-1">
                                Billable expenses appear as a line item the next time you invoice that client.
                            </p>
                        </div>
                        <input id="expense_billable" type="checkbox" class="kt-switch shrink-0" wire:model.live="billable">
                    </div>

                    @if ($billable)
                        <div class="flex flex-col gap-1.5 border-t border-border pt-4">
                            <label class="kt-form-label" for="expense_client">Client</label>
                            <select id="expense_client" wire:model.live="billableTo" class="kt-select">
                                @foreach ($clients as $key => $name)
                                    <option value="{{ $key }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Notes</h3></div>
                <div class="kt-card-content p-5">
                    <textarea wire:model="notes" rows="3" class="kt-textarea w-full"
                              placeholder="What was this for? Future you will want to know."></textarea>
                </div>
            </div>

        </div>

        {{-- Receipt and summary --}}
        <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Receipt</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <label for="expense_receipt"
                           class="flex flex-col items-center justify-center text-center gap-2 rounded-lg border border-dashed border-border bg-muted/30 px-6 py-10 cursor-pointer transition-colors hover:border-primary/50 hover:bg-accent/40">
                        <span class="inline-flex items-center justify-center size-11 rounded-full bg-primary/10 text-primary">
                            <i class="ki-filled ki-cloud-add text-xl"></i>
                        </span>
                        <span class="text-sm font-medium text-mono">Drop a receipt here</span>
                        <span class="text-xs text-muted-foreground">or click to browse — PDF, PNG or JPG up to 8 MB</span>
                        <input id="expense_receipt" type="file" class="sr-only" accept=".pdf,.png,.jpg,.jpeg" multiple>
                    </label>

                    <div class="flex flex-col items-center justify-center text-center py-4 border-t border-border">
                        <i class="ki-filled ki-paper-clip text-2xl text-muted-foreground mb-2"></i>
                        <p class="text-sm text-secondary-foreground">No receipt attached yet.</p>
                        <p class="text-xs text-muted-foreground mt-1">Attachments upload once the expense is saved.</p>
                    </div>

                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Summary</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Vendor</span>
                        <span class="text-mono font-medium truncate max-w-[60%] text-end">{{ $vendor !== '' ? $vendor : '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Category</span>
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $category }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Method</span>
                        <span class="text-mono font-medium">{{ $method }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-secondary-foreground">Rebilled to</span>
                        <span class="text-mono font-medium">{{ $billable ? ($clients[$billableTo] ?? '—') : 'Not billable' }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-border pt-3">
                        <span class="font-medium text-mono">Total</span>
                        <span class="text-2xl font-semibold text-mono" wire:loading.class="opacity-50">{{ $formattedAmount }}</span>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Repeat from last month</h3></div>
                <div class="kt-card-content p-3 flex flex-col gap-1">
                    @foreach ($suggestions as $s)
                        <button wire:click="useSuggestion('{{ $s['vendor'] }}', '{{ $s['category'] }}')"
                                class="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg text-start transition-colors hover:bg-accent/50">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-mono truncate">{{ $s['vendor'] }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $s['category'] }}</span>
                            </span>
                            <span class="text-sm text-mono font-medium shrink-0">{{ $s['amount'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

        </div>
    </div>
</div>
