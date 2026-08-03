<?php

namespace Modules\Mailbox\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Services\Delivery\Delivery;
use Modules\Mailbox\Services\Delivery\OutboundMessage;
use Modules\Mailbox\Services\Delivery\Router;
use Modules\Mailbox\Services\Delivery\SendFailed;

/**
 * One message, sent later.
 *
 * The compose window's 'schedule' button and nothing else. It is a separate job
 * from `SendCampaignChunk` because the two have opposite requirements: a
 * campaign message is one of five hundred and must never be sent twice, while
 * this is a reply to a person and has no row anywhere to claim.
 *
 * That difference is why this job carries the message rather than an id.
 * There is no `campaign_recipients` row to re-read, and the whole point of
 * scheduling is that what was written at three o'clock is what goes out at
 * nine — not whatever the draft looks like by then.
 *
 * The suppression list is still checked, at send time rather than at schedule
 * time. An address can be blocked in the hours between, and a scheduled message
 * that ignores a complaint made yesterday is exactly the send that costs a
 * sending account.
 */
class SendDirectMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly OutboundMessage $message,
        public readonly ?int $providerId = null,
    ) {}

    public function handle(Delivery $delivery, Router $router): void
    {
        if (Suppression::blocks($this->message->toEmail)) {
            Log::warning('mailbox: a scheduled message to '.$this->message->toEmail.' was dropped — the address is suppressed.');

            return;
        }

        $preferred = $this->providerId === null ? null : DeliveryProvider::query()->find($this->providerId);

        $provider = $router->pick($preferred);

        if ($provider === null) {
            Log::warning('mailbox: a scheduled message to '.$this->message->toEmail.' was not sent. '.$router->refusalReason());

            return;
        }

        $driver = $delivery->driverFor($provider->driver);

        if ($driver === null || ($reason = $driver->unavailableReason($provider)) !== null) {
            Log::warning(
                'mailbox: a scheduled message to '.$this->message->toEmail.' was not sent. '
                .($reason ?? 'Kargah has no driver for '.$provider->driver.'.'),
            );

            return;
        }

        try {
            $driver->send($provider, $this->message);
        } catch (SendFailed $e) {
            // Logged rather than rethrown. A retry would be a second copy of a
            // personal message with no row to say the first one went, and the
            // person who wrote it is not watching a queue.
            Log::error('mailbox: a scheduled message to '.$this->message->toEmail.' failed. '.$e->getMessage());

            return;
        }

        $provider->recordSend();
    }
}
