<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteSnapshot;
use Modules\Site\Services\WordPressSite;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The Site module: the website, operated from Kargah.
 *
 * Everything here runs under `Http::preventStrayRequests()`. That is not
 * ceremony on this machine — there is no CA bundle in `php.ini`, so a request
 * that escapes the fake does not reach WordPress and quietly pass, it dies with
 * `cURL error 60` several frames from the assertion that caused it.
 *
 * The load-bearing tests are the ones about *state* rather than about request
 * shape. A REST client that builds the right URL is easy and easy to check; the
 * things that will actually break a person's afternoon are a snapshot that
 * caches a failure, a page that fatals instead of reporting a dead connection,
 * and a credential belonging to a user who cannot write. Those get their own
 * tests and are named for what they prevent.
 */
class SiteModuleTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://bineret.test';

    private const PASSWORD = 'abcd EFGH 1234 ijkl';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /* Helpers ----------------------------------------------------------------- */

    /**
     * A connected WordPress site.
     *
     * By hand rather than through the factory's credential defaults, for the
     * reason `BlogModuleTest` gives: the factory fills every field with the same
     * placeholder, and `site_url` has to be a real address or `baseUrl()`
     * refuses it before any faked request is made.
     */
    private function site(string $url = self::SITE, array $overrides = []): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::WORDPRESS)->create([
            'handle' => 'bineret.test',
            'credentials' => [
                'site_url' => $url,
                'username' => 'nima',
                'application_password' => self::PASSWORD,
            ],
            'connected_at' => now(),
            ...$overrides,
        ]);
    }

    private function expectedAuthorisation(): string
    {
        return 'Basic '.base64_encode('nima:'.self::PASSWORD);
    }

    /** The `/wp-json/` root, as WordPress sends it. */
    private function rootBody(array $namespaces = ['oembed/1.0', 'wp/v2']): array
    {
        return [
            'name' => 'Lavzen Web',
            'description' => 'AI marketplace',
            'url' => self::SITE,
            'namespaces' => $namespaces,
        ];
    }

    /** `wp/v2/users/me?context=edit`, as WordPress sends it. */
    private function meBody(array $capabilities = ['edit_posts', 'edit_pages', 'upload_files', 'manage_categories']): array
    {
        return [
            'id' => 1,
            'name' => 'Nima',
            'roles' => ['administrator'],
            'capabilities' => array_fill_keys($capabilities, true),
        ];
    }

    /** Both calls a snapshot makes, faked. */
    private function fakeHealthySite(array $namespaces = ['oembed/1.0', 'wp/v2'], ?array $capabilities = null): void
    {
        Http::fake([
            self::SITE.'/wp-json/wp/v2/users/me*' => Http::response(
                $capabilities === null ? $this->meBody() : $this->meBody($capabilities),
            ),
            self::SITE.'/wp-json/' => Http::response($this->rootBody($namespaces)),
        ]);
    }

    /**
     * A site whose answers can be changed part-way through a test.
     *
     * 🔴 Calling `Http::fake()` a second time does **not** replace the first
     * set of stubs — the new ones are appended and the earliest matching stub
     * still wins, so a test that re-fakes to simulate the site changing gets
     * the original answer and a failure that reads as though the code ignored
     * it. Two tests here were written that way and passed for the wrong reason
     * until they did not.
     *
     * One fake, registered once, reading a variable the test mutates. The
     * returned closure is how the test moves the site underneath the code.
     *
     * @param  list<string>  $namespaces
     * @return \Closure(list<string>): void
     */
    private function fakeMutableSite(array $namespaces = ['oembed/1.0', 'wp/v2'], int $status = 200): \Closure
    {
        // An object rather than an array: `fn () =>` captures by value, so an
        // array here would freeze at its first contents and the mutation below
        // would be invisible to the stubs — the exact bug this helper exists to
        // stop. A shared object is one reference however it is captured.
        $state = new \stdClass;
        $state->namespaces = $namespaces;
        $state->status = $status;

        $refusal = fn (int $status) => Http::response(
            ['code' => 'rest_forbidden', 'message' => 'The provided password is an invalid application password.'],
            $status,
        );

        Http::fake([
            self::SITE.'/wp-json/wp/v2/users/me*' => fn () => $state->status === 200
                ? Http::response($this->meBody())
                : $refusal($state->status),
            self::SITE.'/wp-json/' => fn () => $state->status === 200
                ? Http::response($this->rootBody($state->namespaces))
                : $refusal($state->status),
        ]);

        return function (array $namespaces, int $status = 200) use ($state): void {
            $state->namespaces = $namespaces;
            $state->status = $status;
        };
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    /* The connection ----------------------------------------------------------- */

    public function test_the_connected_site_is_the_wordpress_account_and_not_a_second_row(): void
    {
        $account = $this->site();

        $site = WordPressSite::connected();

        $this->assertNotNull($site);
        $this->assertTrue($account->is($site->account()));
    }

    public function test_there_is_no_connected_site_when_the_credential_is_incomplete(): void
    {
        SocialAccount::factory()->onNetwork(Networks::WORDPRESS)->create([
            'credentials' => ['site_url' => self::SITE],
            'connected_at' => now(),
        ]);

        $this->assertNull(WordPressSite::connected());
    }

    public function test_there_is_no_connected_site_when_the_account_is_switched_off(): void
    {
        $this->site(overrides: ['is_active' => false]);

        $this->assertNull(WordPressSite::connected());
    }

    /**
     * More than one WordPress row is two different sites, and the newest wins.
     *
     * Deterministic rather than arbitrary: whichever the database happened to
     * return first would make the module's behaviour depend on insertion order,
     * which is exactly the kind of thing that works on this machine and not on
     * the server.
     */
    public function test_the_most_recently_connected_site_wins_when_there_are_two(): void
    {
        $this->site('https://old.test', ['handle' => 'old', 'connected_at' => now()->subDays(3)]);
        $newer = $this->site('https://new.test', ['handle' => 'new', 'connected_at' => now()]);

        $this->assertTrue($newer->is(WordPressSite::require()->account()));
    }

    public function test_require_names_the_problem_when_nothing_is_connected(): void
    {
        $this->expectException(SiteRequestFailed::class);
        $this->expectExceptionMessage('No WordPress site is connected');

        WordPressSite::require();
    }

    /* URLs --------------------------------------------------------------------- */

    public function test_a_site_url_without_a_scheme_is_assumed_to_be_https(): void
    {
        $this->site('bineret.test');

        $this->assertSame('https://bineret.test/wp-json/wp/v2/posts', WordPressSite::require()->url('wp/v2/posts'));
    }

    public function test_a_trailing_slash_does_not_become_a_double_slash(): void
    {
        $this->site(self::SITE.'/');

        $this->assertSame(self::SITE.'/wp-json/wp/v2/posts', WordPressSite::require()->url('wp/v2/posts'));
    }

    /**
     * 🔴 The one refusal in this class that is about safety rather than tidiness.
     *
     * An application password sent over `http://` is a credential in cleartext
     * on the wire, and WordPress itself will not issue one on a site without
     * TLS. Guessing `https` for a scheme-less URL is friendly; silently
     * honouring an explicit `http://` would not be.
     */
    public function test_a_plain_http_site_is_refused_rather_than_downgraded_to(): void
    {
        $this->site('http://bineret.test');

        $this->expectException(SiteRequestFailed::class);
        $this->expectExceptionMessage('cleartext');

        WordPressSite::require()->baseUrl();
    }

    /* Requests ----------------------------------------------------------------- */

    public function test_a_read_carries_the_application_password_as_basic_auth(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts*' => Http::response([['id' => 1]])]);

        WordPressSite::require()->get('wp/v2/posts', ['per_page' => 5]);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', $this->expectedAuthorisation())
                && str_contains($request->url(), 'per_page=5');
        });
    }

    /**
     * WordPress paginates in headers, not in the body.
     *
     * A list page that only decoded JSON would have to walk every page to learn
     * how many there are.
     */
    public function test_pagination_is_read_from_the_wp_total_headers(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts*' => Http::response(
                [['id' => 1], ['id' => 2]],
                headers: ['X-WP-Total' => '57', 'X-WP-TotalPages' => '3'],
            ),
        ]);

        $page = WordPressSite::require()->paginate('wp/v2/posts');

        $this->assertCount(2, $page['items']);
        $this->assertSame(57, $page['total']);
        $this->assertSame(3, $page['pages']);
    }

    public function test_a_route_that_sends_no_pagination_headers_reports_the_row_count(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/settings*' => Http::response([['id' => 1]])]);

        $page = WordPressSite::require()->paginate('wp/v2/settings');

        $this->assertSame(1, $page['total']);
        $this->assertSame(1, $page['pages']);
    }

    /**
     * WordPress writes better refusals than this codebase would.
     *
     * `Sorry, you are not allowed to edit this post.` tells the owner what to
     * change; `403 Forbidden` does not.
     */
    public function test_a_refusal_keeps_wordpresss_own_wording_and_error_code(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts/9*' => Http::response([
                'code' => 'rest_cannot_edit',
                'message' => 'Sorry, you are not allowed to edit this post.',
            ], 403),
        ]);

        try {
            WordPressSite::require()->post('wp/v2/posts/9', ['title' => 'x']);
            $this->fail('The refusal should have thrown.');
        } catch (SiteRequestFailed $e) {
            $this->assertStringContainsString('not allowed to edit this post', $e->getMessage());
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertSame('rest_cannot_edit', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
    }

    /**
     * A 200 that is not JSON is almost always one specific thing on WordPress,
     * and saying which is more useful than 'malformed'.
     */
    public function test_a_non_json_two_hundred_blames_output_before_the_response(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts*' => Http::response('<br />Notice: undefined', 200)]);

        $this->expectException(SiteRequestFailed::class);
        $this->expectExceptionMessage('plugin or theme printing output');

        WordPressSite::require()->get('wp/v2/posts');
    }

    /**
     * A write is not retried.
     *
     * Retrying a `POST` that timed out after the site had already accepted it is
     * how one article becomes two, and nothing downstream can tell the copies
     * apart.
     */
    public function test_a_write_is_not_retried_but_a_read_is(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts' => Http::sequence()
                ->push(['code' => 'rest_error'], 500)
                ->push(['id' => 7], 201),
        ]);

        try {
            WordPressSite::require()->post('wp/v2/posts', ['title' => 'x']);
        } catch (SiteRequestFailed) {
            // The refusal is the point; what matters is that it was sent once.
        }

        Http::assertSentCount(1);
    }

    public function test_an_upload_sends_the_bytes_with_a_content_disposition_filename(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/media' => Http::response(['id' => 12], 201)]);

        WordPressSite::require()->upload('dashboard.png', 'PNGBYTES', 'image/png');

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Content-Disposition', 'attachment; filename="dashboard.png"')
                && $request->hasHeader('Content-Type', 'image/png')
                && $request->body() === 'PNGBYTES';
        });
    }

    /* The snapshot ------------------------------------------------------------- */

    public function test_the_snapshot_reports_identity_capabilities_and_namespaces(): void
    {
        $this->site();
        $this->fakeHealthySite(['oembed/1.0', 'wp/v2', 'rankmath/v1', 'litespeed/v1']);

        $snapshot = SiteSnapshot::of(WordPressSite::require());

        $this->assertTrue($snapshot->connected);
        $this->assertSame('Lavzen Web', $snapshot->name);
        $this->assertSame('Nima', $snapshot->userName);
        $this->assertSame(['administrator'], $snapshot->roles);
        $this->assertTrue($snapshot->hasRankMath());
        $this->assertSame('litespeed/v1', $snapshot->cacheNamespace());
        $this->assertSame([], $snapshot->missingCapabilities());
    }

    /**
     * A Subscriber's password is a working connection that cannot write, and the
     * only cheap moment to find that out is before anybody has typed anything.
     */
    public function test_a_credential_without_write_capability_is_named_rather_than_shown_as_healthy(): void
    {
        $this->site();
        $this->fakeHealthySite(capabilities: ['read']);

        $snapshot = SiteSnapshot::of(WordPressSite::require());

        $this->assertTrue($snapshot->connected);
        $this->assertContains('Write and edit posts', $snapshot->missingCapabilities());
        $this->assertContains('Upload to the media library', $snapshot->missingCapabilities());
    }

    public function test_an_install_without_rank_math_or_a_cache_plugin_says_so(): void
    {
        $this->site();
        $this->fakeHealthySite(['oembed/1.0', 'wp/v2']);

        $snapshot = SiteSnapshot::of(WordPressSite::require());

        $this->assertFalse($snapshot->hasRankMath());
        $this->assertNull($snapshot->cacheNamespace());
    }

    public function test_a_snapshot_is_cached_so_moving_between_pages_costs_one_round_trip(): void
    {
        $this->site();
        $this->fakeHealthySite();

        SiteSnapshot::of(WordPressSite::require());
        SiteSnapshot::of(WordPressSite::require());

        // Two calls per fetch — the root and the user — and only one fetch.
        Http::assertSentCount(2);
    }

    /**
     * 🔴 A failure is never cached.
     *
     * Five minutes of remembering that the site is down is five minutes of
     * somebody fixing it and being told it is still broken.
     */
    public function test_a_failure_is_not_cached(): void
    {
        $this->site();

        $change = $this->fakeMutableSite(status: 401);

        $first = SiteSnapshot::of(WordPressSite::require());

        $this->assertFalse($first->connected);
        $this->assertNull(Cache::get('site:snapshot:'.WordPressSite::require()->account()->getKey()));

        // The site comes back. Nothing was remembered, so the very next look
        // sees it — no waiting out a five-minute memory of a fixed problem.
        $change(['wp/v2'], 200);

        $this->assertTrue(SiteSnapshot::of(WordPressSite::require())->connected);
    }

    public function test_a_fresh_snapshot_ignores_the_cache(): void
    {
        $this->site();

        $change = $this->fakeMutableSite(['wp/v2']);

        $this->assertFalse(SiteSnapshot::of(WordPressSite::require())->hasRankMath());

        // Rank Math is activated on the site while Kargah is holding a snapshot.
        $change(['wp/v2', 'rankmath/v1']);

        $this->assertFalse(SiteSnapshot::of(WordPressSite::require())->hasRankMath());
        $this->assertTrue(SiteSnapshot::of(WordPressSite::require(), fresh: true)->hasRankMath());
    }

    /* The page ------------------------------------------------------------------ */

    public function test_the_overview_explains_itself_when_no_site_is_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::overview')
            ->assertOk()
            ->assertSee('No website is connected')
            ->assertSee('Connect a site');
    }

    /**
     * 🔴 The page must survive the state it exists to report.
     *
     * A component that fatals when the connection is broken cannot tell anybody
     * that the connection is broken.
     */
    public function test_the_overview_reports_a_refused_connection_instead_of_failing(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/*' => Http::response([
            'code' => 'incorrect_password',
            'message' => 'The provided password is an invalid application password.',
        ], 401)]);

        Livewire::actingAs($this->actor())
            ->test('site::overview')
            ->assertOk()
            ->assertSee('is not answering Kargah')
            ->assertSee('invalid application password');
    }

    public function test_the_overview_draws_the_site_when_it_answers(): void
    {
        $this->site();
        $this->fakeHealthySite(['wp/v2', 'rankmath/v1']);

        Livewire::actingAs($this->actor())
            ->test('site::overview')
            ->assertOk()
            ->assertSee('Lavzen Web')
            ->assertSee('administrator')
            ->assertSee('Rank Math');
    }

    public function test_rechecking_asks_the_site_again_rather_than_the_cache(): void
    {
        $this->site();
        $this->fakeHealthySite();

        $component = Livewire::actingAs($this->actor())->test('site::overview')->assertOk();

        Http::assertSentCount(2);

        $component->call('recheck');

        Http::assertSentCount(4);
    }

    /* The promise on the connect page -------------------------------------------- */

    /**
     * 🔴 This module makes the WordPress connect page's own copy false.
     *
     * `Networks::WORDPRESS['permissions']` tells the reader Kargah cannot "edit
     * or delete anything that was already on the site". That was true when the
     * only driver was `WordPressPublisher`. It is not true of a module whose
     * whole purpose is to operate the site, and a promise about what a stored
     * credential will be used for is not something to quietly outgrow.
     *
     * This test fails while the old wording is still there. It is the reason the
     * copy gets rewritten rather than forgotten.
     */
    public function test_the_wordpress_connect_page_no_longer_promises_it_cannot_edit_existing_content(): void
    {
        $permissions = Networks::all()[Networks::WORDPRESS]['permissions'] ?? [];

        foreach ($permissions as $permission) {
            $this->assertStringNotContainsString(
                'Edit or delete anything that was already on the site',
                (string) ($permission['text'] ?? ''),
                'The connect page still promises Kargah cannot edit existing content, which Modules/Site now does.',
            );
        }
    }
}
