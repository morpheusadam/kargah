<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\PostMedia;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\VideoItem;
use Modules\Social\Services\Publishers\YouTubePublisher;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * YouTube — the one destination whose post is a video.
 *
 * Everything here exists because this driver breaks the shape the other
 * fourteen share, and each break is a place a future change could quietly undo
 * it:
 *
 * - `publish()` **must** refuse. It is only reachable when a post carrying no
 *   video is aimed at a channel, and the sentence it produces is the only thing
 *   standing between that person and whatever Google makes of an empty request.
 * - the upload is **two** calls, and the second goes to a URL that only exists
 *   in the first one's `Location` header. A change that read the body instead
 *   would pass a naive test and upload into nothing.
 * - the bytes must **stream**. `test_the_upload_streams_rather_than_buffering`
 *   is the one that fails if somebody swaps `VideoItem` for `MediaItem`.
 * - `invalid_grant` must keep its sentence about Testing mode, because that is
 *   the failure this network actually produces and Google's own wording does
 *   not hint at the cause.
 */
class SocialYouTubeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const INIT = 'https://www.googleapis.com/upload/youtube/v3/videos*';

    private const SESSION = 'https://upload.example.test/session/abc123';

    private const CHANNELS = 'https://www.googleapis.com/youtube/v3/channels*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function account(): SocialAccount
    {
        return SocialAccount::factory()
            ->onNetwork(Networks::YOUTUBE)
            ->connected()
            ->create(['handle' => 'lavzencom']);
    }

    /**
     * A video whose bytes come from a stubbed `AttachmentService`.
     *
     * `VideoItem::stream()` resolves the contract out of the container, so a
     * driver test needs no storage fake and no rows in Data's tables — the same
     * trick `XPublisherTest::media()` uses for pictures.
     */
    private function video(int $sizeBytes = 2_400_000, string $mime = 'video/mp4'): VideoItem
    {
        $this->mock(
            AttachmentService::class,
            fn ($mock) => $mock->shouldReceive('readStream')->andReturnUsing(function (): mixed {
                $handle = fopen('php://memory', 'r+');
                fwrite($handle, 'fake-mp4-bytes');
                rewind($handle);

                return $handle;
            }),
        );

        return new VideoItem(id: 77, name: 'board-rewrite.mp4', mime: $mime, sizeBytes: $sizeBytes);
    }

    /** The happy path's two responses: a session URL, then the finished video. */
    private function fakeUpload(string $videoId = 'dQw4w9WgXcQ'): void
    {
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'ya29.short-lived', 'expires_in' => 3599]),
            self::INIT => Http::response('', 200, ['Location' => self::SESSION]),
            self::SESSION => Http::response(['id' => $videoId, 'kind' => 'youtube#video']),
        ]);
    }

    /* publish() is not a thing YouTube has ------------------------------------- */

    public function test_publish_refuses_because_a_youtube_post_is_a_video(): void
    {
        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('YouTube has no text or photo post');

        (new YouTubePublisher)->publish($this->account(), 'Shipped the board rewrite this week.');
    }

    /* The upload ----------------------------------------------------------------- */

    public function test_a_video_opens_a_resumable_session_then_puts_the_bytes(): void
    {
        $this->fakeUpload();

        $result = (new YouTubePublisher)->publishVideo(
            $this->account(),
            "Board rewrite\n\nWhat changed and why.",
            $this->video(),
        );

        $this->assertSame('dQw4w9WgXcQ', $result->remoteId);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $result->remoteUrl);

        // Token, then the session, then the bytes — and the bytes go to the URL
        // the session answered with, not to the upload endpoint again.
        $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();

        $this->assertCount(3, $urls);
        $this->assertSame(self::TOKEN, $urls[0]);
        $this->assertStringContainsString('uploadType=resumable', $urls[1]);
        $this->assertSame(self::SESSION, $urls[2]);
    }

    public function test_the_session_call_declares_the_byte_count_before_anything_moves(): void
    {
        $this->fakeUpload();

        (new YouTubePublisher)->publishVideo($this->account(), 'Board rewrite', $this->video(sizeBytes: 2_400_000));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'uploadType=resumable')
            && $request->header('X-Upload-Content-Length') === ['2400000']
            && $request->header('X-Upload-Content-Type') === ['video/mp4']);
    }

    /**
     * 🔴 The test that fails if the driver ever buffers the file.
     *
     * A `MediaItem`-shaped rewrite would put the whole video in the request body
     * as a string. Asserting the body is *not* the byte string — while the
     * upload still succeeds — is the cheapest way to say "this streamed".
     */
    public function test_the_upload_streams_rather_than_buffering(): void
    {
        $this->fakeUpload();

        (new YouTubePublisher)->publishVideo($this->account(), 'Board rewrite', $this->video());

        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::SESSION) {
                return false;
            }

            // Guzzle reports a streamed body's size from the handle, and the
            // driver states the count itself. Either way the driver never built
            // a string of the file — which is what `Content-Length` set from
            // `sizeBytes` proves.
            return $request->header('Content-Length') === ['2400000'];
        });
    }

    public function test_a_session_response_with_no_location_is_a_failure_rather_than_an_upload(): void
    {
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'ya29.short-lived']),
            self::INIT => Http::response('', 200),
        ]);

        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('did not say where to send the file');

        (new YouTubePublisher)->publishVideo($this->account(), 'Board rewrite', $this->video());
    }

    /* The title ------------------------------------------------------------------- */

    public function test_the_first_line_becomes_the_title_and_the_body_stays_the_description(): void
    {
        $this->fakeUpload();

        (new YouTubePublisher)->publishVideo(
            $this->account(),
            "Board rewrite\n\nWhat changed and why.",
            $this->video(),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'uploadType=resumable')
            && $request['snippet']['title'] === 'Board rewrite'
            && $request['snippet']['description'] === "Board rewrite\n\nWhat changed and why."
            && $request['status']['privacyStatus'] === 'public');
    }

    public function test_a_long_first_line_is_cut_to_exactly_a_hundred_characters(): void
    {
        $this->fakeUpload();

        (new YouTubePublisher)->publishVideo($this->account(), str_repeat('a', 140), $this->video());

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'uploadType=resumable')) {
                return false;
            }

            return mb_strlen($request['snippet']['title']) === 100
                && str_ends_with($request['snippet']['title'], '…');
        });
    }

    /** The API refuses these two characters and never says which one it meant. */
    public function test_angle_brackets_are_stripped_from_the_title(): void
    {
        $this->fakeUpload();

        (new YouTubePublisher)->publishVideo($this->account(), 'Kargah <live> build', $this->video());

        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'uploadType=resumable')
            || $request['snippet']['title'] === 'Kargah live build');
    }

    public function test_an_empty_body_is_refused_because_a_video_needs_a_title(): void
    {
        Http::fake([self::TOKEN => Http::response(['access_token' => 'ya29.short-lived'])]);

        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('takes it from the first line of the copy, which is empty');

        (new YouTubePublisher)->publishVideo($this->account(), "  \n\n ", $this->video());
    }

    /* Refusals worth their own sentence --------------------------------------------- */

    /**
     * 🔴 The failure this network will actually produce, and the one whose cause
     * is invisible in Google's own wording.
     */
    public function test_an_expired_refresh_token_explains_the_testing_mode_trap(): void
    {
        Http::fake([
            self::TOKEN => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400),
        ]);

        try {
            (new YouTubePublisher)->publishVideo($this->account(), 'Board rewrite', $this->video());
            $this->fail('An invalid_grant should have been refused.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('revoked or has expired', $e->getMessage());
            $this->assertStringContainsString('Testing', $e->getMessage());
            $this->assertStringContainsString('seven days', $e->getMessage());
        }
    }

    public function test_a_quota_refusal_says_it_is_a_daily_allowance(): void
    {
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'ya29.short-lived']),
            self::INIT => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'The request cannot be completed because you have exceeded your quota.',
                    'errors' => [['reason' => 'quotaExceeded']],
                ],
            ], 403),
        ]);

        try {
            (new YouTubePublisher)->publishVideo($this->account(), 'Board rewrite', $this->video());
            $this->fail('A quota refusal should have been reported.');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('daily quota', $e->getMessage());
        }
    }

    /* Refused before a single request ------------------------------------------------ */

    public function test_a_file_over_this_installs_ceiling_is_refused_before_any_request(): void
    {
        // No Http::fake at all: `preventStrayRequests()` turns any request into
        // a failure, so this passing is itself the assertion that nothing was
        // sent — including the token exchange.
        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('this install’s ceiling');

        (new YouTubePublisher)->publishVideo(
            $this->account(),
            'Board rewrite',
            $this->video(sizeBytes: 200 * 1024 * 1024),
        );
    }

    public function test_a_container_youtube_does_not_take_is_refused_before_any_request(): void
    {
        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('does not accept video/x-flv');

        (new YouTubePublisher)->publishVideo(
            $this->account(),
            'Board rewrite',
            $this->video(mime: 'video/x-flv'),
        );
    }

    /* verify() ------------------------------------------------------------------------ */

    public function test_verify_names_the_channel_it_reached(): void
    {
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'ya29.short-lived']),
            self::CHANNELS => Http::response([
                'items' => [['snippet' => ['title' => 'Lavzen', 'customUrl' => '@lavzencom']]],
            ]),
        ]);

        $this->assertSame('Lavzen (@lavzencom)', (new YouTubePublisher)->verify($this->account()));
    }

    /**
     * A Google account without a channel authorises perfectly and has nowhere to
     * upload to — which reads as "it worked" unless somebody says otherwise.
     */
    public function test_verify_says_so_when_the_google_account_has_no_channel(): void
    {
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'ya29.short-lived']),
            self::CHANNELS => Http::response(['items' => []]),
        ]);

        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('no YouTube channel');

        (new YouTubePublisher)->verify($this->account());
    }

    /* VideoItem, and what counts as a video --------------------------------------------- */

    public function test_video_item_takes_videos_and_leaves_everything_else_alone(): void
    {
        $this->assertNotNull(VideoItem::fromAttachment([
            'id' => 5, 'name' => 'clip.mp4', 'mime' => 'video/mp4', 'size_bytes' => 10,
        ]));

        $this->assertNull(VideoItem::fromAttachment([
            'id' => 6, 'name' => 'shot.png', 'mime' => 'image/png', 'size_bytes' => 10,
        ]));

        $this->assertNull(VideoItem::fromAttachment([
            'id' => 7, 'name' => 'brief.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10,
        ]));
    }

    /* The routing ------------------------------------------------------------------------ */

    /**
     * A YouTube target on a post with no video must say so in a sentence.
     *
     * This is `PostPublisher`'s answer rather than the driver's, because only
     * `PostPublisher` knows what is attached — and it is the message a person
     * will actually meet, since the composer is what let them aim a video-only
     * network at a post without one.
     */
    public function test_a_youtube_target_with_no_video_fails_with_a_sentence(): void
    {
        $this->mock(
            AttachmentService::class,
            fn ($mock) => $mock->shouldReceive('forTarget')->andReturn(new Collection),
        );

        $account = $this->account();
        $post = Post::factory()->create();
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->app->make(PostPublisher::class)->publishPost($post->refresh());

        $error = $post->targets()->sole()->error;

        $this->assertStringContainsString('has no video attached', $error);
        $this->assertStringContainsString('no other kind of post', $error);
    }

    /** The first video attached, and only one, whatever else is on the post. */
    public function test_post_media_answers_with_the_first_video_and_ignores_pictures(): void
    {
        $this->mock(
            AttachmentService::class,
            fn ($mock) => $mock->shouldReceive('forTarget')->andReturn(new Collection([
                // `forTarget()` is newest first and `videoForPost()` reverses it,
                // so this is the *last* attached and must lose.
                ['id' => 3, 'name' => 'second.mp4', 'mime' => 'video/mp4', 'size_bytes' => 30],
                ['id' => 2, 'name' => 'first.mp4', 'mime' => 'video/mp4', 'size_bytes' => 20],
                ['id' => 1, 'name' => 'cover.png', 'mime' => 'image/png', 'size_bytes' => 10],
            ])),
        );

        $post = Post::factory()->create();
        $video = $this->app->make(PostMedia::class)->videoForPost($post);

        $this->assertNotNull($video);
        $this->assertSame('first.mp4', $video->name);
        $this->assertSame(2, $video->id);
    }
}
