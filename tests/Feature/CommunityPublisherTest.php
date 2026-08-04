<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\LemmyPublisher;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\RedditPublisher;
use Modules\Social\Services\Publishers\VkPublisher;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * VK, Reddit and Lemmy — the three destinations that are communities rather
 * than feeds, without any of the three.
 *
 * These are request-shape tests and they are the only evidence available: the
 * real calls cannot be made from here, because there is no CA bundle in this
 * php.ini and no account on any of the three. So every one of these asserts what
 * left Kargah rather than what came back, and `preventStrayRequests()` makes a
 * missed fake a failure rather than a request to somebody's real wall.
 *
 * Three things are asserted here that are not obvious and would each cost a
 * debugging cycle if they regressed.
 *
 * **All three answer HTTP 200 with a failure in the body**, in three different
 * shapes — VK's `error.error_code`, Reddit's `json.errors` triples, and (for
 * Lemmy) a login that returns without a `jwt`. A driver that trusted the status
 * code would mark the target delivered and record a remote id of nothing, so
 * each of those envelopes has its own test.
 *
 * **Reddit and Lemmy invent a title the composer never asked for**, and the rule
 * that matters is the second half of it: the first line is copied into the title
 * and **left in the body**. Both title tests assert the body is byte-for-byte
 * what was handed in, because the tempting version of this feature — strip the
 * line so it does not appear twice — is silent data loss.
 *
 * **The password must not reach an error message.** Reddit and Lemmy are the two
 * credentials in this catalogue that are real account passwords, and the last
 * test here forces a refusal whose body echoes the password back — which a real
 * instance has no reason to do, and which is exactly why the drivers scrub it
 * rather than trusting that.
 */
class CommunityPublisherTest extends TestCase
{
    use RefreshDatabase;

    /** The API version `VkPublisher` pins. Bumping it should break these. */
    private const VK_VERSION = '5.199';

    private const VK_TOKEN = 'vk1.a.tokenForTheKargahBuildLog';

    /** Negative: a community wall. The uploads then use 87654321 without the sign. */
    private const VK_OWNER = '-87654321';

    private const REDDIT_CLIENT_ID = 'kargah-script-app';

    private const REDDIT_CLIENT_SECRET = 'reddit-app-secret-for-the-build-log';

    private const REDDIT_PASSWORD = 'orchard-lathe-9142-vise';

    private const REDDIT_TOKEN = 'reddit-bearer-token-good-for-one-hour';

    private const LEMMY_PASSWORD = 'plane-iron-4471-shellac';

    private const LEMMY_JWT = 'lemmy-session-jwt-for-the-build-log';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Http::preventStrayRequests();
    }

    /* Helpers ----------------------------------------------------------------- */

    /** @param  array<string, string>  $credentials */
    private function account(string $network, array $credentials): SocialAccount
    {
        return SocialAccount::factory()->onNetwork($network)->create([
            'credentials' => $credentials,
            'connected_at' => now(),
        ]);
    }

    private function vkAccount(): SocialAccount
    {
        return $this->account(Networks::VK, [
            'access_token' => self::VK_TOKEN,
            'owner_id' => self::VK_OWNER,
        ]);
    }

    private function redditAccount(): SocialAccount
    {
        return $this->account(Networks::REDDIT, [
            'client_id' => self::REDDIT_CLIENT_ID,
            'client_secret' => self::REDDIT_CLIENT_SECRET,
            'username' => 'kargah_workshop',
            'password' => self::REDDIT_PASSWORD,
            // With the prefix on purpose: the driver has to strip it, and a
            // subreddit literally called `r/buildinpublic` does not exist.
            'subreddit' => 'r/buildinpublic',
        ]);
    }

    private function lemmyAccount(): SocialAccount
    {
        return $this->account(Networks::LEMMY, [
            'instance' => 'https://lemmy.test',
            'username' => 'kargah_workshop',
            'password' => self::LEMMY_PASSWORD,
            'community' => 'buildinpublic',
        ]);
    }

    /**
     * Real attachment rows with real bytes, because VK sends the bytes rather
     * than a link to them and a fake binding would prove nothing about the
     * multipart part they end up in.
     *
     * @return list<MediaItem>
     */
    private function images(int $count): array
    {
        $post = Post::factory()->create();
        $attachments = app(AttachmentService::class);

        $items = [];

        for ($i = 1; $i <= $count; $i++) {
            $stored = $attachments->attachContents(
                $post,
                'jpeg-bytes-for-workshop-bench-'.$i,
                'workshop-bench-'.$i.'.jpg',
                'image/jpeg',
            );

            $item = MediaItem::fromAttachment($stored);

            $this->assertNotNull($item);

            $items[] = $item;
        }

        return $items;
    }

    /** Every request that left, in the order it left. @return list<Request> */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])->values()->all();
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }

    /**
     * One parameter out of a GET's query string.
     *
     * `$request['group_id']` does not work for a GET: `Request::data()` reads
     * the *body*, and a GET has none — so array access finds nothing and an
     * assertion built on it would pass while asserting the absence of
     * everything.
     */
    private function queryParam(Request $request, string $key): ?string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

        return isset($params[$key]) && is_string($params[$key]) ? $params[$key] : null;
    }

    private function header(Request $request, string $name): ?string
    {
        $values = $request->hasHeader($name) ? $request->header($name) : [];

        return $values === [] ? null : (string) $values[0];
    }

    /* VK ----------------------------------------------------------------------- */

    public function test_a_text_only_vk_post_is_one_call_to_wall_post_with_no_attachments(): void
    {
        Http::fake([
            'api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 4231]]),
        ]);

        $result = (new VkPublisher)->publish(
            $this->vkAccount(),
            'The invoicing module shipped this week, and the changelog is up.',
        );

        $this->assertSame('4231', $result->remoteId);
        $this->assertSame('https://vk.com/wall-87654321_4231', $result->remoteUrl);

        Http::assertSentCount(1);

        $request = $this->sent()[0];

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://api.vk.com/method/wall.post', $request->url());

        // Form-encoded, not JSON: api.vk.com ignores a JSON document entirely.
        $this->assertTrue($request->isForm());

        $this->assertSame(self::VK_OWNER, $request['owner_id']);
        $this->assertSame('The invoicing module shipped this week, and the changelog is up.', $request['message']);
        $this->assertSame(self::VK_TOKEN, $request['access_token']);
        $this->assertSame(self::VK_VERSION, $request['v']);

        // Absent rather than empty — see the note in `VkPublisher::publish()`.
        $this->assertArrayNotHasKey('attachments', $request->data());

        // The wall is a community's, so the post has to be signed by the
        // community. Without this VK attributes it to the administrator whose
        // token was used — a post in the right place under the wrong name,
        // which VK reports as a complete success. See `VkPublisher::publish()`.
        $this->assertSame('1', $request['from_group']);
    }

    public function test_a_post_to_a_personal_wall_does_not_ask_to_be_signed_by_a_community(): void
    {
        Http::fake([
            'api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 907]]),
        ]);

        $account = $this->account(Networks::VK, [
            'access_token' => self::VK_TOKEN,
            // Positive: a person's own wall. `from_group` means nothing here and
            // sending it is at best ignored and at worst an argument error.
            'owner_id' => '12345678',
        ]);

        (new VkPublisher)->publish($account, 'Shipped the invoicing module.');

        $request = $this->sent()[0];

        $this->assertSame('12345678', $request['owner_id']);
        $this->assertArrayNotHasKey('from_group', $request->data());
    }

    /**
     * A pasted `club…` is a community, and losing that publishes to a stranger.
     *
     * `club87654321` is what the tail of a VK community URL looks like, so it is
     * what people paste. Reduced to its digits it becomes `87654321`, which is
     * not that community — it is whichever *person* holds that user id. VK
     * accepts it, the post succeeds, and it lands on the wrong wall with nothing
     * anywhere reporting a problem. `public…` and `event…` are the same kind of
     * page and carry the same sign; `id…` is the only prefix that means a person.
     */
    public function test_a_community_url_prefix_is_read_as_a_community_rather_than_a_person(): void
    {
        foreach (['club87654321', 'public87654321', 'event87654321', 'CLUB87654321'] as $typed) {
            Http::fake([
                'api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 11]]),
            ]);

            $account = $this->account(Networks::VK, [
                'access_token' => self::VK_TOKEN,
                'owner_id' => $typed,
            ]);

            (new VkPublisher)->publish($account, 'Shipped the invoicing module.');

            $request = $this->sent()[0];

            $this->assertSame('-87654321', $request['owner_id'], $typed.' must resolve to a community wall.');
            $this->assertSame('1', $request['from_group'], $typed.' must be signed by the community.');
        }
    }

    /** And `id…` is a person, so it keeps its positive id and is not signed by a community. */
    public function test_an_id_prefix_is_read_as_a_person(): void
    {
        Http::fake([
            'api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 12]]),
        ]);

        $account = $this->account(Networks::VK, [
            'access_token' => self::VK_TOKEN,
            'owner_id' => 'id12345678',
        ]);

        (new VkPublisher)->publish($account, 'Shipped the invoicing module.');

        $request = $this->sent()[0];

        $this->assertSame('12345678', $request['owner_id']);
        $this->assertArrayNotHasKey('from_group', $request->data());
    }

    public function test_two_vk_pictures_are_uploaded_saved_and_named_in_the_order_they_were_attached(): void
    {
        Http::fake([
            'api.vk.com/method/photos.getWallUploadServer*' => Http::sequence()
                ->push(['response' => ['upload_url' => 'https://pu.vk.com/c1234/upload.php?act=do_add&mid=1']])
                ->push(['response' => ['upload_url' => 'https://pu.vk.com/c1234/upload.php?act=do_add&mid=2']]),
            'pu.vk.com/*' => Http::sequence()
                ->push(['server' => 641234, 'photo' => '[{"photo":"first"}]', 'hash' => 'hash-one'])
                ->push(['server' => 641235, 'photo' => '[{"photo":"second"}]', 'hash' => 'hash-two']),
            'api.vk.com/method/photos.saveWallPhoto' => Http::sequence()
                ->push(['response' => [['id' => 457239018, 'owner_id' => -87654321]]])
                ->push(['response' => [['id' => 457239019, 'owner_id' => -87654321]]]),
            'api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 4232]]),
        ]);

        $result = (new VkPublisher)->publish(
            $this->vkAccount(),
            'Two shots of the bench before the rebuild.',
            $this->images(2),
        );

        $this->assertSame('4232', $result->remoteId);

        // Three per picture, then the post itself.
        Http::assertSentCount(7);

        [$serverOne, $uploadOne, $saveOne, $serverTwo, $uploadTwo, $saveTwo, $post] = $this->sent();

        foreach ([$serverOne, $serverTwo] as $ask) {
            // 🔴 A POST even though this is a read, and the assertion is here
            // rather than the obvious `GET` because the obvious one described a
            // credential leak. A token on a GET is a token in the URL, and
            // Guzzle appends the whole URI to a timeout's exception message,
            // which `PostPublisher` writes to `post_targets.error` and the posts
            // page prints. See `VkPublisher::vkSend()`.
            $this->assertSame('POST', $ask->method());
            $this->assertSame('/method/photos.getWallUploadServer', $this->path($ask));

            // And therefore in the body, where a timeout message cannot reach it.
            $this->assertStringNotContainsString(self::VK_TOKEN, $ask->url());
            $this->assertSame(self::VK_TOKEN, $ask['access_token']);

            // Positive here, negative on the post. Getting this wrong uploads to
            // the wrong album and the picture silently never appears.
            $this->assertSame('87654321', $ask['group_id']);
        }

        foreach ([$uploadOne, $uploadTwo] as $upload) {
            $this->assertSame('POST', $upload->method());
            $this->assertStringStartsWith('https://pu.vk.com/', $upload->url());
            $this->assertTrue($upload->isMultipart(), 'the picture went up as something other than multipart');
            $this->assertTrue($upload->hasFile('photo'), 'the bytes are not in a part named photo');
        }

        // The upload host's three opaque values go back untouched — `photo` in
        // particular is a signed JSON string that must not be re-encoded.
        $this->assertSame('/method/photos.saveWallPhoto', $this->path($saveOne));
        $this->assertSame('641234', $saveOne['server']);
        $this->assertSame('[{"photo":"first"}]', $saveOne['photo']);
        $this->assertSame('hash-one', $saveOne['hash']);
        $this->assertSame('87654321', $saveOne['group_id']);

        $this->assertSame('641235', $saveTwo['server']);
        $this->assertSame('[{"photo":"second"}]', $saveTwo['photo']);
        $this->assertSame('hash-two', $saveTwo['hash']);

        $this->assertSame('/method/wall.post', $this->path($post));
        $this->assertSame(
            'photo-87654321_457239018,photo-87654321_457239019',
            $post['attachments'],
        );
        $this->assertSame('Two shots of the bench before the rebuild.', $post['message']);
    }

    /**
     * VK answers 200 with an `error` object for almost every refusal, and code 5
     * is the one this network is most likely to produce — a token issued without
     * the `offline` scope dies within the day and looks identical to one that
     * does not.
     */
    public function test_a_vk_200_carrying_error_code_5_is_a_failure_that_names_the_token(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response([
                'error' => [
                    'error_code' => 5,
                    'error_msg' => 'User authorization failed: access_token has expired.',
                ],
            ], 200),
        ]);

        try {
            (new VkPublisher)->publish($this->vkAccount(), 'This one goes nowhere.');

            $this->fail('An expired VK token published a post.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('VK', $e->getMessage());
            $this->assertStringContainsString('access token was refused', $e->getMessage());
            $this->assertStringContainsString('access_token has expired', $e->getMessage());
            $this->assertStringContainsString('offline', $e->getMessage());
        }
    }

    /* Reddit -------------------------------------------------------------------- */

    public function test_reddit_fetches_a_token_then_submits_and_both_calls_identify_kargah(): void
    {
        Http::fake([
            'www.reddit.com/api/v1/access_token' => Http::response([
                'access_token' => self::REDDIT_TOKEN,
                'token_type' => 'bearer',
                'expires_in' => 3600,
            ]),
            'oauth.reddit.com/api/submit' => Http::response([
                'json' => [
                    'errors' => [],
                    'data' => [
                        'id' => '1a2b3c',
                        'name' => 't3_1a2b3c',
                        'url' => 'https://www.reddit.com/r/buildinpublic/comments/1a2b3c/shipped_invoicing/',
                    ],
                ],
            ]),
        ]);

        $result = (new RedditPublisher)->publish(
            $this->redditAccount(),
            "Shipped invoicing\n\nIt took three weekends and the VAT rounding is still wrong.",
        );

        $this->assertSame('1a2b3c', $result->remoteId);
        $this->assertSame(
            'https://www.reddit.com/r/buildinpublic/comments/1a2b3c/shipped_invoicing/',
            $result->remoteUrl,
        );

        Http::assertSentCount(2);

        [$token, $submit] = $this->sent();

        $this->assertSame('POST', $token->method());
        $this->assertSame('https://www.reddit.com/api/v1/access_token', $token->url());
        $this->assertTrue($token->isForm());
        $this->assertSame('password', $token['grant_type']);
        $this->assertSame('kargah_workshop', $token['username']);
        $this->assertSame(
            'Basic '.base64_encode(self::REDDIT_CLIENT_ID.':'.self::REDDIT_CLIENT_SECRET),
            $this->header($token, 'Authorization'),
        );

        $this->assertSame('POST', $submit->method());
        $this->assertSame('https://oauth.reddit.com/api/submit', $submit->url());
        $this->assertSame('bearer '.self::REDDIT_TOKEN, $this->header($submit, 'Authorization'));

        // The r/ prefix is stripped, `kind` is a self post, and `api_type=json`
        // is what makes the errors reachable at all.
        $this->assertSame('buildinpublic', $submit['sr']);
        $this->assertSame('self', $submit['kind']);
        $this->assertSame('json', $submit['api_type']);
        $this->assertSame('Shipped invoicing', $submit['title']);

        // 🔴 The one Reddit will throttle for if it is generic. Both calls.
        foreach ([$token, $submit] as $request) {
            $this->assertSame(
                'Kargah/1.0 (self-hosted freelance workspace)',
                $this->header($request, 'User-Agent'),
                'a Reddit call went out without the descriptive User-Agent',
            );
        }
    }

    public function test_reddit_derives_a_title_capped_at_300_and_leaves_the_body_exactly_as_it_was(): void
    {
        Http::fake([
            'www.reddit.com/api/v1/access_token' => Http::response(['access_token' => self::REDDIT_TOKEN]),
            'oauth.reddit.com/api/submit' => Http::response([
                'json' => ['errors' => [], 'data' => ['id' => '9z8y7x']],
            ]),
        ]);

        $firstLine = str_repeat('W', 400);
        $body = $firstLine."\n\nThe rest of the write-up, which is not the title.";

        (new RedditPublisher)->publish($this->redditAccount(), $body);

        $submit = $this->sent()[1];

        $title = $submit['title'];

        // Exactly Reddit's 300, ellipsis included rather than appended past it.
        $this->assertSame(300, mb_strlen((string) $title));
        $this->assertSame(str_repeat('W', 299).'…', $title);

        // 🔴 The half that matters. The line is copied, never moved: the post
        // reads its first line twice, which somebody can fix in thirty seconds,
        // and nothing they wrote has been deleted.
        $this->assertSame($body, $submit['text']);
        $this->assertStringContainsString($firstLine, (string) $submit['text']);
    }

    /**
     * Reddit's refusals arrive inside a 200 as `[code, message, field]` triples,
     * and the person on the posts page is better served by Reddit's own sentence
     * than by Kargah's paraphrase of it.
     */
    public function test_a_reddit_200_carrying_json_errors_is_a_failure_that_quotes_reddit(): void
    {
        Http::fake([
            'www.reddit.com/api/v1/access_token' => Http::response(['access_token' => self::REDDIT_TOKEN]),
            'oauth.reddit.com/api/submit' => Http::response([
                'json' => [
                    'errors' => [['SUBREDDIT_NOTALLOWED', "you aren't allowed to post there", 'sr']],
                ],
            ], 200),
        ]);

        try {
            (new RedditPublisher)->publish($this->redditAccount(), 'A post that goes nowhere.');

            $this->fail('Reddit reported errors and Kargah recorded a published post.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('Reddit', $e->getMessage());
            $this->assertStringContainsString("you aren't allowed to post there", $e->getMessage());
            $this->assertStringContainsString('private', $e->getMessage());
        }
    }

    /**
     * `max_count` is zero in the catalogue on purpose. The refusal is worded by
     * the driver because the catalogue's arithmetic sentence at a limit of zero
     * reads as a bug rather than as a decision — and it happens before a token
     * is even fetched, so an image post costs no requests at all.
     */
    public function test_a_reddit_post_with_a_picture_attached_is_refused_before_any_request(): void
    {
        Http::fake();

        try {
            (new RedditPublisher)->publish(
                $this->redditAccount(),
                'Here is the bench.',
                $this->images(1),
            );

            $this->fail('Reddit accepted a picture.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('text posts only', $e->getMessage());
            $this->assertStringContainsString('workshop-bench-1.jpg', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /* Lemmy --------------------------------------------------------------------- */

    public function test_lemmy_logs_in_resolves_the_community_by_name_and_posts_with_a_bearer_header(): void
    {
        Http::fake([
            'lemmy.test/api/v3/user/login' => Http::response(['jwt' => self::LEMMY_JWT]),
            'lemmy.test/api/v3/community*' => Http::response([
                'community_view' => ['community' => ['id' => 42, 'name' => 'buildinpublic']],
            ]),
            'lemmy.test/api/v3/post' => Http::response([
                'post_view' => [
                    'post' => ['id' => 90210, 'ap_id' => 'https://lemmy.test/post/90210'],
                ],
            ]),
        ]);

        $result = (new LemmyPublisher)->publish(
            $this->lemmyAccount(),
            "Kargah runs on shared hosting\n\nNo Redis, no daemon, one cron a minute.",
        );

        $this->assertSame('90210', $result->remoteId);
        // `ap_id`, because that is the link that works from another instance.
        $this->assertSame('https://lemmy.test/post/90210', $result->remoteUrl);

        Http::assertSentCount(3);

        [$login, $lookup, $post] = $this->sent();

        $this->assertSame('POST', $login->method());
        $this->assertSame('https://lemmy.test/api/v3/user/login', $login->url());
        $this->assertSame('kargah_workshop', $login['username_or_email']);
        // Nothing to authorise with yet, and nothing pretending otherwise.
        $this->assertFalse($login->hasHeader('Authorization'));

        $this->assertSame('GET', $lookup->method());
        $this->assertSame('/api/v3/community', $this->path($lookup));
        $this->assertSame('buildinpublic', $this->queryParam($lookup, 'name'));
        $this->assertSame('Bearer '.self::LEMMY_JWT, $this->header($lookup, 'Authorization'));

        $this->assertSame('POST', $post->method());
        $this->assertSame('https://lemmy.test/api/v3/post', $post->url());
        // 🔴 The bearer header, not an `auth` field in the body — the 0.19 and
        // later shape. See the version note on `LemmyPublisher`.
        $this->assertSame('Bearer '.self::LEMMY_JWT, $this->header($post, 'Authorization'));
        $this->assertArrayNotHasKey('auth', $post->data());

        $this->assertSame('Kargah runs on shared hosting', $post['name']);
        $this->assertSame(42, $post['community_id']);
        // No picture, so no `url` at all rather than an empty one.
        $this->assertArrayNotHasKey('url', $post->data());
    }

    public function test_lemmy_derives_a_title_capped_at_200_and_leaves_the_body_exactly_as_it_was(): void
    {
        Http::fake([
            'lemmy.test/api/v3/user/login' => Http::response(['jwt' => self::LEMMY_JWT]),
            'lemmy.test/api/v3/community*' => Http::response([
                'community_view' => ['community' => ['id' => 42]],
            ]),
            'lemmy.test/api/v3/post' => Http::response([
                'post_view' => ['post' => ['id' => 90211, 'ap_id' => 'https://lemmy.test/post/90211']],
            ]),
        ]);

        $firstLine = str_repeat('L', 350);
        $body = $firstLine."\n\nThe rest of the write-up, which is not the title.";

        (new LemmyPublisher)->publish($this->lemmyAccount(), $body);

        $post = $this->sent()[2];

        $this->assertSame(200, mb_strlen((string) $post['name']));
        $this->assertSame(str_repeat('L', 199).'…', $post['name']);

        // 🔴 The body is what was handed in, to the character.
        $this->assertSame($body, $post['body']);
        $this->assertStringContainsString($firstLine, (string) $post['body']);
    }

    /* Verify, which must never publish -------------------------------------------- */

    /**
     * All three report an identity and none of them creates anything.
     *
     * Two of the three sign in with a POST — Reddit fetches a token and Lemmy
     * has no endpoint that will say anything without a session — so 'verify is a
     * GET' is the wrong assertion here. The right one is that nothing reached
     * the endpoint that makes a post, which is what the promise on
     * `Publisher::verify()` actually is.
     */
    public function test_every_verify_reports_an_identity_and_publishes_nothing(): void
    {
        Http::fake([
            'api.vk.com/method/users.get*' => Http::response([
                'response' => [['id' => 12345678, 'first_name' => 'Nima', 'last_name' => 'Fazlipour']],
            ]),
            'www.reddit.com/api/v1/access_token' => Http::response(['access_token' => self::REDDIT_TOKEN]),
            'oauth.reddit.com/api/v1/me' => Http::response(['name' => 'kargah_workshop', 'id' => 'a1b2c3']),
            'lemmy.test/api/v3/user/login' => Http::response(['jwt' => self::LEMMY_JWT]),
            'lemmy.test/api/v3/site' => Http::response([
                'my_user' => ['local_user_view' => ['person' => ['name' => 'kargah_workshop']]],
            ]),
        ]);

        $this->assertSame(
            'Nima Fazlipour (id12345678)',
            (new VkPublisher)->verify($this->vkAccount()),
        );

        $this->assertSame(
            'u/kargah_workshop',
            (new RedditPublisher)->verify($this->redditAccount()),
        );

        $this->assertSame(
            '@kargah_workshop@lemmy.test',
            (new LemmyPublisher)->verify($this->lemmyAccount()),
        );

        foreach ($this->sent() as $request) {
            foreach (['wall.post', '/api/submit', '/api/v3/post'] as $publishing) {
                $this->assertStringNotContainsString(
                    $publishing,
                    $request->url(),
                    'a verify published something: '.$request->url(),
                );
            }
        }
    }

    /* The password, which must never leave the driver ------------------------------ */

    /**
     * 🔴 The one test here that is about a leak rather than a shape.
     *
     * Reddit and Lemmy are the only two credentials in this catalogue that are
     * real account passwords, and a `PublishFailed` message is written to
     * `post_targets.error` and rendered on a page — so a password that reached
     * one would be a permanent plaintext credential in the database.
     *
     * Both refusals here echo the password back in their own body, which no real
     * server has any reason to do. That is the point: `detailFrom()` copies up
     * to 300 characters of whatever the network said into the message, and the
     * drivers scrub rather than trusting a body they do not control.
     */
    public function test_a_password_never_reaches_a_failure_message(): void
    {
        Http::fake([
            'www.reddit.com/api/v1/access_token' => Http::response([
                'error' => 'invalid_grant for '.self::REDDIT_PASSWORD,
            ], 401),
            'lemmy.test/api/v3/user/login' => Http::response([
                'error' => 'incorrect_login: '.self::LEMMY_PASSWORD,
            ], 401),
        ]);

        try {
            (new RedditPublisher)->publish($this->redditAccount(), 'A post nobody will see.');

            $this->fail('Reddit published with a refused password.');
        } catch (PublishFailed $e) {
            $this->assertStringNotContainsString(self::REDDIT_PASSWORD, $e->getMessage());
            $this->assertStringContainsString('[password removed]', $e->getMessage());
        }

        try {
            (new LemmyPublisher)->publish($this->lemmyAccount(), 'A post nobody will see.');

            $this->fail('Lemmy published with a refused password.');
        } catch (PublishFailed $e) {
            $this->assertStringNotContainsString(self::LEMMY_PASSWORD, $e->getMessage());
            $this->assertStringContainsString('[password removed]', $e->getMessage());
        }
    }
}
