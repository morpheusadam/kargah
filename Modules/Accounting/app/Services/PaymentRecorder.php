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
 *
 * **Taking a payment back is not a delete.** `reverse()` is the other half of
 * `record()`: it writes a contra entry into the ledger, soft-deletes the
 * payment row and re-derives the invoice's status through the same
 * `statusFor()` that recording uses. See its docblock for why all three have to
 * happen together.
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
     * Take back a payment that should never have been recorded.
     *
     * A freelancer who types 12000 for 1200 has no recourse without this, and
     * the book stays permanently wrong. But "undo" here is three things that
     * only mean anything together, and the module refuses rather than doing two
     * of them:
     *
     * 1. **The ledger entry is reversed, not removed.** `LedgerEntry` throws on
     *    `delete()` and says why: a removed row is a gap, a contra entry is a
     *    record. Both rows stay readable and `standing()` stops counting either.
     * 2. **The payment row is soft-deleted.** Every query in the module reads
     *    `payments` through Eloquent, so a trashed row leaves `applied_amount`,
     *    `fx_gain_loss` and the received-this-month figures without anything
     *    having to know about reversal.
     * 3. **The invoice's derived state is recomputed** — through `statusFor()`,
     *    the same method recording goes through, never a second copy of it.
     *
     * The entry is *matched* rather than pointed at, because a payment carries
     * no `ledger_entry_id` and the entry references the invoice rather than the
     * payment. Type, reference, currency, date and applied amount identify it
     * together. Two identical payments against one invoice on one day are
     * genuinely indistinguishable; the older entry is reversed, the ledger nets
     * to the same figure either way, and the trail still reads as one reversal
     * against one entry.
     *
     * 🔴 If the entry cannot be found, nothing happens at all. A soft-deleted
     * payment whose money is still standing in the ledger is worse than the
     * mistake it was trying to correct, because the invoice would read as
     * unpaid while every report still counted the cash.
     *
     * @return Payment The now-trashed payment, still readable.
     *
     * @throws \DomainException When the three steps cannot all be taken.
     */
    public function reverse(Payment $payment, ?string $reason = null): Payment
    {
        if ($payment->trashed()) {
            throw new \DomainException(
                'This payment has already been reversed. Reversing it twice would take back money the invoice '
                .'never received in the first place.',
            );
        }

        // `withTrashed`, because the ledger outlives the document: a payment on
        // a deleted invoice still has an entry standing against it.
        $invoice = $payment->invoice()->withTrashed()->first();

        if ($invoice === null) {
            throw new \DomainException(
                'Payment #'.$payment->getKey().' has no invoice, so there is no status to put back and no safe '
                .'way to reverse it here.',
            );
        }

        $entry = $this->entryFor($invoice, $payment);

        if ($entry === null) {
            throw new \DomainException(
                'The ledger entry this payment posted could not be identified, so nothing was changed. Removing '
                .'the payment on its own would leave its amount standing in every report while the invoice read '
                .'as unpaid. Correct it with an adjusting entry instead.',
            );
        }

        return DB::transaction(function () use ($invoice, $payment, $entry, $reason) {
            $entry->reverse($reason ?? $this->reversalReason($invoice, $payment));

            // Soft, so the row and its chain detail stay readable. `crypto_payments`
            // is untouched on purpose: what a wallet did on chain happened, and
            // re-recording the same hash re-points that row at the new payment.
            $payment->delete();

            $this->settleStatus($invoice->refresh());

            activity('invoice')
                ->performedOn($invoice)
                ->causedBy(auth()->user())
                ->event('invoice.payment_reversed')
                ->withProperties([
                    'payment_id' => $payment->getKey(),
                    'amount' => (string) $payment->amount,
                    'currency' => $payment->currency,
                    'applied_amount' => (string) $payment->applied_amount,
                    // Realised on a payment that is now taken back, so it is
                    // realised no longer. The figure is kept on the trashed row
                    // rather than zeroed: it is what was believed at the time.
                    'fx_gain_loss' => (string) $payment->fx_gain_loss,
                    'reverses_ledger_entry' => $entry->getKey(),
                ])
                ->log('reversed '.Money::format((string) $payment->amount, $payment->currency));

            return $payment;
        });
    }

    /**
     * The entry `record()` wrote for this payment, if it can be named with
     * certainty.
     *
     * Compared in PHP rather than in SQL: on SQLite a `decimal` column has
     * NUMERIC affinity, so a `where('amount', '1500.000000')` is a comparison
     * against an IEEE double. Both sides here come back through the same
     * `decimal:6` cast, which normalises them to identical strings, so an exact
     * string match is an exact decimal match and no float is created to make it.
     */
    private function entryFor(Invoice $invoice, Payment $payment): ?LedgerEntry
    {
        return LedgerEntry::query()
            ->standing()
            ->ofType(LedgerEntry::TYPE_INVOICE_PAYMENT)
            ->forReference($invoice)
            ->orderBy('id')
            ->get()
            ->first(fn (LedgerEntry $entry): bool => $entry->currency === $invoice->currency
                && $entry->occurred_at !== null
                && $payment->paid_at !== null
                && $entry->occurred_at->equalTo($payment->paid_at)
                && (string) $entry->amount === (string) $payment->applied_amount);
    }

    /**
     * The sentence that goes into the ledger.
     *
     * It names the payment, its amount, its date and the invoice, because in
     * two years the contra entry is all anybody has left to work out what was
     * undone and why the invoice stopped reading as paid.
     */
    private function reversalReason(Invoice $invoice, Payment $payment): string
    {
        return 'Reversal of the '.Money::format((string) $payment->amount, $payment->currency)
            .' payment recorded on '.($payment->paid_at?->format('j F Y') ?? 'an unrecorded date')
            .' against '.$invoice->number.' (payment #'.$payment->getKey().')';
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

    /**
     * 🔴 The one definition of "is this invoice paid".
     *
     * Recording a payment, reversing one, and the sentence the show page puts
     * in front of somebody before they click "reverse" all read the answer from
     * here. Two copies of this comparison that disagree do not fail a test —
     * they show up as a dashboard that is wrong six weeks later, and by then
     * nobody remembers which of the two was authoritative.
     *
     * `draft` and `void` are returned but never written: neither is derived
     * from what landed. A draft is not owed yet and a void invoice stopped
     * being owed for a reason that has nothing to do with payments.
     *
     * @param  ?int  $ignoringPaymentId  Answer as though this payment were gone —
     *                                   what the confirmation dialog needs to promise.
     */
    public function statusFor(Invoice $invoice, ?int $ignoringPaymentId = null): string
    {
        if (! $invoice->isIssued()) {
            return 'draft';
        }

        if ($invoice->isVoid()) {
            return 'void';
        }

        // `payments()` is a fresh query and Payment is soft-deleting, so a
        // reversed payment is already out of this sum without being asked for.
        $paid = Money::sum(
            $invoice->payments()
                ->when($ignoringPaymentId !== null, fn ($query) => $query->whereKeyNot($ignoringPaymentId))
                ->pluck('applied_amount')
                ->map(fn ($a): string => (string) $a),
            $invoice->currency,
        );

        $total = Money::fromStorage((string) $invoice->total, $invoice->currency);

        if ($paid->isGreaterThanOrEqualTo($total)) {
            return 'paid';
        }

        return $paid->isPositive() ? 'part_paid' : 'sent';
    }

    /**
     * Write down what `statusFor()` says, and nothing else.
     *
     * `paid_at` is cleared on every path but `paid`, which is the step a
     * reversal gets wrong: an invoice that reads `sent` while still carrying
     * the `paid_at` from the payment somebody took back is an invoice that
     * lands in the wrong month of every cash-flow report.
     */
    private function settleStatus(Invoice $invoice): void
    {
        $status = $this->statusFor($invoice);

        if ($status === 'draft' || $status === 'void') {
            return;
        }

        $invoice->forceFill([
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ])->save();
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
