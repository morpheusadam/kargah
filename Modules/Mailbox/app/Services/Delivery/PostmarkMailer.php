<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;

/**
 * Postmark, through Laravel's `postmark` transport.
 *
 * The best deliverability of the five and the strictest about what it will
 * carry: bulk mail on a transactional stream is refused outright, which is why
 * `message_stream` is a required credential rather than an optional one. A
 * campaign that goes out on the wrong stream does not go out at all, and the
 * error Postmark returns says so clearly enough to land in
 * `campaign_recipients.error` unedited.
 *
 * Postmark does not sign its webhooks — its own documentation recommends
 * putting credentials in the callback URL. The shared secret does that; see
 * `VerifiesWithSharedSecret`.
 */
class PostmarkMailer extends SymfonyMailer implements HandlesWebhooks
{
    use VerifiesWithSharedSecret;

    public function driver(): string
    {
        return Senders::POSTMARK;
    }

    protected function bridgeClass(): string
    {
        return PostmarkTransportFactory::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transportConfig(DeliveryProvider $provider): array
    {
        return [
            'transport' => 'postmark',
            'token' => $provider->credential('token'),
            'message_stream_id' => $provider->credential('message_stream'),
        ];
    }

    /**
     * One Postmark event.
     *
     * `RecordType` names the kind and `Type` refines a bounce, which is the
     * pair that matters: `HardBounce`, `SpamNotification` and `BadEmailAddress`
     * are permanent and suppress, while `Transient` and `SoftBounce` are a full
     * mailbox or a greylist and must not.
     *
     * @return list<DeliveryEvent>
     */
    public function events(Request $request): array
    {
        $email = $request->input('Email', $request->input('Recipient'));

        if (! is_string($email) || $email === '') {
            return [];
        }

        $record = (string) $request->input('RecordType');
        $type = (string) $request->input('Type');

        $kind = match ($record) {
            'Bounce' => match ($type) {
                'HardBounce', 'BadEmailAddress', 'ManuallyDeactivated', 'Unknown' => DeliveryEvent::HARD_BOUNCE,
                'SpamComplaint', 'SpamNotification' => DeliveryEvent::COMPLAINT,
                default => DeliveryEvent::SOFT_BOUNCE,
            },
            'SpamComplaint' => DeliveryEvent::COMPLAINT,
            'SubscriptionChange' => $request->boolean('SuppressSending') ? DeliveryEvent::UNSUBSCRIBE : null,
            'Delivery' => DeliveryEvent::DELIVERED,
            default => null,
        };

        if ($kind === null) {
            return [];
        }

        $at = $request->input('BouncedAt', $request->input('DeliveredAt'));

        return [new DeliveryEvent(
            kind: $kind,
            email: $email,
            messageId: $request->input('MessageID'),
            detail: $request->input('Description', $request->input('Details')),
            occurredAt: is_string($at) ? Carbon::parse($at) : null,
        )];
    }
}
