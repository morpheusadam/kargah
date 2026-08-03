<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;

/**
 * Mailgun, through Laravel's `mailgun` transport.
 *
 * The only one of the five that signs its callbacks, and it signs with a plain
 * HMAC over a timestamp and a token — no certificate to fetch, no network call.
 * That makes a Mailgun bounce the cheapest kind to trust, which is worth
 * knowing when choosing which provider carries the list that matters.
 *
 * `endpoint` is a required credential because the EU and US regions are
 * separate installations with separate keys, and a key from one region against
 * the other answers 401 with nothing that says why.
 */
class MailgunMailer extends SymfonyMailer implements HandlesWebhooks
{
    /**
     * How far out of date a signed callback may be, in seconds.
     *
     * The signature alone does not stop a replay: a captured bounce for a good
     * address could be posted again a month later and would still verify.
     * Fifteen minutes is far longer than any real delivery takes and short
     * enough that a captured payload is worthless by the time anyone finds it.
     */
    private const MAX_SIGNATURE_AGE = 900;

    public function driver(): string
    {
        return Senders::MAILGUN;
    }

    protected function bridgeClass(): string
    {
        return MailgunTransportFactory::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transportConfig(DeliveryProvider $provider): array
    {
        return [
            'transport' => 'mailgun',
            'domain' => $provider->credential('domain'),
            'secret' => $provider->credential('secret'),
            'endpoint' => $provider->credential('endpoint') ?? 'api.mailgun.net',
            'scheme' => 'https',
        ];
    }

    /**
     * Check Mailgun's HMAC.
     *
     * The signature is `hmac-sha256(timestamp + token)` keyed on the webhook
     * signing key, which is a different key from the sending one — a common
     * way to configure this wrong, and the reason both are separate credentials
     * with separate labels.
     *
     * Compared with `hash_equals` so a forged signature cannot be refined a
     * byte at a time, and refused outright when the signing key is absent: an
     * unconfigured provider must accept nothing rather than everything.
     */
    public function verify(Request $request, DeliveryProvider $provider): bool
    {
        $key = $provider->credential('signing_key');

        if ($key === null) {
            return false;
        }

        $signature = $request->input('signature');
        $signature = is_array($signature) ? $signature : $request->all();

        $timestamp = $signature['timestamp'] ?? null;
        $token = $signature['token'] ?? null;
        $given = $signature['signature'] ?? null;

        if (! is_scalar($timestamp) || ! is_string($token) || ! is_string($given)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_SIGNATURE_AGE) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.$token, $key), $given);
    }

    /**
     * One Mailgun event, out of the `event-data` envelope.
     *
     * The distinction that matters is `severity`: Mailgun reports a full
     * mailbox and a dead address under the same `failed` event and separates
     * them only there. Treating both as permanent would suppress addresses that
     * are merely over quota, which is the most common way a suppression list
     * quietly destroys a good list.
     *
     * @return list<DeliveryEvent>
     */
    public function events(Request $request): array
    {
        $data = $request->input('event-data');

        if (! is_array($data)) {
            return [];
        }

        $email = $data['recipient'] ?? null;

        if (! is_string($email) || $email === '') {
            return [];
        }

        $kind = match ((string) ($data['event'] ?? '')) {
            'failed' => ($data['severity'] ?? '') === 'permanent'
                ? DeliveryEvent::HARD_BOUNCE
                : DeliveryEvent::SOFT_BOUNCE,
            'rejected' => DeliveryEvent::HARD_BOUNCE,
            'complained' => DeliveryEvent::COMPLAINT,
            'unsubscribed' => DeliveryEvent::UNSUBSCRIBE,
            'delivered' => DeliveryEvent::DELIVERED,
            default => null,
        };

        if ($kind === null) {
            return [];
        }

        $at = $data['timestamp'] ?? null;
        $reason = $data['delivery-status']['message'] ?? ($data['reason'] ?? null);

        return [new DeliveryEvent(
            kind: $kind,
            email: $email,
            messageId: $data['message']['headers']['message-id'] ?? null,
            detail: is_string($reason) ? $reason : null,
            occurredAt: is_numeric($at) ? Carbon::createFromTimestampUTC((int) $at) : null,
        )];
    }
}
