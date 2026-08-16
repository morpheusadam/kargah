<?php

namespace Modules\Social\Support;

use Nwidart\Modules\Facades\Module;

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
 * **Images only, with exactly one exception.** Every `mimes` list here is a
 * still-image list but YouTube's, and that asymmetry is the honest shape rather
 * than an oversight. X's 1 MB chunks and LinkedIn's 2 MB describe an upload that
 * can span minutes, and a Kargah publish is one HTTP job bounded by
 * `max_execution_time` on shared hosting; an image fits in that budget and a
 * chunked video does not. So video was left out of `Publisher::publish()`
 * entirely and stays out.
 *
 * 🔴 **YouTube does not get an exemption from that reasoning — it gets a
 * different door.** A YouTube post *is* a video, so there was no version of
 * supporting it that kept video out. What it must not do is smuggle a
 * minutes-long upload into the call every other network answers in seconds, and
 * it does not: `Publishers\PublishesVideo` is a separate operation, `VideoItem`
 * streams where `MediaItem` buffers, and `YOUTUBE['media']['max_bytes']` is set
 * from **this install's** budget rather than from Google's 256 GB ceiling. The
 * limit that actually bites is the worker's clock, not the API's.
 *
 * The numbers are the network's, not Kargah's, and they are deliberately the
 * conservative reading where a network's own documentation and its running
 * instances disagree — Mastodon most of all, where the size ceiling is an
 * instance setting rather than a protocol constant. Being told 8 MB and finding
 * an instance accepts 16 costs nothing; the reverse costs a failed post.
 *
 * ## `module`, and why there are two ways to read this catalogue
 *
 * 🔴 **An entry lives here; its driver does not have to.** Fourteen of the
 * seventeen are registered by `SocialServiceProvider::register()`. The three
 * article destinations — WordPress, DEV.to and Hashnode — are registered from
 * `Modules\Blog\Providers\BlogServiceProvider` through `Publishing::extend()`,
 * because Blog may depend on Social and Social may not depend on Blog. That
 * arrow is deliberate and argued in `BlogServiceProvider`'s own docblock; it is
 * not the thing `module` fixes.
 *
 * What it fixes is the consequence. With `Blog` disabled in
 * `modules_statuses.json` its provider never runs, so nothing registers those
 * three drivers — while this catalogue would go on drawing them on the connect
 * page and offering them in the composer. Somebody pastes a DEV.to key, and the
 * only warning the install ever gets arrives an hour later on a `post_targets`
 * row reading *"Kargah has no driver for DEV.to, so the post was not sent."* So
 * every entry names the module whose provider registers its driver, and
 * `NetworkRegistryTest` checks that the name is the truth rather than a comment.
 *
 * That gives two readings of the same data, and picking the wrong one is a real
 * bug in either direction:
 *
 * - **`all()` — the complete catalogue.** Use it to *describe* a destination
 *   that already exists: an account row, a published `post_targets` row, a
 *   notification, a driver asking what its own credential fields are called.
 *   Filtering here would blank out the label, icon and colour of history still
 *   in the database, which is its own confusing failure.
 * - **`available()` — only what this install can currently send to.** Use it to
 *   *offer* a destination: the connect page's picker, the "networks you have
 *   not set up yet" list. Offering one whose module is off invites somebody to
 *   type a credential into a form that leads nowhere.
 *
 * `unavailableReason()` is the third case, and the one neither of those covers:
 * a destination that already exists *and* cannot be sent to. It answers with
 * the sentence to put on the page, in the same register and for the same reason
 * as `HttpPublisher::unavailableReason()`.
 */
final class Networks
{
    /**
     * The module whose service provider registers an entry's driver.
     *
     * Names, not class references: `Modules\Social` must not import anything
     * from `Modules\Blog`, and a string is what `Module::find()` takes anyway.
     * These are the studly names used by `modules_statuses.json` and by the
     * `Modules/` directory, and `NetworkRegistryTest` proves each one against
     * the file the registration closure was actually written in.
     */
    public const MODULE_SOCIAL = 'Social';

    public const MODULE_BLOG = 'Blog';

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
     * 🔴 **The only entry here whose post is a video, and the only one that
     * cannot be published by `Publisher::publish()` at all.**
     *
     * Every other destination in this catalogue takes copy and optionally some
     * pictures. YouTube has no text post and no photo post — `videos.insert` is
     * the only way to put anything on a channel — so its driver implements
     * `Publishers\PublishesVideo` and its `publish()` refuses by name. That is
     * also why `VideoItem` exists beside `MediaItem`: an image is a string that
     * fits in memory, and a video is a stream that must not be turned into one.
     *
     * It is also the only credential here that Kargah cannot obtain by asking a
     * person to paste something a settings screen shows them. Google issues no
     * long-lived API key for uploads; the only route is an OAuth consent that
     * hands back a refresh token, which is then exchanged for a one-hour access
     * token on every publish — the same shape `RedditPublisher` already uses,
     * and the reason that driver's pattern was worth keeping.
     */
    public const YOUTUBE = 'youtube';

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

    public const SLACK = 'slack';

    public const TUMBLR = 'tumblr';

    public const VK = 'vk';

    /**
     * Reddit and Lemmy are the two entries here that take a **real account
     * password**, and it is worth saying so where the constants are rather than
     * only in their catalogue copy.
     *
     * Every other credential in this file is a scoped, individually revocable
     * thing a network's own settings screen hands over: an app password, a bot
     * token, a webhook URL, an OAuth pair. Revoking one costs the person nothing
     * else. Neither Reddit nor Lemmy has such a thing — Reddit's only flow that
     * needs no registered callback URL is a script app's password grant, and
     * Lemmy's API has one way in and it is `user/login`. So Kargah either stores
     * the password or does not support them.
     *
     * It stores it, encrypted like every other credential, and both connect
     * pages say plainly that a dedicated posting account is the right way to use
     * them. That is a worse bargain than the rest of this catalogue offers and
     * the person deserves to be told, not protected from the choice.
     */
    public const REDDIT = 'reddit';

    public const LEMMY = 'lemmy';

    /**
     * DEV.to and Hashnode are article destinations, not social ones.
     *
     * Both take a title, a markdown body, tags and a canonical URL — the same
     * shape WordPress needs — so both live in `Modules\Blog` beside
     * `WordPressPublisher`, implement `TakesTargetOptions`, and read the article
     * out of `post_targets.options`. They are listed in this catalogue for the
     * same reason WordPress is: it is the one place that knows how to draw a
     * destination and what its credentials are called.
     */
    public const DEVTO = 'devto';

    public const HASHNODE = 'hashnode';

    /**
     * The complete catalogue, every entry, whatever this install can send to.
     *
     * This is the one to use when something that **already exists** needs
     * describing — an account row, a `post_targets` row, a notification, a
     * driver asking what its own credential fields are called. It always
     * resolves every key, so a DEV.to account connected before the Blog module
     * was switched off still draws with its own label, icon and colour rather
     * than falling back to a raw key.
     *
     * Use `available()` instead when the answer is going to be *offered* to
     * somebody as a place to connect or publish to. See the class docblock.
     *
     * @return array<string, array{
     *     label: string,
     *     module: string,
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
                'module' => self::MODULE_SOCIAL,
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
                'module' => self::MODULE_SOCIAL,
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
                'module' => self::MODULE_SOCIAL,
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
                'module' => self::MODULE_SOCIAL,
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
                'module' => self::MODULE_SOCIAL,
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
                'module' => self::MODULE_SOCIAL,
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
                'module' => self::MODULE_SOCIAL,
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
                'module' => self::MODULE_SOCIAL,
                'icon' => 'ki-instagram',
                'tone' => 'text-primary',
                'dot' => 'bg-primary',
                'colour' => '#e1306c',
                'limit' => 2200,
                'method' => 'token',
                'summary' => 'Publish to a Business or Creator account linked to a Page.',
                // 🔴 This describes **Instagram Login**, not the Facebook-login
                // variant of the same API, and the difference is not cosmetic:
                // the other route needs a Facebook Page linked to the account and
                // this one needs no Facebook anything. Meta offers to switch a
                // Meta app between the two with one link on the app dashboard,
                // and switching breaks a working connection with an error that
                // says only "Invalid OAuth access token". If this copy is ever
                // rewritten to mention Pages again, `InstagramPublisher::HOST`
                // has to move back to graph.facebook.com in the same commit.
                'requirement' => 'Instagram publishing uses Instagram Login, so no Facebook Page is involved and none has to exist. The account must be a Business or Creator account; a personal account cannot be published to by any API. In your Meta app, add the Instagram use case and choose API setup with Instagram login, request instagram_business_basic and instagram_business_content_publish, then give your account the Instagram Tester role and accept that invitation from Instagram under Settings → Apps and websites. Generating the token on that screen gives you both values below at once.',
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
                        'hint' => 'The number shown under your handle on the token screen in your Meta app. It is not your @handle, and it is not an email address.',
                    ],
                    'access_token' => [
                        'label' => 'Access token',
                        'secret' => true,
                        // Not `EAAG…`. That is a Page token, which is what the
                        // Facebook-login route issues and what this driver is
                        // refused by — see the note on `requirement` above.
                        'placeholder' => 'IGAA…',
                        'hint' => 'Stored encrypted. An Instagram Login token, which starts IGAA. A Page token starting EAA is a different credential and is refused with an error that does not explain why.',
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
                'module' => self::MODULE_SOCIAL,
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
            self::YOUTUBE => [
                'label' => 'YouTube',
                'module' => self::MODULE_SOCIAL,
                'icon' => 'ki-youtube',
                'tone' => 'text-danger',
                'dot' => 'bg-danger',
                'colour' => '#ff0000',
                // The **description's** ceiling. A video also has a title, which
                // is 100 characters and is a separate field this driver derives
                // from the first line rather than counts — the same split
                // `RedditPublisher` makes, so somebody composing for both learns
                // the rule once.
                'limit' => 5000,
                'method' => 'token',
                'summary' => 'Upload a video to a channel you own.',
                'requirement' => 'YouTube is the one destination here with no pasteable API key: Google only issues upload access through an OAuth consent. In Google Cloud, create a project, enable the YouTube Data API v3, create an OAuth client of type Desktop app, then run its consent once to get a refresh token and paste it with the client id and secret. 🔴 Set the OAuth consent screen to In production before you do — while it is in Testing, Google expires every refresh token after seven days and the connection dies without warning.',
                'ingests' => false,
                // A Google refresh token issued by an app **in production** does
                // not expire on a clock at all; it dies when it is revoked, or
                // after six months of not being used. That is the same shape as
                // Mastodon's and Telegram's, so it is null here for the same
                // reason.
                //
                // ⚠️ A token issued while the consent screen is still in Testing
                // expires in seven days, and there is nothing on the pasted
                // string that says which kind it is. Setting this to 7 would
                // warn every correctly-configured install every week; leaving it
                // null and putting the sentence in `requirement`, where somebody
                // reads it *before* pasting, is the trade that costs less.
                'token_lifetime_days' => null,
                'media' => [
                    // One. A YouTube post is a video, singular, and
                    // `PostMedia::videoForPost()` answers with the first one
                    // attached rather than a list.
                    'max_count' => 1,
                    // 🔴 **Kargah's number, and specifically this install's.**
                    // Google accepts 256 GB. Two things here accept far less, and
                    // the smaller of them is the one that decides:
                    //
                    // 1. `config/livewire.php`'s `temporary_file_upload.rules`
                    //    is `max:25600` — **25 MB**, and the composer is the only
                    //    way a video ever becomes an attachment. A larger number
                    //    here would be a promise the attach step cannot keep, and
                    //    the person would meet Livewire's validation error rather
                    //    than this catalogue's sentence.
                    // 2. the upload has to finish inside one queue job; cron
                    //    starts the worker as
                    //    `queue:work --stop-when-empty --max-time=50` on shared
                    //    hosting.
                    //
                    // So this matches the Livewire ceiling exactly rather than
                    // guessing a friendlier number. **Raising it means raising
                    // that config first** — it is app-wide and affects every
                    // upload form in Kargah, which is a decision for the person
                    // running the install and not for this file.
                    'max_bytes' => 25 * 1024 * 1024,
                    'mimes' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo', 'video/mpeg'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'One video, and a video is required — YouTube has no text post. MP4 is the safest container. The 25 MB ceiling is this install’s upload limit rather than YouTube’s, which is far larger.',
                ],
                'credentials' => [
                    'client_id' => [
                        'label' => 'OAuth client ID',
                        'secret' => false,
                        'placeholder' => '1234567890-abc.apps.googleusercontent.com',
                        'hint' => 'From the OAuth client you created in Google Cloud. It identifies the application, not the channel.',
                    ],
                    'client_secret' => [
                        'label' => 'OAuth client secret',
                        'secret' => true,
                        'placeholder' => 'The client secret',
                        'hint' => 'Stored encrypted. Shown when the client is created and re-downloadable from the same screen.',
                    ],
                    'refresh_token' => [
                        'label' => 'Refresh token',
                        'secret' => true,
                        'placeholder' => '1//0g…',
                        'hint' => 'Stored encrypted. Obtained once by running the consent flow; Kargah exchanges it for a short-lived token on every upload and never stores that.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Upload a video to the channel this credential belongs to, and publish it'],
                    ['allowed' => true, 'text' => 'Read that channel’s own name, so the connect page can show you which one it reached'],
                    ['allowed' => false, 'text' => 'Read or reply to comments, see your subscribers, or touch a video it did not upload'],
                ],
            ],
            self::WORDPRESS => [
                'label' => 'WordPress',
                'module' => self::MODULE_BLOG,
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
                /*
                 * ⚠️ This list grew when `Modules\Site` was added, and the line
                 * it replaced is worth recording rather than deleting.
                 *
                 * It used to promise, in as many words, that Kargah could not
                 * "edit or delete anything that was already on the site". That
                 * was true while `WordPressPublisher` was the only thing holding
                 * this credential: publishing an article is a create, and the
                 * driver has no other verb.
                 *
                 * `Modules\Site` holds the same credential and exists to operate
                 * the site — edit a page, trash a post, upload to the library,
                 * change an SEO field, purge a cache. Leaving the old sentence
                 * here would have meant the connect page telling somebody their
                 * password could not do the thing another page in the same
                 * application was doing with it. A promise about what a stored
                 * credential is used for is not something to quietly outgrow, so
                 * it is rewritten to what is now true, and the reach is stated as
                 * plainly as the limit used to be.
                 *
                 * `SiteModuleTest::test_the_wordpress_connect_page_no_longer_promises_it_cannot_edit_existing_content()`
                 * fails while the old wording is present. That test is the only
                 * reason this comment is not a note somebody meant to write.
                 *
                 * What is still refused is real and worth keeping: WordPress has
                 * no notifications to read, so `ingests` is false and nothing in
                 * Kargah polls this site for anything to show in a feed.
                 */
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create posts and upload images as the user above, as drafts or published'],
                    ['allowed' => true, 'text' => 'Edit, trash and restore existing posts, pages and media from the Website pages'],
                    ['allowed' => true, 'text' => 'Change SEO fields and purge the site cache, where a plugin exposes them'],
                    ['allowed' => false, 'text' => 'Anything the WordPress user above cannot do — its role is the real limit'],
                    ['allowed' => false, 'text' => 'Read notifications — WordPress has none to read'],
                ],
            ],
            self::SLACK => [
                'label' => 'Slack',
                'module' => self::MODULE_SOCIAL,
                'icon' => 'ki-slack',
                // Not `destructive`, which was the first thing written here and
                // was wrong for a reason worth recording: `destructive` is the
                // red this application uses for a failed target, so a Slack
                // account would have rendered its dot in the colour that means
                // "this did not send". Slack's aubergine has no token in the
                // Metronic palette — there is no purple — and `mono` is the
                // least wrong of the six that exist. The real brand colour is
                // in `colour` below, where the calendar and the charts read it.
                'tone' => 'text-mono',
                'dot' => 'bg-mono',
                'colour' => '#4a154b',
                // `chat.postMessage`'s `text` allows 40,000, but attaching a
                // picture turns the message into blocks and a block's text is
                // capped at 3,000. This is the number that holds in both shapes,
                // which is what a counter has to show.
                'limit' => 3000,
                'method' => 'token',
                'summary' => 'Post into a channel of a workspace you administer.',
                'requirement' => 'Create an app at api.slack.com/apps, add the chat:write bot scope under OAuth & Permissions, install it to the workspace, then paste the Bot User OAuth Token. Invite the app to the channel with /invite, or it can only post to channels it is already in.',
                'ingests' => false,
                // A bot token lives until somebody revokes it or reinstalls the
                // app. There is no clock on it.
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 10,
                    'max_bytes' => 8 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Pictures ride as image blocks, which means Slack fetches them from this install rather than being sent them — the same requirement Instagram has.',
                ],
                'credentials' => [
                    'bot_token' => [
                        'label' => 'Bot user OAuth token',
                        'secret' => true,
                        'placeholder' => 'xoxb-…',
                        'hint' => 'Stored encrypted. It starts xoxb-; a user token starting xoxp- is a different, wider credential and is not what this wants.',
                    ],
                    'channel' => [
                        'label' => 'Channel',
                        'secret' => false,
                        'placeholder' => '#build-log or C0123456789',
                        'hint' => 'The channel name with its hash, or the channel ID. The app has to be a member of it.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Post messages into the one channel you name above'],
                    ['allowed' => false, 'text' => 'Read any message, channel or member of your workspace'],
                    ['allowed' => false, 'text' => 'Read notifications — Kargah does not ask for the scope that would allow it'],
                ],
            ],
            self::TUMBLR => [
                'label' => 'Tumblr',
                'module' => self::MODULE_SOCIAL,
                'icon' => 'ki-abstract-19',
                'tone' => 'text-info',
                'dot' => 'bg-info',
                'colour' => '#36465d',
                // Tumblr documents no ceiling on a text post's body. This number
                // is Kargah's, not Tumblr's, and it exists because the composer's
                // counter needs one and because a body past it is very likely a
                // paste that went wrong.
                'limit' => 10000,
                'method' => 'token',
                'summary' => 'Publish a text post to a blog you own.',
                'requirement' => 'Register an application at tumblr.com/oauth/apps, then use the API console on the same page to generate an OAuth token and secret for your own account. All four values are on that screen; there is no callback to set up.',
                'ingests' => false,
                // OAuth 1.0a issues no expiry and no refresh token — the same
                // reason X's stays null. See the class docblock.
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 10,
                    'max_bytes' => 10 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Attaching a picture makes this a photo post rather than a text post, which is a different call and puts the copy in the caption.',
                ],
                'credentials' => [
                    'blog_identifier' => [
                        'label' => 'Blog identifier',
                        'secret' => false,
                        'placeholder' => 'yourblog.tumblr.com',
                        'hint' => 'The blog’s host name. A custom domain works here too, and one account can own several blogs.',
                    ],
                    'consumer_key' => [
                        'label' => 'Consumer key',
                        'secret' => false,
                        'placeholder' => 'The application’s consumer key',
                        'hint' => 'From your registered application. It identifies the application, not you.',
                    ],
                    'consumer_secret' => [
                        'label' => 'Consumer secret',
                        'secret' => true,
                        'placeholder' => 'The application’s consumer secret',
                        'hint' => 'Stored encrypted. Shown on the application’s own page and regenerable there.',
                    ],
                    'token' => [
                        'label' => 'OAuth token',
                        'secret' => false,
                        'placeholder' => 'Generated in the API console',
                        'hint' => 'Identifies your account to the application. Generated once and does not expire.',
                    ],
                    'token_secret' => [
                        'label' => 'OAuth token secret',
                        'secret' => true,
                        'placeholder' => 'Paste the token secret',
                        'hint' => 'Stored encrypted. Revoking the application on Tumblr invalidates it and cuts Kargah off.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create posts on the one blog you name above'],
                    ['allowed' => false, 'text' => 'Edit or delete posts that were already there'],
                    ['allowed' => false, 'text' => 'Read your dashboard, your messages or who follows you'],
                ],
            ],
            self::VK => [
                'label' => 'VK',
                'module' => self::MODULE_SOCIAL,
                'icon' => 'ki-abstract-14',
                'tone' => 'text-info',
                'dot' => 'bg-info',
                'colour' => '#0077ff',
                'limit' => 16000,
                'method' => 'token',
                'summary' => 'Post to a wall — your own, or a community you administer.',
                'requirement' => 'Create a standalone application at vk.com/apps?act=manage, then get a token for it with the wall, photos and offline scopes. The offline scope is the one that matters: without it the token dies within the day.',
                'ingests' => false,
                // Only with the `offline` scope, which the requirement text asks
                // for by name. A token granted without it lasts about a day, and
                // there is nothing on the pasted string that says which kind it
                // is — so this stays null and a short-lived token fails loudly on
                // its first post rather than being warned about on the wrong clock.
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 10,
                    'max_bytes' => 50 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Each picture is a three-step upload — ask for a server, send the bytes, save the photo — before the post itself is made.',
                ],
                'credentials' => [
                    'access_token' => [
                        'label' => 'Access token',
                        'secret' => true,
                        'placeholder' => 'vk1.a.…',
                        'hint' => 'Stored encrypted. It must carry the wall, photos and offline scopes.',
                    ],
                    'owner_id' => [
                        'label' => 'Wall owner ID',
                        'secret' => false,
                        'placeholder' => '12345678 or -87654321',
                        'hint' => 'Your user ID for your own wall, or the community ID with a minus sign in front of it for a community wall.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Post to the one wall you name above, with pictures'],
                    ['allowed' => false, 'text' => 'Read your messages, your friends or anyone’s wall'],
                    ['allowed' => false, 'text' => 'Delete or edit posts that were already there'],
                ],
            ],
            self::REDDIT => [
                'label' => 'Reddit',
                'module' => self::MODULE_SOCIAL,
                'icon' => 'ki-abstract-39',
                'tone' => 'text-warning',
                'dot' => 'bg-warning',
                'colour' => '#ff4500',
                // A self post's body. The **title** is 300 and is a separate
                // field this driver derives rather than counts — see
                // RedditPublisher.
                'limit' => 40000,
                'method' => 'token',
                'summary' => 'Submit a text post to one subreddit.',
                // No 🔴 in this string, unlike the docblocks: `requirement` is
                // rendered straight into the connect page, where a red circle
                // reads as a decoration rather than as the warning it is. The
                // sentence carries the weight instead, and the permissions panel
                // below says the same thing a second way.
                'requirement' => 'Reddit is the only credential Kargah asks for that is a real account password, because a script app’s password grant is its only flow that needs no callback URL. Create a script app at reddit.com/prefs/apps, and use a dedicated posting account rather than your own — this password can do everything that account can do.',
                'ingests' => false,
                // The access token Reddit issues lasts an hour, and this driver
                // fetches a fresh one on every publish rather than storing it.
                // What is stored is the password, and a password has no expiry.
                'token_lifetime_days' => null,
                'media' => [
                    // Deliberately none. An image submission is a lease upload
                    // against Reddit's own media host followed by a submit that
                    // references it, and it fails in ways a text post does not.
                    // Text posts work today; pictures are honest future work
                    // rather than a half-built path.
                    'max_count' => 0,
                    'max_bytes' => 0,
                    'mimes' => [],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'Text posts only. An image submission needs a lease upload against Reddit’s media host, which is separate work.',
                ],
                'credentials' => [
                    'client_id' => [
                        'label' => 'App ID',
                        'secret' => false,
                        'placeholder' => 'The short string under the app’s name',
                        'hint' => 'Shown under “personal use script” on your apps page, not labelled as an ID.',
                    ],
                    'client_secret' => [
                        'label' => 'App secret',
                        'secret' => true,
                        'placeholder' => 'The app’s secret',
                        'hint' => 'Stored encrypted. On the same page, next to the app ID.',
                    ],
                    'username' => [
                        'label' => 'Username',
                        'secret' => false,
                        'placeholder' => 'the_posting_account',
                        'hint' => 'Without the u/ prefix. It must be listed as a developer of the app above.',
                    ],
                    'password' => [
                        'label' => 'Account password',
                        'secret' => true,
                        'placeholder' => 'The account’s own password',
                        'hint' => 'Stored encrypted. This is the account’s real password and there is no scoped alternative — use a dedicated posting account.',
                    ],
                    'subreddit' => [
                        'label' => 'Subreddit',
                        'secret' => false,
                        'placeholder' => 'r/somewhere or somewhere',
                        'hint' => 'One subreddit per connection. It has to allow text posts from this account.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Submit text posts to the one subreddit you name above'],
                    ['allowed' => false, 'text' => 'Vote, comment, or read your inbox'],
                    ['allowed' => false, 'text' => 'Anything else — but note the password itself is not scoped, so this is Kargah’s restraint rather than Reddit’s'],
                ],
            ],
            self::LEMMY => [
                'label' => 'Lemmy',
                'module' => self::MODULE_SOCIAL,
                'icon' => 'ki-abstract-31',
                'tone' => 'text-success',
                'dot' => 'bg-success',
                'colour' => '#14854f',
                // A post's body. The **title** is 200 and is a separate field.
                'limit' => 10000,
                'method' => 'token',
                'summary' => 'Post to a community on any Lemmy instance.',
                'requirement' => 'Lemmy’s API has one way in and it is a username and password, so that is what this asks for — a dedicated posting account is the right way to use it. Two-factor must be off on that account, because the login endpoint has nowhere to put a code.',
                'ingests' => false,
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 1,
                    'max_bytes' => 10 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'One picture, uploaded to the instance’s own image host and then set as the post’s URL — a Lemmy post carries one link, not a gallery.',
                ],
                'credentials' => [
                    'instance' => [
                        'label' => 'Instance URL',
                        'secret' => false,
                        'placeholder' => 'https://lemmy.world',
                        'hint' => 'The server the account lives on, with the scheme.',
                    ],
                    'username' => [
                        'label' => 'Username',
                        'secret' => false,
                        'placeholder' => 'the_posting_account',
                        'hint' => 'The account name on that instance, or the email it signs in with.',
                    ],
                    'password' => [
                        'label' => 'Password',
                        'secret' => true,
                        'placeholder' => 'The account’s own password',
                        'hint' => 'Stored encrypted. Lemmy issues no scoped token, so this is the account’s real password — use a dedicated account.',
                    ],
                    'community' => [
                        'label' => 'Community',
                        'secret' => false,
                        'placeholder' => 'buildinpublic',
                        'hint' => 'The community name without the exclamation mark. Use name@instance for a community on another server.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create posts in the one community you name above'],
                    ['allowed' => false, 'text' => 'Vote, comment, or read your inbox'],
                    ['allowed' => false, 'text' => 'Anything else — but the password is not scoped, so this is Kargah’s restraint rather than Lemmy’s'],
                ],
            ],
            self::DEVTO => [
                'label' => 'DEV.to',
                'module' => self::MODULE_BLOG,
                'icon' => 'ki-code',
                'tone' => 'text-mono',
                'dot' => 'bg-mono',
                'colour' => '#0a0a0a',
                'limit' => 100000,
                'method' => 'token',
                'summary' => 'Publish an article to your DEV profile.',
                'requirement' => 'On DEV, open Settings → Extensions → DEV Community API Keys, generate a key named Kargah, and paste it here. It is revocable from the same screen and needs no application registration.',
                'ingests' => false,
                'token_lifetime_days' => null,
                'media' => [
                    // Nothing is uploaded. DEV takes a `main_image` URL and
                    // renders markdown, so a picture reaches it as a link rather
                    // than as bytes — the same shape Instagram needs, for a
                    // different reason.
                    'max_count' => 1,
                    'max_bytes' => 8 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'The first image becomes the article’s cover, as a URL DEV fetches — there is no upload endpoint.',
                ],
                'credentials' => [
                    'api_key' => [
                        'label' => 'API key',
                        'secret' => true,
                        'placeholder' => 'Paste the DEV API key',
                        'hint' => 'Stored encrypted. Revoking it on DEV is enough to cut Kargah off.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create articles on your profile, as drafts or published'],
                    ['allowed' => false, 'text' => 'Read your notifications or anyone’s reading list'],
                    ['allowed' => false, 'text' => 'Delete articles — the API has no delete'],
                ],
            ],
            self::HASHNODE => [
                'label' => 'Hashnode',
                'module' => self::MODULE_BLOG,
                'icon' => 'ki-abstract-45',
                'tone' => 'text-primary',
                'dot' => 'bg-primary',
                'colour' => '#2962ff',
                'limit' => 100000,
                'method' => 'token',
                'summary' => 'Publish an article to a Hashnode publication.',
                'requirement' => 'Generate a personal access token at hashnode.com/settings/developer, then take the publication ID from your blog’s dashboard URL. Hashnode’s API is GraphQL and both values are on those two screens.',
                'ingests' => false,
                'token_lifetime_days' => null,
                'media' => [
                    'max_count' => 1,
                    'max_bytes' => 8 * 1024 * 1024,
                    'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'max_pixels' => null,
                    'max_dimension_sum' => null,
                    'max_aspect_ratio' => null,
                    'caption_limit' => null,
                    'note' => 'The first image becomes the cover, as a URL Hashnode fetches — its upload endpoint is not part of the public API.',
                ],
                'credentials' => [
                    'api_key' => [
                        'label' => 'Personal access token',
                        'secret' => true,
                        'placeholder' => 'Paste the Hashnode token',
                        'hint' => 'Stored encrypted. Revocable from the developer settings page that issued it.',
                    ],
                    'publication_id' => [
                        'label' => 'Publication ID',
                        'secret' => false,
                        'placeholder' => '65b1f0c8a1d2e3f4a5b6c7d8',
                        'hint' => 'The identifier in your blog dashboard’s URL, not the blog’s host name.',
                    ],
                ],
                'permissions' => [
                    ['allowed' => true, 'text' => 'Create posts in the one publication you name above, as drafts or published'],
                    ['allowed' => false, 'text' => 'Edit or delete anything already published'],
                    ['allowed' => false, 'text' => 'Read your notifications or your followers'],
                ],
            ],
        ];
    }

    /** @return list<string> Every key in the catalogue, offerable or not. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The catalogue with every destination this install cannot send to removed.
     *
     * Same shape as `all()`, fewer entries: an entry survives only if the
     * module named in it is enabled, because that module's service provider is
     * the thing that registers the driver. Use it wherever a destination is
     * being **offered** — the connect page's picker, the list of networks not
     * set up yet — so that nobody is invited to paste a credential for
     * something with nothing behind it.
     *
     * Never use it to look an existing row's metadata up; that is `all()`, and
     * the class docblock says why.
     *
     * The enabled check is memoised for the length of one call rather than kept
     * in a static: two distinct modules across seventeen entries means two
     * lookups, and static state in a page-heavy application is how a long-lived
     * worker starts answering with yesterday's configuration.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function available(): array
    {
        $enabled = [];

        return array_filter(
            self::all(),
            function (array $entry) use (&$enabled): bool {
                $module = $entry['module'];

                return $enabled[$module] ??= self::moduleIsEnabled($module);
            },
        );
    }

    /** @return list<string> The keys of `available()`, for a caller that wants a diff rather than the entries. */
    public static function availableKeys(): array
    {
        return array_keys(self::available());
    }

    public static function has(string $network): bool
    {
        return array_key_exists($network, self::all());
    }

    /**
     * Is this a destination Kargah can send to on this install right now?
     *
     * False for a key the catalogue does not describe at all, which is the same
     * answer for the same reason: nothing can be sent to it.
     */
    public static function isAvailable(string $network): bool
    {
        $module = self::all()[$network]['module'] ?? null;

        return $module !== null && self::moduleIsEnabled($module);
    }

    /** The module whose service provider registers this network's driver. */
    public static function module(string $network): string
    {
        return self::all()[$network]['module'] ?? self::MODULE_SOCIAL;
    }

    /**
     * Why this destination cannot be sent to, or null when it can.
     *
     * The catalogue's half of `HttpPublisher::unavailableReason()`, and it
     * exists for the case neither `all()` nor `available()` can serve: an
     * account row that is already connected, with every credential in place, to
     * a network whose module is switched off. Filtering it off the accounts
     * page would show a person nothing at all where a destination used to be,
     * which is a worse failure than showing it — so it stays on the page and
     * this is the sentence that goes under it.
     *
     * A full sentence rather than a flag, for the same reason every other
     * failure string in this application is one: the reader has to be able to
     * act on it, and "unavailable" on its own reads as a bug in Kargah.
     */
    public static function unavailableReason(string $network): ?string
    {
        if (! self::has($network)) {
            return 'Kargah does not know a network called “'.$network.'”, so nothing can be sent to it. '
                .'That is usually a row left behind by an older version of Kargah.';
        }

        if (self::isAvailable($network)) {
            return null;
        }

        $module = self::module($network);

        return self::label($network).' is provided by Kargah’s '.$module.' module, which is not enabled on this '
            .'install, so nothing can be sent to it. Its posts and its stored credential are left alone; '
            .'switching '.$module.' back on in modules_statuses.json brings it back.';
    }

    /**
     * Ask the module repository, rather than reading `modules_statuses.json`.
     *
     * `Module::find()` and not `Module::isEnabled()`: the latter throws
     * `ModuleNotFoundException` for a module that is not merely disabled but
     * gone from disk, and a deleted module directory would then turn every page
     * that draws this catalogue into a 500. A module nobody can find and a
     * module switched off are the same answer here — nothing registers its
     * drivers either way.
     */
    private static function moduleIsEnabled(string $module): bool
    {
        return Module::find($module)?->isEnabled() === true;
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
