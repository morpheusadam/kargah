<?php

namespace Modules\Accounting\Services\RateSources;

use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Support\Currencies;

/**
 * USD/TRY as a reference figure, from ECB data via Frankfurter.
 *
 * Free, keyless and without a quota, which is why it is here: it gives the
 * owner a second opinion on the lira without another account to maintain. It is
 * explicitly *not* the invoice rate — that is TCMB's job — and it is stored
 * under `market` so the two can never be confused.
 *
 * The host is configurable because Frankfurter is self-hostable, and a business
 * that would rather not depend on someone else's uptime can point this at its
 * own instance without touching code.
 */
class Frankfurter extends HttpRateSource
{
    public const NAME = 'frankfurter';

    public function name(): string
    {
        return self::NAME;
    }

    public function fetch(): array
    {
        $body = $this->get($this->baseUrl().'/latest', [
            'base' => Currencies::USD,
            'symbols' => Currencies::TRY,
        ]);

        $rate = $body['rates'][Currencies::TRY] ?? null;
        $date = $body['date'] ?? null;

        if ($rate === null) {
            throw RateSourceFailed::malformed($this->name(), 'the response carried no USD/TRY rate');
        }

        // The ECB publishes once a working day, in the afternoon. A 09:00 fetch
        // therefore usually receives yesterday's figure, and `date` is the day
        // it actually belongs to — trusting the clock instead would file it a
        // day forward.
        if (! is_string($date)) {
            throw RateSourceFailed::malformed($this->name(), 'the response carried no date for the rate');
        }

        return [
            $this->quote(Currencies::USD, Currencies::TRY, $rate, ExchangeRates::MARKET, $date),
        ];
    }

    private function baseUrl(): string
    {
        $url = config('accounting.frankfurter_url');

        return rtrim(is_string($url) && $url !== '' ? $url : 'https://api.frankfurter.dev/v1', '/');
    }
}
