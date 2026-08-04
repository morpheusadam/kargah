<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Company;

/**
 * Issuing an invoice: the one moment its numbers stop being able to change.
 *
 * Everything before issue is a draft and may be edited freely. Issue captures
 * the rates in force *on the issue date* and writes them onto the row, and
 * from then on nothing recomputes them. A rate move next week must not alter
 * what this invoice says — that is the difference between an accounting record
 * and a spreadsheet that lies.
 *
 * Two frozen figures, for two different reasons:
 *
 * - **Reporting**: the owner's own profit-and-loss currency. Every invoice
 *   carries its value in that currency so a year can be added up without
 *   re-deriving rates that have since moved. Which currency that is comes from
 *   `config('accounting.reporting_currency')` — see `reportingCurrency()`.
 * - **Lira equivalent**: only when the buyer is a domestic Turkish company.
 *   Turkish tax procedure requires the TCMB *buying* rate for the invoice
 *   date, and the liability for getting it wrong sits with the issuer.
 *
 * 🔴 Those two are **not** the same question and are deliberately not merged,
 * even though with a lira reporting currency both now compute a lira figure for
 * a domestic buyer. The reporting figure answers "what is this worth in the
 * books"; the lira equivalent answers "what does Turkish tax procedure require
 * to appear *on this document*", which pins the rate *type* — the TCMB buying
 * rate, not a market rate — and records its source and its date so the number
 * can be defended. Collapsing them would silently let a Frankfurter mid-market
 * rate onto a document where the law names TCMB's buying rate, and the two can
 * differ by a lira or more per dollar. They also disagree for a USDT invoice:
 * `turkishFigures()` bridges through USD and says so in `rate_note`, while
 * `reportingFigures()` refuses to invent a rate it does not have.
 */
class InvoiceIssuer
{
    public function __construct(private readonly ExchangeRates $rates) {}

    /**
     * The owner's profit-and-loss currency, from configuration.
     *
     * One place, so nothing has to assume. A value that is not a currency
     * Kargah supports falls back to lira rather than throwing: refusing to
     * issue an invoice because a setting is mistyped would be a far worse
     * failure than freezing the shipped default, and the figure the invoice
     * carries says which currency it is in either way.
     */
    public static function reportingCurrency(): string
    {
        $configured = config('accounting.reporting_currency');

        $configured = is_string($configured) ? strtoupper(trim($configured)) : '';

        return in_array($configured, Currencies::supported(), true) ? $configured : Currencies::TRY;
    }

    /**
     * Recalculate a draft's totals from its lines.
     *
     * Safe to call as often as you like while the invoice is a draft, and
     * refused once it has been issued.
     *
     * 🔴 An invoice raised under a KDV exemption is zero-rated, so the rate is
     * forced to zero here as well as the amount. Leaving `tax_percent` at 20
     * while `tax_amount` is nil would put a line on the screen that the
     * document — which prints "KDV 0%, exempt under code 302" — flatly
     * contradicts, and of the two the document is the one a tax office reads.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $this->refuseIfIssued($invoice, 'recalculated');

        $subtotal = Money::sum(
            $invoice->lines()->pluck('amount')->map(fn ($a): string => (string) $a),
            $invoice->currency,
        );

        $exempt = $invoice->kdv_exemption_code !== null && $invoice->kdv_exemption_code !== '';

        $percent = $exempt ? '0' : (string) $invoice->tax_percent;
        $tax = Money::percentageOf($subtotal, $percent);

        $invoice->forceFill([
            'subtotal' => Money::toStorage($subtotal),
            'tax_percent' => $percent,
            'tax_amount' => Money::toStorage($tax),
            'total' => Money::toStorage($subtotal->plus($tax, Money::ROUNDING)),
        ])->save();

        return $invoice->refresh();
    }

    /**
     * Issue the invoice, freezing every rate it depends on.
     *
     * @param  ?string  $reportingCurrency  The owner's P&L currency. Null takes
     *                                      it from configuration, which is what
     *                                      every caller in Kargah does; it is an
     *                                      argument only so a test can pin one.
     */
    public function issue(Invoice $invoice, ?string $reportingCurrency = null): Invoice
    {
        $this->refuseIfIssued($invoice, 'issued again');

        $reportingCurrency ??= self::reportingCurrency();

        return DB::transaction(function () use ($invoice, $reportingCurrency) {
            $this->recalculate($invoice);

            $issuedOn = $invoice->issued_on ?? now()->toDateString();

            $attributes = [
                'status' => 'sent',
                'issued_on' => $issuedOn,
                'sent_at' => now(),
                'reporting_currency' => $reportingCurrency,
            ];

            $attributes += $this->reportingFigures($invoice, $reportingCurrency, $issuedOn);
            $attributes += $this->turkishFigures($invoice, $issuedOn);

            $invoice->forceFill($attributes)->save();

            activity('invoice')
                ->performedOn($invoice)
                ->causedBy(auth()->user())
                ->event('invoice.issued')
                ->withProperties([
                    'total' => (string) $invoice->total,
                    'currency' => $invoice->currency,
                    'reporting_currency' => $invoice->reporting_currency,
                    'reporting_rate' => (string) $invoice->reporting_rate,
                    'issue_rate_to_try' => (string) $invoice->issue_rate_to_try,
                    // Logged because a zero-rating is a judgement somebody made
                    // rather than a figure Kargah derived, and the trail is the
                    // only place that records it was made deliberately.
                    'kdv_exemption_code' => $invoice->kdv_exemption_code,
                ])
                ->log('issued for '.Money::format((string) $invoice->total, $invoice->currency));

            return $invoice->refresh();
        });
    }

    /**
     * The invoice's value in the owner's reporting currency, frozen.
     *
     * A missing rate is not fatal. Kargah must never block on a rate it could
     * not fetch: the figure is left null and the page says the conversion is
     * unavailable, rather than inventing one or refusing to issue.
     *
     * 🔴 Which pairs actually resolve, because it decides what comes back null.
     * `rateFor()` will invert a stored pair but will not chain two of them, so
     * only what `accounting:fetch-rates` stores can be frozen: USD/TRY (TCMB and
     * Frankfurter) and USDT/USD **and USDT/TRY** (CoinGecko). With lira as the
     * reporting currency, USD→TRY resolves directly, TRY→TRY is the identity,
     * and USDT→TRY resolves from its own quote.
     *
     * **This deliberately does not bridge, and the USDT/TRY quote is why it does
     * not have to.** Multiplying USDT/USD by USD/TRY here would produce a number
     * with no single source, inside a figure whose whole job is to be defensible
     * to somebody who did not compute it. `turkishFigures()` does bridge — but
     * only because a domestic invoice is legally required to carry a lira
     * number, and it writes `rate_note` saying exactly how it got there. Rather
     * than borrow that licence, the lira price of a tether is now fetched
     * directly; see `CoinGecko`'s class docblock.
     *
     * ⚠️ That leg is optional. If CoinGecko stops quoting the lira price, a USDT
     * invoice freezes null figures again and is counted out loud. Nothing here
     * changes: a missing rate has always meant nulls, never a refusal to issue.
     */
    private function reportingFigures(Invoice $invoice, string $reportingCurrency, string $issuedOn): array
    {
        if ($invoice->currency === $reportingCurrency) {
            return [
                'reporting_rate' => '1.000000',
                'reporting_amount' => (string) $invoice->total,
            ];
        }

        $rate = $this->rates->rateFor($invoice->currency, $reportingCurrency, $issuedOn);

        if ($rate === null) {
            return ['reporting_rate' => null, 'reporting_amount' => null];
        }

        return [
            'reporting_rate' => $rate,
            'reporting_amount' => Money::toStorage(
                Money::convert(Money::fromStorage((string) $invoice->total, $invoice->currency), $rate, $reportingCurrency),
            ),
        ];
    }

    /**
     * The lira equivalent, when — and only when — the buyer is domestic.
     *
     * If the buyer is foreign none of this applies: no TL equivalent, no TCMB
     * rate. That is why `companies.is_domestic` exists and why these columns
     * are nullable.
     *
     * The rate used is the TCMB *buying* rate (döviz alış kuru), because that
     * is what Turkish tax procedure specifies, and the row records the rate,
     * its source and its date so the figure can be defended rather than merely
     * asserted.
     */
    private function turkishFigures(Invoice $invoice, string $issuedOn): array
    {
        $company = $invoice->company_id === null ? null : Company::query()->find($invoice->company_id);

        if ($company === null || ! $company->is_domestic || $invoice->currency === Currencies::TRY) {
            return [];
        }

        // USDT to lira has no authoritative Turkish ruling. USD is used as the
        // intermediate — TCMB USD/TRY × USDT/USD — and the note says so, so an
        // accountant can see exactly what was done and override it.
        $note = null;
        $sourceCurrency = $invoice->currency;
        $bridge = '1.000000';

        if ($invoice->currency === Currencies::USDT) {
            $bridge = $this->rates->rateFor(Currencies::USDT, Currencies::USD, $issuedOn);

            if ($bridge === null) {
                return [];
            }

            $sourceCurrency = Currencies::USD;
            $note = 'Lira equivalent computed through USD: USDT/USD '.$bridge.' then the TCMB buying rate. '
                .'No Turkish ruling covers a stablecoin invoice directly — have this confirmed by a muhasebeci.';
        }

        $rate = $this->rates->on($sourceCurrency, Currencies::TRY, $issuedOn, ExchangeRates::TCMB_BUY);

        if ($rate === null) {
            return [];
        }

        $inUsd = Money::convert(
            Money::fromStorage((string) $invoice->total, $invoice->currency),
            $bridge,
            $sourceCurrency,
        );

        return [
            'issue_rate_to_try' => (string) $rate->rate,
            'issue_rate_source' => $rate->source,
            'issue_rate_date' => $rate->as_of instanceof \DateTimeInterface ? $rate->as_of->format('Y-m-d') : $rate->as_of,
            'try_equivalent' => Money::toStorage(Money::convert($inUsd, (string) $rate->rate, Currencies::TRY)),
            'rate_note' => $note,
        ];
    }

    private function refuseIfIssued(Invoice $invoice, string $what): void
    {
        if ($invoice->isIssued()) {
            throw new \DomainException(
                'Invoice '.$invoice->number.' has been issued and cannot be '.$what.'. '
                .'Void it and raise a credit note instead — an issued invoice never changes its numbers.',
            );
        }
    }
}
