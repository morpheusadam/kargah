<?php

namespace Modules\Social\Services\Curation\Sources;

use Modules\Social\Services\Curation\Story;

/**
 * One place stories come from.
 *
 * Modelled on `Modules\Accounting\Services\RateSources\RateSource`, and for the
 * same reason: a daily command that reads forty-odd third-party endpoints on
 * shared hosting cannot let any one of them decide whether the run happens. A
 * source returns stories or it throws, and `CurateDaily` carries on either way.
 *
 * A source distinguishes two kinds of not-working, because they call for
 * different reactions. **Unavailable** means it was never going to run today —
 * a missing key, a host nobody configured. That is a state of the install, it
 * will not fix itself on retry, and it is worth saying out loud once rather
 * than logging a failure every morning. **Failed** means it was asked and did
 * not answer usefully, which is transient and belongs in the run's report.
 *
 * Sources do not filter by age and do not deduplicate. Both of those need to
 * know about the run as a whole — what the window is, what has already been
 * published — and a source that made its own decision about either would be a
 * second place those rules live. `min_points` and its like are the exception,
 * and only because they are the source's own idea of "worth returning at all".
 */
interface Source
{
    /**
     * The name this source is known by, in the catalogue and in every log line.
     *
     * Also the grouping key for corroboration: two stories with the same label
     * are the same outlet and must not count as two outlets agreeing. So it has
     * to be stable per outlet rather than per fetch — see `Clusterer`.
     */
    public function label(): string;

    /** How much to trust this outlet when there is no other signal, 0..1. */
    public function authority(): float;

    /**
     * This source's own age window in hours, or null to use the general one.
     *
     * Exists for the outlets that publish every few days rather than every few
     * hours — a digital-rights organisation's report is still the freshest thing
     * on the subject a week later, and under a general window it would never once
     * get a turn.
     */
    public function maxAgeHours(): ?float;

    /** Why this source cannot run at all, or null if it can. */
    public function unavailableReason(): ?string;

    /**
     * Fetch what this source is publishing now.
     *
     * One HTTP call, a short timeout, a couple of retries, then give up. Nothing
     * here loops or waits: this runs on shared hosting, where a command that will
     * not finish is a command that gets the account suspended.
     *
     * @return list<Story>
     *
     * @throws SourceFailed when the endpoint is unreachable, errors, or answers
     *                      with something that is not a feed
     */
    public function fetch(): array;
}
