<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\Response;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Slack, through a bot token on an app you installed into your own workspace.
 *
 * **Why a bot token and not an incoming webhook, when Discord went the other
 * way.** `DiscordPublisher` argues at length for a webhook: it is one gesture on
 * the network's own settings screen, it grants strictly less than a bot account,
 * and it needs no long-lived socket. Every word of that is true of a Slack
 * incoming webhook as well, and it is still the wrong credential here, for two
 * reasons that are about Slack rather than about taste.
 *
 * The first is `verify()`. Kargah's connect page answers 'do these credentials
 * work' before a post is riding on them, and `Publisher::verify()` makes the
 * contract explicit: verifying publishes nothing. A Discord webhook can be
 * `GET`-ed — it names itself and its channel — so it honours that contract. A
 * Slack incoming webhook has **no identity endpoint at all**: the URL accepts a
 * `POST` and does nothing else, so the only way to check one is to post
 * something into a real channel and ask the person to go and delete it. Every
 * other driver in Kargah honours the contract, and a network that could not
 * would be the one that quietly taught people to distrust the button.
 *
 * The second is pictures. An incoming webhook takes JSON and cannot carry a
 * file or an upload of any kind, so a picture attached in the composer would
 * have no path to Slack whatsoever — the target would either fail or silently
 * publish the copy without the images somebody attached to it. Neither is an
 * acceptable answer now that the media pipeline exists on every other network.
 *
 * A bot token buys both: `auth.test` for verification, which reads and writes
 * nothing, and `chat.postMessage` for publishing, which can carry blocks. The
 * cost is honest and it is exactly the cost Discord declined to pay — three
 * gestures at setup rather than one: create an app, add the `chat:write` scope,
 * install it to the workspace, and then invite it to the channel. The
 * catalogue's `requirement` text spells all of that out, and the permissions
 * panel says what the token can and cannot do, because a bot token *is* a wider
 * credential than a webhook and pretending otherwise would be dishonest.
 *
 * **HTTP 200 is not evidence.** Slack answers `200` with `{"ok": false}` for a
 * refused send, the same trap `TelegramPublisher` and the Meta family have, so
 * the body is read before the status is believed. `HttpPublisher::detailFrom()`
 * is no help here even though it does look at `error`: Slack's `error` is a
 * machine token — `not_in_channel`, `channel_not_found`, `invalid_auth` — and
 * putting `not_in_channel` in a red row on the posts page tells the reader
 * nothing they can act on. The two or three that a person can actually fix are
 * translated into sentences below, `not_in_channel` above all: it means the app
 * was installed but never invited to the channel, it is the single commonest
 * setup mistake on this network, and the fix is one `/invite` typed into Slack.
 *
 * **Pictures ride as image blocks, and that has a precondition.** Slack retired
 * `files.upload` in favour of a three-step external-upload dance — ask for an
 * upload URL, `POST` the bytes to it, then complete the upload and share it into
 * a channel — which is three round trips per picture inside one job's execution
 * budget, on shared hosting, with the other targets of the same post queued
 * behind it. The catalogue's media note commits to the other shape instead: the
 * message becomes `blocks`, each picture an `image` block whose `image_url`
 * points back at this install, and **Slack fetches the picture itself**. That is
 * Instagram's requirement exactly, so this reuses Instagram's honest guard — an
 * install on `localhost` is told so in a sentence rather than being handed a
 * refusal from Slack about a URL it could not load. `unreachableInstallReason()`
 * below is deliberately the same judgement as `MetaGraph::unreachableInstallReason()`;
 * see the comment on it for why it is copied rather than shared.
 *
 * A text-only Slack post has nothing to fetch and is therefore unaffected: it
 * publishes perfectly from a laptop, which is why the guard lives on the media
 * path and not in `unavailableReason()`. `ThreadsPublisher` splits the same way
 * for the same reason.
 *
 * When blocks are used the top-level `text` is still sent. It is not redundant:
 * Slack uses it as the notification fallback — the line that appears in the
 * desktop toast, the mobile push and the browser tab title — and a message with
 * blocks and no `text` arrives as a nameless notification.
 *
 * **No permalink, deliberately.** `chat.postMessage` answers with `ts` and
 * `channel` and nothing else useful. `ts` is the message timestamp and it *is*
 * the message id, so it is what gets recorded against the target. A link would
 * be `https://<workspace>.slack.com/archives/<channel>/p<ts with the dot
 * removed>` — and the workspace host is the one part of that the publish
 * response does not contain. Learning it means a second call on every single
 * publish (`auth.test` returns a `url`, and `chat.getPermalink` returns the link
 * outright), which is a round trip spent on a courtesy. So this returns a null
 * url and says why here. `PublishedPost` takes one, and the posts page renders
 * an em dash exactly as it already does for a Telegram message in a private
 * chat.
 *
 * **Two content types on purpose.** `chat.postMessage` is sent as JSON, because
 * blocks are a nested structure and the form-encoded spelling of them is a JSON
 * string inside a form field — one encoding too many for no gain. `auth.test`
 * carries no parameters at all and is sent form-encoded, which every Slack Web
 * API method accepts; a JSON body of `[]` is the sort of thing an API is within
 * its rights to refuse.
 *
 * Deliberately does **not** implement `IngestsNotifications`. Reading a
 * workspace would need `channels:history` and `users:read`, which is a great
 * deal more access than posting into one channel, and the catalogue's
 * permissions panel promises Kargah does not ask for it. `Networks` marks this
 * network `ingests: false` and `social:sync-notifications` skips it by name.
 */
class SlackPublisher extends HttpPublisher
{
    use FetchesOwnMedia {
        unreachableInstallReason as private fetchGuardReason;
    }

    private const API = 'https://slack.com/api';

    public function network(): string
    {
        return Networks::SLACK;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $token = $this->require($account, 'bot_token');
        $channel = $this->channel($account);
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $payload = ['channel' => $channel, 'text' => $body];

        if ($media !== []) {
            // Built before the request is made, because this is where the
            // install's own reachability is checked — an install Slack cannot
            // fetch from should fail without a call rather than after one.
            $payload['blocks'] = $this->blocks($body, $media);
        }

        $response = $this->accepted($this->send(
            $this->request()->withToken($token),
            'post',
            self::API.'/chat.postMessage',
            $payload,
        ));

        $ts = $response['ts'] ?? null;

        if (! is_scalar($ts) || (string) $ts === '') {
            throw PublishFailed::malformed(
                $this->network(),
                'the send was accepted but no ts came back, and a message timestamp is the only id a Slack message has',
            );
        }

        // Null url on purpose — the workspace host is not in this response. See
        // the class docblock.
        return new PublishedPost((string) $ts, null);
    }

    /**
     * `auth.test` names the bot and the workspace the token belongs to.
     *
     * It writes nothing and reads nothing beyond the token's own identity,
     * which makes it the cheapest possible answer to 'do these credentials
     * work' — it needs no scope at all beyond having a valid token.
     *
     * What it cannot prove is that the app is a member of the channel this
     * account names, because nothing short of posting could. So the string it
     * returns says what was checked — the bot and the workspace — and does not
     * imply the channel was. `TelegramPublisher::verify()` draws the same line
     * for the same reason.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->accepted($this->send(
            $this->request()->asForm()->withToken($this->require($account, 'bot_token')),
            'post',
            self::API.'/auth.test',
        ));

        $user = $response['user'] ?? null;
        $team = $response['team'] ?? null;

        if (! is_string($user) || $user === '') {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no bot came back');
        }

        return is_string($team) && $team !== ''
            ? '@'.$user.' in '.$team
            : '@'.$user;
    }

    /**
     * The channel this account posts into, as `chat.postMessage` wants it.
     *
     * A name with its hash and a channel ID are both accepted by Slack and the
     * catalogue's hint asks for either, so nothing here rewrites what was
     * typed — a `C0123456789` that got its hash added would stop resolving.
     * The only thing worth refusing is an empty one: `chat.postMessage` has no
     * default channel, and a missing `channel` is a `400` that reads like the
     * message itself was wrong.
     *
     * @throws PublishFailed
     */
    private function channel(SocialAccount $account): string
    {
        $channel = trim($this->require($account, 'channel'));

        if ($channel === '' || $channel === '#') {
            throw PublishFailed::rejected(
                $this->network(),
                'no channel is set on this account and chat.postMessage has no default, so there was nowhere to post',
            );
        }

        return $channel;
    }

    /**
     * The message as blocks: the copy, then one `image` block per picture.
     *
     * `alt_text` is required by Slack rather than optional, and it is the
     * attachment's own name rather than a placeholder — it is what a screen
     * reader announces and what shows when the fetch fails, and 'image' would
     * be worse than nothing.
     *
     * An empty section is not sent. Slack refuses a `section` whose text is the
     * empty string, and a picture-only post is a legitimate thing to make.
     *
     * @param  list<MediaItem>  $media
     * @return list<array<string, mixed>>
     *
     * @throws PublishFailed
     */
    private function blocks(string $body, array $media): array
    {
        $blocks = [];

        if (trim($body) !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $body]];
        }

        foreach ($media as $item) {
            $blocks[] = [
                'type' => 'image',
                'image_url' => $this->fetchableUrl($item),
                'alt_text' => $item->name,
            ];
        }

        return $blocks;
    }

    /**
     * A URL for one image that Slack's own servers can fetch.
     *
     * The same requirement Instagram and Threads have, arrived at from the
     * opposite direction: they cannot take bytes, and Slack can only take them
     * through an upload flow Kargah cannot afford. Either way the picture has
     * to be reachable from outside this install, and
     * `AttachmentService::publicUrl()` answers with a signed, expiring link on
     * `data.file-share` — a route that has always sat outside `auth` behind
     * `signed` middleware. The default half-hour window is left alone: Slack
     * fetches during the call being made right now, and the value of an expiry
     * is that a link which leaks stops working.
     *
     * @throws PublishFailed
     */
    private function fetchableUrl(MediaItem $item): string
    {
        if (($reason = $this->unreachableInstallReason()) !== null) {
            throw PublishFailed::rejected($this->network(), $reason);
        }

        $url = app(AttachmentService::class)->publicUrl($item->id);

        if ($url === null) {
            throw PublishFailed::rejected(
                $this->network(),
                'the image “'.$item->name.'” is recorded against this post but its attachment row is gone, '
                .'and Slack fetches the picture itself rather than being sent it',
            );
        }

        return $url;
    }

    /**
     * Why Slack could not fetch anything from this install, or null if it could.
     *
     * The judgement itself is `FetchesOwnMedia`'s, shared with Meta and with the
     * two Blog drivers — this driver had a verbatim copy of it and said so, and
     * that note is now spent. What stays here is the part that was always
     * Slack's: the closing clause about the copy having gone out on its own,
     * which is true of Slack and false of Instagram, where no picture means no
     * post at all.
     */
    private function unreachableInstallReason(): ?string
    {
        $reason = $this->fetchGuardReason('Slack');

        return $reason === null
            ? null
            : $reason.'. The copy on its own would have gone out from here';
    }

    /**
     * The body, once `ok` has been believed rather than the status code.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function accepted(array $body): array
    {
        if (($body['ok'] ?? false) === true) {
            return $body;
        }

        $error = $body['error'] ?? null;

        throw PublishFailed::rejected($this->network(), $this->reasonFor(is_string($error) ? $error : '', $body));
    }

    /**
     * Slack's machine token, as a sentence somebody can act on.
     *
     * Only the ones with a next action get their own wording. Everything else
     * is quoted verbatim rather than paraphrased — a code this does not know is
     * still worth putting in front of a person, because it is searchable, and
     * inventing a friendly sentence for it would be guessing at Slack's meaning.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function reasonFor(string $error, array $body = []): string
    {
        return match ($error) {
            // By a distance the most common failure on this network, and the
            // one whose fix is least guessable: installing an app to a
            // workspace does not put it in any channel.
            'not_in_channel' => 'the app is not a member of that channel. Open the channel in Slack and type '
                .'/invite followed by the app’s name — installing the app to the workspace does not join it to '
                .'anything, and this is the one setup step that has to happen inside Slack',

            'channel_not_found' => 'Slack has no channel by that name that this app can see. Check the spelling on '
                .'this account’s connect page, and note that a private channel does not exist as far as an app is '
                .'concerned until somebody invites it to that channel',

            'is_archived' => 'that channel is archived, so nothing can be posted into it until it is unarchived',

            'invalid_auth', 'not_authed', 'token_revoked', 'account_inactive' => 'Slack refused the bot token. It has '
                .'been revoked, or the app was reinstalled and issued a new one — copy the current Bot User OAuth '
                .'Token from the app’s OAuth & Permissions screen and paste it on this account’s connect page',

            'missing_scope' => 'the token does not carry the scope this needs'
                .$this->scopeDetail($body)
                .'. Add chat:write under OAuth & Permissions, reinstall the app to the workspace, and paste the new '
                .'token — a scope added without reinstalling does not reach the token you already have',

            'ratelimited', 'rate_limited' => 'Slack is rate limiting this app, so nothing was posted. The post can be '
                .'retried in a minute',

            'msg_too_long' => 'Slack refused the message as too long. Kargah caps this network at '
                .Networks::limit($this->network()).' characters, which is the block limit rather than the plain-text '
                .'one, so a refusal here means the copy grew between being written and being sent',

            'invalid_blocks', 'invalid_blocks_format' => 'Slack refused the image blocks. The usual cause is a picture '
                .'URL its servers could not load — this install has to be reachable from the internet for a Slack '
                .'post with pictures in it, the same as Instagram',

            '' => 'Slack answered ok: false without naming an error, so there is nothing to say about why',

            default => 'Slack refused it with “'.mb_substr($error, 0, 100).'”',
        };
    }

    /**
     * The scopes Slack named, when it named any.
     *
     * `missing_scope` comes with `needed` and `provided`, and the difference
     * between them is the whole answer — quoting it saves a trip to the app's
     * settings screen to work out which one is absent.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function scopeDetail(array $body): string
    {
        $needed = $body['needed'] ?? null;

        return is_string($needed) && $needed !== ''
            ? ' — it wants '.mb_substr($needed, 0, 100)
            : '';
    }

    /**
     * A genuine non-200 still puts its reason in the same place.
     *
     * `429` and the occasional `5xx` are the only statuses Slack answers with in
     * practice, and a `429` carries `{"ok": false, "error": "ratelimited"}` like
     * every other refusal. The inherited reader would find the raw token there
     * and print it; this runs the same translation the 200-with-`ok: false` path
     * does, so the two spellings of one refusal read identically.
     */
    protected function detailFrom(Response $response): string
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        if (is_string($error) && $error !== '') {
            return mb_substr($this->reasonFor($error, is_array($body) ? $body : []), 0, 300);
        }

        return parent::detailFrom($response);
    }
}
