<?php

namespace Modules\Mailbox\Services\Delivery;

use Aws\Ses\SesClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;

/**
 * Amazon SES, through Laravel's `ses` transport.
 *
 * Cheapest at volume and least forgiving of a new sender: a fresh account is
 * sandboxed until AWS lifts it, and a bounce rate above five percent gets an
 * account paused rather than warned. That last point is the reason the shared
 * suppression list exists at all — SES counts a bounce Kargah could have
 * avoided exactly the same as one it could not.
 *
 * SES reports through SNS, which signs its messages with an X.509 certificate
 * that has to be fetched from Amazon to be checked. That is an outbound HTTP
 * request per callback, and a bounce storm would turn into a self-inflicted
 * denial of service on shared hosting. The shared secret in the subscription
 * URL is what is checked instead, and `Senders` says so on the provider page
 * rather than implying a verification that is not happening.
 */
class SesMailer extends SymfonyMailer implements HandlesWebhooks
{
    use VerifiesWithSharedSecret;

    public function driver(): string
    {
        return Senders::SES;
    }

    protected function bridgeClass(): string
    {
        return SesClient::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transportConfig(DeliveryProvider $provider): array
    {
        return [
            'transport' => 'ses',
            'key' => $provider->credential('key'),
            'secret' => $provider->credential('secret'),
            'region' => $provider->credential('region'),
        ];
    }

    /**
     * The events inside an SNS envelope.
     *
     * SNS wraps the SES notification as a JSON string inside its own `Message`
     * field, and one notification can name several recipients — a message to
     * five addresses that bounced at three of them arrives as one callback.
     * Hence a list rather than a single event.
     *
     * A `SubscriptionConfirmation` is answered with an empty list rather than
     * an error. Kargah does not auto-confirm: confirming a subscription means
     * fetching a URL Amazon supplied, and an endpoint that fetches URLs on
     * request is an endpoint somebody will point somewhere else.
     *
     * @return list<DeliveryEvent>
     */
    public function events(Request $request): array
    {
        $payload = $request->input('Message');
        $notification = is_string($payload) ? json_decode($payload, true) : $payload;

        if (! is_array($notification)) {
            return [];
        }

        $type = (string) ($notification['notificationType'] ?? $notification['eventType'] ?? '');
        $messageId = $notification['mail']['messageId'] ?? null;
        $events = [];

        if ($type === 'Bounce') {
            $bounce = is_array($notification['bounce'] ?? null) ? $notification['bounce'] : [];
            $permanent = ($bounce['bounceType'] ?? '') === 'Permanent';
            $at = $bounce['timestamp'] ?? null;

            foreach (($bounce['bouncedRecipients'] ?? []) as $recipient) {
                if (! is_array($recipient) || ! is_string($recipient['emailAddress'] ?? null)) {
                    continue;
                }

                $events[] = new DeliveryEvent(
                    kind: $permanent ? DeliveryEvent::HARD_BOUNCE : DeliveryEvent::SOFT_BOUNCE,
                    email: $recipient['emailAddress'],
                    messageId: is_string($messageId) ? $messageId : null,
                    detail: $recipient['diagnosticCode'] ?? ($bounce['bounceSubType'] ?? null),
                    occurredAt: is_string($at) ? Carbon::parse($at) : null,
                );
            }

            return $events;
        }

        if ($type === 'Complaint') {
            $complaint = is_array($notification['complaint'] ?? null) ? $notification['complaint'] : [];
            $at = $complaint['timestamp'] ?? null;

            foreach (($complaint['complainedRecipients'] ?? []) as $recipient) {
                if (! is_array($recipient) || ! is_string($recipient['emailAddress'] ?? null)) {
                    continue;
                }

                $events[] = new DeliveryEvent(
                    kind: DeliveryEvent::COMPLAINT,
                    email: $recipient['emailAddress'],
                    messageId: is_string($messageId) ? $messageId : null,
                    detail: $complaint['complaintFeedbackType'] ?? null,
                    occurredAt: is_string($at) ? Carbon::parse($at) : null,
                );
            }

            return $events;
        }

        if ($type === 'Delivery') {
            $delivery = is_array($notification['delivery'] ?? null) ? $notification['delivery'] : [];

            foreach (($delivery['recipients'] ?? []) as $address) {
                if (is_string($address) && $address !== '') {
                    $events[] = new DeliveryEvent(
                        kind: DeliveryEvent::DELIVERED,
                        email: $address,
                        messageId: is_string($messageId) ? $messageId : null,
                    );
                }
            }
        }

        return $events;
    }
}
