<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;

/**
 * Brevo, over its SMTP relay rather than its API.
 *
 * SMTP on purpose. Brevo's own API transport is a separate bridge package that
 * would have to be installed, and it buys nothing a campaign needs — the relay
 * accepts the same headers, applies the same tracking, and reports the same
 * events. One fewer dependency on a machine where the whole point is that it
 * runs on shared hosting.
 *
 * Brevo does not sign its webhooks. The shared secret in the callback URL is
 * what authenticates them; see `VerifiesWithSharedSecret`.
 */
class BrevoMailer extends SymfonyMailer implements HandlesWebhooks
{
    use VerifiesWithSharedSecret;

    private const HOST = 'smtp-relay.brevo.com';

    private const PORT = 587;

    public function driver(): string
    {
        return Senders::BREVO;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transportConfig(DeliveryProvider $provider): array
    {
        return [
            'transport' => 'smtp',
            'host' => self::HOST,
            'port' => self::PORT,
            'username' => $provider->credential('username'),
            'password' => $provider->credential('password'),
            'timeout' => self::TIMEOUT,
            'local_domain' => $provider->sending_domain,
        ];
    }

    /**
     * One Brevo event.
     *
     * Brevo posts a single flat object per event rather than a batch, and its
     * `event` vocabulary separates a hard bounce from a soft one at the source
     * — which is worth having, because guessing that from an SMTP code is where
     * most bounce handling goes wrong and starts suppressing full mailboxes.
     *
     * @return list<DeliveryEvent>
     */
    public function events(Request $request): array
    {
        $email = $request->input('email');

        if (! is_string($email) || $email === '') {
            return [];
        }

        $kind = match ((string) $request->input('event')) {
            'hard_bounce', 'blocked', 'invalid_email' => DeliveryEvent::HARD_BOUNCE,
            'soft_bounce', 'deferred' => DeliveryEvent::SOFT_BOUNCE,
            'spam', 'complaint' => DeliveryEvent::COMPLAINT,
            'unsubscribed' => DeliveryEvent::UNSUBSCRIBE,
            'delivered' => DeliveryEvent::DELIVERED,
            default => null,
        };

        if ($kind === null) {
            return [];
        }

        $at = $request->input('ts_event', $request->input('ts'));

        return [new DeliveryEvent(
            kind: $kind,
            email: $email,
            messageId: $request->input('message-id', $request->input('messageId')),
            detail: $request->input('reason'),
            occurredAt: is_numeric($at) ? Carbon::createFromTimestampUTC((int) $at) : null,
        )];
    }
}
