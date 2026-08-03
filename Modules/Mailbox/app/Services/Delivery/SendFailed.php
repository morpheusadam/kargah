<?php

namespace Modules\Mailbox\Services\Delivery;

/**
 * A provider was asked to carry a message and did not.
 *
 * Thrown rather than returned so a half-parsed response cannot be mistaken for
 * an accepted message. Every message names the provider and says what actually
 * happened, because the only reader is whoever is looking at a red row on a
 * campaign report asking why one address never received anything.
 *
 * Caught by `CampaignSender` and written to `campaign_recipients.error`. It
 * never reaches the queue: a job that dies takes the rest of its chunk with it,
 * and every recipient it had already claimed would be stranded.
 */
class SendFailed extends \RuntimeException
{
    public static function unreachable(string $provider, string $detail): self
    {
        return new self($provider.' could not be reached, so the message was not sent: '.$detail);
    }

    public static function rejected(string $provider, string $detail): self
    {
        return new self($provider.' refused the message: '.$detail.'.');
    }

    public static function misconfigured(string $provider, string $detail): self
    {
        return new self($provider.' is not set up to send: '.$detail.'.');
    }
}
