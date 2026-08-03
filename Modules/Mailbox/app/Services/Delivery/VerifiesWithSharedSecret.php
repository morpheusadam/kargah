<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Http\Request;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;

/**
 * How a provider that does not sign its callbacks is authenticated.
 *
 * Four of the five publish no signature at all. Brevo and Postmark tell you to
 * put credentials in the callback URL; SES signs through SNS with a certificate
 * that would have to be fetched over the network on every single bounce, which
 * on shared hosting is an HTTP request per callback and a way to make a bounce
 * storm take the site down with it.
 *
 * So Kargah generates a secret of its own, the owner pastes the callback URL
 * — token and all — into the provider's console, and this compares what came
 * back. Weaker than a real signature and honest about it. What matters is the
 * floor it sets: without it the endpoint is a public URL that writes to the
 * shared suppression list, and anyone who guessed the path could silence a
 * client's address permanently.
 *
 * `hash_equals` rather than `===` so the comparison does not leak how much of a
 * guessed token was right, and a provider with no secret configured is refused
 * rather than waved through — the failure mode of 'not set up yet' must be
 * 'nothing is accepted', never 'everything is'.
 */
trait VerifiesWithSharedSecret
{
    public function verify(Request $request, DeliveryProvider $provider): bool
    {
        $expected = $provider->credential(Senders::WEBHOOK_SECRET);

        if ($expected === null) {
            return false;
        }

        $given = $request->query('token');

        if (! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }
}
