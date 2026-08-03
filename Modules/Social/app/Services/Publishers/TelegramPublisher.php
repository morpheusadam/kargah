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

        $response = $this->send(
            $this->request(),
            'post',
            self::HOST.'/bot'.$token.'/sendMessage',
            ['chat_id' => $chat, 'text' => $body, 'disable_web_page_preview' => false],
        );

        if (($response['ok'] ?? false) !== true) {
            $reason = $response['description'] ?? 'the Bot API answered ok: false without saying why';

            throw PublishFailed::rejected($this->network(), is_string($reason) ? $reason : 'the send was refused');
        }

        $messageId = $response['result']['message_id'] ?? null;

        if (! is_scalar($messageId) || (string) $messageId === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no message id');
        }

        return new PublishedPost((string) $messageId, $this->webUrl($response, $chat, (string) $messageId));
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
     * @param  array<array-key, mixed>  $response
     */
    private function webUrl(array $response, string $chat, string $messageId): ?string
    {
        $username = $response['result']['chat']['username'] ?? null;

        if (! is_string($username) || $username === '') {
            $username = str_starts_with($chat, '@') ? ltrim($chat, '@') : null;
        }

        return $username === null ? null : 'https://t.me/'.$username.'/'.$messageId;
    }
}
