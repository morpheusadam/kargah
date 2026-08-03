<?php

namespace Modules\Mailbox\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Services\Delivery\Delivery;
use Modules\Mailbox\Services\Delivery\WebhookProcessor;

/**
 * Where a provider tells Kargah that a message bounced or was complained about.
 *
 * Unauthenticated by necessity — the caller is Brevo or Amazon, not a person —
 * which makes the verification step the only thing between the shared
 * suppression list and anyone who finds the URL. A forged hard bounce for a
 * client's address would silence that address on every provider and look
 * identical to a real one afterwards, so an unverified callback is refused
 * before its body is read rather than parsed and then judged.
 *
 * The route is per provider row rather than per driver, because two Brevo
 * accounts have two different webhook secrets and a callback has to be checked
 * against the credentials of the account it claims to come from.
 *
 * Always answers 200 once verified, whatever the body turned out to contain.
 * Providers treat a non-2xx as a delivery failure and retry with backoff, and a
 * payload Kargah does not model is not a failure — retrying it forever would
 * turn one unrecognised event kind into a permanent stream of traffic.
 */
class DeliveryWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        DeliveryProvider $provider,
        Delivery $delivery,
        WebhookProcessor $processor,
    ): JsonResponse {
        $handler = $delivery->webhookHandlerFor($provider->driver);

        if ($handler === null) {
            // 404 rather than 400: from the outside, a provider whose driver
            // cannot read callbacks is indistinguishable from a URL that does
            // not exist, and saying more would confirm the row exists.
            return response()->json(['status' => 'unknown'], 404);
        }

        if (! $handler->verify($request, $provider)) {
            Log::warning('mailbox: a '.$provider->driver.' callback for provider '.$provider->id.' failed verification.');

            return response()->json(['status' => 'rejected'], 403);
        }

        $events = $handler->events($request);

        // Deliberately idempotent all the way down: `WebhookProcessor` counts
        // only the events that changed something, so a provider redelivering
        // the same callback gets a 200 saying nothing happened rather than
        // double-counting a bounce.
        $changed = $processor->apply($provider, $events);

        return response()->json([
            'status' => 'ok',
            'events' => count($events),
            'applied' => $changed,
        ]);
    }
}
