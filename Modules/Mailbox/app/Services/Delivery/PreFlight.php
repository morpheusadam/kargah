<?php

namespace Modules\Mailbox\Services\Delivery;

use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\DeliveryProvider;

/**
 * The checks a campaign has to pass before a single message leaves.
 *
 * This class exists to be the only way a campaign can start, and it refuses
 * rather than warns. That is the acceptance criterion in
 * project-guaid/spec/05-build-order.md — *the pre-flight refuses to send when
 * SPF, DKIM or unsubscribe are missing* — and the reason it is a refusal is
 * that all three failures are silent at send time and expensive afterwards:
 *
 * - **No SPF** and the receiving server cannot tell that this provider is
 *   allowed to send as the domain. Some accept it, Google does not.
 * - **No DKIM** and nothing in the message is signed, so a forwarded copy
 *   cannot be verified and DMARC has nothing to align against. Since 2024 both
 *   Gmail and Yahoo require it from bulk senders outright.
 * - **No unsubscribe link** and the only way out a recipient has is the spam
 *   button, which costs the sending domain far more than the unsubscribe would
 *   have.
 *
 * Every problem is reported, not just the first. Someone setting up a campaign
 * should learn about all three in one go rather than discovering them one press
 * of the button at a time.
 */
class PreFlight
{
    /**
     * Everything wrong with this campaign, as sentences a person can act on.
     *
     * An empty list means it may start. Each string names what is missing *and*
     * what to do about it, because 'DKIM not verified' on its own leaves the
     * reader to work out whose DNS they are supposed to be editing.
     *
     * @return list<string>
     */
    public function problems(Campaign $campaign, ?DeliveryProvider $provider = null): array
    {
        $problems = [];

        $provider ??= $campaign->provider;

        if (trim((string) $campaign->subject) === '') {
            $problems[] = 'The campaign has no subject line.';
        }

        if (trim((string) $campaign->body_html) === '' && trim((string) $campaign->body_text) === '') {
            $problems[] = 'The campaign has no body.';
        }

        if (! $campaign->hasUnsubscribeLink()) {
            $problems[] = 'The body carries no unsubscribe link. Put '.Campaign::UNSUBSCRIBE_PLACEHOLDER
                .' where the link should appear; Kargah replaces it with a one-click link unique to each recipient.';
        }

        if ($campaign->recipients()->count() === 0) {
            $problems[] = 'The campaign has no recipients.';
        }

        if ($provider === null) {
            $problems[] = 'No delivery provider has been chosen for this campaign.';

            return $problems;
        }

        return array_merge($problems, $this->providerProblems($provider));
    }

    /**
     * What stops this provider carrying a campaign.
     *
     * Separated so the provider page can show the same sentences against the
     * provider itself, before anyone has written a campaign to find out.
     *
     * @return list<string>
     */
    public function providerProblems(DeliveryProvider $provider): array
    {
        $problems = [];

        if (! $provider->is_active) {
            $problems[] = $provider->label().' is switched off.';
        }

        if (! $provider->spf_verified) {
            $problems[] = 'SPF is not verified for '.($provider->sending_domain ?: $provider->label())
                .'. Add the provider\'s SPF record to the domain\'s DNS and mark it verified on the provider page.';
        }

        if (! $provider->dkim_verified) {
            $problems[] = 'DKIM is not verified for '.($provider->sending_domain ?: $provider->label())
                .'. Add the provider\'s DKIM keys to the domain\'s DNS and mark them verified on the provider page.';
        }

        if (($missing = $provider->missingCredentials()) !== []) {
            $problems[] = $provider->label().' is missing '.implode(' and ', $missing).'.';
        }

        if ($provider->from_email === null || $provider->from_email === '') {
            $problems[] = $provider->label().' has no from address.';
        }

        return $problems;
    }

    public function passes(Campaign $campaign, ?DeliveryProvider $provider = null): bool
    {
        return $this->problems($campaign, $provider) === [];
    }

    /**
     * The refusal as one sentence, for a toast.
     *
     * Names the count and then the first problem, because a toast has room for
     * one and the page lists the rest underneath it. Never 'could not start' on
     * its own — a refusal that does not say what is missing is a refusal
     * somebody will work around by pressing the button again.
     */
    public function refusal(array $problems): string
    {
        if ($problems === []) {
            return 'The campaign is ready to send.';
        }

        return count($problems) === 1
            ? $problems[0]
            : count($problems).' things stop this campaign going out. '.$problems[0];
    }
}
