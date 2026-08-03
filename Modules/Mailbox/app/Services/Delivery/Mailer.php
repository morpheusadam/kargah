<?php

namespace Modules\Mailbox\Services\Delivery;

use Modules\Mailbox\Models\DeliveryProvider;

/**
 * One service Kargah can hand a message to.
 *
 * Drivers do not write. They accept a message and report that it was taken, and
 * `CampaignSender` records that against the recipient row — so there is exactly
 * one place in the application that knows how a delivery reaches the table, and
 * therefore exactly one place that has to be right about claiming and
 * idempotency.
 *
 * A driver distinguishes two kinds of not-working, because they call for
 * different reactions. **Unavailable** means it was never going to send: no
 * credentials, a bridge package that is not installed, a provider switched off.
 * That is a state of the install, it will not fix itself by retrying, and it
 * must be reported to the person rather than thrown at the queue. **Failed**
 * means the provider was asked and did not accept, which may be transient.
 *
 * Both end up in `campaign_recipients.error` and neither escapes the job.
 */
interface Mailer
{
    /** The value stored in `delivery_providers.driver`, e.g. `brevo`. */
    public function driver(): string;

    /**
     * Why this provider cannot send at all, or null if it can.
     *
     * The string is written to `campaign_recipients.error` and shown on the
     * report, so it says what is missing *and* what that cost — 'no
     * credentials' on its own leaves the reader to work out which address never
     * received anything.
     */
    public function unavailableReason(DeliveryProvider $provider): ?string;

    /**
     * Hand one message to the provider.
     *
     * One conversation, a short timeout, then it gives up. Nothing here loops
     * or waits: this runs on shared hosting where a job that will not finish is
     * a job that gets the account suspended.
     *
     * @throws SendFailed when the provider is unreachable, errors, or refuses
     *                    the message
     */
    public function send(DeliveryProvider $provider, OutboundMessage $message): SentMessage;
}
