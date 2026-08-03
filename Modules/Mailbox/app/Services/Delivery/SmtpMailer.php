<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Http\Request;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;

/**
 * Any server that speaks SMTP.
 *
 * The fallback, and the one to be careful with. A shared host's own outgoing
 * server will happily accept the first two hundred messages of a campaign and
 * then rate-limit the account, and the provider page says so — this driver
 * exists so a small send works without an account anywhere, not so a campaign
 * can be pushed through a cPanel mailbox.
 *
 * A plain SMTP server reports nothing back, so bounces arrive as messages in
 * the inbox rather than as callbacks. The webhook half is still implemented
 * because a relay in front of it (a self-hosted Postal, an ISP's smart host)
 * often does post reports, and pointing that at Kargah should not need a new
 * driver.
 */
class SmtpMailer extends SymfonyMailer implements HandlesWebhooks
{
    use VerifiesWithSharedSecret;

    public function driver(): string
    {
        return Senders::SMTP;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transportConfig(DeliveryProvider $provider): array
    {
        $port = (int) ($provider->credential('port') ?? 587);

        return [
            'transport' => 'smtp',
            'host' => $provider->credential('host'),
            'port' => $port,
            // Implicit TLS on 465, STARTTLS everywhere else. Symfony infers the
            // same thing from the scheme when it is given a DSN, but the array
            // form has to be told, and getting it wrong on 465 produces a
            // timeout rather than an error worth reading.
            'scheme' => $port === 465 ? 'smtps' : null,
            'username' => $provider->credential('username'),
            'password' => $provider->credential('password'),
            'timeout' => self::TIMEOUT,
            'local_domain' => $provider->sending_domain,
        ];
    }

    /**
     * A relay's report, in whatever shape it posts.
     *
     * Deliberately forgiving about field names, because 'a relay in front of an
     * SMTP server' is not one product with one payload. What it is not
     * forgiving about is the shared secret — see `VerifiesWithSharedSecret`.
     *
     * @return list<DeliveryEvent>
     */
    public function events(Request $request): array
    {
        $email = $request->input('recipient', $request->input('email'));
        $kind = (string) $request->input('event', $request->input('type', ''));

        if (! is_string($email) || $email === '') {
            return [];
        }

        $mapped = match (mb_strtolower($kind)) {
            'bounce', 'hard_bounce', 'permanent', 'failed' => DeliveryEvent::HARD_BOUNCE,
            'deferred', 'soft_bounce', 'temporary' => DeliveryEvent::SOFT_BOUNCE,
            'complaint', 'spam' => DeliveryEvent::COMPLAINT,
            'unsubscribe', 'unsubscribed' => DeliveryEvent::UNSUBSCRIBE,
            'delivered', 'delivery' => DeliveryEvent::DELIVERED,
            default => null,
        };

        if ($mapped === null) {
            return [];
        }

        return [new DeliveryEvent(
            kind: $mapped,
            email: $email,
            messageId: $request->input('message_id'),
            detail: $request->input('reason', $request->input('description')),
        )];
    }
}
