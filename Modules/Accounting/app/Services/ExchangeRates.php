<?php

namespace Modules\Accounting\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\ExchangeRate;
use Modules\Accounting\Support\Currencies;

/**
 * Reading and writing rate history.
 *
 * Two rules, both load-bearing:
 *
 * **Nothing here fetches.** A page render must never depend on someone else's
 * API being up. Rates arrive on a schedule (`accounting:fetch-rates`) and this
 * only ever reads what is already in the table.
 *
 * **Rows are never updated.** A correction is a new row for a new `as_of`, or
 * a re-run of the same day which `updateOrCreate` on the business key makes
 * harmless. Nothing overwrites history, because an invoice issued last March
 * has to keep saying what it said last March.
 */
class ExchangeRates
{
    public const MARKET = 'market';

    public const TCMB_BUY = 'tcmb_buy';

    public const TCMB_SELL = 'tcmb_sell';

    /**
     * The rate to use for a date, or the most recent one before it.
     *
     * Falling back to an earlier day is deliberate: central banks do not
     * publish at weekends, and an invoice issued on a Sunday still needs a
     * defensible number. The row that comes back carries its own `as_of`, so
     * the invoice records which day's rate it actually used rather than
     * implying it was the issue date.
     */
    public function on(
        string $base,
        string $quote,
        Carbon|string $date,
        string $type = self::MARKET,
        int $maxDaysBack = 10,
    ): ?ExchangeRate {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        $asOf = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        if ($base === $quote) {
            return null;
        }

        return ExchangeRate::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('rate_type', $type)
            ->whereDate('as_of', '<=', $asOf)
            ->whereDate('as_of', '>=', Carbon::parse($asOf)->subDays($maxDaysBack)->toDateString())
            ->orderByDesc('as_of')
            ->first();
    }

    /**
     * The rate as a decimal string, in the direction asked for.
     *
     * If only the inverse pair is stored, it is inverted here rather than
     * requiring both directions to be fetched — but the division states its
     * scale and rounding, because an unstated division is how a rate acquires
     * a rounding error nobody can trace.
     */
    public function rateFor(string $from, string $to, Carbon|string $date, string $type = self::MARKET): ?string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return '1.000000';
        }

        if ($direct = $this->on($from, $to, $date, $type)) {
            return (string) $direct->rate;
        }

        if ($inverse = $this->on($to, $from, $date, $type)) {
            return (string) BigDecimal::one()
                ->dividedBy(BigDecimal::of((string) $inverse->rate), 6, RoundingMode::HalfUp);
        }

        return null;
    }

    /**
     * Record a rate.
     *
     * Keyed on the business date rather than the fetch time, so running the
     * fetch job twice in a day writes one row, not two. That single property
     * is what makes the job safe to run from cron.
     */
    public function record(
        string $base,
        string $quote,
        string|float $rate,
        string $source,
        Carbon|string $asOf,
        string $type = self::MARKET,
    ): ExchangeRate {
        // `float` is in the signature so this guard can fire. Typed `string`,
        // PHP coerces the float before the check ever runs and the guard is
        // dead code. It matters more here than almost anywhere: brick/math's
        // BigNumber::of() accepts int|string|BigNumber, so a float rate of
        // 34.1527 becomes the integer 34 — a whole lira per dollar — behind
        // nothing louder than a deprecation notice.
        if (is_float($rate)) {
            throw new \InvalidArgumentException(
                'A float reached the rate path with value '.var_export($rate, true).'. '.
                'Rates are decimal strings: brick/math would truncate this to '.(int) $rate.'.',
            );
        }

        return ExchangeRate::query()->updateOrCreate(
            [
                'base_currency' => strtoupper($base),
                'quote_currency' => strtoupper($quote),
                'rate_type' => $type,
                'as_of' => $asOf instanceof Carbon ? $asOf->toDateString() : $asOf,
            ],
            [
                'rate' => $rate,
                'source' => $source,
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * How far a stablecoin has drifted from its peg, as a percentage string.
     *
     * USDT should sit at 1.00. It is stored anyway and the deviation surfaced,
     * because a stablecoin that has depegged is something the owner wants to
     * know *before* invoicing in it, not after.
     */
    public function tetherDeviation(Carbon|string $date): ?string
    {
        $rate = $this->on(Currencies::USDT, Currencies::USD, $date);

        if ($rate === null) {
            return null;
        }

        return (string) BigDecimal::of((string) $rate->rate)
            ->minus(1)
            ->multipliedBy(100)
            ->toScale(4, RoundingMode::HalfUp);
    }
}
