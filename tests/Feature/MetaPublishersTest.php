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
use Modules\Social\Services\Publishers\FacebookPagePublisher;
use Modules\Social\Services\Publishers\InstagramPublisher;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\ThreadsPublisher;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The three Meta drivers, without Meta.
 *
 * These are request-shape tests and they are the only evidence available: the
 * real calls cannot be made from here — there is no CA bundle in this php.ini,
 * no app on developers.facebook.com and, for Instagram and Threads, no public
 * address for Meta to fetch a picture from. So every one of these asserts what
 * left Kargah rather than what came back, and `preventStrayRequests()` makes a
 * missed fake a failure rather than a slow test.
 *
 * **The version segment is written out in full here on purpose.** It is pinned
 * in `MetaGraph::GRAPH_VERSION`, and bumping it should break these assertions —
 * a Graph version change is a thing somebody should have to look at, not
 * something a wildcard quietly absorbs.
 *
 * **The root URL is forced to a public host.** Instagram and Threads hand Meta a
 * URL and Meta fetches it, so both refuse to publish a picture from an install
 * that answers on localhost — which is what APP_URL is on this machine and in
 * this test suite. Forcing it here is not a workaround for the guard; it is what
 * makes these tests describe a real install rather than this laptop. The guard
 * itself is asserted separately at the bottom.
 */
class MetaPublishersTest extends TestCase
{
    use RefreshDatabase;

    /** The Graph version `MetaGraph` pins. See the class docblock. */
    private const V = 'v23.0';

    private const PAGE_ID = '102938475610293';

    private const PAGE_TOKEN = 'EAAGpageTokenForTheKargahBuildLog';

    private const IG_USER_ID = '17841400000000000';

    private const IG_TOKEN = 'EAAGinstagramTokenForTheKargahBuildLog';

    private const THREADS_USER_ID = '78901234567890123';

    private const THREADS_TOKEN = 'THQVthreadsTokenForTheKargahBuildLog';

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen because a signed URL carries its expiry in the signature: the
        // assertion that Instagram was handed exactly the URL
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

    /* Helpers ----------------------------------------------------------------- */

    /** @param  array<string, string>  $credentials */
    private function account(string $network, array $credentials): SocialAccount
    {
        return SocialAccount::factory()->onNetwork($network)->create([
            'credentials' => $credentials,
            'connected_at' => now(),
        ]);
    }

    private function facebookAccount(): SocialAccount
    {
        return $this->account(Networks::FACEBOOK_PAGE, [
            'page_id' => self::PAGE_ID,
            'page_access_token' => self::PAGE_TOKEN,
        ]);
    }

    private function instagramAccount(): SocialAccount
    {
        return $this->account(Networks::INSTAGRAM, [
            'ig_user_id' => self::IG_USER_ID,
            'access_token' => self::IG_TOKEN,
        ]);
    }

    private function threadsAccount(): SocialAccount
    {
        return $this->account(Networks::THREADS, [
            'threads_user_id' => self::THREADS_USER_ID,
            'access_token' => self::THREADS_TOKEN,
        ]);
    }

    /**
     * A real attachment row with real bytes, because both halves are exercised:
     * Facebook reads the bytes through `AttachmentService::contents()` and
     * Instagram and Threads read a signed URL through `publicUrl()`. A fake
     * binding would prove neither.
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
     * One field out of a multipart body.
     *
     * `$request['published']` does not work for multipart: `Request::data()`
     * returns Guzzle's list of `['name' => …, 'contents' => …]` parts rather
     * than a keyed array, so array access finds nothing and an assertion built
     * on it would pass while asserting the absence of everything.
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

    /* Facebook Page ------------------------------------------------------------ */

    public function test_a_text_only_facebook_post_is_one_call_to_feed_with_no_attached_media(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => self::PAGE_ID.'_5544332211']),
        ]);

        $result = (new FacebookPagePublisher)->publish(
            $this->facebookAccount(),
            'Shipped the invoicing module this week.',
        );

        $this->assertSame(self::PAGE_ID.'_5544332211', $result->remoteId);
        $this->assertSame('https://www.facebook.com/'.self::PAGE_ID.'_5544332211', $result->remoteUrl);

        Http::assertSentCount(1);

        $request = $this->sent()[0];

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://graph.facebook.com/'.self::V.'/'.self::PAGE_ID.'/feed', $request->url());
        $this->assertSame('Shipped the invoicing module this week.', $request['message']);
        $this->assertSame(self::PAGE_TOKEN, $request['access_token']);
        $this->assertArrayNotHasKey('attached_media', $request->data());

        // Form-encoded, not JSON — see the note on `MetaGraph`.
        $this->assertTrue($request->isForm());
    }

    public function test_two_facebook_photos_are_uploaded_unpublished_and_then_attached_to_one_post(): void
    {
        Http::fake([
            'graph.facebook.com/*/photos' => Http::sequence()
                ->push(['id' => '10160000000000001'])
                ->push(['id' => '10160000000000002']),
            'graph.facebook.com/*/feed' => Http::response(['id' => self::PAGE_ID.'_5544332211']),
        ]);

        $result = (new FacebookPagePublisher)->publish(
            $this->facebookAccount(),
            'Two shots of the bench before the rebuild.',
            $this->images(2),
        );

        $this->assertSame(self::PAGE_ID.'_5544332211', $result->remoteId);

        Http::assertSentCount(3);

        [$first, $second, $feed] = $this->sent();

        foreach ([$first, $second] as $upload) {
            $this->assertSame('/'.self::V.'/'.self::PAGE_ID.'/photos', $this->path($upload));
            $this->assertTrue($upload->isMultipart(), 'the photo went up as something other than multipart');
            $this->assertTrue($upload->hasFile('source'), 'the bytes are not in a part named source');
            // The literal string. A PHP false encodes to an empty part, which
            // Graph reads as absent — and the default is published.
            $this->assertSame('false', $this->part($upload, 'published'));
            $this->assertSame(self::PAGE_TOKEN, $this->part($upload, 'access_token'));
        }

        $this->assertSame('/'.self::V.'/'.self::PAGE_ID.'/feed', $this->path($feed));
        $this->assertSame('Two shots of the bench before the rebuild.', $feed['message']);

        // One field carrying a JSON string, not `attached_media[0][media_fbid]`.
        // Both are accepted by Graph; this is the shape every curl example Meta
        // publishes produces. See `FacebookPagePublisher`.
        $this->assertSame(
            '[{"media_fbid":"10160000000000001"},{"media_fbid":"10160000000000002"}]',
            $feed['attached_media'],
        );
    }

    /**
     * Graph answers HTTP 200 with an `error` key more often than it answers a
     * 4xx, so a successful status code is not evidence of a published post.
     * `TelegramPublisher` has the same problem with `ok: false`.
     */
    public function test_a_facebook_200_carrying_an_error_object_is_a_failure_rather_than_a_publish(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => '(#100) Missing message or attachment',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 200),
        ]);

        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('Missing message or attachment');

        (new FacebookPagePublisher)->publish($this->facebookAccount(), 'This one goes nowhere.');
    }

    /**
     * Code 190 is the failure this whole family is most likely to hit, because
     * a short-lived token and a long-lived one are the same opaque string and
     * the short one works for an hour first.
     */
    public function test_an_expired_facebook_token_says_to_paste_a_long_lived_one(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 200),
        ]);

        try {
            (new FacebookPagePublisher)->publish($this->facebookAccount(), 'Weekly build log.');

            $this->fail('An expired token published a post.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('Facebook Page', $e->getMessage());
            $this->assertStringContainsString('Session has expired', $e->getMessage());
            $this->assertStringContainsString('long-lived', $e->getMessage());
            $this->assertStringContainsString('Graph API Explorer', $e->getMessage());
        }
    }

    /* Instagram ---------------------------------------------------------------- */

    public function test_a_single_instagram_image_creates_a_container_then_publishes_it(): void
    {
        Http::fake([
            // 🔴 `graph.instagram.com`, not `graph.facebook.com`. The host is
            // the assertion here as much as the paths below are: an Instagram
            // Login token is refused by Graph's host outright, so a driver that
            // drifts back would match no pattern, receive an empty body and fail
            // on "the response carried no container id" — which is the failure
            // this fake exists to produce rather than hide.
            'graph.instagram.com/*/media_publish' => Http::response(['id' => '17900000000000001']),
            'graph.instagram.com/*/media' => Http::response(['id' => '17800000000000002']),
        ]);

        $images = $this->images(1);

        $result = (new InstagramPublisher)->publish(
            $this->instagramAccount(),
            'The bench, finally square.',
            $images,
        );

        $this->assertSame('17900000000000001', $result->remoteId);
        // No permalink: it would cost a second fetch per publish. See the class.
        $this->assertNull($result->remoteUrl);

        Http::assertSentCount(2);

        [$container, $publish] = $this->sent();

        $this->assertSame('/'.self::V.'/'.self::IG_USER_ID.'/media', $this->path($container));

        // The picture is fetched by Meta, never sent — so what goes over the
        // wire is the signed link `AttachmentService::publicUrl()` builds.
        $this->assertSame(
            app(AttachmentService::class)->publicUrl($images[0]->id),
            $container['image_url'],
        );
        $this->assertStringContainsString('signature=', (string) $container['image_url']);
        $this->assertSame('The bench, finally square.', $container['caption']);

        $this->assertSame('/'.self::V.'/'.self::IG_USER_ID.'/media_publish', $this->path($publish));
        $this->assertSame('17800000000000002', $publish['creation_id']);
        $this->assertSame(self::IG_TOKEN, $publish['access_token']);
    }

    public function test_three_instagram_images_become_three_children_a_carousel_parent_and_one_publish(): void
    {
        Http::fake([
            'graph.instagram.com/*/media_publish' => Http::response(['id' => '17900000000000009']),
            'graph.instagram.com/*/media' => Http::sequence()
                ->push(['id' => 'child-one'])
                ->push(['id' => 'child-two'])
                ->push(['id' => 'child-three'])
                ->push(['id' => 'carousel-parent']),
        ]);

        $result = (new InstagramPublisher)->publish(
            $this->instagramAccount(),
            'Three stages of the same joint.',
            $this->images(3),
        );

        $this->assertSame('17900000000000009', $result->remoteId);

        Http::assertSentCount(5);

        [$one, $two, $three, $parent, $publish] = $this->sent();

        foreach ([$one, $two, $three] as $child) {
            $this->assertSame('/'.self::V.'/'.self::IG_USER_ID.'/media', $this->path($child));
            $this->assertSame('true', $child['is_carousel_item']);
            $this->assertNotNull($child['image_url']);
            // A caption on a child is accepted and discarded, and the finished
            // carousel arrives without one.
            $this->assertArrayNotHasKey('caption', $child->data());
        }

        $this->assertSame('/'.self::V.'/'.self::IG_USER_ID.'/media', $this->path($parent));
        $this->assertSame('CAROUSEL', $parent['media_type']);
        $this->assertSame('child-one,child-two,child-three', $parent['children']);
        $this->assertSame('Three stages of the same joint.', $parent['caption']);
        $this->assertArrayNotHasKey('image_url', $parent->data());

        $this->assertSame('/'.self::V.'/'.self::IG_USER_ID.'/media_publish', $this->path($publish));
        $this->assertSame('carousel-parent', $publish['creation_id']);
    }

    /**
     * Instagram has no text-only post — not one Kargah declines to make, one the
     * API does not have. So the refusal has to happen before a request, or the
     * target would carry whatever Graph makes of an empty container instead.
     */
    public function test_an_instagram_post_with_no_images_fails_before_any_request(): void
    {
        Http::fake();

        try {
            (new InstagramPublisher)->publish($this->instagramAccount(), 'Just a thought, no picture.');

            $this->fail('Instagram published a post with no images.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('no text-only post', $e->getMessage());
            $this->assertStringContainsString('at least one JPEG', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * The bug this exists for: Instagram's image container is JPEG-only, and a
     * PNG used to be a hard refusal — "Instagram does not accept image/png" —
     * that named a network problem nobody attaching a screenshot expects to
     * hit. `HttpPublisher::acceptableMedia()` now re-encodes it, and because
     * Instagram never receives bytes (see `images()`'s docblock), the proof is
     * fetching the exact URL Instagram was handed and finding a JPEG behind it,
     * not just that publish did not throw.
     */
    public function test_a_png_attached_to_instagram_is_served_as_the_jpeg_it_requires(): void
    {
        Http::fake([
            'graph.instagram.com/*/media_publish' => Http::response(['id' => '17900000000000005']),
            'graph.instagram.com/*/media' => Http::response(['id' => '17800000000000006']),
        ]);

        $result = (new InstagramPublisher)->publish(
            $this->instagramAccount(),
            'A screenshot, not a photo.',
            [$this->testImage('image/png')],
        );

        $this->assertSame('17900000000000005', $result->remoteId);

        [$container] = $this->sent();
        $imageUrl = (string) $container['image_url'];

        // The conversion is visible on the link itself before it is ever
        // fetched: `as=image/jpeg` is what tells `data.file-share` to re-encode
        // rather than stream the stored PNG.
        $this->assertStringContainsString('as=image%2Fjpeg', $imageUrl);

        // And the link actually serves a JPEG — the part Meta relies on, since
        // it never sees the mime this test asserted above, only the bytes.
        $response = $this->get($imageUrl);

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));

        $info = getimagesizefromstring($response->streamedContent());
        $this->assertNotFalse($info, 'the served bytes did not decode as an image at all');
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    /**
     * `FacebookPagePublisher` reads bytes directly rather than handing Graph a
     * URL (see `images()`'s docblock) — the other half of the conversion path,
     * and the one `MediaItem::contents()` serves without Data's involvement.
     *
     * WebP, not PNG: Facebook Page's catalogue entry already takes PNG (see
     * `Networks::all()`), so a PNG here would prove nothing about conversion.
     * WebP is the one raster format its `mimes` list leaves out.
     */
    public function test_a_webp_attached_to_facebook_uploads_as_jpeg_bytes(): void
    {
        Http::fake([
            'graph.facebook.com/*/photos' => Http::response(['id' => '10160000000000009']),
            'graph.facebook.com/*/feed' => Http::response(['id' => self::PAGE_ID.'_9988776655']),
        ]);

        (new FacebookPagePublisher)->publish(
            $this->facebookAccount(),
            'A screenshot, not a photo.',
            [$this->testImage('image/webp')],
        );

        [$upload] = $this->sent();

        $this->assertTrue($upload->hasFile('source'));

        $bytes = (string) $this->part($upload, 'source');

        // The JPEG magic number. What went up is not the RIFF/WEBP header
        // `testImage()` built.
        $this->assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3));
    }

    /**
     * Nothing here should turn a genuine refusal into a broken publish. A
     * source `ImageTranscoder` cannot decode (SVG: vectors, not GD's problem —
     * see the class) falls straight through to the original message.
     */
    public function test_instagram_still_refuses_a_mime_it_cannot_convert(): void
    {
        Http::fake();

        $post = Post::factory()->create();
        $stored = app(AttachmentService::class)->attachContents(
            $post,
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'icon.svg',
            'image/svg+xml',
        );
        $item = MediaItem::fromAttachment($stored);
        $this->assertNotNull($item);

        try {
            (new InstagramPublisher)->publish($this->instagramAccount(), 'This should not go out.', [$item]);

            $this->fail('Instagram published an SVG.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('does not accept image/svg+xml', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * A real, tiny, GD-decodable image in the given format — `images()` next
     * door stores literal text under an `image/jpeg` label, which is fine for
     * request-shape assertions but cannot exercise `ImageTranscoder`, which
     * reads actual pixels.
     */
    private function testImage(string $mime): MediaItem
    {
        $canvas = imagecreatetruecolor(2, 2);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        ob_start();
        match ($mime) {
            'image/png' => imagepng($canvas),
            'image/webp' => imagewebp($canvas),
            'image/gif' => imagegif($canvas),
            default => throw new \InvalidArgumentException("no encoder wired up for {$mime} in this test"),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        };

        $post = Post::factory()->create();
        $stored = app(AttachmentService::class)->attachContents($post, $bytes, 'screenshot.'.$extension, $mime);
        $item = MediaItem::fromAttachment($stored);
        $this->assertNotNull($item);

        return $item;
    }

    /* Threads ------------------------------------------------------------------ */

    public function test_a_text_only_threads_post_creates_a_text_container_then_publishes_it(): void
    {
        Http::fake([
            'graph.threads.net/*/threads_publish' => Http::response(['id' => '18000000000000001']),
            'graph.threads.net/*/threads' => Http::response(['id' => '17000000000000002']),
        ]);

        $result = (new ThreadsPublisher)->publish(
            $this->threadsAccount(),
            'Text posts are real posts here, unlike next door.',
        );

        $this->assertSame('18000000000000001', $result->remoteId);

        Http::assertSentCount(2);

        [$container, $publish] = $this->sent();

        $this->assertSame(
            'https://graph.threads.net/v1.0/'.self::THREADS_USER_ID.'/threads',
            $container->url(),
        );
        $this->assertSame('TEXT', $container['media_type']);
        $this->assertSame('Text posts are real posts here, unlike next door.', $container['text']);
        $this->assertArrayNotHasKey('image_url', $container->data());
        // A Threads token, not the Instagram one, even though the account is
        // the same account.
        $this->assertSame(self::THREADS_TOKEN, $container['access_token']);

        $this->assertSame(
            'https://graph.threads.net/v1.0/'.self::THREADS_USER_ID.'/threads_publish',
            $publish->url(),
        );
        $this->assertSame('17000000000000002', $publish['creation_id']);
    }

    public function test_a_threads_post_with_one_image_sends_an_image_container_carrying_a_url(): void
    {
        Http::fake([
            'graph.threads.net/*/threads_publish' => Http::response(['id' => '18000000000000007']),
            'graph.threads.net/*/threads' => Http::response(['id' => '17000000000000008']),
        ]);

        $images = $this->images(1);

        $result = (new ThreadsPublisher)->publish(
            $this->threadsAccount(),
            'One picture, fetched rather than sent.',
            $images,
        );

        $this->assertSame('18000000000000007', $result->remoteId);

        Http::assertSentCount(2);

        [$container, $publish] = $this->sent();

        $this->assertSame('IMAGE', $container['media_type']);
        $this->assertSame(
            app(AttachmentService::class)->publicUrl($images[0]->id),
            $container['image_url'],
        );
        $this->assertSame('One picture, fetched rather than sent.', $container['text']);

        $this->assertSame('17000000000000008', $publish['creation_id']);
    }

    /* Verify, which must never publish ------------------------------------------ */

    public function test_every_meta_verify_returns_the_handle_and_posts_nothing(): void
    {
        Http::fake([
            'graph.facebook.com/'.self::V.'/me*' => Http::response([
                'id' => self::PAGE_ID,
                'name' => 'Kargah Workshop',
                'username' => 'kargahworkshop',
            ]),
            'graph.instagram.com/'.self::V.'/'.self::IG_USER_ID.'*' => Http::response([
                'id' => self::IG_USER_ID,
                'username' => 'kargah.workshop',
            ]),
            'graph.threads.net/v1.0/me*' => Http::response([
                'id' => self::THREADS_USER_ID,
                'username' => 'kargah.workshop',
            ]),
        ]);

        $this->assertSame(
            'Kargah Workshop (@kargahworkshop)',
            (new FacebookPagePublisher)->verify($this->facebookAccount()),
        );

        $this->assertSame(
            '@kargah.workshop',
            (new InstagramPublisher)->verify($this->instagramAccount()),
        );

        $this->assertSame(
            '@kargah.workshop',
            (new ThreadsPublisher)->verify($this->threadsAccount()),
        );

        Http::assertSentCount(3);

        foreach ($this->sent() as $request) {
            $this->assertSame('GET', $request->method(), 'a verify wrote something: '.$request->url());
        }
    }

    /* The install, not the account ---------------------------------------------- */

    /**
     * The one precondition in Kargah that is about the machine rather than the
     * credentials, and the one most likely to be misread as a bug.
     *
     * Instagram is refused outright, because no Instagram post can work without
     * a fetchable picture. Threads is not, because a text post from a laptop
     * publishes perfectly — it only refuses once a picture is attached.
     */
    public function test_a_localhost_install_is_refused_by_instagram_but_only_by_threads_when_a_picture_is_attached(): void
    {
        Http::fake([
            'graph.threads.net/*/threads_publish' => Http::response(['id' => '18000000000000011']),
            'graph.threads.net/*/threads' => Http::response(['id' => '17000000000000012']),
        ]);

        $images = $this->images(1);

        URL::forceRootUrl('http://localhost');

        $instagram = new InstagramPublisher;
        $reason = $instagram->unavailableReason($this->instagramAccount());

        $this->assertNotNull($reason);

        // The two things the sentence has to carry, rather than a phrase from
        // it: the address that is the actual problem, and the setting that
        // fixes it. Asserting on prose made this fail the day the guard moved
        // into `FetchesOwnMedia` and its wording changed by three words, which
        // told nobody anything — the behaviour had not moved at all.
        $this->assertStringContainsString('localhost', $reason);
        $this->assertStringContainsString('APP_URL', $reason);

        $threads = new ThreadsPublisher;
        $account = $this->threadsAccount();

        // Text still goes out, because nothing has to be fetched.
        $this->assertNull($threads->unavailableReason($account));
        $this->assertSame('18000000000000011', $threads->publish($account, 'A thought with no picture.')->remoteId);

        try {
            $threads->publish($account, 'A thought with a picture.', $images);

            $this->fail('Threads published a picture from an install Meta cannot reach.');
        } catch (PublishFailed $e) {
            // The address and the fix, not a phrase — see the note above.
            $this->assertStringContainsString('localhost', $e->getMessage());
            $this->assertStringContainsString('APP_URL', $e->getMessage());
        }

        // The text post and its publish, and nothing from the picture attempt.
        Http::assertSentCount(2);
    }
}
