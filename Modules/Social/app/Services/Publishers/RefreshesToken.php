<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;

/**
 * A network that will trade a living credential for a longer-lived one.
 *
 * Separate from `Publisher` for the same reason `IngestsNotifications` is: it is
 * genuinely optional and the honest answer differs per network. Of the fourteen
 * drivers Kargah ships, most have nothing to implement here —
 *
 * - **Mastodon, Bluesky, Discord, Slack, Telegram, WordPress, Lemmy** issue
 *   credentials that do not expire at all (`token_lifetime_days` is null for
 *   every one of them), so there is nothing to refresh;
 * - **X and Tumblr** sign each request with OAuth 1.0a, where the token is a
 *   long-term key rather than a session;
 * - **LinkedIn's** refresh grant is gated behind a partner programme that a
 *   self-serving developer cannot enter, which is exactly why its `requirement`
 *   copy tells the person to come back and paste a new one every sixty days;
 * - **a Facebook Page token** derived from a long-lived user token does not
 *   expire, and one taken out of Graph API Explorer cannot be extended in place
 *   — it has to be exchanged for a different kind of credential entirely.
 *
 * That leaves Instagram and Threads, which both mint a sixty-day token and both
 * publish a `refresh_access_token` edge that hands back a fresh one for nothing
 * but the old one. They are the two networks where a person who does nothing
 * would otherwise watch a working connection die on a date nobody wrote down.
 *
 * 🔴 **Implementing this is a promise that the refresh needs no new permission.**
 * Instagram's edge is covered by `instagram_business_basic` and Threads' by
 * `threads_basic` — both already granted, both already required to verify the
 * account at all. A network whose refresh needed a scope Kargah does not ask for
 * would have to widen `Networks`' permission list in the same commit, and that
 * list is a promise made to the person on the connect page. See
 * `.data/meta-app.txt`.
 */
interface RefreshesToken
{
    /**
     * Trade this account's stored token for a fresh one, publishing nothing.
     *
     * The old credential must still be valid: every implementation of this is an
     * *extension*, not a re-issue, and an expired token cannot be extended by
     * any means short of a person authorising the app again. `social:refresh-tokens`
     * is what decides when to ask; this only asks.
     *
     * @throws PublishFailed when the network is unreachable, refuses the token,
     *                       or answers with something that is not a credential
     */
    public function refreshToken(SocialAccount $account): RefreshedToken;
}
