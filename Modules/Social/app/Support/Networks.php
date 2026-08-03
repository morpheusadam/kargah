<?php

namespace Modules\Social\Support;

/**
 * The networks Kargah can publish to, and everything the pages need to draw one.
 *
 * This is a code constant rather than a config entry because none of it is an
 * install decision: the character limit is the network's, the icon has to be a
 * name that exists in the keenicons bundle, and the credential field names are
 * what the driver goes looking for. Config holds what differs per install —
 * the Mastodon instance, an API base — and nothing else.
 *
 * Every class string here is written out whole. Tailwind's scanner reads source
 * text, so a tone built as `'bg-'.$key` would be a class nobody generated; see
 * docs/frontend-conventions.md.
 *
 * `ingests` says whether the network's API lets Kargah read notifications back.
 * Mastodon and Bluesky publish a notifications endpoint that needs no special
 * partnership. LinkedIn's requires partner access nobody self-serving has,
 * Telegram's `getUpdates` consumes the update queue the bot itself needs, and a
 * Discord incoming webhook has no read side at all, so those three are marked
 * false and `social:sync-notifications` skips them rather than pretending there
 * is nothing to show.
 *
 * `token_lifetime_days` is null for every credential that does not expire on
 * its own — Mastodon, Bluesky, Telegram and Discord all issue a scoped,
 * revocable token from the network's own settings screen with no clock
 * attached; the
 * person revokes it, Kargah does not out-wait it. LinkedIn's member token is
 * the one exception Kargah has today, and the pasted-token model means there
 * is no OAuth response to read a real expiry off — the credential arrives
 * over `⚡account-connect`'s form, not a token exchange this application ever
 * sees. `social:check-token-expiry` therefore infers `token_expires_at` from
 * this lifetime, counted from the moment the credential was saved. That is an
 * approximation — the true clock started whenever LinkedIn actually issued
 * the token, which may be minutes or days before it was pasted here — and it
 * is the same shortcut the constraint that ruled out OAuth (see
 * `08-postiz-parity.md`) makes unavoidable: a value that reads slightly early
 * beats a column that never gets a value at all.
 *
 * `media` is the picture pipeline's half of the same idea: a network's own
 * limits, written down here rather than discovered from a 422 an hour after the
 * person walked away from the composer. The composer checks an image against
 * every selected network's entry **before** a post row exists, so an oversized
 * file is a sentence on the page rather than a red target row later.
 *
 * **Images only, deliberately.** Every `mimes` list here is a still-image list,
 * and nothing in Kargah uploads video. That is not an omission: X's 1 MB
 * chunks, LinkedIn's 2 MB and YouTube's 8 MB resumable protocol all describe an
 * upload that can span minutes, and a Kargah publish is one HTTP job bounded by
 * `max_execution_time` on shared hosting. An image fits in that budget; a
 * chunked video does not, and pretending otherwise would produce a job that is
 * killed halfway with the post half-sent. Video is real, separate future work —
 * see `project-guaid/spec/08-postiz-parity.md`, *Media*.
 *
 * The numbers are the network's, not Kargah's, and they are deliberately the
 * conservative reading where a network's own documentation and its running
 * instances disagree — Mastodon most of all, where the size ceiling is an
 * instance setting rather than a protocol constant. Being told 8 MB and finding
 * an instance accepts 16 costs nothing; the reverse costs a failed post.
 */
final class Networks
{
    public const MASTODON = 'mastodon';

    public const BLUESKY = 'bluesky';

    public const LINKEDIN = 'linkedin';

    public const TELEGRAM = 'telegram';

    public const DISCORD = 'discord';

    /**
     * @return array<string, array{
     *     label: string,
     *     icon: string,
     *     tone: string,
     *     dot: string,
     *     colour: string,
     *     limit: int,
     *     method: string,
     *     summary: string,
     *     requirement: string,
     *     ingests: bool,
     *     token_lifetime_days: int|null,
     *     media: array{
     *         max_count: int,
     *         max_bytes: int,
     *         mimes: list<string>,
     *         max_pixels: int|null,
     *         max_dimension_sum: int|null,
     *         max_aspect_ratio: int|null,
     *         caption_limit: int|null,
     *         note: string
     *     },
     *     credentials: array<string, array{label: string, secret: bool, placeholder: string, hint: string}>,
     *     permissions: list<array{allowed: bool, text: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            self::MASTODON => [
                'label' => 'Mastodon',
                'icon' => 'ki-abstract-26',
                'tone' => 'text-info',
                'dot' => 'bg-info',
                'colour' => '#6364ff',
                'limit' => 500,
                'method' => 'token',
                'summary' => 'Post to your own instance and read mentions back.',
                'requirement' => 'Create an application under Preferences → Development on your instance, tick write:statuses and read:notifications, then paste the access token here.',
                'ingests' => true,
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 4,
                    // An instance setting, not a protocol constant. Eight
                    // megabytes is the value a stock install ships with; a
                    // large instance often raises it and a small one sometimes
                    // lowers it. Kargah checks against the stock number because
                    // there is no endpoint that reports the instance's own
                    // without a second round trip on every attach.
                    'max_bytes' => 8 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    // 3840 × 2160. Mastodon re-encodes anything larger and
                    // rejects what it cannot re-encode, so this is the number
                    // worth warning about rather than the width alone.
                    'max_pixels' => 8294400,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Up to four images. The size ceiling is set per instance; eight megabytes is what a stock install allows.',
                ],
                'credentials' => [
                    'instance' => [
                        'label' => 'Instance URL',
                        'secret' => false,
                        'placeholder' => 'https://mastodon.social',
                        'hint' => 'The server your account lives on, with the scheme.',
                    ],
                    'access_token' => [
                        'label' => 'Access token',
                        'secret' => true,
                        'placeholder' => 'Paste the application token',
                        'hint' => 'Stored encrypted. It is never rendered back into this page once saved.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Post a status when you press publish or when a scheduled post fires'],
                    ['allowed' => true, 'text' => 'Read mentions, follows and boosts so they appear in the unified feed'],
                    ['allowed' => false, 'text' => 'Read your direct messages or follow anyone on your behalf'],
                ],
            ],
            self::BLUESKY => [
                'label' => 'Bluesky',
                'icon' => 'ki-abstract-25',
                'tone' => 'text-primary',
                'dot' => 'bg-primary',
                'colour' => '#0085ff',
                'limit' => 300,
                'method' => 'token',
                'summary' => 'Publish to your feed and read replies and likes back.',
                'requirement' => 'Create an app password under Settings → App passwords. Your account password is not accepted and should not be pasted here.',
                'ingests' => true,
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 4,
                    // One million bytes, and it is the lexicon's own number
                    // rather than a server policy — `app.bsky.embed.images`
                    // declares maxSize on the blob. The official client resizes
                    // before uploading, which is why a photo straight off a
                    // phone is refused here and appears to work there.
                    'max_bytes' => 1000000,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Up to four images, each under one million bytes — the protocol refuses a larger blob outright, so resize before attaching.',
                ],
                'credentials' => [
                    'identifier' => [
                        'label' => 'Handle',
                        'secret' => false,
                        'placeholder' => 'you.bsky.social',
                        'hint' => 'The handle you sign in with, without the leading at sign.',
                    ],
                    'app_password' => [
                        'label' => 'App password',
                        'secret' => true,
                        'placeholder' => 'xxxx-xxxx-xxxx-xxxx',
                        'hint' => 'Stored encrypted. Revoking it in Bluesky is enough to cut Kargah off.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create posts on your behalf, including scheduled ones'],
                    ['allowed' => true, 'text' => 'Read replies, likes and reposts on posts Kargah created'],
                    ['allowed' => false, 'text' => 'Change your profile, follow accounts or delete existing posts'],
                ],
            ],
            self::LINKEDIN => [
                'label' => 'LinkedIn',
                'icon' => 'ki-abstract-41',
                'tone' => 'text-info',
                'dot' => 'bg-info',
                'colour' => '#0a66c2',
                'limit' => 3000,
                'method' => 'token',
                'summary' => 'Publish to your personal feed.',
                'requirement' => 'Create an app on the LinkedIn developer portal, request the w_member_social product, then paste the member access token and your member URN.',
                'ingests' => false,
                // The member token's real lifetime, per LinkedIn's own docs.
                // `social:check-token-expiry` counts it from when the
                // credential is saved, not from when LinkedIn actually issued
                // it — see the class docblock above for why that is the best
                // available approximation under the pasted-token model.
                'token_lifetime_days' => 60,
                'media' => [
                    'max_count' => 9,
                    'max_bytes' => 10 * 1024 * 1024,
                    // No WebP. LinkedIn's feedshare-image recipe accepts JPEG,
                    // PNG and GIF; a WebP upload registers, transfers, and then
                    // fails processing after the share has already been created,
                    // which is the worst shape of failure available here.
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Up to nine images. LinkedIn takes JPEG, PNG and GIF only — a WebP is accepted for upload and then fails processing.',
                ],
                'credentials' => [
                    'member_urn' => [
                        'label' => 'Member URN',
                        'secret' => false,
                        'placeholder' => 'urn:li:person:AbCdEfGh',
                        'hint' => 'Returned by the userinfo endpoint as the sub claim, prefixed with urn:li:person:.',
                    ],
                    'access_token' => [
                        'label' => 'Access token',
                        'secret' => true,
                        'placeholder' => 'Paste the member access token',
                        'hint' => 'Stored encrypted. LinkedIn tokens expire after sixty days and have to be replaced here.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create posts when you press publish or when a scheduled post fires'],
                    ['allowed' => false, 'text' => 'Read your connections, messages or feed'],
                    ['allowed' => false, 'text' => 'Read notifications — LinkedIn does not expose them without partner access'],
                ],
            ],
            self::TELEGRAM => [
                'label' => 'Telegram',
                'icon' => 'ki-paper-plane',
                'tone' => 'text-info',
                'dot' => 'bg-info',
                'colour' => '#0088cc',
                'limit' => 4096,
                'method' => 'token',
                'summary' => 'Post to a channel or group through a bot you own.',
                'requirement' => 'Create a bot with @BotFather, add it to the channel as an administrator, then paste its token and the chat it should post to.',
                'ingests' => false,
                'token_lifetime_days' => null,
                'media' => [
                    // One photo is `sendPhoto`; two to ten is `sendMediaGroup`.
                    // Eleven is not a bigger album, it is a different request
                    // the Bot API does not have.
                    'max_count' => 10,
                    'max_bytes' => 10 * 1024 * 1024,
                    // No GIF. `sendPhoto` treats an animated GIF as a still and
                    // usually refuses it; an animation is `sendAnimation`, a
                    // different endpoint with a different payload, and quietly
                    // sending a frozen first frame would be worse than saying so.
                    'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                    'max_pixels' => null,
                    // The Bot API's two documented geometry rules, and both are
                    // refusals rather than re-encodes: width plus height must
                    // not exceed 10000, and neither side may be more than
                    // twenty times the other.
                    'max_dimension_sum' => 10000,
                    'max_aspect_ratio' => 20,
                    // The whole reason this key exists. A Telegram *message* is
                    // 4096 characters; a photo *caption* is 1024, and attaching
                    // an image therefore shortens the post rather than leaving
                    // it alone. Discovered as a 400 at send time otherwise.
                    'caption_limit' => 1024,
                    'note' => 'Up to ten photos. Attaching one caps the copy at 1,024 characters rather than 4,096, because a caption is not a message.',
                ],
                'credentials' => [
                    'bot_token' => [
                        'label' => 'Bot token',
                        'secret' => true,
                        'placeholder' => '7104932188:AAF…',
                        'hint' => 'Stored encrypted. It is never rendered back into this page once saved.',
                    ],
                    'chat_id' => [
                        'label' => 'Chat ID',
                        'secret' => false,
                        'placeholder' => '@kargah_buildlog or -1001234567890',
                        'hint' => 'A public channel username, or the numeric ID for a private channel or group.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Send messages to the chat you name above'],
                    ['allowed' => false, 'text' => 'Read your personal Telegram account or its chats'],
                    ['allowed' => false, 'text' => 'Read notifications — a bot reading updates would consume the queue it needs'],
                ],
            ],
            self::DISCORD => [
                'label' => 'Discord',
                'icon' => 'ki-message-programming',
                'tone' => 'text-primary',
                'dot' => 'bg-primary',
                'colour' => '#5865f2',
                // A normal message, without Nitro. Discord counts characters,
                // not bytes, so this is the same number for any language.
                'limit' => 2000,
                'method' => 'token',
                'summary' => 'Post into one channel of a server through a webhook.',
                'requirement' => 'Open the channel in Discord, then Edit Channel → Integrations → Webhooks → New Webhook, and copy its URL. No bot and no server invite is needed; one webhook posts to one channel.',
                'ingests' => false,
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 10,
                    // The per-file ceiling for a server with no boost. A
                    // boosted server allows more, and there is no way to ask a
                    // webhook which kind it is attached to, so the floor is the
                    // only number that is safe to check against.
                    'max_bytes' => 10 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Up to ten images. Ten megabytes each is what an unboosted server allows, and a webhook cannot ask whether this one is boosted.',
                ],
                'credentials' => [
                    // One field, because the URL already carries both halves of
                    // the credential — the webhook id and its token. Marked
                    // secret for the same reason: anyone holding this URL can
                    // post to that channel, so it is not an address, it is the
                    // password. See DiscordPublisher for why a webhook was
                    // chosen over a bot token and a channel id.
                    'webhook_url' => [
                        'label' => 'Webhook URL',
                        'secret' => true,
                        'placeholder' => 'https://discord.com/api/webhooks/1145…/AbC…',
                        'hint' => 'Stored encrypted. Deleting the webhook in Discord cuts Kargah off on its own.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Post messages into the one channel that webhook belongs to'],
                    ['allowed' => false, 'text' => 'Read any message, channel or member of your server'],
                    ['allowed' => false, 'text' => 'Read notifications — a webhook can only write, and has no read side at all'],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $network): bool
    {
        return array_key_exists($network, self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $network): ?array
    {
        return self::all()[$network] ?? null;
    }

    /** The display name, falling back to the stored key so an unknown row still reads as something. */
    public static function label(string $network): string
    {
        return self::all()[$network]['label'] ?? ucfirst($network);
    }

    /** A keenicon name that is known to exist in the bundle. */
    public static function icon(string $network): string
    {
        return self::all()[$network]['icon'] ?? 'ki-abstract-26';
    }

    public static function limit(string $network): int
    {
        return self::all()[$network]['limit'] ?? 4096;
    }

    public static function colour(string $network): string
    {
        return self::all()[$network]['colour'] ?? '#78829d';
    }

    /** The credential field names a driver for this network expects. @return list<string> */
    public static function credentialFields(string $network): array
    {
        return array_keys(self::all()[$network]['credentials'] ?? []);
    }

    public static function ingestsNotifications(string $network): bool
    {
        return (bool) (self::all()[$network]['ingests'] ?? false);
    }

    /**
     * How many days a freshly saved credential is assumed good for, or null
     * for a network whose token does not expire on its own. See the class
     * docblock for why this is an approximation rather than a real value read
     * off an OAuth response.
     */
    public static function tokenLifetimeDays(string $network): ?int
    {
        return self::all()[$network]['token_lifetime_days'] ?? null;
    }

    /**
     * What this network will accept as a picture.
     *
     * Falls back to a shape rather than to null, so a caller that walks the
     * keys does not have to guard every one of them. The fallback is
     * deliberately restrictive — an unknown network gets one small JPEG or PNG
     * rather than a free pass — because the only way to reach it is a row whose
     * `network` value this catalogue does not describe, and guessing generously
     * on behalf of an API nobody has read is how a post fails at send time.
     *
     * @return array{
     *     max_count: int, max_bytes: int, mimes: list<string>, max_pixels: int|null,
     *     max_dimension_sum: int|null, max_aspect_ratio: int|null,
     *     caption_limit: int|null, note: string
     * }
     */
    public static function media(string $network): array
    {
        return self::all()[$network]['media'] ?? [
            'max_count' => 1,
            'max_bytes' => 1024 * 1024,
            'mimes' => ['image/jpeg', 'image/png'],
            'max_pixels' => null,
            'max_dimension_sum' => null,
            'max_aspect_ratio' => null,
            'caption_limit' => null,
            'note' => 'Kargah does not know this network’s media rules, so it assumes the smallest ones that are likely to work.',
        ];
    }

    /**
     * How many characters this network allows *given* what is attached.
     *
     * Telegram is the only network here where the two numbers differ, and they
     * differ by a factor of four: a message is 4,096 characters and a photo
     * caption is 1,024. Everything that counts characters — the composer's live
     * counter, `trimToLimit()`, the driver itself — has to ask this rather than
     * `limit()`, or a post that fit while it was text stops fitting the moment
     * a picture is attached and nothing says so until Telegram answers 400.
     */
    public static function limitWithMedia(string $network, bool $hasMedia): int
    {
        $caption = self::media($network)['caption_limit'] ?? null;

        return $hasMedia && is_int($caption) ? $caption : self::limit($network);
    }

    /**
     * Every image type any network here will take.
     *
     * The union, not the intersection: the composer accepts a file that at
     * least one selected network can use and then names the ones that cannot,
     * which is more useful than a file picker that silently refuses a GIF
     * because Telegram is ticked.
     *
     * @return list<string>
     */
    public static function acceptedImageMimes(): array
    {
        $mimes = [];

        foreach (self::all() as $entry) {
            foreach ($entry['media']['mimes'] ?? [] as $mime) {
                $mimes[$mime] = true;
            }
        }

        return array_keys($mimes);
    }
}
