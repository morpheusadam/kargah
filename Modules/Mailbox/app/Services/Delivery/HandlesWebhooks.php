<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Http\Request;
use Modules\Mailbox\Models\DeliveryProvider;

/**
 * A driver that can read its provider's bounce and complaint callbacks.
 *
 * Separate from `Mailer` for the same reason `IngestsNotifications` is separate
 * in Social: sending and reporting are different capabilities and a provider
 * may have one without the other. A plain SMTP relay sends perfectly well and
 * reports nothing.
 *
 * `verify()` is asked first and its answer is final. A webhook that writes to
 * the shared suppression list can silence any address on the system, so an
 * unverified callback is refused rather than parsed — a forged bounce for a
 * client's address would be indistinguishable from a real one afterwards.
 */
interface HandlesWebhooks
{
    /**
     * Whether this callback really came from the provider.
     *
     * Mailgun signs with an HMAC and this checks it. The other four publish no
     * signature, so what is checked is a secret Kargah generated and the owner
     * put in the callback URL — weaker than a signature, and still the
     * difference between an endpoint anyone can post to and one they cannot.
     */
    public function verify(Request $request, DeliveryProvider $provider): bool;

    /**
     * The events this callback carries, translated into Kargah's vocabulary.
     *
     * A list because several providers batch: SES wraps an SNS envelope that
     * can name many recipients, and Mailgun can post an array. An empty list is
     * a normal answer — a delivery notification for an event kind Kargah does
     * not model is not an error.
     *
     * @return list<DeliveryEvent>
     */
    public function events(Request $request): array;
}
