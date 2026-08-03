<?php

namespace Modules\Accounting\Services\RateSources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Support\Currencies;

/**
 * USDT/USD, volume-weighted across exchanges.
 *
 * A tether is supposed to be a dollar and usually is, so this looks like a
 * pointless row until the day it is not. Storing it every day is what makes the
 * peg check possible, and a depeg is something the owner needs to know about
 * before invoicing in USDT rather than after being paid in it.
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
            'vs_currencies' => strtolower(Currencies::USD),
            'include_last_updated_at' => 'true',
        ]);

        $rate = $body[self::TETHER_ID]['usd'] ?? null;

        if ($rate === null) {
            throw RateSourceFailed::malformed($this->name(), 'the response carried no USDT price');
        }

        return [
            $this->quote(Currencies::USDT, Currencies::USD, $rate, ExchangeRates::MARKET, $this->asOf($body)),
        ];
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
