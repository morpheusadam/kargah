<?php

namespace Modules\Accounting\Services\RateSources;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Modules\Accounting\Support\Currencies;

/**
 * One rate, on its way from a provider to the table.
 *
 * This class exists for a single reason: `json_decode` hands back floats, and a
 * float must not reach the money path. `of()` is the one door a provider's
 * number comes through, and it leaves as a decimal string at the storage scale.
 * Doing the conversion in three separate parsers instead would be three chances
 * to forget.
 *
 * `asOf` is the provider's own date rather than today's. Central banks publish
 * in the afternoon and not at all at weekends, so a 09:00 fetch is usually
 * recording yesterday's number — filing it under today would quietly attach the
 * wrong date to an invoice.
 */
final readonly class Quote
{
    private function __construct(
        public string $base,
        public string $quote,
        public string $rate,
        public string $rateType,
        public string $asOf,
    ) {}

    /**
     * Build a quote from whatever the provider's JSON contained.
     *
     * `$raw` is deliberately untyped: it is the untrusted value straight out of
     * the response, and the checks here are what turn it into something the
     * rest of the module can rely on. A rate of zero or less is refused rather
     * than stored, because `ExchangeRates::rateFor()` inverts stored rates and
     * a zero would surface much later as a division error with no trail back
     * to the provider that sent it.
     */
    public static function of(
        string $base,
        string $quote,
        mixed $raw,
        string $rateType,
        Carbon|string $asOf,
    ): self {
        if (! is_numeric($raw)) {
            throw new \InvalidArgumentException(
                'A rate of '.var_export($raw, true).' is not a number.',
            );
        }

        try {
            $rate = BigDecimal::of(self::decimalString($raw))
                ->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp);
        } catch (MathException $e) {
            throw new \InvalidArgumentException('A rate of '.var_export($raw, true).' is not a number.', 0, $e);
        }

        if ($rate->isNegativeOrZero()) {
            throw new \InvalidArgumentException('A rate of '.$rate.' cannot be right.');
        }

        return new self(
            strtoupper($base),
            strtoupper($quote),
            (string) $rate,
            $rateType,
            $asOf instanceof Carbon ? $asOf->toDateString() : $asOf,
        );
    }

    /**
     * A float in fixed notation, before `brick/math` ever sees it.
     *
     * This is not belt and braces. `BigDecimal::of()` in this version takes
     * `int|string|BigNumber`, so a float passed to it is coerced to `int` and
     * `34.1527` silently becomes `34` — a whole lira lost per dollar, with only
     * a deprecation notice to show for it. `%.12F` gives fixed notation with
     * twice the decimals anything is stored at, and unlike `(string)` it does
     * not vary with the host's `precision` ini setting.
     */
    private static function decimalString(int|float|string $raw): string
    {
        return is_float($raw) ? sprintf('%.12F', $raw) : (string) $raw;
    }

    /** How the pair reads in a log line or on the console. */
    public function pair(): string
    {
        return $this->base.'/'.$this->quote;
    }
}
