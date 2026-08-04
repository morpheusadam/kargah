<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\SlackPublisher;
use Modules\Social\Services\Publishers\TumblrPublisher;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The Slack and Tumblr drivers, without Slack or Tumblr.
 *
 * These are request-shape tests and they are the only evidence available: there
 * is no CA bundle in this php.ini, no app on api.slack.com and no registered
 * Tumblr application, so nothing here can be proved by making the call. Every
 * assertion is about what left Kargah, and `preventStrayRequests()` turns a
 * missed fake into a failure rather than a slow test.
 *
 * Three properties are worth more than the rest and each has a test of its own:
 *
 * - **a successful HTTP status proves nothing on either network.** Slack answers
 *   `200` with `{"ok": false}` and Tumblr answers `200` with a failing
 *   `meta.status`, so both drivers read the body first. A test that only
 *   asserted on a `4xx` would pass against a driver that treated every 200 as a
 *   published post;
 * - **Slack fetches the picture from this install rather than being sent it**,
 *   which makes an install's own address a precondition for a Slack post with
 *   images in it and for nothing else. Text still publishes from a laptop;
 * - **the Tumblr request carries an `Authorization: OAuth …` header** built by
 *   the shared `OAuth1` signer, and the copy travels in `body` or in `caption`
 *   depending on whether the post became a photo post.
 *
 * The root URL is forced to a public host for everything except the test that is
 * about a private one. That is not a workaround for the guard — it is what makes
 * these describe a real install rather than this laptop, and the guard itself is
 * asserted on its own at the bottom of the Slack section.
 */
class SlackTumblrPublisherTest extends TestCase
{
    use RefreshDatabase;

    private const SLACK_POST = 'https://slack.com/api/chat.postMessage';

    private const SLACK_AUTH = 'https://slack.com/api/auth.test';

    /**
     * Deliberately not shaped like a real bot token.
     *
     * The first version of this constant was `xoxb-` followed by two long
     * numeric segments and a word, which is exactly Slack's real format — and
     * GitHub's push protection blocked the whole branch over it. It was invented,
     * it authenticated nothing, and none of that mattered: a scanner cannot tell
     * a plausible fake from a leak, which is precisely why it is right to refuse
     * both.
     *
     * So this keeps the `xoxb-` prefix, because the driver's error copy talks
     * about it and a fixture that dropped it would stop testing the thing people
     * actually paste, and makes everything after the prefix unmistakably prose.
     * A test fixture only has to be a distinctive string; it never has to look
     * real.
     */
    private const SLACK_TOKEN = 'xoxb-EXAMPLE-NOT-A-REAL-TOKEN-for-tests-only';

    private const CHANNEL = '#build-log';

    private const BLOG = 'kargah-workshop.tumblr.com';

    private const TUMBLR_POST = 'https://api.tumblr.com/v2/blog/kargah-workshop.tumblr.com/post';

    private const TUMBLR_INFO = 'https://api.tumblr.com/v2/user/info';

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen because a signed attachment URL carries its expiry inside the
        // signature: asserting that Slack was handed exactly the URL
        // `AttachmentService::publicUrl()` builds only holds if both are built
        // at the same instant.
        Carbon::setTestNow('2026-08-04 11:20:00');

        Storage::fake('local');

        URL::forceRootUrl('https://kargah.example.com');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* Helpers ------------------------------------------------------------------ */

    private function slackAccount(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::SLACK)->create([
            'credentials' => ['bot_token' => self::SLACK_TOKEN, 'channel' => self::CHANNEL],
            'connected_at' => now(),
        ]);
    }

    /** @param array<string, string> $overrides */
    private function tumblrAccount(array $overrides = []): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::TUMBLR)->create([
            'credentials' => array_merge([
                'blog_identifier' => self::BLOG,
                'consumer_key' => 'kargah-tumblr-consumer-key',
                'consumer_secret' => 'kargah-tumblr-consumer-secret',
                'token' => 'kargah-tumblr-oauth-token',
                'token_secret' => 'kargah-tumblr-oauth-token-secret',
            ], $overrides),
            'connected_at' => now(),
        ]);
    }

    /**
     * Real attachment rows with real bytes.
     *
     * Slack reads a signed URL through `AttachmentService::publicUrl()` and
     * Tumblr reads the bytes through `contents()`, so both halves of the
     * contract are exercised and a fake binding would prove neither.
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

    /**
     * One field out of a multipart body.
     *
     * `$request['type']` does not work for multipart: `Request::data()` hands
     * back Guzzle's list of `['name' => …, 'contents' => …]` parts rather than a
     * keyed array, so array access finds nothing and an assertion built on it
     * would pass while asserting the absence of everything.
     */
    private function part(Request $request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                $contents = $part['contents'] ?? null;

                return is_string($contents) ? $contents : null;
            }
        }

        return null;
    }

    /* Slack --------------------------------------------------------------------- */

    public function test_a_text_only_slack_message_is_one_call_with_a_channel_a_text_and_no_blocks(): void
    {
        Http::fake([
            self::SLACK_POST => Http::response([
                'ok' => true,
                'channel' => 'C0193847562',
                'ts' => '1785921000.123456',
            ]),
        ]);

        $result = (new SlackPublisher)->publish(
            $this->slackAccount(),
            'Invoice reminders now go out on Monday mornings.',
        );

        // `ts` is the message id; there is no other one.
        $this->assertSame('1785921000.123456', $result->remoteId);
        // No permalink: the workspace host is not in this response. See the class.
        $this->assertNull($result->remoteUrl);

        Http::assertSentCount(1);

        $request = $this->sent()[0];

        $this->assertSame('POST', $request->method());
        $this->assertSame(self::SLACK_POST, $request->url());
        $this->assertTrue($request->isJson());
        $this->assertSame('Bearer '.self::SLACK_TOKEN, $request->header('Authorization')[0]);

        // Identity rather than `assertArrayHasKey`: a `blocks` key carrying an
        // empty list changes how Slack renders the message, and only `===`
        // would notice one.
        $this->assertSame(
            ['channel' => self::CHANNEL, 'text' => 'Invoice reminders now go out on Monday mornings.'],
            $request->data(),
        );
    }

    /**
     * Two pictures become two image blocks, and the copy stays in `text` too.
     *
     * The `text` is not redundant when blocks are present — Slack uses it as the
     * notification fallback, so a message without it arrives as a nameless
     * push. And the `image_url` is asserted against the signed URL
     * `AttachmentService::publicUrl()` builds rather than against a pattern,
     * because a URL Slack cannot fetch fails at Slack rather than here.
     */
    public function test_two_slack_images_become_image_blocks_pointing_at_signed_urls(): void
    {
        Http::fake([
            self::SLACK_POST => Http::response([
                'ok' => true,
                'channel' => 'C0193847562',
                'ts' => '1785921444.998877',
            ]),
        ]);

        $images = $this->images(2);

        $result = (new SlackPublisher)->publish(
            $this->slackAccount(),
            'Two shots of the bench before the rebuild.',
            $images,
        );

        $this->assertSame('1785921444.998877', $result->remoteId);

        Http::assertSentCount(1);

        $request = $this->sent()[0];
        $attachments = app(AttachmentService::class);

        $this->assertSame(self::CHANNEL, $request['channel']);
        $this->assertSame('Two shots of the bench before the rebuild.', $request['text']);

        $blocks = $request['blocks'];

        $this->assertCount(3, $blocks);

        $this->assertSame([
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => 'Two shots of the bench before the rebuild.'],
        ], $blocks[0]);

        foreach ([1, 2] as $position) {
            $image = $images[$position - 1];

            $this->assertSame('image', $blocks[$position]['type']);
            $this->assertSame($attachments->publicUrl($image->id), $blocks[$position]['image_url']);
            $this->assertStringContainsString('signature=', (string) $blocks[$position]['image_url']);
            // Required by Slack, and the attachment's own name rather than a
            // placeholder — it is what a screen reader announces.
            $this->assertSame('workshop-bench-'.$position.'.jpg', $blocks[$position]['alt_text']);
        }
    }

    /**
     * The trap: HTTP 200 and nothing was posted.
     *
     * `not_in_channel` is the commonest setup mistake on this network and its
     * fix is invisible — installing an app to a workspace joins it to no
     * channel. `HttpPublisher::detailFrom()` would have found the token and
     * printed `not_in_channel` into a red row, which tells the reader nothing.
     */
    public function test_a_slack_ok_false_on_a_200_is_a_failure_that_names_the_invite(): void
    {
        Http::fake([
            self::SLACK_POST => Http::response(['ok' => false, 'error' => 'not_in_channel'], 200),
        ]);

        try {
            (new SlackPublisher)->publish($this->slackAccount(), 'This one never arrived.');

            $this->fail('an ok: false answer must not read as a published message');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('not a member of that channel', $e->getMessage());
            $this->assertStringContainsString('/invite', $e->getMessage());
            // The raw token on its own would be the unhelpful answer.
            $this->assertStringNotContainsString('not_in_channel', $e->getMessage());
        }
    }

    public function test_slack_verify_calls_auth_test_names_the_bot_and_publishes_nothing(): void
    {
        Http::fake([
            self::SLACK_AUTH => Http::response([
                'ok' => true,
                'url' => 'https://kargah-workshop.slack.com/',
                'team' => 'Kargah Workshop',
                'user' => 'kargah',
                'team_id' => 'T0192837465',
                'user_id' => 'U0192837466',
                'bot_id' => 'B0192837467',
            ]),
        ]);

        $this->assertSame('@kargah in Kargah Workshop', (new SlackPublisher)->verify($this->slackAccount()));

        Http::assertSentCount(1);

        $this->assertSame(self::SLACK_AUTH, $this->sent()[0]->url());

        // The contract on `Publisher::verify()`: verifying puts nothing in a
        // channel. `auth.test` is a POST, so the assertion is about where it
        // went rather than about the verb.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'chat.'));
    }

    /**
     * The one precondition that is about the machine rather than the credentials.
     *
     * Slack fetches an image block's picture from this install, so a Slack post
     * with pictures cannot work from an address the internet cannot reach — and
     * a text-only one is completely unaffected, which is why the guard lives on
     * the media path and not in `unavailableReason()`. `ThreadsPublisher` splits
     * the same way.
     */
    public function test_a_localhost_install_refuses_a_slack_picture_but_still_posts_text(): void
    {
        Http::fake([
            self::SLACK_POST => Http::response(['ok' => true, 'channel' => 'C0193847562', 'ts' => '1785922000.010203']),
        ]);

        $images = $this->images(1);
        $account = $this->slackAccount();
        $slack = new SlackPublisher;

        URL::forceRootUrl('http://localhost');

        // Nothing has to be fetched, so this goes out exactly as before.
        $this->assertNull($slack->unavailableReason($account));
        $this->assertSame('1785922000.010203', $slack->publish($account, 'A note with no picture.')->remoteId);

        try {
            $slack->publish($account, 'A note with a picture.', $images);

            $this->fail('Slack was handed a picture from an install it cannot reach');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('localhost', $e->getMessage());
            $this->assertStringContainsString('APP_URL', $e->getMessage());
        }

        // The text post, and nothing at all from the picture attempt.
        Http::assertSentCount(1);
    }

    /* Tumblr -------------------------------------------------------------------- */

    /**
     * A legacy text post, signed, with a title taken from the first line.
     *
     * The body is asserted whole: the first line becomes the title and is
     * deliberately **not** stripped out of the copy. See the class docblock on
     * `TumblrPublisher`, and `WordPressPublisher`'s, which decided it first.
     */
    public function test_a_tumblr_text_post_is_a_legacy_type_text_signed_with_oauth(): void
    {
        Http::fake([
            self::TUMBLR_POST => Http::response([
                'meta' => ['status' => 201, 'msg' => 'Created'],
                'response' => ['id' => 794455661234, 'id_string' => '794455661234'],
            ]),
        ]);

        $body = "Invoice reminders now go out on Monday.\n\nThe cron claims rows with a conditional update, "
            .'so two runs cannot double-send.';

        $result = (new TumblrPublisher)->publish($this->tumblrAccount(), $body);

        $this->assertSame('794455661234', $result->remoteId);
        $this->assertSame('https://'.self::BLOG.'/post/794455661234', $result->remoteUrl);

        Http::assertSentCount(1);

        $request = $this->sent()[0];

        $this->assertSame('POST', $request->method());
        $this->assertSame(self::TUMBLR_POST, $request->url());
        // JSON rather than form-encoded, so that none of these fields belongs in
        // the signature base string. See `OAuth1`.
        $this->assertTrue($request->isJson());

        $this->assertSame('text', $request['type']);
        $this->assertSame('Invoice reminders now go out on Monday.', $request['title']);
        $this->assertSame($body, $request['body']);

        $this->assertStringStartsWith('OAuth oauth_consumer_key=', $request->header('Authorization')[0]);
        $this->assertStringContainsString('oauth_signature=', $request->header('Authorization')[0]);
    }

    /**
     * One picture makes it a photo post, and the copy moves to the caption.
     *
     * A legacy photo post has no title field at all, so the absence of one is
     * part of the assertion rather than an oversight.
     */
    public function test_a_tumblr_photo_post_moves_the_copy_into_the_caption(): void
    {
        Http::fake([
            self::TUMBLR_POST => Http::response([
                'meta' => ['status' => 201, 'msg' => 'Created'],
                'response' => ['id_string' => '794455669988'],
            ]),
        ]);

        $result = (new TumblrPublisher)->publish(
            $this->tumblrAccount(),
            'The bench, finally square.',
            $this->images(1),
        );

        $this->assertSame('794455669988', $result->remoteId);

        Http::assertSentCount(1);

        $request = $this->sent()[0];

        $this->assertTrue($request->isMultipart(), 'the picture went up as something other than multipart');
        $this->assertSame('photo', $this->part($request, 'type'));
        $this->assertSame('The bench, finally square.', $this->part($request, 'caption'));
        $this->assertNull($this->part($request, 'title'), 'a legacy photo post has no title field');
        $this->assertNull($this->part($request, 'body'));

        $this->assertTrue(
            $request->hasFile('data[0]', 'jpeg-bytes-for-workshop-bench-1'),
            'the bytes are not in an indexed data part',
        );

        $this->assertStringStartsWith('OAuth ', $request->header('Authorization')[0]);
    }

    /**
     * A 503 is hit once, not three times.
     *
     * `HttpPublisher::request()` retries the same `PendingRequest`, headers
     * included, and the `Authorization` header here is a nonce that is single
     * use by definition — `TumblrPublisher::TRIES` overrides the default of
     * three to one for exactly that reason. This is the assertion that would
     * catch a regression back to the default: a retried request would still
     * fail, but as a misdiagnosed 401 rather than as the transient error it
     * actually was.
     */
    public function test_a_transient_tumblr_failure_is_not_retried_because_the_nonce_would_be_replayed(): void
    {
        Http::fake([self::TUMBLR_POST => Http::response('Service Unavailable', 503)]);

        try {
            (new TumblrPublisher)->publish($this->tumblrAccount(), 'This one hits a transient failure.');

            $this->fail('a 503 must not be retried with a replayed signature');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    /**
     * Tumblr's own trap: HTTP 200 with a failing `meta.status`.
     *
     * The same shape as Slack's `ok: false` and Telegram's, and the reason both
     * drivers in this file read the body before they believe the status line.
     */
    public function test_a_tumblr_meta_status_of_401_on_a_200_is_a_failure(): void
    {
        Http::fake([
            self::TUMBLR_POST => Http::response([
                'meta' => ['status' => 401, 'msg' => 'Not Authorized'],
                'response' => [],
            ], 200),
        ]);

        try {
            (new TumblrPublisher)->publish($this->tumblrAccount(), 'This one is not going anywhere.');

            $this->fail('a failing meta.status must not read as a published post');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('meta.status 401', $e->getMessage());
            $this->assertStringContainsString('Not Authorized', $e->getMessage());
            // And the next thing to do, which Tumblr's own message never says.
            $this->assertStringContainsString('token secret', $e->getMessage());
        }
    }

    /**
     * `user/info` is asked what the account owns, not merely whether it exists.
     *
     * 'The token works but that blog is not yours' is the failure that would
     * otherwise wait until somebody's first real post, so it is worth a call at
     * connect time. The second half proves the happy path still passes — a check
     * that only ever failed would be indistinguishable from a broken matcher.
     */
    public function test_tumblr_verify_refuses_a_blog_the_token_does_not_own(): void
    {
        Http::fake([
            self::TUMBLR_INFO => Http::sequence()
                ->push([
                    'meta' => ['status' => 200, 'msg' => 'OK'],
                    'response' => ['user' => ['name' => 'nima', 'blogs' => [
                        ['name' => 'nima-reading-notes', 'url' => 'https://nima-reading-notes.tumblr.com/'],
                    ]]],
                ])
                ->push([
                    'meta' => ['status' => 200, 'msg' => 'OK'],
                    'response' => ['user' => ['name' => 'nima', 'blogs' => [
                        ['name' => 'nima-reading-notes', 'url' => 'https://nima-reading-notes.tumblr.com/'],
                        ['name' => 'kargah-workshop', 'url' => 'https://kargah-workshop.tumblr.com/'],
                    ]]],
                ]),
        ]);

        $tumblr = new TumblrPublisher;
        $account = $this->tumblrAccount();

        try {
            $tumblr->verify($account);

            $this->fail('a blog the account does not own must not verify');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString(self::BLOG, $e->getMessage());
            $this->assertStringContainsString('nima-reading-notes.tumblr.com', $e->getMessage());
            $this->assertStringContainsString('nima', $e->getMessage());
        }

        $this->assertSame(self::BLOG.', as @nima', $tumblr->verify($account));

        Http::assertSentCount(2);

        foreach ($this->sent() as $request) {
            $this->assertSame('GET', $request->method(), 'a verify wrote something: '.$request->url());
            $this->assertStringStartsWith('OAuth ', $request->header('Authorization')[0]);
        }
    }

    /* Both ---------------------------------------------------------------------- */

    /**
     * The copy is checked against the catalogue before anything moves.
     *
     * One over each limit rather than a round number, because the boundary is
     * where a mistake in either direction would show. Slack's 3,000 is the block
     * limit rather than `chat.postMessage`'s 40,000 — see the note on the
     * catalogue entry — and Tumblr's 10,000 is Kargah's own number, because
     * Tumblr documents no ceiling at all.
     */
    public function test_an_over_limit_body_is_refused_before_any_request_for_both(): void
    {
        Http::fake();

        $filler = 'Shipped the invoicing rewrite today and the reminders go out on Monday. ';

        foreach ([
            [new SlackPublisher, $this->slackAccount(), 3001, 3000],
            [new TumblrPublisher, $this->tumblrAccount(), 10001, 10000],
        ] as [$publisher, $account, $length, $limit]) {
            $body = mb_substr(str_repeat($filler, (int) ceil($length / mb_strlen($filler))), 0, $length);

            $this->assertSame($length, mb_strlen($body));

            try {
                $publisher->publish($account, $body);

                $this->fail($length.' characters must not be sent to a network that allows '.$limit);
            } catch (PublishFailed $e) {
                $this->assertStringContainsString($length.' characters', $e->getMessage());
                $this->assertStringContainsString((string) $limit, $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }
}
