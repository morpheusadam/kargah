<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Invoice document view.
 *
 * The left column is the thing the client sees — it is laid out as a sheet of paper
 * and everything around it carries `print:hidden`, so the browser print dialog
 * produces the invoice and nothing else. The right column is the operator's side:
 * what happened to this invoice and what can still be done to it.
 */
new
#[Title('Invoice — Kargah')]
class extends Component
{
    public const CURRENCIES = ['USD' => '$', 'GBP' => '£', 'EUR' => '€'];

    public string $invoiceId = '1';

    public string $currency = 'USD';

    public function mount(string $invoice): void
    {
        $this->invoiceId = $invoice;

        // Backend phase resolves the stored invoice here.
    }

    public function with(): array
    {
        $items = [
            ['description' => 'Design system audit and component inventory', 'qty' => 1.0,  'price' => 1200.00],
            ['description' => 'Landing page build — Blade and Tailwind',      'qty' => 18.0, 'price' => 85.00],
            ['description' => 'Client training session',                       'qty' => 2.0,  'price' => 150.00],
        ];

        $discount = 150.00;
        $taxRate = 0.0;

        $subtotal = round(array_sum(array_map(fn (array $i) => $i['qty'] * $i['price'], $items)), 2);
        $taxable = round($subtotal - $discount, 2);
        $tax = round($taxable * ($taxRate / 100), 2);
        $total = round($taxable + $tax, 2);
        $paid = 0.00;

        return [
            'invoice' => [
                'number' => 'INV-00' . $this->invoiceId,
                'issued' => '20 July 2026',
                'due' => '19 August 2026',
                'status' => 'sent',
                'poNumber' => 'NW-2026-114',
            ],
            'business' => [
                'name' => 'Kargah Studio',
                'owner' => 'Nima Fazlipour',
                'email' => 'billing@kargah.dev',
                'address' => "Unit 4, Tanner Works\nManchester M4 1HN\nUnited Kingdom",
                'vat' => 'GB 412 8873 21',
            ],
            'client' => [
                'name' => 'Northwind Ltd',
                'contact' => 'Sam Okafor',
                'email' => 'sam@northwind.example',
                'address' => "42 Bevis Marks\nLondon EC3A 7BA\nUnited Kingdom",
            ],
            'rows' => array_map(fn (array $i) => [
                'description' => $i['description'],
                'qty' => rtrim(rtrim(number_format($i['qty'], 2), '0'), '.'),
                'price' => $this->money($i['price']),
                'total' => $this->money(round($i['qty'] * $i['price'], 2)),
            ], $items),
            'totals' => [
                'subtotal' => $this->money($subtotal),
                'discount' => '−' . $this->money($discount),
                'tax' => $this->money($tax),
                'taxRate' => rtrim(rtrim(number_format($taxRate, 2), '0'), '.'),
                'total' => $this->money($total),
                'paid' => $this->money($paid),
                'balance' => $this->money(round($total - $paid, 2)),
            ],
            'notes' => 'Thanks for the work — happy to pick up the analytics dashboard next quarter.',
            'terms' => 'Payment due within 30 days. Bank transfer preferred; details on request.',
            'timeline' => [
                ['label' => 'Created',  'detail' => 'Draft started by Nima', 'at' => '20 Jul 2026, 09:12', 'icon' => 'ki-notepad-edit', 'tone' => 'text-muted-foreground', 'done' => true],
                ['label' => 'Sent',     'detail' => 'Emailed to sam@northwind.example', 'at' => '20 Jul 2026, 14:38', 'icon' => 'ki-paper-plane', 'tone' => 'text-info', 'done' => true],
                ['label' => 'Viewed',   'detail' => 'Opened from London, twice', 'at' => '21 Jul 2026, 08:04', 'icon' => 'ki-eye', 'tone' => 'text-primary', 'done' => true],
                ['label' => 'Reminded', 'detail' => 'First reminder sent', 'at' => '30 Jul 2026, 09:00', 'icon' => 'ki-notification-status', 'tone' => 'text-warning', 'done' => true],
                ['label' => 'Paid',     'detail' => 'Awaiting payment', 'at' => '—', 'icon' => 'ki-dollar', 'tone' => 'text-muted-foreground', 'done' => false],
            ],
        ];
    }

    /* ---- Actions the backend will implement. Signatures are final. ---- */

    public function downloadPdf(): void
    {
        // Streams the rendered PDF once the invoice store exists.
    }

    public function sendReminder(): void
    {
        // Queues a reminder email through the mail module.
    }

    public function markAsPaid(): void
    {
        // Records the payment and closes the invoice.
    }

    public function duplicate(): void
    {
        // Copies this invoice into a new draft.
    }

    protected function money(float $amount): string
    {
        return (self::CURRENCIES[$this->currency] ?? '$') . number_format($amount, 2);
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Heading --}}
    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('accounting.invoices') }}" wire:navigate
                   class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to invoices" aria-label="Back to invoices">
                    <i class="ki-filled ki-black-left text-sm"></i>
                </a>
                <h1 class="text-xl font-semibold text-mono">{{ $invoice['number'] }}</h1>
                <span class="kt-badge kt-badge-sm kt-badge-info">Sent</span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">The document as the client sees it, plus what has happened to it.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('accounting.invoice-edit', ['invoice' => $invoiceId]) }}" wire:navigate
               class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-pencil"></i> Edit
            </a>
            <button wire:click="downloadPdf" wire:loading.attr="disabled" wire:target="downloadPdf"
                    class="kt-btn kt-btn-primary gap-2">
                <span wire:loading.remove wire:target="downloadPdf" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-exit-down"></i> Download PDF
                </span>
                <span wire:loading wire:target="downloadPdf" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Preparing…
                </span>
            </button>
        </div>
    </div>

    {{-- Payment status banner --}}
    <div class="kt-card border-warning/30 bg-warning/10 print:hidden">
        <div class="kt-card-content p-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center size-9 rounded-lg bg-warning/15 text-warning shrink-0">
                    <i class="ki-filled ki-time text-lg"></i>
                </span>
                <div>
                    <div class="text-sm font-semibold text-mono">Awaiting payment</div>
                    <div class="text-xs text-secondary-foreground mt-0.5">
                        {{ $totals['balance'] }} outstanding — due {{ $invoice['due'] }}.
                    </div>
                </div>
            </div>
            <button wire:click="markAsPaid" wire:loading.attr="disabled" wire:target="markAsPaid"
                    class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                <span wire:loading.remove wire:target="markAsPaid" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-check-circle"></i> Mark as paid
                </span>
                <span wire:loading wire:target="markAsPaid" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Recording…
                </span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- The document --}}
        <div class="col-span-12 lg:col-span-8 print:col-span-12">
            <div class="kt-card">
                <div class="kt-card-content p-6 sm:p-10 flex flex-col gap-8">

                    {{-- Business header --}}
                    <div class="flex flex-wrap items-start justify-between gap-6">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary text-primary-foreground font-bold shrink-0">K</span>
                            <div>
                                <div class="text-base font-semibold text-mono">{{ $business['name'] }}</div>
                                <div class="text-xs text-secondary-foreground mt-0.5">{{ $business['owner'] }}</div>
                                <div class="text-xs text-muted-foreground mt-2 whitespace-pre-line leading-relaxed">{{ $business['address'] }}</div>
                                <div class="text-xs text-muted-foreground mt-2">{{ $business['email'] }}</div>
                                <div class="text-xs text-muted-foreground">VAT {{ $business['vat'] }}</div>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="text-xs uppercase tracking-wide text-muted-foreground">Invoice</div>
                            <div class="text-2xl font-semibold text-mono mt-1">{{ $invoice['number'] }}</div>
                            <dl class="mt-4 text-xs flex flex-col gap-1">
                                <div class="flex justify-end gap-4">
                                    <dt class="text-muted-foreground">Issued</dt>
                                    <dd class="text-mono font-medium w-[110px] text-end">{{ $invoice['issued'] }}</dd>
                                </div>
                                <div class="flex justify-end gap-4">
                                    <dt class="text-muted-foreground">Due</dt>
                                    <dd class="text-mono font-medium w-[110px] text-end">{{ $invoice['due'] }}</dd>
                                </div>
                                <div class="flex justify-end gap-4">
                                    <dt class="text-muted-foreground">PO number</dt>
                                    <dd class="text-mono font-medium w-[110px] text-end">{{ $invoice['poNumber'] }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    {{-- Bill to --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 border-y border-border py-6">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-muted-foreground mb-2">Bill to</div>
                            <div class="text-sm font-semibold text-mono">{{ $client['name'] }}</div>
                            <div class="text-xs text-secondary-foreground mt-0.5">{{ $client['contact'] }}</div>
                            <div class="text-xs text-muted-foreground mt-2 whitespace-pre-line leading-relaxed">{{ $client['address'] }}</div>
                            <a href="mailto:{{ $client['email'] }}" class="text-xs text-primary hover:underline mt-2 inline-block">{{ $client['email'] }}</a>
                        </div>
                        <div class="sm:text-end">
                            <div class="text-xs uppercase tracking-wide text-muted-foreground mb-2">Amount due</div>
                            <div class="text-3xl font-semibold text-mono">{{ $totals['balance'] }}</div>
                            <div class="text-xs text-secondary-foreground mt-1">Payable in {{ $currency }} by {{ $invoice['due'] }}.</div>
                        </div>
                    </div>

                    {{-- Line items --}}
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[260px]">Description</th>
                                    <th class="w-[80px] text-end">Qty</th>
                                    <th class="w-[130px] text-end">Unit price</th>
                                    <th class="w-[130px] text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="text-mono">{{ $row['description'] }}</td>
                                        <td class="text-end text-secondary-foreground">{{ $row['qty'] }}</td>
                                        <td class="text-end text-secondary-foreground whitespace-nowrap">{{ $row['price'] }}</td>
                                        <td class="text-end font-medium text-mono whitespace-nowrap">{{ $row['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Totals --}}
                    <div class="flex justify-end">
                        <dl class="w-full sm:w-[320px] flex flex-col gap-2.5 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-secondary-foreground">Subtotal</dt>
                                <dd class="text-mono font-medium">{{ $totals['subtotal'] }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-secondary-foreground">Discount</dt>
                                <dd class="text-mono font-medium">{{ $totals['discount'] }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-secondary-foreground">Tax ({{ $totals['taxRate'] }}%)</dt>
                                <dd class="text-mono font-medium">{{ $totals['tax'] }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-border pt-2.5">
                                <dt class="font-semibold text-mono">Total</dt>
                                <dd class="text-mono font-semibold">{{ $totals['total'] }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-secondary-foreground">Paid to date</dt>
                                <dd class="text-mono font-medium">{{ $totals['paid'] }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-border pt-2.5">
                                <dt class="font-semibold text-mono">Balance due</dt>
                                <dd class="text-lg font-semibold text-warning">{{ $totals['balance'] }}</dd>
                            </div>
                        </dl>
                    </div>

                    {{-- Notes and terms --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 border-t border-border pt-6">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-muted-foreground mb-2">Notes</div>
                            <p class="text-sm text-secondary-foreground leading-relaxed">{{ $notes }}</p>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-wide text-muted-foreground mb-2">Payment terms</div>
                            <p class="text-sm text-secondary-foreground leading-relaxed">{{ $terms }}</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        {{-- Side panel --}}
        <div class="col-span-12 lg:col-span-4 flex flex-col gap-5 print:hidden">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Actions</h3></div>
                <div class="kt-card-content p-4 flex flex-col gap-2">
                    <button wire:click="downloadPdf" wire:loading.attr="disabled" wire:target="downloadPdf"
                            class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                        <i class="ki-filled ki-exit-down"></i> Download PDF
                    </button>
                    <button wire:click="sendReminder" wire:loading.attr="disabled" wire:target="sendReminder"
                            class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                        <span wire:loading.remove wire:target="sendReminder" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-notification-status"></i> Send reminder
                        </span>
                        <span wire:loading wire:target="sendReminder" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Queueing…
                        </span>
                    </button>
                    <button wire:click="markAsPaid" wire:loading.attr="disabled" wire:target="markAsPaid"
                            class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                        <i class="ki-filled ki-check-circle"></i> Mark as paid
                    </button>
                    <button wire:click="duplicate" wire:loading.attr="disabled" wire:target="duplicate"
                            class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                        <span wire:loading.remove wire:target="duplicate" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-copy"></i> Duplicate
                        </span>
                        <span wire:loading wire:target="duplicate" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Copying…
                        </span>
                    </button>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Activity</h3></div>
                <div class="kt-card-content p-5">
                    <ol class="flex flex-col">
                        @foreach ($timeline as $index => $event)
                            <li class="flex gap-3">
                                <div class="flex flex-col items-center shrink-0">
                                    <span class="inline-flex items-center justify-center size-8 rounded-full border border-border
                                                 {{ $event['done'] ? 'bg-accent/60' : 'bg-muted/40 border-dashed' }}">
                                        <i class="ki-filled {{ $event['icon'] }} text-sm {{ $event['tone'] }}"></i>
                                    </span>
                                    @unless ($loop->last)
                                        <span class="w-px grow bg-border my-1 min-h-[18px]"></span>
                                    @endunless
                                </div>
                                <div class="pb-5 min-w-0">
                                    <div class="text-sm font-medium text-mono">{{ $event['label'] }}</div>
                                    <div class="text-xs text-secondary-foreground mt-0.5">{{ $event['detail'] }}</div>
                                    <div class="text-xs text-muted-foreground mt-1">{{ $event['at'] }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Client</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary font-semibold shrink-0">
                            {{ strtoupper(substr($client['name'], 0, 2)) }}
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-mono truncate">{{ $client['name'] }}</div>
                            <div class="text-xs text-secondary-foreground truncate">{{ $client['contact'] }}</div>
                        </div>
                    </div>
                    <a href="{{ route('accounting.client-show', ['client' => 1]) }}" wire:navigate
                       class="kt-btn kt-btn-ghost kt-btn-sm justify-start gap-2 text-primary">
                        <i class="ki-filled ki-arrow-right"></i> Open client record
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>
