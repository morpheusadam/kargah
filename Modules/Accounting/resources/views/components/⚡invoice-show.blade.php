<?php

use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Accounting\Models\CryptoPayment;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Services\PaymentRecorder;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * The issued invoice, and what has happened to it.
 *
 * This page exists to make every figure defensible. Per 03-accounting.md it
 * must always show the invoice's own currency with its symbol, the reporting
 * figure alongside marked as converted, **the rate that produced it and the
 * date that rate is for** — never only the converted number — the chain and the
 * transaction hash for anything settled in USDT, and for a domestic Turkish
 * buyer the TCMB buying rate, its date and the lira equivalent.
 *
 * A number whose provenance is invisible is a number nobody can defend to an
 * accountant, so nothing here is shown without what produced it.
 *
 * Voiding sets `voided_at`. It never deletes: the ledger outlives the document,
 * and an invoice that vanishes is an invoice nobody can explain.
 */
new
#[Title('Invoice — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public const METHODS = [
        'bank' => 'Bank transfer',
        'wise' => 'Wise',
        'crypto' => 'Crypto',
        'cash' => 'Cash',
    ];

    public const CHAINS = [
        CryptoPayment::CHAIN_TRON => 'Tron — TRC-20',
        CryptoPayment::CHAIN_ETHEREUM => 'Ethereum — ERC-20',
    ];

    public ?int $invoiceId = null;

    public bool $missing = false;

    /* The payment panel, driven from component state rather than from KTUI. */

    public bool $paymentOpen = false;

    public string $paymentAmount = '';

    public string $paymentCurrency = Currencies::USD;

    public string $paymentPaidAt = '';

    public string $paymentMethod = 'bank';

    /** Left blank means "use the rate in force on the day it landed". */
    public string $paymentRate = '';

    public string $paymentNote = '';

    /* The on-chain half, asked for only when the method is crypto. */

    public string $chain = CryptoPayment::CHAIN_TRON;

    public string $txHash = '';

    public string $fromAddress = '';

    public string $toAddress = '';

    public string $chainAmount = '';

    public string $networkFee = '';

    public string $confirmations = '0';

    public bool $voidOpen = false;

    private ?Invoice $resolved = null;

    public function mount(string $invoice): void
    {
        $found = Invoice::query()->find($invoice);

        if ($found === null) {
            $this->missing = true;

            return;
        }

        $this->invoiceId = (int) $found->getKey();
        $this->resolved = $found;

        $this->paymentCurrency = $found->currency;
        $this->paymentAmount = app(PaymentRecorder::class)->outstanding($found);
        $this->paymentPaidAt = today()->toDateString();
    }

    public function invoice(): ?Invoice
    {
        if ($this->invoiceId === null) {
            return null;
        }

        return $this->resolved ??= Invoice::query()
            ->with(['lines', 'company', 'customer', 'payments.chainDetail'])
            ->find($this->invoiceId);
    }

    /* Everything the page states, with what produced it ----------------------- */

    /**
     * The reporting figure, never alone.
     *
     * The rate and the date it was taken for travel with it. `reporting_rate`
     * is frozen at issue, so this is what the invoice said on the day and not
     * what the market says now.
     */
    private function reportingFigure(Invoice $invoice): ?array
    {
        if ($invoice->reporting_currency === null) {
            return null;
        }

        return [
            'currency' => $invoice->reporting_currency,
            'amount' => $invoice->formattedReporting(),
            'rate' => $invoice->reporting_rate === null ? null : (string) $invoice->reporting_rate,
            'on' => $invoice->issued_on?->format('j F Y'),
            'same' => $invoice->reporting_currency === $invoice->currency,
        ];
    }

    /** The lira figure, which exists only for a domestic Turkish buyer. */
    private function liraFigure(Invoice $invoice): ?array
    {
        if ($invoice->try_equivalent === null) {
            return null;
        }

        return [
            'amount' => Money::format((string) $invoice->try_equivalent, Currencies::TRY),
            'rate' => (string) $invoice->issue_rate_to_try,
            'source' => $invoice->issue_rate_source,
            'on' => $invoice->issue_rate_date?->format('j F Y'),
            'note' => $invoice->rate_note,
        ];
    }

    /**
     * What restating the open part at today's rate would come to.
     *
     * A report, computed on demand and written nowhere, because nothing has
     * happened yet. Shown with the rate and the date, like everything else.
     */
    private function revaluation(Invoice $invoice): ?array
    {
        $unrealised = app(PaymentRecorder::class)->unrealised($invoice);

        if ($unrealised['rate'] === null) {
            return null;
        }

        return [
            'rate' => $unrealised['rate'],
            'on' => today()->format('j F Y'),
            'at_today' => Money::format($unrealised['at_today'], $invoice->reporting_currency ?? Currencies::USD),
            'difference' => Money::format($unrealised['difference'], $invoice->reporting_currency ?? Currencies::USD),
            'gain' => ! str_starts_with((string) $unrealised['difference'], '-'),
        ];
    }

    public function with(): array
    {
        $invoice = $this->invoice();

        if ($invoice === null) {
            return ['invoice' => null];
        }

        $recorder = app(PaymentRecorder::class);
        $outstanding = $recorder->outstanding($invoice);

        return [
            'invoice' => $invoice,
            'lines' => $invoice->lines,
            'payments' => $invoice->payments,
            'reporting' => $this->reportingFigure($invoice),
            'lira' => $this->liraFigure($invoice),
            'revaluation' => $invoice->isVoid() ? null : $this->revaluation($invoice),
            'outstanding' => Money::format($outstanding, $invoice->currency),
            'isSettled' => Money::fromStorage($outstanding, $invoice->currency)->isZero(),
            'methods' => self::METHODS,
            'chains' => self::CHAINS,
            'currencies' => Currencies::supported(),
            'state' => $this->state($invoice),
        ];
    }

    /** How the invoice reads, which is not always the column — overdue is a date having passed. */
    public function state(Invoice $invoice): string
    {
        if ($invoice->isVoid()) {
            return 'void';
        }

        if (! $invoice->isIssued()) {
            return 'draft';
        }

        return $invoice->isOverdue() ? 'overdue' : $invoice->status;
    }

    public function billedTo(Invoice $invoice): string
    {
        return $invoice->company?->billingName() ?? $invoice->customer?->name ?? 'No client on this invoice';
    }

    /* The payment panel --------------------------------------------------------- */

    public function openPayment(): void
    {
        $invoice = $this->invoice();

        if ($invoice === null) {
            return;
        }

        if (! $invoice->isIssued()) {
            $this->toastError(
                'Nothing to record yet',
                $invoice->number.' is still a draft. Issue it first, then a payment has something to settle.',
            );

            return;
        }

        if ($invoice->isVoid()) {
            $this->toastError('This invoice is void', 'A void invoice is not owed, so nothing can be paid against it.');

            return;
        }

        $this->resetValidation();

        $this->voidOpen = false;
        $this->paymentOpen = true;
        $this->paymentAmount = app(PaymentRecorder::class)->outstanding($invoice);
        $this->paymentCurrency = $invoice->currency;
        $this->paymentPaidAt = today()->toDateString();

        $this->toastSuccess(
            'Recording a payment',
            'It defaults to the whole of what is outstanding, in the invoice currency.',
        );
    }

    public function closePayment(): void
    {
        $wasOpen = $this->paymentOpen;

        $this->paymentOpen = false;

        if ($wasOpen) {
            $this->toastSuccess('Payment form closed', 'Nothing was recorded.');
        }
    }

    protected function rules(): array
    {
        return [
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
            'paymentCurrency' => ['required', Rule::in(Currencies::supported())],
            'paymentPaidAt' => ['required', 'date'],
            'paymentMethod' => ['required', Rule::in(array_keys(self::METHODS))],
            'paymentRate' => ['nullable', 'numeric', 'gt:0'],
            'paymentNote' => ['nullable', 'string', 'max:500'],
            'chain' => ['required_if:paymentMethod,crypto', Rule::in(array_keys(self::CHAINS))],
            'txHash' => ['nullable', 'string', 'max:100'],
            'fromAddress' => ['nullable', 'string', 'max:100'],
            'toAddress' => ['nullable', 'string', 'max:100'],
            'chainAmount' => ['nullable', 'numeric', 'gte:0'],
            'networkFee' => ['nullable', 'numeric', 'gte:0'],
            'confirmations' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'paymentAmount' => 'amount',
            'paymentCurrency' => 'currency',
            'paymentPaidAt' => 'payment date',
            'paymentMethod' => 'method',
            'paymentRate' => 'settlement rate',
            'txHash' => 'transaction hash',
            'chainAmount' => 'on-chain amount',
            'networkFee' => 'network fee',
        ];
    }

    /**
     * Record what actually landed.
     *
     * Everything goes through `PaymentRecorder`, which is where the realised
     * gain or loss between the issue rate and the settlement rate is worked out
     * and where the ledger entry is written. Nothing about that is repeated
     * here; a second implementation is a second thing to be wrong.
     */
    public function recordPayment(): void
    {
        $invoice = $this->invoice();

        if ($invoice === null) {
            return;
        }

        if (! $invoice->isIssued() || $invoice->isVoid()) {
            $this->toastError(
                'This payment was not recorded',
                $invoice->isVoid()
                    ? 'A void invoice is not owed, so nothing settles against it.'
                    : $invoice->number.' is still a draft. Issue it first.',
            );

            return;
        }

        $this->validate();

        $recorder = app(PaymentRecorder::class);

        $payment = $recorder->record(
            invoice: $invoice,
            amount: $this->decimal($this->paymentAmount),
            currency: $this->paymentCurrency,
            paidAt: $this->paymentPaidAt,
            method: $this->paymentMethod,
            settlementRate: $this->paymentRate === '' ? null : $this->decimal($this->paymentRate),
            note: trim($this->paymentNote) === '' ? null : trim($this->paymentNote),
        );

        if ($this->paymentMethod === 'crypto' && trim($this->txHash) !== '') {
            $recorder->attachChainDetail($payment, [
                'chain' => $this->chain,
                'tx_hash' => trim($this->txHash),
                'from_address' => trim($this->fromAddress) === '' ? null : trim($this->fromAddress),
                'to_address' => trim($this->toAddress) === '' ? null : trim($this->toAddress),
                // What the chain says arrived, which is not assumed to be what
                // the invoice asked for — wallets round differently.
                'amount' => $this->chainAmount === '' ? $this->decimal($this->paymentAmount) : $this->decimal($this->chainAmount),
                'network_fee' => $this->networkFee === '' ? null : $this->decimal($this->networkFee),
                'confirmations' => (int) $this->decimal($this->confirmations),
                'status' => 'confirmed',
                'verified_at' => now(),
            ]);
        }

        $this->paymentOpen = false;
        $this->resolved = null;

        $fresh = $this->invoice();
        $outstanding = $recorder->outstanding($fresh);

        $this->toastSuccess(
            $payment->formattedAmount().' recorded against '.$fresh->number,
            Money::fromStorage($outstanding, $fresh->currency)->isZero()
                ? 'It is settled in full and now reads as paid.'
                : Money::format($outstanding, $fresh->currency).' is still outstanding.',
        );

        $this->paymentAmount = $outstanding;
    }

    /* Voiding -------------------------------------------------------------------- */

    public function openVoid(): void
    {
        $this->paymentOpen = false;
        $this->voidOpen = true;

        $this->toastSuccess('Void this invoice?', 'Voiding keeps the document and everything recorded against it.');
    }

    public function closeVoid(): void
    {
        $wasOpen = $this->voidOpen;

        $this->voidOpen = false;

        if ($wasOpen) {
            $this->toastSuccess('Void cancelled', 'The invoice still stands.');
        }
    }

    /**
     * Void, which is not delete.
     *
     * The row stays, its payments stay, and the ledger entries were never the
     * invoice's to remove. What changes is that the amount stops being owed.
     */
    public function voidInvoice(): void
    {
        $invoice = $this->invoice();

        if ($invoice === null) {
            return;
        }

        if ($invoice->isVoid()) {
            $this->voidOpen = false;

            $this->toastError('Already void', $invoice->number.' was voided on '.$invoice->voided_at->format('j F Y').'.');

            return;
        }

        $invoice->forceFill(['status' => 'void', 'voided_at' => now()])->save();

        $this->voidOpen = false;
        $this->resolved = null;

        $this->toastSuccess(
            $invoice->number.' voided',
            'Nothing was deleted. The document and its payments stay on the record, and the amount is no longer owed.',
        );
    }

    /** A decimal string, or zero when the box does not hold one. Never a float. */
    private function decimal(mixed $value, string $fallback = '0'): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? $value : $fallback;
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($invoice === null)

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

    @else

        {{-- Click-away for whichever panel is open, below the panels and above everything else. --}}
        @if ($paymentOpen || $voidOpen)
            <div class="fixed inset-0 z-10" wire:click="closePayment" aria-hidden="true"></div>
        @endif

        {{-- Heading --}}
        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('accounting.invoices') }}" wire:navigate
                       class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Back to invoices" aria-label="Back to invoices">
                        <i class="ki-filled ki-black-left text-sm"></i>
                    </a>
                    <h1 class="text-xl font-semibold text-mono">{{ $invoice->number }}</h1>
                    <span class="kt-badge kt-badge-sm
                        @class([
                            'kt-badge-outline' => in_array($state, ['draft', 'void'], true),
                            'kt-badge-info' => $state === 'sent',
                            'kt-badge-warning' => $state === 'part_paid',
                            'kt-badge-success' => $state === 'paid',
                            'kt-badge-destructive' => $state === 'overdue',
                        ])">
                        {{ ['draft' => 'Draft', 'sent' => 'Sent', 'part_paid' => 'Part paid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'void' => 'Void'][$state] ?? ucfirst($state) }}
                    </span>
                </div>
                <p class="text-sm text-secondary-foreground mt-1">
                    Every figure below carries the rate and the date that produced it.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @unless ($invoice->isIssued())
                    <a href="{{ route('accounting.invoice-edit', ['invoice' => $invoice->id]) }}" wire:navigate
                       class="kt-btn kt-btn-outline gap-2">
                        <i class="ki-filled ki-pencil"></i> Edit draft
                    </a>
                @endunless
                <a href="{{ route('accounting.invoice-pdf', $invoice) }}" target="_blank"
                   class="kt-btn kt-btn-outline gap-2">
                    <i class="ki-filled ki-document"></i> PDF
                </a>
                @if ($invoice->isIssued() && ! $invoice->isVoid())
                    <button wire:click="openPayment" wire:loading.attr="disabled" wire:target="openPayment"
                            class="kt-btn kt-btn-primary gap-2">
                        <i class="ki-filled ki-wallet"></i> Record a payment
                    </button>
                @endif
            </div>
        </div>

        @unless ($invoice->isIssued())
            <div class="kt-card border-warning/30 bg-warning/10 print:hidden">
                <div class="kt-card-content p-4 flex items-start gap-3">
                    <span class="inline-flex items-center justify-center size-9 rounded-lg bg-warning/15 text-warning shrink-0">
                        <i class="ki-filled ki-notepad-edit text-lg"></i>
                    </span>
                    <div>
                        <div class="text-sm font-semibold text-mono">This is still a draft</div>
                        <p class="text-xs text-secondary-foreground mt-1">
                            No rate has been frozen and nothing is owed yet. Issue it from the editor when it is ready.
                        </p>
                    </div>
                </div>
            </div>
        @endunless

        @if ($invoice->isVoid())
            <div class="kt-card border-border bg-muted/40 print:hidden">
                <div class="kt-card-content p-4 flex items-start gap-3">
                    <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted text-muted-foreground shrink-0">
                        <i class="ki-filled ki-cross-circle text-lg"></i>
                    </span>
                    <div>
                        <div class="text-sm font-semibold text-mono">Voided on {{ $invoice->voided_at?->format('j F Y') }}</div>
                        <p class="text-xs text-secondary-foreground mt-1">
                            Nothing was deleted. The document and everything recorded against it stay on the record.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-12 gap-5 items-start">

            {{-- The figures --}}
            <div class="col-span-12 lg:col-span-8 flex flex-col gap-5">

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">What this invoice says</h3>
                        <span class="text-sm text-muted-foreground">{{ $invoice->currency }}</span>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-5">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-muted-foreground mb-1">Total</div>
                                <div class="text-3xl font-semibold text-mono">{{ $invoice->formattedTotal() }}</div>
                                <div class="text-xs text-secondary-foreground mt-1">
                                    In {{ $invoice->currency }}, the currency the client pays in.
                                </div>
                            </div>
                            <div class="sm:text-end">
                                <div class="text-xs uppercase tracking-wide text-muted-foreground mb-1">Outstanding</div>
                                <div class="text-3xl font-semibold {{ $isSettled ? 'text-success' : 'text-warning' }}">
                                    {{ $outstanding }}
                                </div>
                                <div class="text-xs text-secondary-foreground mt-1">
                                    Due {{ $invoice->due_on?->format('j F Y') ?? '—' }}
                                </div>
                            </div>
                        </div>

                        {{--
                            The reporting figure, and the rate that produced it.
                            Never the converted number on its own.
                        --}}
                        @if ($reporting !== null)
                            <div class="rounded-lg border border-border bg-muted/40 px-4 py-3">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="text-xs uppercase tracking-wide text-muted-foreground">
                                        In {{ $reporting['currency'] }} — reporting currency, converted
                                    </span>
                                    <span class="text-lg font-semibold text-mono">{{ $reporting['amount'] ?? '—' }}</span>
                                </div>
                                <p class="text-xs text-secondary-foreground mt-1.5 leading-relaxed">
                                    @if ($reporting['same'])
                                        The invoice is already in the reporting currency, so there is nothing to convert.
                                    @elseif ($reporting['rate'] === null)
                                        No rate was available for {{ $reporting['on'] ?? 'the issue date' }}, so no figure was
                                        frozen. It is left blank rather than invented.
                                    @else
                                        Rate {{ $reporting['rate'] }} {{ $invoice->currency }}/{{ $reporting['currency'] }},
                                        taken for {{ $reporting['on'] }} and frozen at issue. A later rate move does not change it.
                                    @endif
                                </p>
                            </div>
                        @endif

                        {{-- The lira figure, for a domestic Turkish buyer only. --}}
                        @if ($lira !== null)
                            <div class="rounded-lg border border-info/30 bg-info/10 px-4 py-3">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="text-xs uppercase tracking-wide text-info">
                                        Lira equivalent — Turkish tax procedure
                                    </span>
                                    <span class="text-lg font-semibold text-mono">{{ $lira['amount'] }}</span>
                                </div>
                                <p class="text-xs text-secondary-foreground mt-1.5 leading-relaxed">
                                    TCMB buying rate {{ $lira['rate'] }}, as at {{ $lira['on'] }}, source
                                    {{ $lira['source'] ?? 'unrecorded' }}. The buying rate is what the law specifies, and the
                                    liability for getting it wrong sits with the issuer.
                                </p>
                                @if ($lira['note'])
                                    <p class="text-xs text-warning mt-2 leading-relaxed">{{ $lira['note'] }}</p>
                                @endif
                            </div>
                        @endif

                        {{-- Unrealised revaluation: a report, written nowhere. --}}
                        @if ($revaluation !== null)
                            <div class="rounded-lg border border-border px-4 py-3">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="text-xs uppercase tracking-wide text-muted-foreground">
                                        Open amount at today's rate — unrealised
                                    </span>
                                    <span class="text-sm font-semibold text-mono">{{ $revaluation['at_today'] }}</span>
                                </div>
                                <p class="text-xs text-secondary-foreground mt-1.5 leading-relaxed">
                                    Rate {{ $revaluation['rate'] }} as at {{ $revaluation['on'] }}, which is
                                    {{ $revaluation['difference'] }}
                                    <span class="{{ $revaluation['gain'] ? 'text-success' : 'text-destructive' }}">
                                        {{ $revaluation['gain'] ? 'more' : 'less' }}
                                    </span>
                                    than the invoice froze. Nothing has happened yet, so nothing is recorded — this is a
                                    report, not an entry.
                                </p>
                            </div>
                        @endif

                    </div>
                </div>

                {{-- Lines --}}
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Lines</h3>
                        <span class="text-sm text-muted-foreground">
                            Issued {{ $invoice->issued_on?->format('j F Y') ?? '—' }}
                        </span>
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
                                    @forelse ($lines as $line)
                                        <tr wire:key="line-{{ $line->id }}">
                                            <td class="text-mono">{{ $line->description }}</td>
                                            <td class="text-end text-secondary-foreground">{{ $line->quantity }}</td>
                                            <td class="text-end text-secondary-foreground whitespace-nowrap">
                                                {{ \Modules\Accounting\Support\Money::format((string) $line->unit_price, $invoice->currency) }}
                                            </td>
                                            <td class="text-end font-medium text-mono whitespace-nowrap">
                                                {{ $line->formattedAmount($invoice->currency) }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="flex flex-col items-center justify-center text-center py-10">
                                                    <i class="ki-filled ki-questionnaire-tablet text-3xl text-muted-foreground mb-3"></i>
                                                    <p class="text-sm text-secondary-foreground">This invoice has no lines on it.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="kt-card-footer flex-col items-end gap-1">
                        <div class="flex items-center gap-6 text-sm">
                            <span class="text-secondary-foreground">Subtotal</span>
                            <span class="font-medium text-mono w-[140px] text-end">
                                {{ \Modules\Accounting\Support\Money::format((string) $invoice->subtotal, $invoice->currency) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-6 text-sm">
                            <span class="text-secondary-foreground">Tax at {{ rtrim(rtrim((string) $invoice->tax_percent, '0'), '.') ?: '0' }}%</span>
                            <span class="font-medium text-mono w-[140px] text-end">
                                {{ \Modules\Accounting\Support\Money::format((string) $invoice->tax_amount, $invoice->currency) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-6 border-t border-border pt-2 mt-1">
                            <span class="text-sm font-semibold text-mono">Total</span>
                            <span class="text-lg font-semibold text-mono w-[140px] text-end">{{ $invoice->formattedTotal() }}</span>
                        </div>
                    </div>
                </div>

                {{-- Payments --}}
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Payments</h3>
                        <span class="text-sm text-muted-foreground">
                            {{ $payments->count() }} {{ $payments->count() === 1 ? 'payment' : 'payments' }}
                        </span>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">
                        @forelse ($payments as $payment)
                            <div class="rounded-lg border border-border px-4 py-3" wire:key="payment-{{ $payment->id }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <div class="text-sm font-semibold text-mono">
                                        {{ $payment->formattedAmount() }}
                                        <span class="kt-badge kt-badge-sm kt-badge-outline ms-1">
                                            {{ $methods[$payment->method] ?? $payment->method }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ $payment->paid_at?->format('j F Y') }}
                                    </div>
                                </div>

                                @if ($payment->isCrossCurrency())
                                    <p class="text-xs text-secondary-foreground mt-1.5 leading-relaxed">
                                        Settled at {{ $payment->settlement_rate }}
                                        {{ $payment->currency }}/{{ $invoice->currency }} on
                                        {{ $payment->paid_at?->format('j F Y') }}, which applied
                                        {{ \Modules\Accounting\Support\Money::format((string) $payment->applied_amount, $invoice->currency) }}
                                        to the invoice.
                                        @if ((string) $payment->fx_gain_loss !== '0.000000')
                                            The rate move realised
                                            {{ \Modules\Accounting\Support\Money::format((string) $payment->fx_gain_loss, $invoice->currency) }}.
                                        @endif
                                    </p>
                                @endif

                                @if ($payment->note)
                                    <p class="text-xs text-muted-foreground mt-1.5">{{ $payment->note }}</p>
                                @endif

                                @if ($payment->chainDetail)
                                    @php($chainDetail = $payment->chainDetail)
                                    <div class="mt-3 rounded-lg bg-muted/40 border border-border px-3 py-2.5 flex flex-col gap-1.5">
                                        <div class="flex flex-wrap items-center gap-2 text-xs">
                                            <span class="kt-badge kt-badge-sm kt-badge-info">
                                                {{ $chains[$chainDetail->chain] ?? $chainDetail->chain }}
                                            </span>
                                            <span class="text-secondary-foreground">
                                                {{ $chainDetail->confirmations }} confirmations —
                                                {{ $chainDetail->isFinal() ? 'final' : 'still settling' }}
                                            </span>
                                        </div>
                                        <div class="text-xs text-secondary-foreground break-all">
                                            @if ($chainDetail->explorerUrl())
                                                <a href="{{ $chainDetail->explorerUrl() }}" target="_blank" rel="noopener"
                                                   class="text-primary hover:underline inline-flex items-center gap-1">
                                                    {{ $chainDetail->tx_hash }}
                                                    <i class="ki-filled ki-arrow-up-right text-[10px]"></i>
                                                </a>
                                            @else
                                                {{ $chainDetail->tx_hash }}
                                            @endif
                                        </div>
                                        <div class="text-xs text-muted-foreground">
                                            {{ $chainDetail->formattedAmount() }} arrived on chain.
                                            @if ($chainDetail->deltaAgainstPayment() !== null && $chainDetail->deltaAgainstPayment() !== '0.000000')
                                                That is {{ $chainDetail->deltaAgainstPayment() }} against what the payment
                                                records — wallets round differently, and the difference is a decision, not
                                                an error to hide.
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center text-center py-8">
                                <i class="ki-filled ki-wallet text-3xl text-muted-foreground mb-3"></i>
                                <p class="text-sm text-secondary-foreground mb-4">
                                    Nothing has been received against this invoice yet.
                                </p>
                                @if ($invoice->isIssued() && ! $invoice->isVoid())
                                    <button wire:click="openPayment" class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                        <i class="ki-filled ki-plus"></i> Record a payment
                                    </button>
                                @endif
                            </div>
                        @endforelse
                    </div>
                </div>

            </div>

            {{-- Side panel --}}
            <div class="col-span-12 lg:col-span-4 flex flex-col gap-5 print:hidden">

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Client</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary font-semibold shrink-0">
                                {{ $invoice->company?->initials() ?? $invoice->customer?->initials() ?? '—' }}
                            </span>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-mono truncate">{{ $this->billedTo($invoice) }}</div>
                                <div class="text-xs text-secondary-foreground truncate">
                                    {{ $invoice->customer?->email ?? $invoice->company?->country ?? '—' }}
                                </div>
                            </div>
                        </div>
                        @if ($invoice->company?->is_domestic)
                            <p class="text-xs text-info">
                                Domestic Turkish buyer, so the lira equivalent and the TCMB rate are compulsory on this invoice.
                            </p>
                        @endif
                        @if ($invoice->customer)
                            <a href="{{ route('accounting.client-show', ['client' => $invoice->customer->id]) }}" wire:navigate
                               class="kt-btn kt-btn-ghost kt-btn-sm justify-start gap-2 text-primary">
                                <i class="ki-filled ki-arrow-right"></i> Open client record
                            </a>
                        @endif
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Actions</h3></div>
                    <div class="kt-card-content p-4 flex flex-col gap-2">
                        <a href="{{ route('accounting.invoice-pdf', $invoice) }}" target="_blank"
                           class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                            <i class="ki-filled ki-document"></i> Open the PDF
                        </a>
                        <a href="{{ route('accounting.invoice-download', $invoice) }}"
                           class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                            <i class="ki-filled ki-exit-down"></i> Download the PDF
                        </a>
                        @if ($invoice->isIssued() && ! $invoice->isVoid())
                            <button wire:click="openPayment" class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                                <i class="ki-filled ki-wallet"></i> Record a payment
                            </button>
                            <button wire:click="openVoid" class="kt-btn kt-btn-outline justify-start gap-2 w-full text-destructive">
                                <i class="ki-filled ki-cross-circle"></i> Void this invoice
                            </button>
                        @endif
                    </div>
                </div>

                @if ($invoice->notes || $invoice->terms)
                    <div class="kt-card">
                        <div class="kt-card-header"><h3 class="kt-card-title">Notes and terms</h3></div>
                        <div class="kt-card-content p-5 flex flex-col gap-4">
                            @if ($invoice->notes)
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-muted-foreground mb-1">Notes</div>
                                    <p class="text-sm text-secondary-foreground leading-relaxed">{{ $invoice->notes }}</p>
                                </div>
                            @endif
                            @if ($invoice->terms)
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-muted-foreground mb-1">Payment terms</div>
                                    <p class="text-sm text-secondary-foreground leading-relaxed">{{ $invoice->terms }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

            </div>
        </div>

        {{-- Record a payment. State-driven, never KTUI: the morph strips a class KTUI added. --}}
        <div class="kt-modal kt-modal-center z-50 {{ $paymentOpen ? 'open' : '' }}"
             role="dialog" aria-modal="true" aria-labelledby="payment_form_title">

            <div class="kt-modal-backdrop" wire:click="closePayment"></div>

            <div class="kt-modal-content max-w-[620px] w-full">
                <div class="kt-modal-header">
                    <h3 class="kt-modal-title" id="payment_form_title">Record a payment</h3>
                    <button wire:click="closePayment" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                            title="Close" aria-label="Close">
                        <i class="ki-filled ki-cross text-base"></i>
                    </button>
                </div>

                <div class="kt-modal-body max-h-[70vh] kt-scrollable-y">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="payment_amount">Amount received</label>
                            <input id="payment_amount" type="text" inputmode="decimal" wire:model="paymentAmount"
                                   class="kt-input text-end @error('paymentAmount') border-destructive @enderror">
                            @error('paymentAmount')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="payment_currency">Received in</label>
                            <select id="payment_currency" wire:model.live="paymentCurrency"
                                    class="kt-select @error('paymentCurrency') border-destructive @enderror">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                            @error('paymentCurrency')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="payment_date">Landed on</label>
                            <input id="payment_date" type="date" wire:model="paymentPaidAt"
                                   class="kt-input @error('paymentPaidAt') border-destructive @enderror">
                            @error('paymentPaidAt')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="payment_method">Method</label>
                            <select id="payment_method" wire:model.live="paymentMethod"
                                    class="kt-select @error('paymentMethod') border-destructive @enderror">
                                @foreach ($methods as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('paymentMethod')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        @if ($paymentCurrency !== $invoice->currency)
                            <div class="flex flex-col gap-1.5 sm:col-span-2">
                                <label class="kt-form-label" for="payment_rate">
                                    Settlement rate to {{ $invoice->currency }}
                                </label>
                                <input id="payment_rate" type="text" inputmode="decimal" wire:model="paymentRate"
                                       placeholder="Leave blank to use the rate in force on the day it landed"
                                       class="kt-input @error('paymentRate') border-destructive @enderror">
                                <p class="kt-form-description mt-1">
                                    The realised gain or loss is the difference between this and the rate the invoice was
                                    issued at. It is worked out once, when the money lands, and never recomputed.
                                </p>
                                @error('paymentRate')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                        @endif

                        @if ($paymentMethod === 'crypto')
                            <div class="sm:col-span-2 border-t border-border pt-4">
                                <div class="text-sm font-medium text-mono">On chain</div>
                                <p class="kt-form-description mt-1">
                                    Enough for someone who does not trust you to check it themselves. The chain is not
                                    cosmetic: the same hash means nothing on the other network's explorer.
                                </p>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="payment_chain">Chain</label>
                                <select id="payment_chain" wire:model.live="chain"
                                        class="kt-select @error('chain') border-destructive @enderror">
                                    @foreach ($chains as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('chain')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="payment_confirmations">Confirmations</label>
                                <input id="payment_confirmations" type="text" inputmode="numeric" wire:model="confirmations"
                                       class="kt-input @error('confirmations') border-destructive @enderror">
                                @error('confirmations')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1.5 sm:col-span-2">
                                <label class="kt-form-label" for="payment_hash">Transaction hash</label>
                                <input id="payment_hash" type="text" wire:model="txHash"
                                       class="kt-input @error('txHash') border-destructive @enderror">
                                @error('txHash')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="payment_from">From address</label>
                                <input id="payment_from" type="text" wire:model="fromAddress" class="kt-input">
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="payment_to">To address</label>
                                <input id="payment_to" type="text" wire:model="toAddress" class="kt-input">
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="payment_chain_amount">Arrived on chain</label>
                                <input id="payment_chain_amount" type="text" inputmode="decimal" wire:model="chainAmount"
                                       placeholder="Blank means the same as the amount received"
                                       class="kt-input text-end @error('chainAmount') border-destructive @enderror">
                                @error('chainAmount')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="payment_fee">Network fee</label>
                                <input id="payment_fee" type="text" inputmode="decimal" wire:model="networkFee"
                                       class="kt-input text-end @error('networkFee') border-destructive @enderror">
                                @error('networkFee')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                        @endif

                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <label class="kt-form-label" for="payment_note">Note</label>
                            <textarea id="payment_note" rows="2" wire:model="paymentNote" class="kt-textarea w-full"
                                      placeholder="Reference, or anything worth remembering about this payment."></textarea>
                            @error('paymentNote')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                    </div>
                </div>

                <div class="kt-modal-footer">
                    <button wire:click="closePayment" class="kt-btn kt-btn-ghost">Cancel</button>
                    <button wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment"
                            class="kt-btn kt-btn-primary gap-2">
                        <span wire:loading.remove wire:target="recordPayment" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-check"></i> Record the payment
                        </span>
                        <span wire:loading wire:target="recordPayment" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Recording…
                        </span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Void confirmation --}}
        <div class="kt-modal kt-modal-center z-50 {{ $voidOpen ? 'open' : '' }}"
             role="dialog" aria-modal="true" aria-labelledby="void_title">

            <div class="kt-modal-backdrop" wire:click="closeVoid"></div>

            <div class="kt-modal-content max-w-[480px] w-full">
                <div class="kt-modal-header">
                    <h3 class="kt-modal-title" id="void_title">Void {{ $invoice->number }}?</h3>
                    <button wire:click="closeVoid" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                            title="Close" aria-label="Close">
                        <i class="ki-filled ki-cross text-base"></i>
                    </button>
                </div>
                <div class="kt-modal-body">
                    <p class="text-sm text-secondary-foreground leading-relaxed">
                        Voiding keeps the invoice, its lines and every payment recorded against it. Nothing is deleted —
                        the amount simply stops being owed, and the ledger entries stay where they are.
                    </p>
                </div>
                <div class="kt-modal-footer">
                    <button wire:click="closeVoid" class="kt-btn kt-btn-ghost">Keep it</button>
                    <button wire:click="voidInvoice" wire:loading.attr="disabled" wire:target="voidInvoice"
                            class="kt-btn kt-btn-destructive gap-2">
                        <span wire:loading.remove wire:target="voidInvoice" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-cross-circle"></i> Void it
                        </span>
                        <span wire:loading wire:target="voidInvoice" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Voiding…
                        </span>
                    </button>
                </div>
            </div>
        </div>

    @endif
</div>
