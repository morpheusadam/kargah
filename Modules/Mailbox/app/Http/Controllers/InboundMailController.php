<?php

namespace Modules\Mailbox\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\Imap\WebklexHydrator;
use Modules\Mailbox\Services\MessageStore;
use Webklex\PHPIMAP\Message;

/**
 * The endpoint a Cloudflare Email Worker posts every incoming message to.
 *
 * Cloudflare Email Routing holds the MX for the domain. A routing rule can hand
 * a message to a Worker, and the Worker streams the raw RFC822 here. That is the
 * whole receiving path: no IMAP server to rent, no mailbox to poll, no cron to
 * arrive five minutes late, and no app password stored on behalf of a Gmail
 * account. A message is in the inbox by the time the sender's server has
 * finished the SMTP conversation.
 *
 * **The status code is the protocol.** The Worker calls `setReject()` on
 * anything that is not 2xx, which makes the *sending* server queue the message
 * and try again later rather than lose it. So a failure this class cannot fix by
 * retrying — a body that will never parse, a secret that will never match — must
 * not answer with an error, or a permanently undeliverable message is retried
 * for days and the sender eventually gets a bounce for a mailbox that is working
 * fine. Only genuinely transient failures are allowed to be 5xx; everything else
 * is accepted, logged, and dropped on the floor deliberately.
 *
 * What replaces a session here is the shared secret. There is no user: the
 * caller is a Worker with no cookie jar and nothing that has ever seen a Kargah
 * page, which is also why the route drops CSRF.
 */
class InboundMailController
{
    public function __invoke(
        Request $request,
        MessageStore $store,
        WebklexHydrator $hydrator,
    ): Response {
        if (! $this->authentic($request)) {
            /*
             * 404, not 401. An endpoint that answers "wrong secret" confirms it
             * exists and is worth grinding at; one that is indistinguishable
             * from a route that was never registered is not. The Worker never
             * sees this — it holds the right secret — so the message it would
             * have carried is not at stake.
             */
            return response('', 404);
        }

        $raw = $request->getContent();

        if (! is_string($raw) || trim($raw) === '') {
            return $this->accepted('an empty body');
        }

        if (strlen($raw) > $this->maxBytes()) {
            return $this->accepted('a body over the '.config('mailbox.inbound.max_size_kb').' KB limit');
        }

        $account = $this->account($request);

        if ($account === null) {
            /*
             * The one case that *is* worth a retry. An install whose inbound
             * account has been deactivated mid-flight, or which has not
             * finished being set up, will likely have one again shortly — and
             * holding the message on the sender's queue until then is better
             * than accepting mail there is nowhere to put.
             */
            Log::warning('mailbox: refused an inbound message because no active inbound account exists.');

            return response('', 503);
        }

        try {
            $message = Message::fromString($raw);
        } catch (\Throwable $e) {
            // A message this library cannot parse will not parse on the tenth
            // attempt either, so it is accepted rather than retried for ever.
            return $this->accepted('an unparseable body: '.Str::limit($e->getMessage(), 200));
        }

        $remote = $hydrator->hydrate($message, uid: 0);

        $threadId = $store->store($account, $remote, $this->folder());
        $store->refreshThreads([$threadId]);

        return response('', 204);
    }

    /**
     * Whether the caller holds the configured secret.
     *
     * `hash_equals` rather than `===` so a wrong secret takes the same time as a
     * right one and cannot be recovered a byte at a time. A missing or empty
     * configured secret is never a match — an install that has not set one has
     * not deployed a Worker either, and an endpoint that accepted anything would
     * let a stranger write rows into the owner's inbox.
     */
    private function authentic(Request $request): bool
    {
        $expected = (string) config('mailbox.inbound.secret');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $request->header('X-Inbound-Secret', ''));
    }

    /**
     * The account a pushed message belongs to.
     *
     * Matched on the envelope recipient the Worker passes in `X-Mail-To`, so an
     * install that receives for two domains files each under its own account.
     * Falling back to the single active inbound account covers the ordinary
     * case, where the catch-all sends everything at one place and matching on
     * the recipient would only mean refusing mail for an address nobody thought
     * to write down.
     */
    private function account(Request $request): ?MailAccount
    {
        $to = trim((string) $request->header('X-Mail-To', ''));

        $inbound = MailAccount::query()->active()->where('kind', MailAccount::KIND_INBOUND);

        if ($to !== '') {
            $matched = (clone $inbound)->where('email', $to)->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        return $inbound->oldest('id')->first();
    }

    /**
     * Take the message off the sender's hands, and say why it went nowhere.
     *
     * 200 with a log line rather than a 4xx, because the Worker turns anything
     * that is not 2xx into an SMTP rejection and the sending server into a
     * retry loop. Nothing about an empty or unparseable body improves on the
     * next attempt, so retrying it only delays the bounce.
     */
    private function accepted(string $reason): Response
    {
        Log::info('mailbox: dropped an inbound message with '.$reason);

        return response('', 200);
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('mailbox.inbound.max_size_kb', 30720)) * 1024;
    }

    private function folder(): string
    {
        $folder = trim((string) config('mailbox.inbound.folder', 'INBOX'));

        return $folder === '' ? 'INBOX' : $folder;
    }
}
