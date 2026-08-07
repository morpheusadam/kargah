<?php

namespace Modules\Mailbox\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Modules\Mailbox\Models\CampaignLink;
use Modules\Mailbox\Models\CampaignLinkClick;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Support\Tokens;

/**
 * The two requests a sent message makes back to Kargah on its own.
 *
 * Both are public, both are reached by a mail client rather than by a person
 * with a session, and both are signed URLs carrying an HMAC — the same pair of
 * proofs the unsubscribe link uses, for the same reason. Neither takes anything
 * from the query string but the signature.
 *
 * **The redirect is the part that had to be got right.** Its destination comes
 * from a `campaign_links` row that was written when the message was built, and
 * from nowhere else. There is no parameter naming a URL, so there is nothing to
 * edit: the endpoint can only ever send somebody to a page this campaign already
 * agreed to. An `?url=` here would have made lavzen.com an open redirect, which
 * is a vulnerability and — because a domain that will forward anywhere is what a
 * phishing campaign goes looking for — a spam signal charged against the
 * reputation the rest of this module exists to protect.
 *
 * Recording is the *second* thing each does, and never the thing that decides
 * the response. The pixel is returned whatever happens, and a valid link
 * redirects even when the recipient it names is gone — a seed test deletes its
 * throwaway row before the message is even sent, and a person clicking a link in
 * that test should still arrive where the link points. What is lost in that case
 * is the attribution, which was never the reason the person clicked.
 */
class TrackingController extends Controller
{
    /**
     * A 1×1 transparent GIF, and the smallest one there is.
     *
     * GIF rather than PNG because it is 43 bytes against 68, and inline rather
     * than a file on disk because this is answered on every open of every
     * campaign and a filesystem read for a constant is a filesystem read for
     * nothing.
     */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * The tracking pixel.
     *
     * Always the image, whatever the token turns out to name. A broken image in
     * the middle of somebody's newsletter is a visible defect in a message
     * Kargah sent, and a 404 here would announce which tokens are real to anyone
     * who cared to try — while telling the person reading the message nothing
     * except that something is wrong with it.
     */
    public function open(string $token): Response
    {
        $recipientId = Tokens::recipientFrom(Tokens::OPEN, $token);

        if ($recipientId !== null) {
            CampaignRecipient::recordOpen($recipientId);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Content-Disposition' => 'inline; filename="o.gif"',
            // Every open has to be a request. A cached pixel is a message read
            // ten times and counted once, and `no-store` is the only one of
            // these an image proxy is likely to respect — the others are there
            // for the clients that predate it.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * The redirect a rewritten link goes through.
     *
     * Two tokens, verified independently: one names the recipient, one names the
     * registered link. The URL as a whole is signed as well, so the pair cannot
     * be recombined — but the destination depends on the link alone, which is
     * what lets a message whose recipient row has since been deleted still take
     * a person where it said it would.
     */
    public function click(string $token, string $link): RedirectResponse
    {
        $linkId = Tokens::idFrom(Tokens::LINK, $link);

        $destination = $linkId === null ? null : CampaignLink::query()->find($linkId);

        if ($destination === null) {
            // A link Kargah did not register, or one whose campaign has since
            // been deleted. Home rather than an error page: whoever is here
            // followed a link in an email in good faith, and a 404 tells them
            // only that something they cannot fix is broken.
            return redirect()->to((string) config('app.url'));
        }

        $recipientId = Tokens::recipientFrom(Tokens::CLICK, $token);

        $recipient = $recipientId === null ? null : CampaignRecipient::query()->find($recipientId);

        // The campaign has to match. A recipient of one campaign arriving on
        // another's link is not something a message Kargah built can produce, so
        // it is recorded against neither — but the destination is still a URL
        // this install registered, and refusing to follow it would punish the
        // person rather than whoever assembled the URL.
        if ($recipient !== null && (int) $recipient->campaign_id === (int) $destination->campaign_id) {
            CampaignRecipient::recordClick((int) $recipient->getKey());
            CampaignLinkClick::record((int) $destination->getKey(), (int) $recipient->getKey());
        }

        // Away from Kargah, so `away` rather than `to`: the destination is not a
        // route here and must never be validated as though it were.
        return redirect()->away((string) $destination->url);
    }
}
