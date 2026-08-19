<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteCache;
use Modules\Site\Services\SiteSnapshot;
use Modules\Site\Services\WordPressSite;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * Purging the website's cache.
 *
 * The tests here are mostly about *not acting*. No cache plugin exposes a purge
 * route over REST, so the page's default state is "this cannot be done yet,
 * here is the file that changes that" — and the way to get that wrong is to
 * offer a button that 404s, or worse, to purge a busy site's entire cache
 * because somebody clicked once.
 */
class SiteCacheTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://bineret.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function site(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::WORDPRESS)->create([
            'handle' => 'bineret.test',
            'credentials' => [
                'site_url' => self::SITE,
                'username' => 'nima',
                'application_password' => 'abcd EFGH 1234 ijkl',
            ],
            'connected_at' => now(),
        ]);
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    private function fakeSite(array $namespaces): void
    {
        Http::fake([
            self::SITE.'/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 1,
                'name' => 'Nima',
                'roles' => ['administrator'],
                'capabilities' => ['edit_posts' => true, 'edit_pages' => true, 'upload_files' => true, 'manage_categories' => true],
            ]),
            self::SITE.'/wp-json/' => Http::response([
                'name' => 'Lavzen Web',
                'namespaces' => $namespaces,
            ]),
            self::SITE.'/wp-json/kargah/v1/cache/purge' => Http::response([
                'purged' => true,
                'driver' => 'LiteSpeed Cache',
                'message' => 'Purged.',
            ]),
        ]);
    }

    /* Availability -------------------------------------------------------------- */

    /**
     * 🔴 A detected cache plugin is not a purge route.
     *
     * `litespeed/v1` proves LiteSpeed is installed. Its routes exist for
     * QUIC.cloud's own integration, not as a public API, and treating the
     * namespace as permission to purge would give the panel a button that 404s.
     */
    public function test_a_cache_plugin_being_installed_does_not_make_purging_available(): void
    {
        $this->site();
        $this->fakeSite(['wp/v2', 'litespeed/v1']);

        $snapshot = SiteSnapshot::of(WordPressSite::require());

        $this->assertSame('litespeed/v1', $snapshot->cacheNamespace());
        $this->assertFalse(SiteCache::available($snapshot));
    }

    public function test_purging_becomes_available_when_the_site_registers_the_route(): void
    {
        $this->site();
        $this->fakeSite(['wp/v2', 'litespeed/v1', 'kargah/v1']);

        $this->assertTrue(SiteCache::available(SiteSnapshot::of(WordPressSite::require())));
    }

    /* The snippet ---------------------------------------------------------------- */

    public function test_the_snippet_dispatches_to_every_plugin_it_claims_to_support(): void
    {
        $snippet = SiteCache::purgeSnippet();

        // The three vendor-documented entry points.
        $this->assertStringContainsString("do_action('litespeed_purge_all')", $snippet);
        $this->assertStringContainsString('rocket_clean_domain()', $snippet);
        $this->assertStringContainsString('w3tc_flush_all()', $snippet);

        // And an honest fallback rather than a silent no-op.
        $this->assertStringContainsString('wp_cache_flush()', $snippet);
    }

    /**
     * Emptying a live site's cache under load is an operational act, not an
     * editorial one, and does not deserve the same key as writing a meta
     * description.
     */
    public function test_the_purge_route_is_locked_to_an_administrator(): void
    {
        $this->assertStringContainsString("current_user_can('manage_options')", SiteCache::purgeSnippet());
    }

    public function test_the_snippet_is_an_mu_plugin_so_a_theme_update_cannot_empty_it(): void
    {
        $this->assertStringContainsString('mu-plugins', SiteCache::purgeSnippet());
    }

    /* Purging -------------------------------------------------------------------- */

    public function test_purging_one_address_names_the_scope_and_the_url(): void
    {
        $this->site();
        $this->fakeSite(['wp/v2', 'kargah/v1']);

        SiteCache::purgeUrl(WordPressSite::require(), self::SITE.'/four-board-views/');

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'kargah/v1/cache/purge')) {
                return false;
            }

            return $request->data() === ['scope' => 'url', 'url' => self::SITE.'/four-board-views/'];
        });
    }

    public function test_purging_everything_says_so_explicitly(): void
    {
        $this->site();
        $this->fakeSite(['wp/v2', 'kargah/v1']);

        $result = SiteCache::purgeAll(WordPressSite::require());

        $this->assertTrue($result['purged']);
        $this->assertSame('LiteSpeed Cache', $result['driver']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'kargah/v1/cache/purge')
            && $request->data() === ['scope' => 'all']);
    }

    /* The page -------------------------------------------------------------------- */

    public function test_the_page_explains_and_offers_the_file_when_the_route_is_missing(): void
    {
        $this->site();
        $this->fakeSite(['wp/v2', 'litespeed/v1']);

        Livewire::actingAs($this->actor())
            ->test('site::cache')
            ->assertOk()
            ->assertSee('cannot be purged from here yet')
            ->assertSee('litespeed/v1')
            ->assertDontSee('Purge the whole cache');
    }

    /**
     * 🔴 Purging everything is never one click.
     *
     * On a busy site on shared hosting it sends every visitor to an uncached
     * page at once, which arrives as a slow few minutes and occasionally as a
     * 503. The first click asks; the second acts.
     */
    public function test_purging_everything_takes_two_clicks_and_the_first_sends_nothing(): void
    {
        $this->site();
        $this->fakeSite(['wp/v2', 'kargah/v1']);

        $component = Livewire::actingAs($this->actor())
            ->test('site::cache')
            ->assertOk()
            ->assertSee('Purge the whole cache')
            ->set('confirmingAll', true);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'cache/purge'));

        $component->call('purgeAll')->assertSet('confirmingAll', false);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'cache/purge'));
    }

    public function test_purging_an_empty_address_asks_for_one_rather_than_purging_everything(): void
    {
        $this->site();
        $this->fakeSite(['wp/v2', 'kargah/v1']);

        Livewire::actingAs($this->actor())
            ->test('site::cache')
            ->set('url', '  ')
            ->call('purgeUrl');

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'cache/purge'));
    }

    public function test_the_page_reports_a_refused_purge(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/users/me*' => Http::response(['id' => 1, 'name' => 'Nima', 'roles' => [], 'capabilities' => []]),
            self::SITE.'/wp-json/' => Http::response(['name' => 'Lavzen Web', 'namespaces' => ['wp/v2', 'kargah/v1']]),
            self::SITE.'/wp-json/kargah/v1/cache/purge' => Http::response([
                'code' => 'rest_forbidden',
                'message' => 'Sorry, you are not allowed to do that.',
            ], 403),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::cache')
            ->set('url', self::SITE.'/x/')
            ->call('purgeUrl')
            ->assertDispatched('toast')
            ->assertSet('lastResult', null);
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::cache')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
