<?php

namespace Modules\Accounting\Services\RateSources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Support\Currencies;

/**
 * USDT priced in dollars **and** in lira, volume-weighted across exchanges.
 *
 * A tether is supposed to be a dollar and usually is, so USDT/USD looks like a
 * pointless row until the day it is not. Storing it every day is what makes the
 * peg check possible, and a depeg is something the owner needs to know about
 * before invoicing in USDT rather than after being paid in it.
 *
 * 🔴 **USDT/TRY is fetched directly rather than derived, and that is the whole
 * point of it being here.** `ExchangeRates::rateFor()` inverts a stored pair but
 * will not chain two, so while this source stored only USDT/USD, a USDT invoice
 * reporting in lira froze **null** figures — it dropped out of every lira total
 * with nothing but a footnote to say so. The tempting fix is to multiply
 * USDT/USD by USD/TRY inside `InvoiceIssuer`, and `InvoiceIssuer::reportingFigures()`
 * argues at length against exactly that: a composite of two rates from two
 * providers is a number with no single source, on a figure whose entire job is to
 * be defensible to somebody who did not compute it.
 *
 * Asking CoinGecko for the lira price costs nothing — `vs_currencies` is a list
 * and this is the same request — and what comes back is one quote, from one
 * source, on one day, which `rateFor()` then resolves directly. The rule the
 * module keeps is intact: **every frozen figure names the rate that produced it.**
 *
 * The public endpoint needs no key. A free demo key raises the per-minute limit
 * and is used when one is configured; Kargah calls this once a day, so neither
 * limit is close.
 */
class CoinGecko extends HttpRateSource
{
    public const NAME = 'coingecko';

    private const ENDPOINT = 'https://api.coingecko.com/api/v3/simple/price';

    /** CoinGecko keys its assets by slug, not by ticker. */
    private const TETHER_ID = 'tether';

    public function name(): string
    {
        return self::NAME;
    }

    public function fetch(): array
    {
        $body = $this->get(self::ENDPOINT, [
            'ids' => self::TETHER_ID,
            'vs_currencies' => strtolower(Currencies::USD).','.strtolower(Currencies::TRY),
            'include_last_updated_at' => 'true',
        ]);

        $rate = $body[self::TETHER_ID]['usd'] ?? null;

        if ($rate === null) {
            throw RateSourceFailed::malformed($this->name(), 'the response carried no USDT price');
        }

        $asOf = $this->asOf($body);

        $quotes = [
            $this->quote(Currencies::USDT, Currencies::USD, $rate, ExchangeRates::MARKET, $asOf),
        ];

        // The lira leg is wanted but not required. If CoinGecko ever stops
        // quoting it, the dollar peg check — the reason this source existed
        // first — must keep working, and a USDT invoice goes back to freezing
        // no lira figure and being counted out loud. That is the behaviour this
        // change improves on, so falling back to it is safe; throwing here
        // would take the peg row down with it.
        $lira = $body[self::TETHER_ID]['try'] ?? null;

        if ($lira !== null) {
            $quotes[] = $this->quote(Currencies::USDT, Currencies::TRY, $lira, ExchangeRates::MARKET, $asOf);
        }

        return $quotes;
    }

    protected function request(): PendingRequest
    {
        $key = config('accounting.coingecko_api_key');

        return is_string($key) && $key !== ''
            ? parent::request()->withHeaders(['x-cg-demo-api-key' => $key])
            : parent::request();
    }

    /**
     * The day the quoted price belongs to.
     *
     * Crypto markets do not close, so unlike a central bank rate this is
     * genuinely current and today is the honest answer. `last_updated_at` is
     * still preferred when present, because near midnight UTC the two differ
     * and the provider's own timestamp is the one that can be checked.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function asOf(array $body): string
    {
        $updated = $body[self::TETHER_ID]['last_updated_at'] ?? null;

        return is_int($updated)
            ? Carbon::createFromTimestampUTC($updated)->toDateString()
            : today()->toDateString();
    }
}
