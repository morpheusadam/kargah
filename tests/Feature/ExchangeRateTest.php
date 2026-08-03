<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Modules\Accounting\Models\ExchangeRate;
use Modules\Accounting\Services\ExchangeRates;
use Tests\TestCase;

/**
 * Fetching rates.
 *
 * Two properties decide whether this is safe to put on cron: running it twice
 * must change nothing, and one provider having a bad day must not cost the
 * owner the other two. Everything else here is about the number itself — a rate
 * that arrives as a JSON float and reaches the table as a float is a rounding
 * error waiting to appear on an invoice.
 *
 * No test touches the network. `preventStrayRequests()` makes that a failure
 * rather than a slow test.
 */
class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed day, because the TCMB window and every `as_of` assertion are
        // relative to it.
        Carbon::setTestNow('2026-03-02 09:00:00');

        // The retry backoff is real time otherwise, and three of these tests
        // deliberately make a provider fail.
        Sleep::fake();

        config(['accounting.evds_api_key' => 'test-key']);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* Fixtures ---------------------------------------------------------------- */

    /**
     * EVDS as it actually answers: series names with dots turned into
     * underscores, rates as strings, dd-mm-yyyy dates, and a null row for the
     * day the market was closed.
     */
    private function evdsBody(): string
    {
        return <<<'JSON'
        {
            "totalCount": 3,
            "items": [
                {"Tarih": "27-02-2026", "TP_DK_USD_A_YTL": "34.0011", "TP_DK_USD_S_YTL": "34.0700", "UNIXTIME": {"$numberLong": "1772150400"}},
                {"Tarih": "01-03-2026", "TP_DK_USD_A_YTL": null, "TP_DK_USD_S_YTL": null, "UNIXTIME": {"$numberLong": "1772323200"}},
                {"Tarih": "02-03-2026", "TP_DK_USD_A_YTL": "34.1527", "TP_DK_USD_S_YTL": "34.2214", "UNIXTIME": {"$numberLong": "1772409600"}}
            ]
        }
        JSON;
    }

    /** Frankfurter sends the rate as a JSON number, which is where floats get in. */
    private function frankfurterBody(): string
    {
        return '{"amount":1.0,"base":"USD","date":"2026-02-27","rates":{"TRY":34.1527}}';
    }

    private function coinGeckoBody(string $usd = '0.999812'): string
    {
        return '{"tether":{"usd":'.$usd.',"last_updated_at":1772409600}}';
    }

    /** @param  array<string, mixed>  $overrides */
    private function fakeAll(array $overrides = []): void
    {
        Http::fake(array_merge([
            'evds2.tcmb.gov.tr/*' => Http::response($this->evdsBody()),
            'api.frankfurter.dev/*' => Http::response($this->frankfurterBody()),
            'api.coingecko.com/*' => Http::response($this->coinGeckoBody()),
        ], $overrides));
    }

    /* The happy path ---------------------------------------------------------- */

    public function test_it_records_a_rate_from_each_of_the_three_sources(): void
    {
        $this->fakeAll();

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $this->assertSame(4, ExchangeRate::count());

        $buy = ExchangeRate::where('rate_type', ExchangeRates::TCMB_BUY)->sole();
        $this->assertSame('USD', $buy->base_currency);
        $this->assertSame('TRY', $buy->quote_currency);
        $this->assertSame('tcmb_evds', $buy->source);
        $this->assertSame('2026-03-02', $buy->as_of->toDateString());

        $sell = ExchangeRate::where('rate_type', ExchangeRates::TCMB_SELL)->sole();
        $this->assertSame('34.221400', $sell->rate);

        $reference = ExchangeRate::where('source', 'frankfurter')->sole();
        $this->assertSame(ExchangeRates::MARKET, $reference->rate_type);

        $tether = ExchangeRate::where('source', 'coingecko')->sole();
        $this->assertSame('USDT', $tether->base_currency);
        $this->assertSame('USD', $tether->quote_currency);
        $this->assertSame('0.999812', $tether->rate);
    }

    /**
     * The invoice rate and the reference rate are different rows.
     *
     * Which of the two an invoice used is a legal question, so they must never
     * collapse into one another — the unique key includes `rate_type` precisely
     * to stop that.
     */
    public function test_the_official_rate_and_the_reference_rate_are_kept_apart(): void
    {
        $this->fakeAll();

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $rates = app(ExchangeRates::class);

        $this->assertSame('34.152700', $rates->rateFor('USD', 'TRY', '2026-03-02', ExchangeRates::TCMB_BUY));
        $this->assertSame('34.221400', $rates->rateFor('USD', 'TRY', '2026-03-02', ExchangeRates::TCMB_SELL));
        $this->assertSame('34.152700', $rates->rateFor('USD', 'TRY', '2026-03-02', ExchangeRates::MARKET));
    }

    /**
     * The date on the row is the provider's, not the clock's.
     *
     * The ECB publishes in the afternoon, so a 09:00 fetch receives an earlier
     * day's figure. Recording it as today would put a date on an invoice that
     * the rate does not belong to.
     */
    public function test_a_rate_is_filed_under_the_day_the_provider_says_it_belongs_to(): void
    {
        $this->fakeAll();

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $this->assertSame(
            '2026-02-27',
            ExchangeRate::where('source', 'frankfurter')->sole()->as_of->toDateString(),
        );
    }

    /** TCMB publishes nothing on a holiday, and a null row is not a rate. */
    public function test_a_day_the_market_was_closed_is_skipped_rather_than_stored_as_nothing(): void
    {
        $this->fakeAll([
            'evds2.tcmb.gov.tr/*' => Http::response(<<<'JSON'
            {
                "totalCount": 2,
                "items": [
                    {"Tarih": "27-02-2026", "TP_DK_USD_A_YTL": "34.0011", "TP_DK_USD_S_YTL": "34.0700"},
                    {"Tarih": "02-03-2026", "TP_DK_USD_A_YTL": null, "TP_DK_USD_S_YTL": null}
                ]
            }
            JSON),
        ]);

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $buy = ExchangeRate::where('rate_type', ExchangeRates::TCMB_BUY)->sole();

        $this->assertSame('34.001100', $buy->rate);
        $this->assertSame('2026-02-27', $buy->as_of->toDateString());
    }

    /* Never a float ------------------------------------------------------------ */

    /**
     * The number that arrives as a float leaves as a decimal string.
     *
     * `34.1527` is sent by Frankfurter as a JSON number and by EVDS as a
     * string. Both have to land on the same six-decimal string, or the two
     * sources would disagree about a rate they agree about.
     */
    public function test_a_rate_survives_the_trip_from_json_exactly(): void
    {
        $this->fakeAll();

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $fromFloat = ExchangeRate::where('source', 'frankfurter')->sole()->rate;
        $fromString = ExchangeRate::where('rate_type', ExchangeRates::TCMB_BUY)->sole()->rate;

        $this->assertIsString($fromFloat);
        $this->assertSame('34.152700', $fromFloat);
        $this->assertSame($fromString, $fromFloat);

        // Six decimals, always — the scale every money column is stored at, so
        // raw SQL can compare any two of them.
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', $fromFloat);
    }

    /** Six decimals of a tether are the difference between a match and a discrepancy. */
    public function test_a_tether_price_keeps_all_six_decimals(): void
    {
        $this->fakeAll(['api.coingecko.com/*' => Http::response($this->coinGeckoBody('0.9998719'))]);

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        // Seven decimals in, six out, rounded half up and stated as such.
        $this->assertSame('0.999872', ExchangeRate::where('source', 'coingecko')->sole()->rate);
    }

    /* Idempotency -------------------------------------------------------------- */

    /**
     * The property that makes this safe on cron.
     *
     * Cron double-fires, a deploy re-runs the schedule, someone runs it by
     * hand. None of those may produce a second row for the same day, because a
     * second row is a second answer to "what rate did we use".
     */
    public function test_running_it_twice_in_a_day_changes_nothing(): void
    {
        $this->fakeAll();

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $before = ExchangeRate::orderBy('id')->get()
            ->map(fn (ExchangeRate $r) => [$r->id, $r->base_currency, $r->quote_currency, $r->rate, $r->rate_type, $r->source, $r->as_of->toDateString()])
            ->all();

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $after = ExchangeRate::orderBy('id')->get()
            ->map(fn (ExchangeRate $r) => [$r->id, $r->base_currency, $r->quote_currency, $r->rate, $r->rate_type, $r->source, $r->as_of->toDateString()])
            ->all();

        $this->assertCount(4, $after);
        $this->assertSame($before, $after);
    }

    /* No EVDS key -------------------------------------------------------------- */

    /**
     * The key is not on every machine, and that is not a reason to stop.
     *
     * The two keyless sources still run, the command still exits 0, and the log
     * says which rates are missing rather than only that a key is missing.
     */
    public function test_without_an_evds_key_the_other_two_sources_still_run(): void
    {
        Log::spy();
        config(['accounting.evds_api_key' => null]);

        $this->fakeAll();

        $this->artisan('accounting:fetch-rates')->assertExitCode(0);

        $this->assertSame(2, ExchangeRate::count());
        $this->assertSame(0, ExchangeRate::whereIn('rate_type', [ExchangeRates::TCMB_BUY, ExchangeRates::TCMB_SELL])->count());
        $this->assertSame(1, ExchangeRate::where('source', 'frankfurter')->count());
        $this->assertSame(1, ExchangeRate::where('source', 'coingecko')->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'EVDS_API_KEY')
                && str_contains($message, 'TCMB buying and selling rates for USD/TRY'))
            ->once();
    }

    public function test_without_an_evds_key_nothing_is_asked_of_tcmb(): void
    {
        config(['accounting.evds_api_key' => null]);

        $this->fakeAll();

        $this->artisan('accounting:fetch-rates')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tcmb.gov.tr'));
    }

    /* Providers behaving badly -------------------------------------------------- */

    /**
     * A 500 from one provider costs that provider's rates and nothing else.
     *
     * The non-zero exit is deliberate: cron mail is the only place a silent gap
     * would ever be noticed.
     */
    public function test_a_provider_returning_500_does_not_take_the_others_down(): void
    {
        Log::spy();

        $this->fakeAll(['api.frankfurter.dev/*' => Http::response('upstream error', 500)]);

        $this->artisan('accounting:fetch-rates')->assertFailed();

        $this->assertSame(3, ExchangeRate::count());
        $this->assertSame(0, ExchangeRate::where('source', 'frankfurter')->count());
        $this->assertSame(2, ExchangeRate::where('source', 'tcmb_evds')->count());
        $this->assertSame(1, ExchangeRate::where('source', 'coingecko')->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'frankfurter') && str_contains($message, '500'))
            ->once();
    }

    public function test_a_500_from_tcmb_still_leaves_the_reference_rates(): void
    {
        $this->fakeAll(['evds2.tcmb.gov.tr/*' => Http::response('', 500)]);

        $this->artisan('accounting:fetch-rates')->assertFailed();

        $this->assertSame(2, ExchangeRate::count());
        $this->assertSame(0, ExchangeRate::where('source', 'tcmb_evds')->count());
    }

    /** A failing provider is retried before being given up on, but not for long. */
    public function test_a_failing_provider_is_retried_and_then_abandoned(): void
    {
        $this->fakeAll(['api.coingecko.com/*' => Http::response('', 503)]);

        $this->artisan('accounting:fetch-rates')->assertFailed();

        Http::assertSentCount(5); // 1 TCMB + 1 Frankfurter + 3 attempts at CoinGecko
    }

    /**
     * A body that is not a rate is a failure, not a zero.
     *
     * Storing whatever `json_decode` produced would put a rate of 0 in the
     * table, and a rate of 0 is the kind of number that only surfaces when an
     * invoice is already out.
     */
    public function test_a_malformed_body_is_refused_rather_than_stored(): void
    {
        Log::spy();

        $this->fakeAll([
            'api.frankfurter.dev/*' => Http::response('{"amount":1.0,"base":"USD","rates":{}}'),
            'api.coingecko.com/*' => Http::response('<html><body>We will be back shortly</body></html>'),
        ]);

        $this->artisan('accounting:fetch-rates')->assertFailed();

        $this->assertSame(2, ExchangeRate::count());
        $this->assertSame(2, ExchangeRate::where('source', 'tcmb_evds')->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'not a rate'))
            ->twice();
    }

    public function test_evds_answering_with_no_observations_is_a_failure(): void
    {
        $this->fakeAll(['evds2.tcmb.gov.tr/*' => Http::response('{"totalCount":0,"items":[]}')]);

        $this->artisan('accounting:fetch-rates')->assertFailed();

        $this->assertSame(0, ExchangeRate::where('source', 'tcmb_evds')->count());
    }

    public function test_a_negative_rate_is_never_stored(): void
    {
        $this->fakeAll(['api.coingecko.com/*' => Http::response($this->coinGeckoBody('-1.0'))]);

        $this->artisan('accounting:fetch-rates')->assertFailed();

        $this->assertSame(0, ExchangeRate::where('source', 'coingecko')->count());
    }

    /* The peg ------------------------------------------------------------------- */

    public function test_a_tether_at_its_peg_is_stored_without_comment(): void
    {
        Log::spy();

        $this->fakeAll(['api.coingecko.com/*' => Http::response($this->coinGeckoBody('1.0'))]);

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $this->assertSame('1.000000', ExchangeRate::where('source', 'coingecko')->sole()->rate);
        $this->assertSame('0.0000', app(ExchangeRates::class)->tetherDeviation('2026-03-02'));

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * A depegged stablecoin is something the owner needs to know before
     * invoicing in it, not after being paid in it.
     */
    public function test_a_depegged_tether_is_stored_and_warned_about(): void
    {
        Log::spy();

        $this->fakeAll(['api.coingecko.com/*' => Http::response($this->coinGeckoBody('0.9850'))]);

        $this->artisan('accounting:fetch-rates')->assertSuccessful();

        $this->assertSame('0.985000', ExchangeRate::where('source', 'coingecko')->sole()->rate);
        $this->assertSame('-1.5000', app(ExchangeRates::class)->tetherDeviation('2026-03-02'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'peg'))
            ->once();
    }

    /* Wiring --------------------------------------------------------------------- */

    /**
     * Registered on the schedule, not called from anywhere else.
     *
     * `withoutOverlapping` matters because a provider that hangs would
     * otherwise let cron stack a second run on top of the first.
     */
    public function test_the_fetch_runs_daily_from_the_scheduler_and_never_overlaps(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'accounting:fetch-rates'));

        $this->assertCount(1, $events);
        $this->assertSame('0 9 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
