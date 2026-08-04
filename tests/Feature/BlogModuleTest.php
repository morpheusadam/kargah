<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Modules\Blog\Models\Article;
use Modules\Blog\Services\WordPressPublisher;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Services\Publishers\FakePublisher;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishedPost;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\TakesTargetOptions;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The Blog module.
 *
 * Two things are being asserted here and only one of them is a driver.
 *
 * The driver half is ordinary: a WordPress site is asked for a post, and the
 * request shape — url, method, headers, body — is what a test can check when the
 * real call cannot be made. Every one of them runs under
 * `Http::preventStrayRequests()`, which on a machine with no CA bundle is the
 * difference between a failing assertion and `cURL error 60`.
 *
 * The half that matters is the *routing*. The whole design rests on
 * `PostPublisher` asking `instanceof TakesTargetOptions` and making one of two
 * different calls — so a test that only proved the driver works would prove
 * nothing about whether the title ever reaches it, or whether Telegram is
 * accidentally handed a slug. `test_post_publisher_hands_options_to_wordpress_and_not_to_telegram()`
 * is that test, and it is the load-bearing one.
 */
class BlogModuleTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://blog.kargah.test';

    private const PASSWORD = 'abcd EFGH 1234 ijkl';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-04 11:15:00');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* Helpers ----------------------------------------------------------------- */

    private function registry(): Publishing
    {
        return $this->app->make(Publishing::class);
    }

    /**
     * A connected WordPress site.
     *
     * Built by hand rather than with `connected()`: Social's factory fills every
     * credential field with the same placeholder string, and `site_url` has to be
     * a real address or `WordPressPublisher::baseUrl()` refuses it before a
     * single faked request is made — which is the factory being right about
     * placeholders rather than wrong about this network.
     */
    private function site(string $url = self::SITE): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::WORDPRESS)->create([
            'handle' => 'blog.kargah.test',
            'credentials' => [
                'site_url' => $url,
                'username' => 'nima',
                'application_password' => self::PASSWORD,
            ],
            'connected_at' => now(),
        ]);
    }

    private function expectedAuthorisation(): string
    {
        return 'Basic '.base64_encode('nima:'.self::PASSWORD);
    }

    /** The options a composed article carries. */
    private function articleOptions(array $overrides = []): array
    {
        return [
            'title' => 'Four board views and what each one cost',
            'status' => 'draft',
            'excerpt' => 'Table, calendar, dashboard, activity — and the one that was not worth it.',
            'slug' => 'four-board-views',
            'create_missing_terms' => true,
            ...$overrides,
        ];
    }

    /* The registration --------------------------------------------------------- */

    /**
     * The extension point, asserted from the container rather than by
     * constructing the driver.
     *
     * Constructing `new WordPressPublisher` proves the class exists and nothing
     * at all about whether a WordPress target would ever find it — which is the
     * failure this test is here to catch, because `Publishing` is a singleton and
     * a callback registered after its first resolution never runs.
     */
    public function test_the_wordpress_driver_is_registered_in_socials_registry(): void
    {
        $driver = $this->registry()->driverFor(Networks::WORDPRESS);

        $this->assertNotNull($driver, 'Blog did not register a driver for the wordpress network');
        $this->assertInstanceOf(WordPressPublisher::class, $driver);

        // The half of the contract `PostPublisher` actually branches on.
        $this->assertInstanceOf(TakesTargetOptions::class, $driver);
    }

    public function test_the_registry_still_holds_socials_own_drivers(): void
    {
        $this->assertNotNull($this->registry()->driverFor(Networks::TELEGRAM));
        $this->assertNotNull($this->registry()->driverFor(Networks::MASTODON));
    }

    /* The driver ---------------------------------------------------------------- */

    public function test_it_posts_the_title_status_and_content_from_the_options(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response([
                'id' => 412,
                'link' => 'https://blog.kargah.test/four-board-views/',
            ], 201),
        ]);

        $result = (new WordPressPublisher)->publishWithOptions(
            $this->site(),
            "We rebuilt the board four times this quarter.\n\nHere is what each view cost.",
            [],
            $this->articleOptions(),
        );

        $this->assertSame('412', $result->remoteId);
        $this->assertSame('https://blog.kargah.test/four-board-views/', $result->remoteUrl);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/posts'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', $this->expectedAuthorisation())
                && $request['title'] === 'Four board views and what each one cost'
                && $request['status'] === 'draft'
                && $request['slug'] === 'four-board-views'
                && str_contains($request['content'], 'We rebuilt the board four times this quarter.');
        });
    }

    /**
     * The plain path — no options at all — still publishes.
     *
     * This is a WordPress target the ordinary social composer created, or a post
     * scheduled before this module existed. Both have to go out, and WordPress
     * will not take a post with no title.
     */
    public function test_the_plain_path_derives_a_title_and_publishes(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response(['id' => 7, 'link' => 'https://blog.kargah.test/?p=7'], 201),
        ]);

        (new WordPressPublisher)->publish(
            $this->site(),
            "Four board views and what each one cost\n\nWe rebuilt the board four times this quarter.",
        );

        Http::assertSent(function ($request): bool {
            return $request['title'] === 'Four board views and what each one cost'
                // The default when nobody said, and the class docblock argues it.
                && $request['status'] === 'publish'
                // The first line is left in the body. Never edit somebody's copy.
                && str_starts_with($request['content'], 'Four board views and what each one cost');
        });
    }

    public function test_a_canonical_link_is_credited_at_the_foot_of_the_post(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response(['id' => 9, 'link' => 'https://blog.kargah.test/?p=9'], 201),
        ]);

        (new WordPressPublisher)->publishWithOptions(
            $this->site(),
            'We rebuilt the board four times this quarter.',
            [],
            $this->articleOptions(['canonical_url' => 'https://kargah.dev/notes/board-views']),
        );

        Http::assertSent(fn ($request): bool => str_contains($request['content'], 'https://kargah.dev/notes/board-views')
            && str_contains($request['content'], 'rel="canonical"'));
    }

    /* Taxonomy ------------------------------------------------------------------- */

    public function test_category_and_tag_names_are_resolved_to_term_ids(): void
    {
        Http::fake([
            // The search leg. A trailing `*` because the query string is part of
            // the url `Http::fake()` matches against.
            'blog.kargah.test/wp-json/wp/v2/categories?*' => Http::response([
                ['id' => 4, 'name' => 'Build log'],
                ['id' => 9, 'name' => 'Build log archive'],
            ]),
            'blog.kargah.test/wp-json/wp/v2/tags?*' => Http::response([]),
            // The create leg: no query string, so it cannot match the pattern above.
            'blog.kargah.test/wp-json/wp/v2/tags' => Http::response(['id' => 77, 'name' => 'livewire'], 201),
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response(['id' => 51, 'link' => 'https://blog.kargah.test/?p=51'], 201),
        ]);

        (new WordPressPublisher)->publishWithOptions(
            $this->site(),
            'We rebuilt the board four times this quarter.',
            [],
            $this->articleOptions(['categories' => ['build log'], 'tags' => ['livewire']]),
        );

        // Searched by name, and the substring match WordPress does on its side
        // is narrowed to an exact, case-insensitive name match on ours — 'Build
        // log archive' came back and was not used.
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/wp/v2/categories?')
            && str_contains($request->url(), 'search='));

        // The tag the site did not have was created.
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/tags'
            && $request['name'] === 'livewire');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/posts'
            && $request['categories'] === [4]
            && $request['tags'] === [77]);
    }

    public function test_create_missing_terms_false_skips_a_term_rather_than_writing_to_the_site(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/tags?*' => Http::response([]),
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response(['id' => 52, 'link' => 'https://blog.kargah.test/?p=52'], 201),
        ]);

        (new WordPressPublisher)->publishWithOptions(
            $this->site(),
            'We rebuilt the board four times this quarter.',
            [],
            $this->articleOptions(['tags' => ['livewire'], 'create_missing_terms' => false]),
        );

        // Nothing was created — a POST to /tags would have been a stray request
        // and `preventStrayRequests()` would have failed the test — and the post
        // went out without the tag rather than not at all.
        Http::assertSent(fn ($request): bool => $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/posts'
            && ! isset($request['tags']));
    }

    /* Pictures -------------------------------------------------------------------- */

    public function test_a_featured_image_is_uploaded_before_the_post_and_named_in_featured_media(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/media' => Http::response([
                'id' => 88,
                'source_url' => 'https://blog.kargah.test/wp-content/uploads/image-12.jpg',
            ], 201),
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response(['id' => 61, 'link' => 'https://blog.kargah.test/?p=61'], 201),
        ]);

        $this->stubAttachmentBytes();

        (new WordPressPublisher)->publishWithOptions(
            $this->site(),
            'We rebuilt the board four times this quarter.',
            [new MediaItem(id: 12, name: 'board-views.jpg', mime: 'image/jpeg', sizeBytes: 240_000)],
            $this->articleOptions(),
        );

        // Two requests, in this order. The post cannot name an attachment id
        // that does not exist yet, so the order is the assertion.
        Http::assertSentInOrder([
            fn ($request): bool => $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/media',
            fn ($request): bool => $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/posts',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/media'
                && $request->hasHeader('Content-Disposition', 'attachment; filename="image-12.jpg"')
                && $request->hasHeader('Content-Type', 'image/jpeg')
                && $request->body() === 'the bytes of a jpeg';
        });

        Http::assertSent(fn ($request): bool => $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/posts'
            && $request['featured_media'] === 88);
    }

    public function test_the_second_image_is_appended_to_the_post_rather_than_left_in_the_library(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/media' => Http::sequence()
                ->push(['id' => 88, 'source_url' => 'https://blog.kargah.test/uploads/one.jpg'], 201)
                ->push(['id' => 89, 'source_url' => 'https://blog.kargah.test/uploads/two.jpg'], 201),
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response(['id' => 62, 'link' => 'https://blog.kargah.test/?p=62'], 201),
        ]);

        $this->stubAttachmentBytes();

        (new WordPressPublisher)->publishWithOptions(
            $this->site(),
            'We rebuilt the board four times this quarter.',
            [
                new MediaItem(id: 12, name: 'board-views.jpg', mime: 'image/jpeg', sizeBytes: 240_000),
                new MediaItem(id: 13, name: 'calendar.jpg', mime: 'image/jpeg', sizeBytes: 180_000),
            ],
            // The cover is named by attachment id, not by position.
            $this->articleOptions(['featured_attachment_id' => 13]),
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/posts'
                && $request['featured_media'] === 89
                && str_contains($request['content'], 'https://blog.kargah.test/uploads/one.jpg');
        });
    }

    /* The refusals ----------------------------------------------------------------- */

    public function test_a_401_names_the_application_password(): void
    {
        Http::fake([
            'blog.kargah.test/*' => Http::response([
                'code' => 'incorrect_password',
                'message' => 'The provided password is an invalid application password.',
            ], 401),
        ]);

        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('application password');

        (new WordPressPublisher)->publishWithOptions(
            $this->site(),
            'We rebuilt the board four times this quarter.',
            [],
            $this->articleOptions(),
        );
    }

    /**
     * A 404 is almost never the post. It is the site url or a disabled REST API,
     * and sending the reader to look at their password instead would waste the
     * afternoon.
     */
    public function test_a_404_names_the_rest_api_and_the_site_url(): void
    {
        Http::fake([
            'blog.kargah.test/*' => Http::response('<!doctype html><title>Not found</title>', 404),
        ]);

        try {
            (new WordPressPublisher)->publishWithOptions(
                $this->site(),
                'We rebuilt the board four times this quarter.',
                [],
                $this->articleOptions(),
            );

            $this->fail('a 404 should not have published anything');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('REST API', $e->getMessage());
            $this->assertStringContainsString('site URL', $e->getMessage());
            $this->assertStringNotContainsString('application password', $e->getMessage());
        }
    }

    public function test_a_site_url_with_no_scheme_is_refused_before_anything_is_sent(): void
    {
        // No `Http::fake()` at all: `preventStrayRequests()` means any request
        // made here would fail the test, which is the point.
        $account = $this->site('blog.kargah.test');

        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('https://example.com');

        (new WordPressPublisher)->publish($account, 'We rebuilt the board four times this quarter.');
    }

    public function test_a_pasted_wp_json_path_is_taken_back_off_rather_than_doubled(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/posts' => Http::response(['id' => 71, 'link' => 'https://blog.kargah.test/?p=71'], 201),
        ]);

        (new WordPressPublisher)->publish(
            $this->site('https://blog.kargah.test/wp-json/'),
            'We rebuilt the board four times this quarter.',
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://blog.kargah.test/wp-json/wp/v2/posts');
    }

    /* Verify -------------------------------------------------------------------------- */

    public function test_verify_asks_who_the_credential_is_and_publishes_nothing(): void
    {
        Http::fake([
            'blog.kargah.test/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 3,
                'name' => 'Nima Fazlipour',
                'slug' => 'nima',
            ]),
        ]);

        $this->assertSame('Nima Fazlipour', (new WordPressPublisher)->verify($this->site()));

        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/wp-json/wp/v2/users/me')
                // The view context would answer for a public profile on a site
                // that lists users anonymously, and a wrong password would come
                // back as a success.
                && str_contains($request->url(), 'context=edit')
                && $request->hasHeader('Authorization', $this->expectedAuthorisation());
        });
    }

    /* The routing — the load-bearing test ------------------------------------------------ */

    /**
     * `PostPublisher` makes one of two different calls, decided by `instanceof`.
     *
     * A driver that asked for options gets them; a driver that did not never
     * sees them, even when the target carries some. That is the whole of the
     * design in `Publishers\TakesTargetOptions`, and it is what lets one post
     * carry a title for the blog without the title being Telegram's business.
     */
    public function test_post_publisher_hands_options_to_wordpress_and_not_to_telegram(): void
    {
        $wordpress = new class implements TakesTargetOptions
        {
            /** @var list<array{path: string, body: string, options: array}> */
            public array $calls = [];

            public function network(): string
            {
                return Networks::WORDPRESS;
            }

            public function unavailableReason(SocialAccount $account): ?string
            {
                return null;
            }

            public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
            {
                $this->calls[] = ['path' => 'plain', 'body' => $body, 'options' => []];

                return new PublishedPost('plain');
            }

            public function publishWithOptions(
                SocialAccount $account,
                string $body,
                array $media = [],
                array $options = [],
            ): PublishedPost {
                $this->calls[] = ['path' => 'options', 'body' => $body, 'options' => $options];

                return new PublishedPost('412', 'https://blog.kargah.test/four-board-views/');
            }

            public function verify(SocialAccount $account): string
            {
                return 'blog.kargah.test';
            }
        };

        $telegram = new FakePublisher(Networks::TELEGRAM);

        $this->registry()->swap($wordpress);
        $this->registry()->swap($telegram);

        $site = $this->site();
        $channel = SocialAccount::factory()->onNetwork(Networks::TELEGRAM)->connected()->create();

        $post = Post::factory()->create(['body' => 'We rebuilt the board four times this quarter.']);

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $site->id,
            'options' => $this->articleOptions(),
        ]);

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $channel->id,
            'body_override' => 'New post: four board views and what each one cost.',
        ]);

        $report = $this->app->make(PostPublisher::class)->publishPost($post);

        $this->assertSame(2, $report->published);

        // WordPress went through the options path, and the options are the ones
        // on its own target rather than anybody else's.
        $this->assertCount(1, $wordpress->calls);
        $this->assertSame('options', $wordpress->calls[0]['path']);
        $this->assertSame('Four board views and what each one cost', $wordpress->calls[0]['options']['title']);
        $this->assertSame('We rebuilt the board four times this quarter.', $wordpress->calls[0]['body']);

        // Telegram went through the plain path — `FakePublisher` has no
        // `publishWithOptions()` at all, so anything else would have been fatal
        // — and received its own copy and no title.
        $this->assertSame(1, $telegram->sendCount());
        $this->assertSame('New post: four board views and what each one cost.', $telegram->sent[0]['body']);

        $wpTarget = $post->targets()->where('social_account_id', $site->id)->sole();

        $this->assertSame(PostTarget::PUBLISHED, $wpTarget->status);
        $this->assertSame('412', $wpTarget->remote_id);
        $this->assertSame('https://blog.kargah.test/four-board-views/', $wpTarget->remote_url);
    }

    /* The pages --------------------------------------------------------------------------- */

    public function test_both_pages_render_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->site();

        $post = Post::factory()->create(['body' => 'We rebuilt the board four times this quarter.']);

        Article::query()->create([
            'post_id' => $post->id,
            'title' => 'Four board views and what each one cost',
            'excerpt' => 'Table, calendar, dashboard, activity.',
        ]);

        $this->actingAs($user)->get('/blog')->assertOk()->assertSee('Four board views and what each one cost');
        $this->actingAs($user)->get('/blog/compose')->assertOk();
    }

    /**
     * The composer writes rows and hands them to Social, and the WordPress
     * target is the only one carrying options.
     */
    public function test_the_composer_writes_a_post_an_article_and_one_target_per_destination(): void
    {
        $user = User::factory()->create();

        $site = $this->site();
        $channel = SocialAccount::factory()->onNetwork(Networks::TELEGRAM)->connected()->create();

        Livewire::actingAs($user)
            ->test('blog::compose')
            ->set('title', 'Four board views and what each one cost')
            ->set('body', "We rebuilt the board four times this quarter.\n\nHere is what each view cost.")
            ->set('excerpt', 'Table, calendar, dashboard, activity.')
            ->set('slug', 'four-board-views')
            ->set('teaser', 'New post: four board views and what each one cost.')
            ->set('categories', 'Build log, Build Log')
            ->set('tags', 'laravel, livewire')
            ->set('wpStatus', 'draft')
            ->set('createMissingTerms', false)
            ->set('targets', [$site->id, $channel->id])
            // A draft, so nothing is published and no request is made. The
            // publishing path is `PostPublisher`'s and is tested above.
            ->set('schedule', 'draft')
            ->call('submit');

        $this->assertSame(1, Post::query()->count());
        $this->assertSame(1, Article::query()->count());

        $post = Post::query()->sole();
        $article = Article::query()->sole();

        $this->assertSame($post->id, $article->post_id);
        $this->assertSame('Four board views and what each one cost', $article->title);
        $this->assertSame('four-board-views', $article->slug);
        $this->assertSame(Post::DRAFT, $post->status);

        $this->assertSame(2, $post->targets()->count());

        $wpTarget = $post->targets()->where('social_account_id', $site->id)->sole();

        $this->assertSame('draft', $wpTarget->options['status']);
        $this->assertSame('Four board views and what each one cost', $wpTarget->options['title']);
        $this->assertSame('four-board-views', $wpTarget->options['slug']);
        $this->assertFalse($wpTarget->options['create_missing_terms']);
        // Typed twice in two cases, filed once.
        $this->assertSame(['Build log'], $wpTarget->options['categories']);
        $this->assertSame(['laravel', 'livewire'], $wpTarget->options['tags']);
        // WordPress reads the article body off the post; it has no override.
        $this->assertNull($wpTarget->body_override);

        $telegramTarget = $post->targets()->where('social_account_id', $channel->id)->sole();

        // The teaser, and no options: a slug is not Telegram's business.
        $this->assertSame('New post: four board views and what each one cost.', $telegramTarget->body_override);
        $this->assertNull($telegramTarget->options);
    }

    public function test_the_composer_refuses_an_article_with_no_title(): void
    {
        $user = User::factory()->create();

        $site = $this->site();

        Livewire::actingAs($user)
            ->test('blog::compose')
            ->set('title', '   ')
            ->set('body', 'We rebuilt the board four times this quarter.')
            ->set('targets', [$site->id])
            ->set('schedule', 'draft')
            ->call('submit');

        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, Article::query()->count());
    }

    /* ------------------------------------------------------------------------------------ */

    /**
     * Bytes for a `MediaItem` without touching a disk.
     *
     * `MediaItem::contents()` goes through `Modules\Data`'s contract rather than
     * reaching for a storage disk — see its docblock — which is exactly what
     * makes it stubbable from here.
     */
    private function stubAttachmentBytes(string $bytes = 'the bytes of a jpeg'): void
    {
        $attachments = Mockery::mock(AttachmentService::class);
        $attachments->shouldReceive('contents')->andReturn($bytes);

        $this->app->instance(AttachmentService::class, $attachments);
    }
}
