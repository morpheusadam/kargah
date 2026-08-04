<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Mockery;
use Modules\Blog\Services\DevToPublisher;
use Modules\Blog\Services\HashnodePublisher;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\TakesTargetOptions;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The two article destinations that are not WordPress.
 *
 * `BlogModuleTest` covers WordPress and the routing design in general; this file
 * is the same two halves for DEV.to and Hashnode, and it exists separately
 * because these two are where the design either pays off or does not. Nothing in
 * `Modules/Social` was touched to add them, no migration was written, no
 * scheduler learned anything — so what is worth asserting is precisely that the
 * existing machinery finds them.
 *
 * Three things here are load-bearing rather than incidental:
 *
 * 1. **The registration is asserted from the container.** Constructing
 *    `new DevToPublisher` proves the class exists and nothing at all about
 *    whether a DEV target would ever reach it. `Publishing` is a singleton and a
 *    callback registered after its first resolution never runs — that is the
 *    failure mode `BlogServiceProvider`'s `callAfterResolving()` comment is
 *    about, and this is the test that would catch it.
 * 2. **`PostPublisher` is exercised end to end for DEV.to**, through the real
 *    driver and the real registry, because the `instanceof TakesTargetOptions`
 *    branch is the whole reason these two cost a class each.
 * 3. **Hashnode's HTTP 200 failure.** GraphQL answers 200 for a refusal and puts
 *    the reason in a top-level `errors` array. A driver that trusted the status
 *    code would record a published article with a null id, so the test asserts
 *    the refusal rather than the success.
 *
 * Every test runs under `Http::preventStrayRequests()`, which on a machine with
 * no CA bundle is the difference between a failing assertion and `cURL error 60`.
 */
class BlogDestinationsTest extends TestCase
{
    use RefreshDatabase;

    private const DEV_KEY = 'dev-to-key-for-kargah';

    private const HASHNODE_TOKEN = 'hashnode-pat-for-kargah';

    private const PUBLICATION = '65b1f0c8a1d2e3f4a5b6c7d8';

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

    /* Helpers ------------------------------------------------------------------ */

    private function registry(): Publishing
    {
        return $this->app->make(Publishing::class);
    }

    private function devAccount(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::DEVTO)->create([
            'handle' => '@nima',
            'credentials' => ['api_key' => self::DEV_KEY],
            'connected_at' => now(),
        ]);
    }

    private function hashnodeAccount(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::HASHNODE)->create([
            'handle' => 'notes.kargah.dev',
            'credentials' => [
                'api_key' => self::HASHNODE_TOKEN,
                'publication_id' => self::PUBLICATION,
            ],
            'connected_at' => now(),
        ]);
    }

    /**
     * The bag the composer writes for every article destination on a post.
     *
     * Deliberately the WordPress-shaped one, keys and all: the composer writes
     * one bag and each driver takes the part of it that means something. If
     * `slug` or `create_missing_terms` ever made a DEV.to article fail, this is
     * the fixture that would catch it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function articleOptions(array $overrides = []): array
    {
        return [
            'title' => 'Four board views and what each one cost',
            'status' => 'publish',
            'slug' => 'four-board-views',
            'excerpt' => 'Table, calendar, dashboard, activity — and the one that was not worth it.',
            'create_missing_terms' => true,
            'categories' => ['Build log'],
            ...$overrides,
        ];
    }

    private const BODY = "We rebuilt the board four times this quarter.\n\nHere is what each view cost.";

    /* The registration ----------------------------------------------------------- */

    /**
     * Both drivers are reachable through Social's registry.
     *
     * From the container, never by construction — see the class docblock.
     */
    public function test_both_new_drivers_are_registered_in_socials_registry(): void
    {
        $devto = $this->registry()->driverFor(Networks::DEVTO);
        $hashnode = $this->registry()->driverFor(Networks::HASHNODE);

        $this->assertNotNull($devto, 'Blog did not register a driver for the devto network');
        $this->assertNotNull($hashnode, 'Blog did not register a driver for the hashnode network');

        $this->assertInstanceOf(DevToPublisher::class, $devto);
        $this->assertInstanceOf(HashnodePublisher::class, $hashnode);

        // The half `PostPublisher` actually branches on. Without it the article
        // would reach the driver as a plain body with no title.
        $this->assertInstanceOf(TakesTargetOptions::class, $devto);
        $this->assertInstanceOf(TakesTargetOptions::class, $hashnode);

        // Registering these two did not cost the one that was already there.
        $this->assertInstanceOf(TakesTargetOptions::class, $this->registry()->driverFor(Networks::WORDPRESS));
    }

    /* DEV.to ---------------------------------------------------------------------- */

    public function test_devto_posts_the_article_with_the_api_key_header(): void
    {
        Http::fake([
            'dev.to/api/articles' => Http::response([
                'id' => 1_284_551,
                'url' => 'https://dev.to/nima/four-board-views-and-what-each-one-cost-4h2n',
            ], 201),
        ]);

        $result = (new DevToPublisher)->publishWithOptions(
            $this->devAccount(),
            self::BODY,
            [],
            $this->articleOptions(),
        );

        $this->assertSame('1284551', $result->remoteId);
        $this->assertSame('https://dev.to/nima/four-board-views-and-what-each-one-cost-4h2n', $result->remoteUrl);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://dev.to/api/articles'
                && $request->method() === 'POST'
                // A header named exactly this. A bearer token is refused with a
                // 401 that says nothing about which of the two shapes was wrong.
                && $request->hasHeader('api-key', self::DEV_KEY)
                && ! $request->hasHeader('Authorization')
                && $request['article']['title'] === 'Four board views and what each one cost'
                && $request['article']['body_markdown'] === self::BODY
                && $request['article']['published'] === true
                // The composer's excerpt is DEV's meta description.
                && str_starts_with($request['article']['description'], 'Table, calendar, dashboard, activity');
        });
    }

    /**
     * DEV takes four tags and they must be lowercase alphanumeric.
     *
     * Beyond the fourth they are dropped rather than failing the post, which is
     * a judgement call argued in `DevToPublisher`'s class docblock: an article
     * missing two tags is fixable in ten seconds on DEV, and an article that did
     * not go out because somebody typed a fifth tag into a field shared with
     * WordPress is a publishing tool that refuses to publish.
     */
    /**
     * A tag Kargah cannot render in Latin is dropped, never invented.
     *
     * `Str::slug()` transliterates rather than strips, so `برنامه‌نویسی` came out
     * as `brnamhnoysy` — a non-word, published as a tag on a public article, that
     * nobody typed. This project's owner writes Persian, so it is not a
     * hypothetical input. A missing tag is visible and fixable; an invented one
     * looks deliberate.
     */
    public function test_a_tag_that_is_not_already_latin_is_dropped_rather_than_transliterated(): void
    {
        Http::fake(['dev.to/api/articles' => Http::response(['id' => 1, 'url' => 'https://dev.to/a/b'], 201)]);

        (new DevToPublisher)->publishWithOptions(
            $this->devAccount(),
            'The board views, and what each one cost.',
            [],
            ['title' => 'Four board views', 'tags' => ['برنامه‌نویسی', 'Laravel', 'لاراول', 'PHP']],
        );

        $sent = Http::recorded()[0][0]->data()['article']['tags'] ?? null;

        $this->assertSame(['laravel', 'php'], $sent);
    }

    public function test_devto_tags_are_normalised_and_capped_at_four(): void
    {
        Http::fake(['dev.to/api/articles' => Http::response(['id' => 7, 'url' => 'https://dev.to/nima/x'], 201)]);

        (new DevToPublisher)->publishWithOptions(
            $this->devAccount(),
            self::BODY,
            [],
            $this->articleOptions([
                'tags' => ['Laravel', 'Build Log', 'livewire-4', 'Scope!', 'tooling', 'laravel'],
            ]),
        );

        Http::assertSent(function ($request): bool {
            $tags = $request['article']['tags'];

            return $tags === ['laravel', 'buildlog', 'livewire4', 'scope']
                // The fifth and sixth are gone, and the duplicate did not eat a
                // place: `laravel` appears once, not twice.
                && count($tags) === 4;
        });
    }

    public function test_devto_maps_a_draft_status_to_published_false(): void
    {
        Http::fake(['dev.to/api/articles' => Http::response(['id' => 8, 'url' => 'https://dev.to/nima/y'], 201)]);

        (new DevToPublisher)->publishWithOptions(
            $this->devAccount(),
            self::BODY,
            [],
            $this->articleOptions(['status' => 'draft']),
        );

        Http::assertSent(fn ($request): bool => $request['article']['published'] === false);
    }

    /**
     * A `private` WordPress article is an unpublished DEV draft, not a public one.
     *
     * The mapping is lossy and the docblock says so; what matters is which way
     * it is lossy. Publishing something marked private because DEV has no
     * private article is the one mistake in this module that cannot be undone.
     */
    public function test_devto_maps_a_private_status_to_published_false(): void
    {
        Http::fake(['dev.to/api/articles' => Http::response(['id' => 9, 'url' => 'https://dev.to/nima/z'], 201)]);

        (new DevToPublisher)->publishWithOptions(
            $this->devAccount(),
            self::BODY,
            [],
            $this->articleOptions(['status' => 'private']),
        );

        Http::assertSent(fn ($request): bool => $request['article']['published'] === false);
    }

    /**
     * An install with no public address publishes the article without a cover.
     *
     * 🔴 **This is the opposite of Instagram and deliberately so.** Instagram
     * refuses outright, because there is no text-only Instagram post and no
     * request that could succeed. A DEV article is text and the cover is a
     * decoration, so refusing to publish eight hundred words over a thumbnail
     * would be Kargah inventing a requirement DEV does not have.
     *
     * `url('/')` answers `http://localhost` in the test environment, which is
     * exactly the case the guard is for — no forcing is needed to reach it.
     */
    public function test_devto_publishes_without_a_cover_when_the_install_has_no_public_address(): void
    {
        Http::fake(['dev.to/api/articles' => Http::response(['id' => 21, 'url' => 'https://dev.to/nima/a'], 201)]);

        // Not stubbed and not needed: the guard runs before anything asks for a
        // URL. A call to `publicUrl()` here would be a Mockery failure.
        $attachments = Mockery::mock(AttachmentService::class);
        $attachments->shouldNotReceive('publicUrl');
        $this->app->instance(AttachmentService::class, $attachments);

        $this->assertStringContainsString('localhost', url('/'));

        (new DevToPublisher)->publishWithOptions(
            $this->devAccount(),
            self::BODY,
            [new MediaItem(id: 12, name: 'board-views.jpg', mime: 'image/jpeg', sizeBytes: 240_000)],
            $this->articleOptions(),
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://dev.to/api/articles'
                && ! isset($request['article']['main_image'])
                // The article itself went out untouched.
                && $request['article']['body_markdown'] === self::BODY;
        });
    }

    /** The other half of the same decision: given a public address, the cover is sent. */
    public function test_devto_sends_a_main_image_url_when_the_install_is_reachable(): void
    {
        Http::fake(['dev.to/api/articles' => Http::response(['id' => 22, 'url' => 'https://dev.to/nima/b'], 201)]);

        URL::forceRootUrl('https://kargah.example.com');

        $attachments = Mockery::mock(AttachmentService::class);
        $attachments->shouldReceive('publicUrl')
            ->with(12)
            ->andReturn('https://kargah.example.com/files/12/share?signature=abc');
        $this->app->instance(AttachmentService::class, $attachments);

        (new DevToPublisher)->publishWithOptions(
            $this->devAccount(),
            self::BODY,
            [new MediaItem(id: 12, name: 'board-views.jpg', mime: 'image/jpeg', sizeBytes: 240_000)],
            $this->articleOptions(),
        );

        Http::assertSent(fn ($request): bool => $request['article']['main_image']
            === 'https://kargah.example.com/files/12/share?signature=abc');
    }

    public function test_devto_verify_reads_the_user_and_publishes_nothing(): void
    {
        Http::fake([
            'dev.to/api/users/me' => Http::response(['id' => 501, 'username' => 'nima', 'name' => 'Nima Fazlipour']),
        ]);

        $this->assertSame('@nima', (new DevToPublisher)->verify($this->devAccount()));

        Http::assertSentCount(1);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://dev.to/api/users/me'
            && $request->hasHeader('api-key', self::DEV_KEY));
    }

    /**
     * A missing title is derived from the first line, and the first line stays.
     *
     * `WordPressPublisher`'s docblock argues it and this is the house answer:
     * the article reads its first line twice, which is visible and fixable in
     * thirty seconds, whereas silently deleting a sentence from somebody's copy
     * is data loss they cannot see from Kargah at all.
     */
    public function test_devto_derives_a_missing_title_and_leaves_the_body_intact(): void
    {
        Http::fake(['dev.to/api/articles' => Http::response(['id' => 31, 'url' => 'https://dev.to/nima/c'], 201)]);

        $body = "Four board views and what each one cost\n\nWe rebuilt the board four times this quarter.";

        // The plain path — no options at all. A DEV target the ordinary social
        // composer created, or a post scheduled before this driver existed.
        (new DevToPublisher)->publish($this->devAccount(), $body);

        Http::assertSent(function ($request) use ($body): bool {
            return $request['article']['title'] === 'Four board views and what each one cost'
                && $request['article']['body_markdown'] === $body
                && str_contains($request['article']['body_markdown'], 'Four board views and what each one cost')
                // No status said, so the default is publish — whoever aimed a
                // post at a destination meant to publish it.
                && $request['article']['published'] === true;
        });
    }

    public function test_devto_names_the_api_key_on_a_401(): void
    {
        Http::fake(['dev.to/api/*' => Http::response(['error' => 'unauthorized', 'status' => 401], 401)]);

        try {
            (new DevToPublisher)->publishWithOptions($this->devAccount(), self::BODY, [], $this->articleOptions());

            $this->fail('a 401 should not have published anything');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('API key', $e->getMessage());
            $this->assertStringContainsString('api-key', $e->getMessage());
        }
    }

    /* Hashnode --------------------------------------------------------------------- */

    public function test_hashnode_sends_a_publish_post_mutation_with_the_input(): void
    {
        Http::fake([
            'gql.hashnode.com/*' => Http::response([
                'data' => [
                    'publishPost' => [
                        'post' => [
                            'id' => '65c9a1b2d3e4f5a6b7c8d9e0',
                            'slug' => 'four-board-views-and-what-each-one-cost',
                            'url' => 'https://notes.kargah.dev/four-board-views-and-what-each-one-cost',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new HashnodePublisher)->publishWithOptions(
            $this->hashnodeAccount(),
            self::BODY,
            [],
            $this->articleOptions([
                'tags' => ['Build Log', 'Laravel'],
                'canonical_url' => 'https://kargah.dev/notes/board-views',
            ]),
        );

        $this->assertSame('65c9a1b2d3e4f5a6b7c8d9e0', $result->remoteId);
        $this->assertSame('https://notes.kargah.dev/four-board-views-and-what-each-one-cost', $result->remoteUrl);

        Http::assertSent(function ($request): bool {
            $input = $request['variables']['input'];

            return $request->url() === 'https://gql.hashnode.com/'
                && $request->method() === 'POST'
                // The token raw, with no `Bearer ` in front of it.
                && $request->hasHeader('Authorization', self::HASHNODE_TOKEN)
                && str_contains($request['query'], 'mutation PublishPost')
                && str_contains($request['query'], 'publishPost(input: $input)')
                && $input['publicationId'] === self::PUBLICATION
                && $input['title'] === 'Four board views and what each one cost'
                && $input['contentMarkdown'] === self::BODY
                // Tags are objects, not strings, and the slug is derived while
                // the name keeps what was typed.
                && $input['tags'] === [
                    ['slug' => 'build-log', 'name' => 'Build Log'],
                    ['slug' => 'laravel', 'name' => 'Laravel'],
                ]
                && $input['originalArticleURL'] === 'https://kargah.dev/notes/board-views'
                // Not sent, on purpose: an unknown field on a GraphQL input type
                // fails the whole mutation, and `slug` is not corroborated.
                && ! isset($input['slug'])
                && ! isset($input['categories'])
                // Kargah's cron owns time; two schedulers on one article is an
                // article that appears twice or not at all.
                && ! isset($input['publishedAt']);
        });
    }

    /**
     * 🔴 HTTP 200 with a top-level `errors` array is a failure, not a success.
     *
     * This is the trap in its fourth costume — Telegram's `ok: false`, Slack's
     * `ok: false`, VK's `error` object, and now GraphQL's envelope. A driver
     * that read `$response->successful()` would record a published article and
     * hand `post_targets` a null remote id.
     */
    public function test_hashnode_treats_a_200_with_an_errors_array_as_a_failure(): void
    {
        Http::fake([
            'gql.hashnode.com/*' => Http::response([
                'data' => null,
                'errors' => [
                    [
                        'message' => 'Publication not found for the given id',
                        'extensions' => ['code' => 'NOT_FOUND'],
                    ],
                    ['message' => 'a second error nobody should be shown'],
                ],
            ], 200),
        ]);

        try {
            (new HashnodePublisher)->publishWithOptions(
                $this->hashnodeAccount(),
                self::BODY,
                [],
                $this->articleOptions(),
            );

            $this->fail('a GraphQL errors envelope should not have counted as a published post');
        } catch (PublishFailed $e) {
            // The first message, in Hashnode's own words, because it is more
            // exact than anything the driver could write.
            $this->assertStringContainsString('Publication not found for the given id', $e->getMessage());
            $this->assertStringContainsString('NOT_FOUND', $e->getMessage());
            $this->assertStringNotContainsString('a second error nobody should be shown', $e->getMessage());
        }
    }

    /**
     * A status this driver cannot honour is refused before anything is sent.
     *
     * `publishPost` publishes; Hashnode's drafts are a different mutation that
     * is not implemented here. Publishing a draft to a public blog anyway is the
     * one mistake in this module that cannot be taken back, so the target fails
     * with a sentence naming the limitation instead.
     *
     * No `Http::fake()` at all: `preventStrayRequests()` means any request made
     * here would fail the test, which is the point.
     */
    public function test_hashnode_refuses_a_draft_rather_than_publishing_it(): void
    {
        try {
            (new HashnodePublisher)->publishWithOptions(
                $this->hashnodeAccount(),
                self::BODY,
                [],
                $this->articleOptions(['status' => 'draft']),
            );

            $this->fail('a draft should not have been published to a public blog');
        } catch (PublishFailed $e) {
            $this->assertStringContainsString('createDraft', $e->getMessage());
            $this->assertStringContainsString('draft', $e->getMessage());
        }
    }

    public function test_hashnode_verify_reads_the_user_and_publishes_nothing(): void
    {
        Http::fake([
            'gql.hashnode.com/*' => Http::response(['data' => ['me' => ['username' => 'nima']]]),
        ]);

        $this->assertSame('@nima', (new HashnodePublisher)->verify($this->hashnodeAccount()));

        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://gql.hashnode.com/'
                && $request->hasHeader('Authorization', self::HASHNODE_TOKEN)
                // A query, not a mutation. On GraphQL that is the whole
                // guarantee that verifying cannot create anything.
                && str_contains($request['query'], 'me { username }')
                && ! str_contains($request['query'], 'mutation');
        });
    }

    public function test_hashnode_derives_a_missing_title_and_leaves_the_body_intact(): void
    {
        Http::fake([
            'gql.hashnode.com/*' => Http::response([
                'data' => ['publishPost' => ['post' => ['id' => 'abc123', 'slug' => 'four', 'url' => 'https://notes.kargah.dev/four']]],
            ]),
        ]);

        $body = "Four board views and what each one cost\n\nWe rebuilt the board four times this quarter.";

        (new HashnodePublisher)->publish($this->hashnodeAccount(), $body);

        Http::assertSent(function ($request) use ($body): bool {
            $input = $request['variables']['input'];

            return $input['title'] === 'Four board views and what each one cost'
                && $input['contentMarkdown'] === $body
                && str_contains($input['contentMarkdown'], 'Four board views and what each one cost');
        });
    }

    public function test_hashnode_publishes_without_a_cover_when_the_install_has_no_public_address(): void
    {
        Http::fake([
            'gql.hashnode.com/*' => Http::response([
                'data' => ['publishPost' => ['post' => ['id' => 'cover1', 'slug' => 'x', 'url' => 'https://notes.kargah.dev/x']]],
            ]),
        ]);

        $attachments = Mockery::mock(AttachmentService::class);
        $attachments->shouldNotReceive('publicUrl');
        $this->app->instance(AttachmentService::class, $attachments);

        (new HashnodePublisher)->publishWithOptions(
            $this->hashnodeAccount(),
            self::BODY,
            [new MediaItem(id: 12, name: 'board-views.jpg', mime: 'image/jpeg', sizeBytes: 240_000)],
            $this->articleOptions(),
        );

        Http::assertSent(fn ($request): bool => ! isset($request['variables']['input']['coverImageOptions']));
    }

    /* The routing — the load-bearing test -------------------------------------------- */

    /**
     * `PostPublisher` routes a DEV.to target through `publishWithOptions()`.
     *
     * The real driver, the real registry, the real `instanceof` branch — nothing
     * swapped, because a fake driver would prove that `PostPublisher` calls
     * whatever it is handed and nothing about whether `BlogServiceProvider`
     * handed it anything. The title arriving at DEV is the proof: the plain
     * `publish()` path would have derived it from the body's first line and sent
     * “We rebuilt the board four times this quarter.” instead.
     */
    public function test_post_publisher_routes_a_devto_target_through_the_options_path(): void
    {
        Http::fake([
            'dev.to/api/articles' => Http::response([
                'id' => 1_284_552,
                'url' => 'https://dev.to/nima/four-board-views-and-what-each-one-cost-4h2n',
            ], 201),
        ]);

        $account = $this->devAccount();

        $post = Post::factory()->create(['body' => self::BODY]);

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'options' => $this->articleOptions(['tags' => ['Build Log', 'Laravel']]),
        ]);

        $report = $this->app->make(PostPublisher::class)->publishPost($post);

        $this->assertSame(1, $report->published, $report->firstError() ?? '');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://dev.to/api/articles'
                // From the options, not derived from the body — which is the
                // whole of `TakesTargetOptions` in one assertion.
                && $request['article']['title'] === 'Four board views and what each one cost'
                && $request['article']['tags'] === ['buildlog', 'laravel'];
        });

        $target = $post->targets()->sole();

        $this->assertSame(PostTarget::PUBLISHED, $target->status);
        $this->assertSame('1284552', $target->remote_id);
        $this->assertSame('https://dev.to/nima/four-board-views-and-what-each-one-cost-4h2n', $target->remote_url);
        $this->assertNull($target->error);
    }
}
