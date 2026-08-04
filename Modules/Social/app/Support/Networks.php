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
 * **Only Mastodon and Bluesky do.** They publish a notifications endpoint that
 * needs no special partnership; every other entry here is marked false and
 * `social:sync-notifications` skips it rather than pretending there is nothing
 * to show. The reasons differ and each is worth having written down, because
 * "not supported" invites somebody to try again: LinkedIn's requires partner
 * access nobody self-serving has, Telegram's `getUpdates` consumes the update
 * queue the bot itself needs, a Discord incoming webhook has no read side at
 * all, X's mentions endpoint needs a paid tier, Meta's would need permissions
 * Kargah deliberately does not request, and WordPress has no notifications.
 *
 * `token_lifetime_days` is null for every credential that does not expire on
 * its own — Mastodon, Bluesky, Telegram and Discord all issue a scoped,
 * revocable token from the network's own settings screen with no clock
 * attached; the
 * person revokes it, Kargah does not out-wait it. X joins them, for a reason
 * worth naming: OAuth 1.0a issues no expiry and no refresh token at all, which
 * is exactly why it fits a model that has nowhere to put either.
 *
 * LinkedIn and the three Meta networks are the exceptions, and the pasted-token
 * model means there is no OAuth response to read a real expiry off — the
 * credential arrives
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

    public const X = 'x';

    public const FACEBOOK_PAGE = 'facebook_page';

    public const INSTAGRAM = 'instagram';

    public const THREADS = 'threads';

    /**
     * A WordPress site, which is a network here for one reason: everything
     * downstream then works for free.
     *
     * The alternative was a second publishing concept living beside this one —
     * its own table, its own queue, its own retry. Making a site an account and
     * a published article a `post_targets` row means the scheduler, the
     * one-minute cron, the claim, the forward-only status, the per-target error
     * and the media pipeline all apply to it without a line being written for
     * any of them, and the composer can send an article to the blog and a teaser
     * to X as **one intention** rather than two things a person has to remember
     * to do twice. The cost is that "network" now stretches to cover a website,
     * which is a word doing slightly more work than it used to. That is the
     * cheaper of the two wrongs. See `Modules\Blog\Services\WordPressPublisher`.
     */
    public const WORDPRESS = 'wordpress';

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
            self::X => [
                'label' => 'X',
                'icon' => 'ki-twitter',
                'tone' => 'text-mono',
                'dot' => 'bg-mono',
                'colour' => '#0f1419',
                // The free and basic tiers' number. A Premium account writes
                // 25,000, and there is no field on any response that says which
                // tier a credential belongs to — so the counter shows the number
                // every account has. Being told 280 and finding 25,000 works
                // costs a person one edit; the reverse costs a refused post.
                'limit' => 280,
                'method' => 'token',
                'summary' => 'Post to your timeline through an app you own.',
                'requirement' => 'Create a project and an app on developer.x.com, set User authentication settings to Read and write, then copy the API key and secret from Keys and tokens and generate an access token and secret for your own account on the same screen.',
                // The v2 mentions endpoint needs a paid tier, and the free one
                // answers 403. Marked false so `social:sync-notifications` skips
                // it rather than logging a refusal every hour.
                'ingests' => false,
                // OAuth 1.0a issues no expiry and no refresh token: the pair is
                // good until somebody revokes it or regenerates it in the portal.
                // This is exactly why X fits the pasted-credential model that
                // ruled OAuth out everywhere else — see `⚡account-connect`.
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 4,
                    // Five megabytes is the still-image ceiling. An animated GIF
                    // is allowed fifteen, and the conservative number is the one
                    // worth checking against because the composer cannot tell an
                    // animated GIF from a still one without decoding it.
                    'max_bytes' => 5 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Up to four images. Five megabytes each — an animated GIF is allowed more, but Kargah checks the number that is safe for both.',
                ],
                'credentials' => [
                    'consumer_key' => [
                        'label' => 'API key',
                        'secret' => false,
                        'placeholder' => 'The app’s API key',
                        'hint' => 'From Keys and tokens on your app. It identifies the application, not you.',
                    ],
                    'consumer_secret' => [
                        'label' => 'API key secret',
                        'secret' => true,
                        'placeholder' => 'The app’s API key secret',
                        'hint' => 'Stored encrypted. Shown once when the app is created and regenerable on the same screen.',
                    ],
                    'access_token' => [
                        'label' => 'Access token',
                        'secret' => false,
                        'placeholder' => '1234567890-AbCdEf…',
                        'hint' => 'Generated under Keys and tokens for your own account. It carries the app’s Read and write permission.',
                    ],
                    'access_token_secret' => [
                        'label' => 'Access token secret',
                        'secret' => true,
                        'placeholder' => 'Paste the access token secret',
                        'hint' => 'Stored encrypted. Regenerating the token on X invalidates this and cuts Kargah off.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Post to your timeline when you press publish or when a scheduled post fires'],
                    ['allowed' => false, 'text' => 'Read your timeline, your direct messages or anyone’s followers'],
                    ['allowed' => false, 'text' => 'Read notifications — the mentions endpoint needs a paid tier'],
                ],
            ],
            self::FACEBOOK_PAGE => [
                'label' => 'Facebook Page',
                'icon' => 'ki-facebook',
                'tone' => 'text-info',
                'dot' => 'bg-info',
                'colour' => '#1877f2',
                // Facebook's own ceiling is 63,206 characters, which is not a
                // number a composer counter can do anything useful with. Five
                // thousand is the conservative reading, and it is the same
                // number Mixpost settled on for the same reason.
                'limit' => 5000,
                'method' => 'token',
                'summary' => 'Publish to a Page you administer.',
                'requirement' => 'Create an app on developers.facebook.com, add the pages_manage_posts and pages_read_engagement permissions, then use Graph API Explorer to get a Page access token. Exchange it for a long-lived one first — a token straight out of the Explorer dies in an hour.',
                'ingests' => false,
                // A Page token derived from a **long-lived** user token does not
                // expire at all; one taken straight out of Graph API Explorer
                // dies in an hour. Nothing on a pasted string says which of the
                // two it is, so Kargah warns at sixty days — early for the good
                // credential and late for the bad one, which is the right way
                // round: the bad one fails loudly on its first post, with the
                // Graph error on the target row.
                'token_lifetime_days' => 60,
                'media' => [
                    'max_count' => 10,
                    // The Photo API's documented ceiling. Larger files often
                    // work; a refusal here is cheaper than a post that uploads
                    // three of four pictures and then stops.
                    'max_bytes' => 4 * 1024 * 1024,
                    // No WebP. The `/photos` edge documents JPEG, PNG, GIF, TIFF
                    // and BMP, and a WebP is refused after the bytes have
                    // already gone up.
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Up to ten photos. Each is uploaded unpublished first and then attached to the post, which is what lets several share one story.',
                ],
                'credentials' => [
                    'page_id' => [
                        'label' => 'Page ID',
                        'secret' => false,
                        'placeholder' => '102938475610293',
                        'hint' => 'The numeric id of the Page, not its vanity name. Graph API Explorer shows it under /me/accounts.',
                    ],
                    'page_access_token' => [
                        'label' => 'Page access token',
                        'secret' => true,
                        'placeholder' => 'EAAG…',
                        'hint' => 'Stored encrypted. A Page token, not a user token — the two look alike and only one of them can post.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Publish posts and photos to the one Page named above'],
                    ['allowed' => false, 'text' => 'Post to your personal profile, or to any other Page you administer'],
                    ['allowed' => false, 'text' => 'Read notifications — Kargah does not ask for the permission that would allow it'],
                ],
            ],
            self::INSTAGRAM => [
                'label' => 'Instagram',
                'icon' => 'ki-instagram',
                'tone' => 'text-primary',
                'dot' => 'bg-primary',
                'colour' => '#e1306c',
                'limit' => 2200,
                'method' => 'token',
                'summary' => 'Publish to a Business or Creator account linked to a Page.',
                'requirement' => 'Instagram publishing goes through the same Meta app as the Page. The account must be a Business or Creator account and it must be linked to a Facebook Page; a personal account cannot be published to by any API. Add instagram_basic and instagram_content_publish, then take the account id from /me/accounts?fields=instagram_business_account.',
                'ingests' => false,
                'token_lifetime_days' => 60,
                'media' => [
                    // A carousel. One image is a single post; two to ten is a
                    // carousel, which is a different sequence of calls rather
                    // than a longer one.
                    'max_count' => 10,
                    'max_bytes' => 8 * 1024 * 1024,
                    // 🔴 JPEG and nothing else. This is not conservatism: the
                    // Instagram Graph API's image container accepts JPEG only,
                    // and refuses a PNG with an error that names neither the
                    // file nor the reason. The composer says so while attaching
                    // rather than letting somebody discover it from a red row.
                    'mimes' => ['image/jpeg'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'JPEG only, and at least one is required — Instagram has no text-only post. Up to ten becomes a carousel.',
                ],
                'credentials' => [
                    'ig_user_id' => [
                        'label' => 'Instagram account ID',
                        'secret' => false,
                        'placeholder' => '17841400000000000',
                        'hint' => 'The instagram_business_account id from /me/accounts, not your @handle and not the Page id.',
                    ],
                    'access_token' => [
                        'label' => 'Access token',
                        'secret' => true,
                        'placeholder' => 'EAAG…',
                        'hint' => 'Stored encrypted. The Page token for the Page this account is linked to.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Publish images and carousels to the one account named above'],
                    ['allowed' => false, 'text' => 'Post Stories or Reels — neither is reachable through this API'],
                    ['allowed' => false, 'text' => 'Read your feed, your followers or your direct messages'],
                ],
            ],
            self::THREADS => [
                'label' => 'Threads',
                'icon' => 'ki-abstract-33',
                'tone' => 'text-mono',
                'dot' => 'bg-mono',
                'colour' => '#101010',
                'limit' => 500,
                'method' => 'token',
                'summary' => 'Post to Threads through the Threads API.',
                'requirement' => 'Threads has its own API and its own host, and its token is not the Instagram one even though the account is. Add the Threads use case to your Meta app, request threads_basic and threads_content_publish, then take the Threads user id and token from the Threads API settings.',
                'ingests' => false,
                'token_lifetime_days' => 60,
                'media' => [
                    'max_count' => 20,
                    'max_bytes' => 8 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Up to twenty images as a carousel, or none at all — unlike Instagram, a text-only post is a real post here.',
                ],
                'credentials' => [
                    'threads_user_id' => [
                        'label' => 'Threads user ID',
                        'secret' => false,
                        'placeholder' => '78901234567890123',
                        'hint' => 'From the Threads API settings on your Meta app. It is not the Instagram account id, even though the account is the same.',
                    ],
                    'access_token' => [
                        'label' => 'Access token',
                        'secret' => true,
                        'placeholder' => 'THQV…',
                        'hint' => 'Stored encrypted. A Threads token — an Instagram or Page token is refused here.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Post to the Threads account named above, with or without images'],
                    ['allowed' => false, 'text' => 'Reply on your behalf, or read your timeline'],
                    ['allowed' => false, 'text' => 'Read notifications — Kargah does not ask for the permission that would allow it'],
                ],
            ],
            self::WORDPRESS => [
                'label' => 'WordPress',
                'icon' => 'ki-notepad',
                'tone' => 'text-info',
                'dot' => 'bg-info',
                'colour' => '#21759b',
                // WordPress imposes no limit on post content, so this number is
                // Kargah's rather than the network's — it exists because the
                // composer's counter needs one, and because a body past this
                // length is very likely a paste that went wrong.
                'limit' => 100000,
                'method' => 'token',
                'summary' => 'Publish an article to your own WordPress site.',
                'requirement' => 'On your WordPress site, open Users → Profile → Application Passwords, add one named Kargah, and paste the generated password here with your username. This is WordPress’s own mechanism and needs no plugin; the site must be reachable over HTTPS and must not have the REST API disabled.',
                'ingests' => false,
                // An application password does not expire. It is revocable from
                // the same screen that created it, one row per application, so
                // cutting Kargah off never touches the person's own login.
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 10,
                    // The site's `upload_max_filesize`, which Kargah cannot ask
                    // for without uploading something. Eight megabytes is what a
                    // typical shared host ships with; a site configured lower
                    // answers 413 and the target says so.
                    'max_bytes' => 8 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'The first image becomes the featured image; the rest are uploaded to the media library and left in the post’s gallery.',
                ],
                'credentials' => [
                    'site_url' => [
                        'label' => 'Site URL',
                        'secret' => false,
                        'placeholder' => 'https://example.com',
                        'hint' => 'The site’s home URL with the scheme, not the /wp-json path — Kargah appends that itself.',
                    ],
                    'username' => [
                        'label' => 'Username',
                        'secret' => false,
                        'placeholder' => 'editor',
                        'hint' => 'The WordPress login the application password belongs to. It needs permission to publish posts.',
                    ],
                    'application_password' => [
                        'label' => 'Application password',
                        'secret' => true,
                        'placeholder' => 'abcd EFGH 1234 ijkl MNOP 5678',
                        'hint' => 'Stored encrypted. Paste it with the spaces exactly as WordPress showed them; revoking it there is enough to cut Kargah off.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create posts and upload images as the user above, as drafts or published'],
                    ['allowed' => false, 'text' => 'Edit or delete anything that was already on the site'],
                    ['allowed' => false, 'text' => 'Read notifications — WordPress has none to read'],
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
