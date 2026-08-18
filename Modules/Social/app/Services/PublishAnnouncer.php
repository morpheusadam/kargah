<?php

namespace Modules\Social\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Support\Networks;

/**
 * Tell the operator, on Telegram, that a post has actually gone out.
 *
 * **Why this exists.** The curator publishes at an hour chosen at random inside a
 * window, with nobody present — that is the design, and the cost of it is that
 * the owner has no idea anything happened until they open the panel. A post going
 * out is the one moment worth interrupting somebody for, and it is also the only
 * moment at which the thing they would want to check is one tap away.
 *
 * ---
 *
 * ## What the message is, and what it deliberately is not
 *
 * **No emoji.** Asked for, and right: this is a record of something that
 * happened, and a coloured pictogram in front of it makes it look like marketing
 * rather than a log. The structure carries the meaning instead — a rule, a
 * headline, the copy, then the facts in a row.
 *
 * **The picture is the post's own.** Not a template, not a card with text drawn
 * on it — the same image the network received, so the notification is a preview
 * of the post rather than a description of one. Sent with `sendPhoto` when there
 * is one, which is also why the caption is written to 1,024 characters rather
 * than 4,096: a Telegram caption is not a message, and discovering that at send
 * time is a 400.
 *
 * **One message per post, not one per network.** A post that reached three
 * networks is one thing that happened. Three notifications for it would be the
 * quickest way to make somebody mute the bot, which would cost the one case this
 * exists for — the failure nobody was watching.
 *
 * ## Failure is silent, on purpose
 *
 * Nothing here may throw into the publish path. The post has already gone out by
 * the time this runs; a bot token that has been revoked must not turn a
 * successful publish into a failed job, and must certainly not cause a retry that
 * publishes it a second time. Every failure is logged and swallowed.
 */
class PublishAnnouncer
{
    /** A Telegram photo caption, which is not a message and is a quarter the size. */
    private const CAPTION_LIMIT = 1024;

    private const MESSAGE_LIMIT = 4096;

    /** Long enough for a slow Telegram, short enough not to hold a cron minute. */
    private const TIMEOUT = 15;

    public function __construct(private readonly PostMedia $media) {}

    /**
     * Announce a post, if there is anywhere to announce it to.
     *
     * Called after a publish run rather than after each target, and only when
     * something was actually published — a run that found nothing to do is not an
     * event.
     */
    public function announce(Post $post): void
    {
        $settings = CurationSetting::current();

        if (! $settings->canNotify()) {
            return;
        }

        $published = $post->targets()->with('account')->get()
            ->filter(fn (PostTarget $target): bool => $target->isPublished());

        if ($published->isEmpty()) {
            return;
        }

        try {
            $this->send($settings, $post, $published->all());
        } catch (\Throwable $e) {
            // The post is already out. Nothing that happens here may undo that,
            // fail the job, or provoke a retry that publishes it twice.
            Log::warning('social: the publish announcement could not be sent. '.$e->getMessage());
        }
    }

    /**
     * Send one now, from the settings page, to prove the pair works.
     *
     * The whole value of this feature is that it speaks when nobody is watching,
     * which is also why a wrong chat id would go unnoticed for days. This is the
     * only path that reaches Telegram without a post behind it.
     */
    public function test(): bool
    {
        $settings = CurationSetting::current();

        if (! $settings->canNotify()) {
            return false;
        }

        return $this->call($settings, 'sendMessage', [
            'chat_id' => $settings->notify_chat_id,
            'parse_mode' => 'HTML',
            'text' => '<b>PUBLISHED</b>'."\n".str_repeat('─', 18)."\n\n"
                .'<b>This is where a post will appear.</b>'."\n\n"
                .'Kargah will send one of these each time something is published, with the '
                .'picture that went out and the opening of the copy.'."\n\n"
                .'<i>Test  ·  '.now()->format('H:i').'</i>',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    /**
     * @param  list<PostTarget>  $published
     */
    private function send(CurationSetting $settings, Post $post, array $published): void
    {
        $image = $this->imageUrl($post);
        $text = $this->compose($post, $published, $image !== null);

        $payload = [
            'chat_id' => $settings->notify_chat_id,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $this->buttons($published)],
        ];

        $method = 'sendMessage';

        if ($image !== null) {
            $method = 'sendPhoto';
            $payload['photo'] = $image;
            $payload['caption'] = $text;
        } else {
            $payload['text'] = $text;
            // Nothing here should generate a second preview card: the post's own
            // link is on a button, and a preview of it under the copy would
            // duplicate the whole message.
            $payload['link_preview_options'] = ['is_disabled' => true];
        }

        $response = $this->call($settings, $method, $payload);

        // A photo can be refused for reasons the copy cannot fix — the network's
        // CDN blocking Telegram's fetcher is the usual one. Falling back to text
        // means the operator still hears about the post, which is the point;
        // silently losing the notification because the picture was awkward is
        // the failure worth avoiding.
        if ($response === false && $method === 'sendPhoto') {
            $this->call($settings, 'sendMessage', [
                'chat_id' => $settings->notify_chat_id,
                'parse_mode' => 'HTML',
                'text' => $this->compose($post, $published, false),
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => $this->buttons($published)],
            ]);
        }
    }

    /**
     * The message.
     *
     * Shaped rather than templated, and the shape is the whole of the "creative"
     * brief: a rule to separate it from whatever is above it in the chat, the
     * headline on its own line where the eye lands first, the opening of the copy
     * so it is a preview and not a receipt, then the measurable facts on one row
     * because they are reference rather than reading.
     *
     * @param  list<PostTarget>  $published
     */
    private function compose(Post $post, array $published, bool $withImage): string
    {
        $limit = $withImage ? self::CAPTION_LIMIT : self::MESSAGE_LIMIT;

        [$headline, $body] = $this->split($post->body);

        $networks = implode(' · ', array_map(
            fn (PostTarget $t): string => Networks::all()[$t->account?->network ?? '']['label']
                ?? ($t->account?->network ?? 'unknown'),
            $published,
        ));

        $when = ($published[0]->published_at ?? now())->format('H:i');

        $facts = implode('  ·  ', array_filter([
            $networks,
            $when,
            mb_strlen($post->body).' characters',
            $this->hashtagCount($post->body) > 0
                ? $this->hashtagCount($post->body).' hashtags'
                : null,
        ]));

        // The head and the foot are fixed; only the body is negotiable, so the
        // room left for it is measured rather than guessed at.
        $head = '<b>PUBLISHED</b>'."\n".str_repeat('─', 18)."\n\n";
        $foot = "\n\n".'<i>'.e($facts).'</i>';

        $room = $limit - mb_strlen(strip_tags($head)) - mb_strlen(strip_tags($foot)) - mb_strlen($headline) - 8;

        return $head
            .'<b>'.e($headline).'</b>'
            .($body === '' ? '' : "\n\n".e($this->trim($body, max(60, $room))))
            .$foot;
    }

    /**
     * Headline and remainder.
     *
     * The copy the networks receive already opens with its own strongest line —
     * the prompt insists on it, because Instagram shows 125 characters before
     * "more" and LinkedIn about 140. So the first line is the headline here too,
     * and no second title has to be invented for this message.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $body): array
    {
        $body = trim($body);

        // A first line that is really a whole paragraph is cut at the first
        // sentence, so the headline stays a headline.
        $firstBreak = mb_strpos($body, "\n");
        $head = $firstBreak === false ? $body : mb_substr($body, 0, $firstBreak);

        if (mb_strlen($head) > 90) {
            $stop = mb_strpos($head, '. ');
            $head = $stop !== false && $stop > 20
                ? mb_substr($head, 0, $stop + 1)
                : mb_substr($head, 0, 90).'…';
        }

        $rest = trim(mb_substr($body, mb_strlen($head)));

        // The hashtag block belongs to the post, not to this message: it is the
        // least informative part of the copy and would eat the preview.
        $rest = trim((string) preg_replace('/(?:^|\n)\s*(?:#[^\s#]+\s*)+$/u', '', $rest));

        return [$head, $rest];
    }

    /** Word-boundary trim, because a Persian sentence cut mid-word reads as a bug. */
    private function trim(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $limit * 0.6 ? mb_substr($cut, 0, $space) : $cut).'…';
    }

    private function hashtagCount(string $body): int
    {
        return preg_match_all('/(?:^|\s)#[^\s#]+/u', $body) ?: 0;
    }

    /**
     * One button per network that has a public URL to open.
     *
     * `url` buttons rather than `callback_data`, for the reason the publishing
     * side already documents: a callback needs something alive to answer it, and
     * this application exists for the sixty seconds a cron job takes.
     *
     * @param  list<PostTarget>  $published
     * @return list<list<array{text: string, url: string}>>
     */
    private function buttons(array $published): array
    {
        $rows = [];

        foreach ($published as $target) {
            $url = trim((string) $target->remote_url);

            if ($url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }

            $label = Networks::all()[$target->account?->network ?? '']['label']
                ?? ($target->account?->network ?? 'Open');

            $rows[] = [['text' => 'View on '.$label, 'url' => $url]];
        }

        return $rows;
    }

    /**
     * The post's own picture, as a URL Telegram can fetch.
     *
     * Telegram fetches a photo by URL itself rather than being handed bytes,
     * which is why this needs a publicly reachable address and not a local path.
     * Attachments behind Kargah's own auth are therefore no use here, so a post
     * whose only image is a private attachment is announced without one rather
     * than with a broken link.
     */
    private function imageUrl(Post $post): ?string
    {
        foreach ($this->media->attachmentsFor($post) as $attachment) {
            $url = $attachment['inline_url'] ?? $attachment['url'] ?? null;

            if (is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
        }

        return null;
    }

    /**
     * One Bot API call. False when it was refused, so the caller can fall back.
     *
     * @param  array<string, mixed>  $payload
     */
    private function call(CurationSetting $settings, string $method, array $payload): bool
    {
        $endpoint = 'https://api.telegram.org/bot'.$settings->notify_bot_token.'/'.$method;

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(5)->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            // 🔴 Never the raw message. The bot token is in the URL — Telegram's
            // Bot API has no other shape — and a connection error quotes the URL
            // it was given. The same disclosure happened through the assistant's
            // Gemini driver on 18 August 2026 and cost a real key; see
            // `Modules\Platform\Services\Assistant\HttpAssistantDriver::withoutSecrets()`.
            Log::warning('social: Telegram could not be reached for the publish announcement.');

            return false;
        }

        if ($response->failed()) {
            // `description` rather than the body: Telegram echoes nothing secret
            // in it, and it names the actual problem ("chat not found").
            Log::warning('social: Telegram refused the publish announcement. '
                .(string) $response->json('description', 'no reason given'));

            return false;
        }

        return true;
    }
}
