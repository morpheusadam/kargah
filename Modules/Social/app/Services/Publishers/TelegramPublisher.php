<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Telegram, through a bot you own.
 *
 * Deliberately does **not** implement `IngestsNotifications`. The Bot API's only
 * read is `getUpdates`, which *consumes* the update queue — calling it here
 * would take messages the bot itself is meant to handle and leave a webhook
 * consumer with nothing. Kargah skips this network in
 * `social:sync-notifications` rather than quietly breaking the bot.
 *
 * The Bot API answers HTTP 200 with `ok: false` for a refused send, so a
 * successful status code is not evidence the message went anywhere and the body
 * is checked as well.
 *
 * 🔴 **The bot token is a path segment: `/bot<token>/sendMessage`.** It is not a
 * header and not a parameter, and the Bot API has no other way to address a bot
 * — so every request this class makes carries a working credential in its URL,
 * and anything that copies a request URL somewhere a person can read it publishes
 * that credential. `HttpPublisher::cannotReach()` is what keeps a timed-out send
 * from doing exactly that, and the general rule and its reasoning live there; VK
 * had the same exposure and could fix it in its own driver by moving the token
 * into a form body, which is not available here. Two things follow for whoever
 * moves this network to a different transport: a request URL built here may
 * never be logged, echoed into an exception, or shown, and the replacement has
 * to solve that itself before the first send — it cannot be assumed, because
 * every other network in this directory authenticates in a header and does not
 * have this problem.
 *
 * **Pictures: a different endpoint, not an extra field.** Telegram is the one
 * network here where attaching an image stops `publish()` calling the method it
 * otherwise calls. There are three shapes and the count decides which:
 *
 * - none — `sendMessage`, as before;
 * - one — `sendPhoto`, multipart, the copy travelling as `caption`;
 * - two to ten — `sendMediaGroup`, multipart, every file attached under its own
 *   part name and a JSON `media` array referring to them by `attach://<part>`.
 *   The caption belongs on the **first** item only; repeating it captions every
 *   photo in the album.
 *
 * Two consequences worth having in one place. `sendMediaGroup` answers with an
 * *array* of messages rather than one, so the id recorded against the target is
 * the first of them — the album's anchor, and the message a `t.me` link opens.
 * And a caption is 1,024 characters where a message is 4,096, so attaching a
 * picture shortens the post; that number lives in the catalogue as
 * `caption_limit` and `HttpPublisher::bodyWithin()` enforces it rather than
 * truncating somebody's last sentence at send time.
 *
 * Eleven images is not a bigger album; it is a request the Bot API does not
 * have. The catalogue caps it at ten and the composer says so while attaching.
 */
class TelegramPublisher extends HttpPublisher
{
    private const HOST = 'https://api.telegram.org';

    public function network(): string
    {
        return Networks::TELEGRAM;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $token = $this->require($account, 'bot_token');
        $chat = $this->require($account, 'chat_id');
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $response = match (true) {
            $media === [] => $this->sendText($token, $chat, $body),
            count($media) === 1 => $this->sendPhoto($token, $chat, $body, $media[0]),
            default => $this->sendAlbum($token, $chat, $body, $media),
        };

        if (($response['ok'] ?? false) !== true) {
            $reason = $response['description'] ?? 'the Bot API answered ok: false without saying why';

            throw PublishFailed::rejected($this->network(), is_string($reason) ? $reason : 'the send was refused');
        }

        // `sendMediaGroup` answers with a list of messages and the other two
        // with one. The album's first message is its anchor — the one a t.me
        // link opens — so both shapes reduce to a single result here.
        $result = $response['result'] ?? null;
        $result = is_array($result) && array_is_list($result) ? ($result[0] ?? null) : $result;

        $messageId = is_array($result) ? ($result['message_id'] ?? null) : null;

        if (! is_scalar($messageId) || (string) $messageId === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no message id');
        }

        return new PublishedPost(
            (string) $messageId,
            $this->webUrl(is_array($result) ? $result : [], $chat, (string) $messageId),
        );
    }

    /**
     * The text-only send, unchanged from before pictures existed.
     *
     * @return array<array-key, mixed>
     */
    private function sendText(string $token, string $chat, string $body): array
    {
        return $this->send(
            $this->request(),
            'post',
            self::HOST.'/bot'.$token.'/sendMessage',
            ['chat_id' => $chat, 'text' => $body, 'disable_web_page_preview' => false],
        );
    }

    /**
     * One picture, captioned.
     *
     * @return array<array-key, mixed>
     */
    private function sendPhoto(string $token, string $chat, string $body, MediaItem $item): array
    {
        return $this->sendMultipart(
            $this->uploadRequest(),
            self::HOST.'/bot'.$token.'/sendPhoto',
            ['photo' => [$item->filename(), $item->contents(), $item->mime]],
            ['chat_id' => $chat, 'caption' => $body],
        );
    }

    /**
     * Two to ten pictures as one album.
     *
     * The files are parts of the request and the `media` field is a JSON string
     * that points at them by part name — `attach://file0`. Both halves have to
     * agree, which is why the loop builds them together rather than in two
     * passes.
     *
     * @param  list<MediaItem>  $media
     * @return array<array-key, mixed>
     */
    private function sendAlbum(string $token, string $chat, string $body, array $media): array
    {
        $files = [];
        $descriptors = [];

        foreach ($media as $index => $item) {
            $part = 'file'.$index;

            $files[$part] = [$item->filename(), $item->contents(), $item->mime];

            $descriptors[] = [
                'type' => 'photo',
                'media' => 'attach://'.$part,
            ]
                // The caption goes on the first item and nowhere else: repeated,
                // Telegram captions every photo in the album with it.
                + ($index === 0 ? ['caption' => $body] : []);
        }

        return $this->sendMultipart(
            $this->uploadRequest(),
            self::HOST.'/bot'.$token.'/sendMediaGroup',
            $files,
            [
                'chat_id' => $chat,
                // A JSON string in a multipart field, which is what the Bot API
                // documents for this parameter — not a nested form array.
                'media' => (string) json_encode($descriptors),
            ],
        );
    }

    /**
     * `getMe` names the bot the token belongs to.
     *
     * It does not prove the bot is an administrator of the chat, and nothing
     * short of sending a message would — so the page says which of the two this
     * checked rather than implying both.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->send(
            $this->request(),
            'get',
            self::HOST.'/bot'.$this->require($account, 'bot_token').'/getMe',
        );

        if (($response['ok'] ?? false) !== true) {
            throw PublishFailed::rejected($this->network(), 'the Bot API did not recognise that token');
        }

        $username = $response['result']['username'] ?? null;

        if (! is_string($username) || $username === '') {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no bot came back');
        }

        return '@'.$username;
    }

    /**
     * A link, but only when there is one to give.
     *
     * A public channel has a username and `t.me/<username>/<id>` opens the
     * message. A private group has neither, and the honest answer there is no
     * url at all — the page renders an em dash rather than a link that 404s.
     *
     * Takes the message itself rather than the whole envelope, because
     * `sendMediaGroup` has no single `result` to reach into — the caller has
     * already picked the album's first message out of the list.
     *
     * @param  array<array-key, mixed>  $message
     */
    private function webUrl(array $message, string $chat, string $messageId): ?string
    {
        $username = $message['chat']['username'] ?? null;

        if (! is_string($username) || $username === '') {
            $username = str_starts_with($chat, '@') ? ltrim($chat, '@') : null;
        }

        return $username === null ? null : 'https://t.me/'.$username.'/'.$messageId;
    }
}
