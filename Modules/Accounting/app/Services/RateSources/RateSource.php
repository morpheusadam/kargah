<?php

namespace Modules\Accounting\Services\RateSources;

/**
 * One provider of exchange rates.
 *
 * Sources do not write. They return quotes and the command hands those to
 * `ExchangeRates::record()`, so there is exactly one place in the application
 * that knows how a rate reaches the table — and therefore exactly one place
 * that has to be right about idempotency.
 *
 * A source distinguishes two kinds of not-working, because they call for
 * different reactions. **Unavailable** means it was never going to run today:
 * no API key, no configured host. That is a state of the install, it will not
 * fix itself by retrying, and the other sources should carry on. **Failed**
 * means it was asked and did not answer usefully, which is a transient fault
 * worth reporting to whoever reads cron output.
 */
interface RateSource
{
    /** The value written to `exchange_rates.source`, e.g. `tcmb_evds`. */
    public function name(): string;

    /**
     * Why this source cannot run at all, or null if it can.
     *
     * The string is shown to the owner and written to the log, so it says what
     * is missing *and* which rates are consequently absent — "no key" on its
     * own leaves them to work out what they have lost.
     */
    public function unavailableReason(): ?string;

    /**
     * Fetch the most recent quotes this source publishes.
     *
     * One HTTP call, a short timeout, a couple of retries and then it gives up.
     * Nothing here loops or waits, because this runs on shared hosting where a
     * command that will not finish is a command that gets the account
     * suspended.
     *
     * @return list<Quote>
     *
     * @throws RateSourceFailed when the provider is unreachable, errors, or
     *                          answers with something that is not a rate
     */
    public function fetch(): array;
}
