<?php

namespace Modules\Accounting\Services\RateSources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Support\Currencies;

/**
 * The Turkish central bank, through its EVDS statistics service.
 *
 * This is the invoice-facing source. Turkish tax procedure requires a foreign
 * currency invoice to a domestic company to show the lira equivalent at the
 * TCMB *buying* rate for the invoice date, and the liability for getting it
 * wrong sits with the issuer — so both the buying and the selling rate are
 * stored as separate rows and nothing downstream ever has to guess which one a
 * number was.
 *
 * EVDS needs a free account. When the key is absent this source stands down and
 * says so; it does not fall back to a market rate, because a market rate in the
 * place a TCMB rate belongs is exactly the kind of substitution that looks fine
 * until an accountant asks where the number came from.
 */
class TcmbEvds extends HttpRateSource
{
    public const NAME = 'tcmb_evds';

    /**
     * Series identifiers, not names.
     *
     * `TP.DK.USD.A.YTL` is USD döviz alış — the buying rate, in lira — and
     * `.S.` is the selling rate. EVDS returns them as keys with the dots
     * replaced by underscores, which is why the response is read under a
     * different spelling from the one requested.
     */
    private const BUY_SERIES = 'TP.DK.USD.A.YTL';

    private const SELL_SERIES = 'TP.DK.USD.S.YTL';

    /**
     * How far back to ask.
     *
     * TCMB does not publish at weekends or on public holidays, and a religious
     * holiday can close the market for the better part of a working week. Ten
     * days matches the fallback window `ExchangeRates::on()` already applies
     * when reading, so the two ends of the system agree about how stale a rate
     * may be. It is still one request.
     */
    private const WINDOW_DAYS = 10;

    private const ENDPOINT = 'https://evds2.tcmb.gov.tr/service/evds/';

    public function name(): string
    {
        return self::NAME;
    }

    public function unavailableReason(): ?string
    {
        if (filled($this->key())) {
            return null;
        }

        return 'EVDS_API_KEY is not set, so the TCMB buying and selling rates for USD/TRY are unavailable. '
            .'Invoices to domestic Turkish companies need the buying rate by law, so they cannot show a lira '
            .'equivalent until a key is configured. Register at https://evds2.tcmb.gov.tr and take the key '
            .'from Profil > API Anahtarı.';
    }

    public function fetch(): array
    {
        $body = $this->get($this->url());

        $items = $body['items'] ?? null;

        if (! is_array($items) || $items === []) {
            throw RateSourceFailed::malformed($this->name(), 'the response carried no observations');
        }

        // Newest first. EVDS returns the window in ascending date order and the
        // most recent day is the one an invoice issued today should use.
        foreach (array_reverse($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $buy = $item[$this->column(self::BUY_SERIES)] ?? null;
            $sell = $item[$this->column(self::SELL_SERIES)] ?? null;
            $date = $item['Tarih'] ?? null;

            // Holidays come back as rows with null values rather than as
            // missing rows, so a present row is not yet a rate.
            if (! is_numeric($buy) || ! is_numeric($sell) || ! is_string($date)) {
                continue;
            }

            try {
                $asOf = Carbon::createFromFormat('d-m-Y', $date)->toDateString();
            } catch (\Throwable $e) {
                throw RateSourceFailed::malformed($this->name(), 'the observation date "'.$date.'" is not a date');
            }

            return [
                $this->quote(Currencies::USD, Currencies::TRY, $buy, ExchangeRates::TCMB_BUY, $asOf),
                $this->quote(Currencies::USD, Currencies::TRY, $sell, ExchangeRates::TCMB_SELL, $asOf),
            ];
        }

        throw RateSourceFailed::malformed(
            $this->name(),
            'no rate was published in the last '.self::WINDOW_DAYS.' days',
        );
    }

    /**
     * The key travels as a header rather than in the query string.
     *
     * EVDS accepts either. A header keeps the key out of the URL, and therefore
     * out of exception messages, HTTP client logs and anything that records a
     * request line.
     */
    protected function request(): PendingRequest
    {
        return parent::request()->withHeaders(['key' => (string) $this->key()]);
    }

    private function key(): ?string
    {
        $key = config('accounting.evds_api_key');

        return is_string($key) ? $key : null;
    }

    /**
     * EVDS takes its parameters as a path fragment, not a query string.
     *
     * `series=...&startDate=...` sits after the endpoint with no `?`, which is
     * unusual enough that building the URL by hand is clearer than persuading
     * a query builder to emit it.
     */
    private function url(): string
    {
        $end = today();
        $start = $end->copy()->subDays(self::WINDOW_DAYS);

        return self::ENDPOINT
            .'series='.self::BUY_SERIES.'-'.self::SELL_SERIES
            .'&startDate='.$start->format('d-m-Y')
            .'&endDate='.$end->format('d-m-Y')
            .'&type=json';
    }

    private function column(string $series): string
    {
        return str_replace('.', '_', $series);
    }
}
