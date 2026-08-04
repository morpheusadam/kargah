<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\XPublisher;
use Modules\Social\Support\Networks;
use Modules\Social\Support\OAuth1;
use Tests\TestCase;

/**
 * The X driver, and the signature everything about it depends on.
 *
 * Two properties are worth a test each and neither is visible in a response
 * body, which is why every assertion here is about the shape of the **request**:
 *
 * - the tweet is v2 JSON on `api.twitter.com` and the pictures are v1.1
 *   multipart on `upload.twitter.com`, in that order, with the media ids taken
 *   from `media_id_string` and never from `media_id`;
 * - the OAuth 1.0a signature is computed over the `oauth_*` parameters and the
 *   query string and over **nothing else**. A signature that included the JSON
 *   body would be refused with a bare 401, so the test that two different tweets
 *   sent at the same instant carry an identical `Authorization` header is the
 *   one that would catch the mistake — a live call could only report that it
 *   failed.
 *
 * The expected signatures were computed from RFC 5849 §3.4 independently of the
 * class under test, so a change to the encoding rules — `urlencode()` for
 * `rawurlencode()`, an unsorted parameter list, the query dropped — fails here
 * rather than in production with no explanation.
 *
 * No test touches the network. `preventStrayRequests()` makes that a failure
 * rather than a slow test, and this machine has no CA bundle so a real call
 * could not succeed anyway.
 */
class XPublisherTest extends TestCase
{
    use RefreshDatabase;

    /** A fixed nonce, so a signature is a value a test can assert on. */
    private const NONCE = '7f3c1d9a5b2e4086af61c0d3e2b7a495';

    private const STAMP = 1785921000;

    private const TWEETS = 'https://api.twitter.com/2/tweets';

    private const UPLOAD = 'https://upload.twitter.com/1.1/media/upload.json';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-04 09:30:00');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        // The nonce factory is global state on `Str`; left set, it would hand
        // the same nonce to every later test in the suite.
        Str::createRandomStringsNormally();

        parent::tearDown();
    }

    /* Helpers ------------------------------------------------------------------ */

    /** An account with all four credentials, each of them `test-credential`. */
    private function account(): SocialAccount
    {
        return SocialAccount::factory()
            ->onNetwork(Networks::X)
            ->connected()
            ->create(['handle' => '@kargah_buildlog']);
    }

    /**
     * Two pictures, with bytes that come from a stubbed `AttachmentService`.
     *
     * `MediaItem::contents()` resolves the contract out of the container rather
     * than touching a disk, so a driver test needs no storage fake and no rows
     * in Data's tables.
     *
     * @return list<MediaItem>
     */
    private function media(): array
    {
        $this->mock(
            AttachmentService::class,
            fn ($mock) => $mock->shouldReceive('contents')->andReturnUsing(
                fn (int $id): string => 'png-bytes-of-'.$id,
            ),
        );

        return [
            new MediaItem(id: 41, name: 'board-after-the-rewrite.png', mime: 'image/png', sizeBytes: 240_118),
            new MediaItem(id: 42, name: 'invoice-reminder-settings.png', mime: 'image/png', sizeBytes: 118_402),
        ];
    }

    /** The hosts every recorded request went to, in the order they were made. */
    private function hostsInOrder(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_HOST))
            ->all();
    }

    /* The tweet ---------------------------------------------------------------- */

    public function test_a_text_only_tweet_posts_json_with_no_media_key(): void
    {
        Http::fake([
            self::TWEETS => Http::response([
                'data' => ['id' => '1789310022114455661', 'text' => 'Shipped the invoice reminders this week.'],
            ], 201),
        ]);

        $result = (new XPublisher)->publish($this->account(), 'Shipped the invoice reminders this week.');

        $this->assertSame('1789310022114455661', $result->remoteId);
        $this->assertSame('https://x.com/kargah_buildlog/status/1789310022114455661', $result->remoteUrl);

        Http::assertSentCount(1);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::TWEETS
            && $request->method() === 'POST'
            && $request->isJson()
            // Identity, not `assertArrayHasKey`: a stray `media` key with an
            // empty list is refused by X, and only `===` would notice one.
            && $request->data() === ['text' => 'Shipped the invoice reminders this week.']
            && str_starts_with($request->header('Authorization')[0], 'OAuth oauth_consumer_key='));
    }

    /**
     * Pictures go up first, and the tweet quotes the string ids back.
     *
     * The fake answers with a `media_id` whose last digits differ from
     * `media_id_string`, which is what a 64-bit id looks like after a JSON
     * decoder has put it through a double. A driver that read the integer would
     * attach a picture that does not exist, and this asserts on the difference
     * rather than on a pair of values that happen to match.
     */
    public function test_two_images_are_uploaded_first_and_named_in_attach_order(): void
    {
        Http::fake([
            self::UPLOAD => Http::sequence()
                ->push(['media_id' => 1690000000000000000, 'media_id_string' => '1690000000000000001'])
                ->push(['media_id' => 1690000000000000000, 'media_id_string' => '1690000000000000002']),
            self::TWEETS => Http::response(['data' => ['id' => '1789310098877665544']], 201),
        ]);

        $result = (new XPublisher)->publish(
            $this->account(),
            'Two screenshots from the rewrite.',
            $this->media(),
        );

        $this->assertSame('1789310098877665544', $result->remoteId);

        // The order is the assertion: a tweet naming a media id that has not
        // been uploaded yet is refused.
        $this->assertSame(
            ['upload.twitter.com', 'upload.twitter.com', 'api.twitter.com'],
            $this->hostsInOrder(),
        );

        Http::assertSent(fn (Request $request): bool => $request->url() === self::UPLOAD
            && $request->isMultipart()
            && $request->hasFile('media', 'png-bytes-of-41', 'image-41.png'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::UPLOAD
            && $request->hasFile('media', 'png-bytes-of-42', 'image-42.png'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::TWEETS
            && $request->data() === [
                'text' => 'Two screenshots from the rewrite.',
                'media' => ['media_ids' => ['1690000000000000001', '1690000000000000002']],
            ]);
    }

    /* Verification -------------------------------------------------------------- */

    public function test_verify_names_the_account_and_posts_nothing(): void
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => [
                    'id' => '1445829901',
                    'name' => 'Nima Fazlipour',
                    'username' => 'kargah_buildlog',
                ],
            ]),
        ]);

        $this->assertSame('@kargah_buildlog', (new XPublisher)->verify($this->account()));

        Http::assertSentCount(1);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'user.fields=username'));

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    /* Failure ------------------------------------------------------------------- */

    /**
     * A 403 says what X said, and then says what to do about it.
     *
     * X's v2 errors are RFC 7807 — `title` and `detail` — and
     * `HttpPublisher::detailFrom()` looks in four keys, none of which is either
     * of those. Without the override this message would be the raw JSON.
     */
    public function test_a_forbidden_response_carries_x_s_own_words_into_the_failure(): void
    {
        Http::fake([
            'api.twitter.com/*' => Http::response([
                'title' => 'Forbidden',
                'detail' => 'You are not permitted to perform this action.',
                'type' => 'about:blank',
                'status' => 403,
            ], 403),
        ]);

        try {
            (new XPublisher)->publish($this->account(), 'This one is not going anywhere.');

            $this->fail('a 403 must not read as a published tweet');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringContainsString('You are not permitted to perform this action.', $e->getMessage());
            // The commonest cause by a distance, and nothing in X's own message
            // hints at it.
            $this->assertStringContainsString('Read and write', $e->getMessage());
        }
    }

    /**
     * The copy is checked against the catalogue before anything moves.
     *
     * 281 characters rather than 400, because the boundary is where a mistake
     * in either direction would show.
     */
    public function test_a_body_over_the_limit_is_refused_before_any_request_is_made(): void
    {
        Http::fake();

        $body = mb_substr(
            str_repeat('Shipped the invoicing rewrite today and the reminders go out on Monday. ', 5),
            0,
            281,
        );

        $this->assertSame(281, mb_strlen($body));

        try {
            (new XPublisher)->publish($this->account(), $body);

            $this->fail('281 characters must not be sent to a network that allows 280');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('281 characters', $e->getMessage());
            $this->assertStringContainsString('280', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /* The signature ------------------------------------------------------------- */

    /**
     * The exact header, for a fixed nonce and timestamp.
     *
     * Both strings were computed from RFC 5849 §3.4 by hand rather than read out
     * of this class, so they fail if the encoding, the sort order or the base
     * string's three-part shape changes.
     */
    public function test_the_signer_produces_the_documented_signature(): void
    {
        $signer = $this->signer();

        $this->assertSame(
            'OAuth oauth_consumer_key="kargah-api-key", oauth_nonce="7f3c1d9a5b2e4086af61c0d3e2b7a495", '
            .'oauth_signature="GdnsdtmDcSNCrjjNSTsx%2B%2FgO37Q%3D", oauth_signature_method="HMAC-SHA1", '
            .'oauth_timestamp="1785921000", oauth_token="1445829901-BuildLogAccessToken", oauth_version="1.0"',
            $signer->header(
                'GET',
                'https://api.twitter.com/2/users/me',
                ['user.fields' => 'username'],
                self::NONCE,
                self::STAMP,
            ),
        );

        $this->assertSame(
            'OAuth oauth_consumer_key="kargah-api-key", oauth_nonce="7f3c1d9a5b2e4086af61c0d3e2b7a495", '
            .'oauth_signature="Zo4DdZfBnaRn9VOfERCnXa0njds%3D", oauth_signature_method="HMAC-SHA1", '
            .'oauth_timestamp="1785921000", oauth_token="1445829901-BuildLogAccessToken", oauth_version="1.0"',
            $signer->header('POST', self::TWEETS, [], self::NONCE, self::STAMP),
        );
    }

    /**
     * Query parameters are signed; the URL they came attached to is not.
     *
     * The first half proves a caller cannot be quietly wrong by passing the URL
     * it already has. The second proves the query genuinely reaches the base
     * string — a signer that ignored it would pass the first half as well.
     */
    public function test_a_query_parameter_is_signed_whether_it_arrives_inline_or_separately(): void
    {
        $signer = $this->signer();

        $expected = $signer->header(
            'GET',
            'https://api.twitter.com/2/users/me',
            ['user.fields' => 'username'],
            self::NONCE,
            self::STAMP,
        );

        $this->assertSame(
            $expected,
            $signer->header('GET', 'https://api.twitter.com/2/users/me?user.fields=username', [], self::NONCE, self::STAMP),
        );

        $this->assertNotSame(
            $expected,
            $signer->header('GET', 'https://api.twitter.com/2/users/me', [], self::NONCE, self::STAMP),
        );
    }

    /**
     * A space is `%20`, twice over.
     *
     * `urlencode()` writes `+`, which signs perfectly and is refused. The base
     * string encodes each parameter and then encodes the joined list again, so
     * the space that survived as `%20` appears in the finished base string as
     * `%2520` — and a `+` anywhere in it is the bug this catches.
     */
    public function test_the_base_string_uses_rfc_3986_encoding_rather_than_form_encoding(): void
    {
        $base = $this->signer()->baseString(
            'GET',
            'https://api.twitter.com/2/tweets/search/recent',
            ['query' => 'kargah build log'],
        );

        $this->assertStringContainsString('query%3Dkargah%2520build%2520log', $base);
        $this->assertStringNotContainsString('+', $base);
    }

    /**
     * The JSON body never reaches the signature.
     *
     * Both tweets are signed at the same frozen instant with the same pinned
     * nonce, so the only thing that differs between the two requests is the
     * body — and the two `Authorization` headers must still be identical. This
     * is the assertion that would have caught the mistake the whole `OAuth1`
     * docblock is about; a live call could only have reported HTTP 401.
     */
    public function test_two_different_tweets_signed_at_one_instant_carry_the_same_header(): void
    {
        Str::createRandomStringsUsing(fn (): string => self::NONCE);

        Http::fake([self::TWEETS => Http::response(['data' => ['id' => '1789310044556677889']], 201)]);

        $account = $this->account();
        $publisher = new XPublisher;

        $publisher->publish($account, 'One sentence about the week.');
        $publisher->publish($account, 'A different sentence, of an entirely different length, about the same week.');

        $headers = Http::recorded()
            ->map(fn (array $pair): string => $pair[0]->header('Authorization')[0])
            ->all();

        $this->assertCount(2, $headers);
        $this->assertSame($headers[0], $headers[1], 'the JSON body reached the signature base string');

        // And it is exactly the header for this method and URL with no query and
        // no body at all — the account factory sets all four credentials to the
        // same string, which is what makes the expected value writable here.
        $this->assertSame(
            (new OAuth1('test-credential', 'test-credential', 'test-credential', 'test-credential'))
                ->header('POST', self::TWEETS, [], self::NONCE, now()->getTimestamp()),
            $headers[0],
        );
    }

    private function signer(): OAuth1
    {
        return new OAuth1(
            'kargah-api-key',
            'kargah-api-secret',
            '1445829901-BuildLogAccessToken',
            'kargah-access-token-secret',
        );
    }
}
