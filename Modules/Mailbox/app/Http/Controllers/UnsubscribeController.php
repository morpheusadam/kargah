<?php

namespace Modules\Mailbox\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\Contact;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Support\Tokens;

/**
 * The one-click unsubscribe.
 *
 * No login, no confirmation step, no 'are you sure'. That is not laziness: it
 * is what `List-Unsubscribe-Post: List-Unsubscribe=One-Click` promises the
 * receiving mail client, and Gmail and Yahoo have required that promise from
 * bulk senders since February 2024. A page that asks the person to confirm is a
 * page that fails the mail client's automated check, and the sender is treated
 * as if it had no unsubscribe at all.
 *
 * Two independent proofs are required and both are checked:
 *
 * - **The URL is signed** by Laravel with the application key, via the `signed`
 *   middleware on the route. That stops the query being edited to name a
 *   different recipient.
 * - **The token is itself an HMAC** over the recipient's row id, so even a URL
 *   that reached here some other way names exactly one row or none. See
 *   `Support\Tokens` for why the two are not the same check.
 *
 * The write is idempotent. `suppressions` is unique on the address, so a mail
 * client that prefetches the link and then follows it when the person clicks —
 * which several do — produces one row, not two.
 */
class UnsubscribeController extends Controller
{
    /**
     * The page a person lands on when they click the link in the message.
     *
     * The unsubscribe has already happened by the time this renders. Showing a
     * form first would mean a second request the mail client's one-click check
     * never makes, and an address that stayed subscribed because somebody did
     * not press a second button.
     */
    public function show(string $token): View
    {
        $recipient = $this->resolve($token);

        $this->suppress($recipient);

        return view('mailbox::unsubscribed', [
            'email' => $recipient?->email,
            'campaign' => $recipient?->campaign?->name,
        ]);
    }

    /**
     * The one-click POST a mail client makes on the person's behalf.
     *
     * Answers JSON rather than a page: nobody is looking at it, and the only
     * thing the client checks is the status code. It stays 200 even for a token
     * that resolves to nothing — a client that gets an error will show the
     * person that unsubscribing failed, and a stale link is not something they
     * can do anything about.
     */
    public function store(string $token): JsonResponse
    {
        $recipient = $this->resolve($token);

        $this->suppress($recipient);

        return response()->json(['status' => 'ok']);
    }

    /**
     * The recipient a token names, or null.
     *
     * Both halves are checked: the HMAC has to verify *and* the row it names
     * has to carry that exact token. The second check is what makes a token
     * useless after the recipient row is gone, and it costs one indexed lookup
     * on the unique column the migration added for it.
     */
    private function resolve(string $token): ?CampaignRecipient
    {
        $id = Tokens::recipientFrom(Tokens::UNSUBSCRIBE, $token);

        if ($id === null) {
            return null;
        }

        return CampaignRecipient::query()
            ->whereKey($id)
            ->where('unsubscribe_token', $token)
            ->first();
    }

    /**
     * Block the address and record the person's own preference alongside it.
     *
     * Both, because they answer different questions. The suppression list is
     * what every future send checks and is global; `contacts.is_subscribed` is
     * what the contact page shows and is this person's stated wish. Writing
     * only the first would leave the contacts page saying somebody is
     * subscribed when they have asked not to be.
     */
    private function suppress(?CampaignRecipient $recipient): void
    {
        if ($recipient === null) {
            return;
        }

        Suppression::block(
            (string) $recipient->email,
            Suppression::UNSUBSCRIBE,
            'one-click',
            $recipient->campaign?->name === null ? null : 'Unsubscribed from '.$recipient->campaign->name.'.',
        );

        Contact::query()
            ->where('email', Suppression::normalise((string) $recipient->email))
            ->update(['is_subscribed' => false, 'updated_at' => now()]);
    }
}
