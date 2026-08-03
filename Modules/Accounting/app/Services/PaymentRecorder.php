<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CryptoPayment;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;

/**
 * Taking a payment, and the gain or loss between issue and settlement.
 *
 * A payment may arrive in a different currency from the invoice: a USD invoice
 * settled in USDT, or a TRY invoice paid weeks later at a different rate. The
 * realised difference is computed once, at the moment money actually lands,
 * and written to the row.
 *
 * **Realised, not unrealised.** Restating still-open invoices at today's rate
 * is a report, computed on demand and stored nowhere, because nothing has
 * happened yet. Only a payment realises anything.
 */
class PaymentRecorder
{
    public function __construct(private readonly ExchangeRates $rates) {}

    /**
     * Record a payment against an invoice.
     *
     * @param  string  $amount  Decimal string, in `$currency`.
     * @param  ?string  $settlementRate  To the invoice's currency. Looked up when omitted.
     */
    public function record(
        Invoice $invoice,
        string|float $amount,
        string $currency,
        Carbon|string $paidAt,
        string $method = 'bank',
        string|float|null $settlementRate = null,
        ?string $note = null,
    ): Payment {
        // `float` is in the signature only so this guard can reject it by name.
        // Typed `string`, PHP would coerce first and the check would never fire.
        if (is_float($amount) || is_float($settlementRate)) {
            throw new \InvalidArgumentException(
                'A float reached the payment path. Money is decimal strings all the way through — see '
                .Money::class.'.',
            );
        }

        $paidAt = $paidAt instanceof Carbon ? $paidAt : Carbon::parse($paidAt);

        return DB::transaction(function () use ($invoice, $amount, $currency, $paidAt, $method, $settlementRate, $note) {
            $rate = $settlementRate
                ?? $this->rates->rateFor($currency, $invoice->currency, $paidAt)
                ?? '1.000000';

            $applied = Money::convert(Money::of($amount, $currency), $rate, $invoice->currency);

            $payment = Payment::query()->create([
                'invoice_id' => $invoice->id,
                'currency' => $currency,
                'amount' => Money::toStorage(Money::of($amount, $currency)),
                'settlement_rate' => $rate,
                'applied_amount' => Money::toStorage($applied),
                'fx_gain_loss' => $this->realisedFxGainLoss($invoice, $amount, $currency, $rate),
                'method' => $method,
                'paid_at' => $paidAt,
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            // The ledger is how a balance is read rather than recomputed by
            // summing three tables and hoping. Append only, so this entry
            // outlives the invoice being deleted.
            LedgerEntry::query()->create([
                'entry_type' => 'invoice_payment',
                'reference_type' => $invoice->getMorphClass(),
                'reference_id' => $invoice->id,
                'currency' => $invoice->currency,
                'amount' => Money::toStorage($applied),
                'reporting_currency' => $invoice->reporting_currency,
                'reporting_amount' => $this->inReportingCurrency($invoice, $applied, $paidAt),
                'description' => 'Payment on '.$invoice->number,
                'occurred_at' => $paidAt,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            $this->settleStatus($invoice);

            activity('invoice')
                ->performedOn($invoice)
                ->causedBy(auth()->user())
                ->event('invoice.paid')
                ->withProperties([
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'settlement_rate' => $payment->settlement_rate,
                    'fx_gain_loss' => $payment->fx_gain_loss,
                ])
                ->log('received '.Money::format((string) $payment->amount, $payment->currency));

            return $payment;
        });
    }

    /**
     * Attach the on-chain detail to a crypto payment.
     *
     * Enough on the row to be verified by someone who does not trust you: the
     * chain, the hash, both addresses and what actually arrived. `amount` is
     * recorded separately from the invoice figure on purpose — wallets round
     * differently and a few micro-units either way is normal. Papering over
     * that delta by assuming they match is how a real shortfall goes unnoticed.
     */
    public function attachChainDetail(Payment $payment, array $attributes): CryptoPayment
    {
        return CryptoPayment::query()->updateOrCreate(
            ['tx_hash' => $attributes['tx_hash']],
            [
                'payment_id' => $payment->id,
                'chain' => $attributes['chain'],
                'token_standard' => $attributes['token_standard'] ?? ($attributes['chain'] === 'tron' ? 'TRC-20' : 'ERC-20'),
                'from_address' => $attributes['from_address'] ?? null,
                'to_address' => $attributes['to_address'] ?? null,
                'amount' => $attributes['amount'],
                'network_fee' => $attributes['network_fee'] ?? null,
                'block_number' => $attributes['block_number'] ?? null,
                'confirmations' => $attributes['confirmations'] ?? 0,
                'status' => $attributes['status'] ?? 'pending',
                'verified_at' => $attributes['verified_at'] ?? null,
            ],
        );
    }

    /**
     * What the rate move actually cost or earned.
     *
     * The invoice was issued expecting this payment currency to be worth some
     * amount in the invoice's own currency. It settled at a different rate.
     * The difference is realised the moment the money lands, and it is stored
     * in the invoice's currency because that is the number the owner is short
     * or up by.
     *
     * A payment in the invoice's own currency realises nothing, by definition.
     */
    private function realisedFxGainLoss(Invoice $invoice, string $amount, string $currency, string $settlementRate): string
    {
        if ($currency === $invoice->currency) {
            return '0.000000';
        }

        $issueRate = $invoice->issued_on === null
            ? null
            : $this->rates->rateFor($currency, $invoice->currency, $invoice->issued_on);

        if ($issueRate === null) {
            // No rate on the issue date means there is nothing to compare
            // against. Recording a gain of zero is honest; inventing one is not.
            return '0.000000';
        }

        $paid = Money::of($amount, $currency);

        $settled = Money::convert($paid, $settlementRate, $invoice->currency);
        $expected = Money::convert($paid, $issueRate, $invoice->currency);

        return Money::toStorage($settled->minus($expected, Money::ROUNDING));
    }

    private function inReportingCurrency(Invoice $invoice, \Brick\Money\Money $applied, Carbon $paidAt): ?string
    {
        $reporting = $invoice->reporting_currency;

        if ($reporting === null) {
            return null;
        }

        if ($reporting === $invoice->currency) {
            return Money::toStorage($applied);
        }

        $rate = $this->rates->rateFor($invoice->currency, $reporting, $paidAt);

        return $rate === null ? null : Money::toStorage(Money::convert($applied, $rate, $reporting));
    }

    /** Mark the invoice paid, part paid, or leave it alone. */
    private function settleStatus(Invoice $invoice): void
    {
        $paid = Money::sum(
            $invoice->payments()->pluck('applied_amount')->map(fn ($a): string => (string) $a),
            $invoice->currency,
        );

        $total = Money::fromStorage((string) $invoice->total, $invoice->currency);

        if ($paid->isGreaterThanOrEqualTo($total)) {
            $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return;
        }

        if ($paid->isPositive()) {
            $invoice->forceFill(['status' => 'part_paid', 'paid_at' => null])->save();
        }
    }

    /** What is still owed on an invoice, in its own currency. */
    public function outstanding(Invoice $invoice): string
    {
        $paid = Money::sum(
            $invoice->payments()->pluck('applied_amount')->map(fn ($a): string => (string) $a),
            $invoice->currency,
        );

        $owed = Money::fromStorage((string) $invoice->total, $invoice->currency)->minus($paid, Money::ROUNDING);

        return Money::toStorage($owed->isNegative() ? Money::zero($invoice->currency) : $owed);
    }

    /**
     * Unrealised revaluation — a report, never a row.
     *
     * Restates the still-open part of an invoice at today's rate. Nothing has
     * happened yet, so nothing is written; this exists so the number can be
     * shown with the date and rate that produced it.
     *
     * @return array{rate: ?string, at_today: ?string, difference: ?string}
     */
    public function unrealised(Invoice $invoice, Carbon|string|null $on = null): array
    {
        $on = $on === null ? now() : ($on instanceof Carbon ? $on : Carbon::parse($on));
        $reporting = $invoice->reporting_currency ?? Currencies::USD;

        $outstanding = Money::fromStorage($this->outstanding($invoice), $invoice->currency);

        if ($outstanding->isZero() || $invoice->reporting_rate === null) {
            return ['rate' => null, 'at_today' => null, 'difference' => null];
        }

        $today = $this->rates->rateFor($invoice->currency, $reporting, $on);

        if ($today === null) {
            return ['rate' => null, 'at_today' => null, 'difference' => null];
        }

        $atToday = Money::convert($outstanding, $today, $reporting);
        $atIssue = Money::convert($outstanding, (string) $invoice->reporting_rate, $reporting);

        return [
            'rate' => $today,
            'at_today' => Money::toStorage($atToday),
            'difference' => Money::toStorage($atToday->minus($atIssue, Money::ROUNDING)),
        ];
    }
}
