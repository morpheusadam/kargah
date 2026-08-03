<?php

namespace Modules\Accounting\Console;

use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Services\RateSources\CoinGecko;
use Modules\Accounting\Services\RateSources\Frankfurter;
use Modules\Accounting\Services\RateSources\RateSource;
use Modules\Accounting\Services\RateSources\RateSourceFailed;
use Modules\Accounting\Services\RateSources\TcmbEvds;

/**
 * Bring in the day's rates.
 *
 * The only place in Kargah that talks to a rate provider. Nothing fetches
 * during a request, because a page render must not depend on someone else's API
 * being up; this runs from the scheduler and everything else reads the table.
 *
 * Running it twice on the same day leaves the same rows, since `record()` keys
 * on the business date rather than the fetch time. That single property is what
 * makes it safe on cron, where a missed run and a doubled run are both normal.
 *
 * Sources are independent on purpose. One provider being down, or one key being
 * absent, must not cost the owner the rates the other two would have supplied.
 */
class FetchRates extends Command
{
    protected $signature = 'accounting:fetch-rates';

    protected $description = 'Fetch exchange rates from TCMB, Frankfurter and CoinGecko into the rate history';

    /**
     * How far USDT may drift from a dollar before the owner is told, in percent.
     *
     * Half a percent is well inside normal exchange spread and well outside the
     * range a healthy peg trades in, so it catches a real depeg without crying
     * wolf on a quiet Tuesday.
     */
    private const PEG_TOLERANCE_PERCENT = '0.5';

    public function handle(
        ExchangeRates $rates,
        TcmbEvds $tcmb,
        Frankfurter $frankfurter,
        CoinGecko $coinGecko,
    ): int {
        /** @var list<RateSource> $sources */
        $sources = [$tcmb, $frankfurter, $coinGecko];

        $recorded = 0;
        $failed = [];

        foreach ($sources as $source) {
            if ($reason = $source->unavailableReason()) {
                $this->components->warn($source->name().' was skipped. '.$reason);
                Log::warning('accounting:fetch-rates skipped '.$source->name().'. '.$reason);

                continue;
            }

            try {
                $quotes = $source->fetch();
            } catch (RateSourceFailed $e) {
                $failed[] = $source->name();
                $this->components->warn($e->getMessage());
                Log::warning('accounting:fetch-rates: '.$e->getMessage());

                continue;
            }

            foreach ($quotes as $quote) {
                $rates->record(
                    $quote->base,
                    $quote->quote,
                    $quote->rate,
                    $source->name(),
                    $quote->asOf,
                    $quote->rateType,
                );

                $recorded++;

                $this->components->info(
                    $quote->pair().' '.$quote->rateType.' '.$quote->rate.' as of '.$quote->asOf.' from '.$source->name(),
                );
            }
        }

        $this->reportPeg($rates);

        $summary = 'Recorded '.$recorded.' '.str('rate')->plural($recorded).'.';

        if ($failed !== []) {
            // Non-zero so that cron, and whoever reads its mail, sees that a
            // provider is down. What was fetched is already committed — the
            // exit code reports the gap, it does not undo the rest.
            $this->components->warn($summary.' '.implode(' and ', $failed).' failed and will be retried tomorrow.');

            return self::FAILURE;
        }

        $this->components->info($summary);

        return self::SUCCESS;
    }

    /**
     * Say something when a tether stops being worth a dollar.
     *
     * Read back from the table rather than from the quote just fetched, so the
     * warning reflects what an invoice issued now would actually use. Logged as
     * well as printed, because cron output is usually discarded and this is the
     * one line in the run that might need acting on the same day.
     */
    private function reportPeg(ExchangeRates $rates): void
    {
        $deviation = $rates->tetherDeviation(today());

        if ($deviation === null) {
            return;
        }

        if (BigDecimal::of($deviation)->abs()->isGreaterThan(self::PEG_TOLERANCE_PERCENT)) {
            $message = 'USDT is '.$deviation.'% away from its peg. Check before invoicing or accepting payment in it.';

            $this->components->warn($message);
            Log::warning('accounting:fetch-rates: '.$message);
        }
    }
}
