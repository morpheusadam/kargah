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
 * partnership. LinkedIn's requires partner access nobody self-serving has, and
 * Telegram's `getUpdates` consumes the update queue the bot itself needs, so
 * both are marked false and `social:sync-notifications` skips them rather than
 * pretending there is nothing to show.
 */
final class Networks
{
    public const MASTODON = 'mastodon';

    public const BLUESKY = 'bluesky';

    public const LINKEDIN = 'linkedin';

    public const TELEGRAM = 'telegram';

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
}
