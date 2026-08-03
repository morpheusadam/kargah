<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Discord, through an incoming webhook on one channel.
 *
 * **Why a webhook URL rather than a bot token and a channel id.** Both work, and
 * a bot token would be the closer copy of `TelegramPublisher` — but the two
 * credentials are not equally cheap to obtain, and Kargah's connect page is
 * built entirely around a credential the network's own settings screen hands
 * over in one gesture. A Discord bot token is three gestures: create an
 * application in the developer portal, add a bot to it, then build an OAuth2
 * invite URL with the right permissions integer and walk it to add the bot to
 * the server. Only after all of that does anyone have a channel id to paste
 * alongside it. A webhook is one: channel settings → Integrations → Webhooks →
 * Copy Webhook URL. It needs no application, no bot invite and no gateway
 * connection — which matters here, because a gateway is a long-lived socket and
 * shared hosting is the one thing Kargah is not allowed to need. It also grants
 * strictly less: a webhook can write into exactly one channel and can read
 * nothing at all, which is a better answer to the connect page's 'what will
 * Kargah be able to do' panel than a bot account with server-wide scope.
 *
 * The cost of that choice is honest and small: one webhook is one channel, so
 * posting to a second channel means a second connected account rather than a
 * second chat id on the same one. That is how `social_accounts` already models
 * a connection, so it costs nothing structurally.
 *
 * **The URL is the whole secret.** A Discord webhook URL ends in its own token
 * and anyone holding it can post to that channel, so it is stored encrypted and
 * marked `secret` in the catalogue like any other credential — not treated as a
 * harmless address because it happens to be shaped like one.
 *
 * **`?wait=true` is load-bearing.** Executing a webhook answers `204 No Content`
 * by default — a success with no body, and therefore no message id to record
 * against the target. With `wait=true` Discord holds the response until the
 * message is actually saved and returns the message object, which is both the
 * id `PostTarget` needs and real evidence of delivery rather than an
 * acknowledgement that it was queued.
 *
 * Deliberately does **not** implement `IngestsNotifications`: a webhook has no
 * read side whatsoever. `social:sync-notifications` skips this network by name,
 * the same as LinkedIn and Telegram.
 *
 * **Pictures: the same endpoint, a different body.** Discord is the only network
 * here where attaching an image changes neither the URL nor the number of calls
 * — the webhook execute simply stops being JSON and becomes multipart, with the
 * message that was the whole JSON body moving into a single `payload_json` part
 * and each file arriving as `files[0]`, `files[1]` and so on. All ten go up in
 * one request, which makes this the cheapest media path of the five and the only
 * one where a failure is genuinely all-or-nothing rather than leaving orphaned
 * uploads behind.
 *
 * The part names are not decorative. Discord matches `files[n]` against the
 * `attachments` array by index when one is supplied, and rejects parts it cannot
 * place; naming them `file` or `image` produces a 400 that reads like the
 * message was refused.
 *
 * `allowed_mentions` is deliberately left at Discord's default, which honours
 * whatever the body says. Quietly stripping an `@everyone` somebody typed would
 * be Kargah overruling the author of the post, and the composer's per-network
 * override already exists for the case where Discord should say something
 * different from the other networks.
 */
class DiscordPublisher extends HttpPublisher
{
    /**
     * Hosts a webhook URL is allowed to point at.
     *
     * This is a guard, not a formality. The credential is a whole URL supplied
     * by a person and used verbatim as a request target, so without this a
     * mistyped or pasted-from-the-wrong-tab value would have Kargah POST the
     * body of a post to an arbitrary host. Every network here builds its URL
     * from a constant; this one has to earn the same property by checking.
     *
     * @var list<string>
     */
    private const HOSTS = ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'];

    /** `/api/webhooks/<id>/<token>`, with or without an explicit API version. */
    private const PATH = '#^/api(/v\d{1,2})?/webhooks/\d+/[A-Za-z0-9_.-]+$#';

    public function network(): string
    {
        return Networks::DISCORD;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        // `?wait=true` matters just as much on the multipart path: without it
        // Discord answers 204 with no body, and there would be no message id to
        // record against the target. See the class docblock.
        $url = $this->endpoint($account).'?wait=true';

        $response = $media === []
            ? $this->send($this->request(), 'post', $url, ['content' => $body])
            : $this->sendWithFiles($url, $body, $media);

        $id = $response['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no message id');
        }

        return new PublishedPost((string) $id, $this->webUrl($response, (string) $id));
    }

    /**
     * The message and every file, in one multipart request.
     *
     * `payload_json` carries what would otherwise be the whole JSON body. It is
     * one field holding an encoded object rather than flattened form fields,
     * because Discord's webhook execute has no form representation — the
     * multipart shape is the JSON shape with the files bolted alongside it.
     *
     * @param  list<MediaItem>  $media
     * @return array<array-key, mixed>
     */
    private function sendWithFiles(string $url, string $body, array $media): array
    {
        $files = [];

        foreach ($media as $index => $item) {
            $files['files['.$index.']'] = [$item->filename(), $item->contents(), $item->mime];
        }

        return $this->sendMultipart(
            $this->uploadRequest(),
            $url,
            $files,
            ['payload_json' => (string) json_encode(['content' => $body])],
        );
    }

    /**
     * A `GET` on the webhook URL names the webhook and the channel it posts to.
     *
     * It posts nothing, and it is the only read a webhook credential can do at
     * all. It proves the URL is live and points where the person thinks it
     * does — it cannot prove more than that, because there is no more.
     *
     * The response also contains the webhook's own token. Only the name and the
     * channel are echoed back; nothing that could put the secret on the page.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->send($this->request(), 'get', $this->endpoint($account));

        $name = $response['name'] ?? null;
        $channel = $response['channel_id'] ?? null;

        if (! is_string($name) || $name === '') {
            throw PublishFailed::malformed($this->network(), 'the URL was accepted but no webhook came back');
        }

        return is_scalar($channel) && (string) $channel !== ''
            ? 'the “'.$name.'” webhook, posting to channel '.$channel
            : 'the “'.$name.'” webhook';
    }

    /**
     * The pasted URL, checked and stripped back to the webhook itself.
     *
     * Any query string the person copied along with it is dropped, because
     * `publish()` appends its own and two `?` in one URL is a 400 that reads
     * like Discord rejected the post.
     *
     * @throws PublishFailed
     */
    private function endpoint(SocialAccount $account): string
    {
        $url = trim($this->require($account, 'webhook_url'));

        $parts = parse_url($url);

        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $path = is_array($parts) ? rtrim((string) ($parts['path'] ?? ''), '/') : '';

        if (! is_string($host) || ! in_array(strtolower($host), self::HOSTS, true)) {
            throw PublishFailed::rejected($this->network(), 'that is not a discord.com webhook URL, so nothing was sent to it');
        }

        if (preg_match(self::PATH, $path) !== 1) {
            throw PublishFailed::rejected($this->network(), 'the webhook URL is missing its id or token — copy the whole thing from the channel’s Integrations screen');
        }

        return 'https://'.strtolower($host).$path;
    }

    /**
     * A link to the message, but only when Discord gave enough to build one.
     *
     * A jump link is `/channels/<guild>/<channel>/<message>` and the guild id is
     * the part a webhook execute does not reliably return. Rather than fetch the
     * webhook a second time on every publish to learn it, this uses the id when
     * it is there and gives no url when it is not — the same choice
     * `TelegramPublisher` makes for a private chat, and the posts page renders
     * an em dash rather than a link that goes nowhere.
     *
     * @param  array<array-key, mixed>  $response
     */
    private function webUrl(array $response, string $messageId): ?string
    {
        $guild = $response['guild_id'] ?? null;
        $channel = $response['channel_id'] ?? null;

        if (! is_scalar($guild) || (string) $guild === '' || ! is_scalar($channel) || (string) $channel === '') {
            return null;
        }

        return 'https://discord.com/channels/'.$guild.'/'.$channel.'/'.$messageId;
    }
}
