<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SitePlugins;
use Modules\Site\Services\WordPressSite;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The website's plugins.
 *
 * Two tests here are load-bearing and neither is about the happy path.
 *
 * `test_a_plugin_path_survives_into_the_url` guards a slash. WordPress
 * identifies a plugin by its file path — `akismet/akismet` — and an unencoded
 * slash makes that two path segments, which is a 404 that looks like the plugin
 * does not exist.
 *
 * `test_switching_off_something_that_handles_logging_in_asks_first` guards the
 * connection this whole module depends on. Deactivating a security or REST-auth
 * plugin can end Kargah's own access to the site, and the confirmation says
 * what could happen rather than asking a question with no information in it.
 */
class SitePluginsTest extends TestCase
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

    private function plugin(string $path, string $name, string $status = 'inactive'): array
    {
        return [
            'plugin' => $path,
            'name' => $name,
            'status' => $status,
            'version' => '1.2.3',
            'author' => 'Somebody',
        ];
    }

    private function fakePlugins(array $plugins): void
    {
        Http::fake([
            self::SITE.'/wp-json/wp/v2/plugins/*' => Http::response(['plugin' => 'x', 'status' => 'inactive']),
            self::SITE.'/wp-json/wp/v2/plugins' => Http::response($plugins),
        ]);
    }

    /* Listing ----------------------------------------------------------------------- */

    /**
     * The endpoint neither paginates nor takes an `orderby`, so the ordering is
     * done here: active first, then by name.
     */
    public function test_active_plugins_are_listed_first_then_alphabetically(): void
    {
        $this->site();
        $this->fakePlugins([
            $this->plugin('zeta/zeta', 'Zeta'),
            $this->plugin('alpha/alpha', 'Alpha'),
            $this->plugin('rank-math/rank-math', 'Rank Math', 'active'),
        ]);

        $rows = (new SitePlugins(WordPressSite::require()))->list();

        $this->assertSame('Rank Math', $rows[0]['name']);
        $this->assertSame('Alpha', $rows[1]['name']);
        $this->assertSame('Zeta', $rows[2]['name']);
    }

    public function test_active_ones_are_counted(): void
    {
        $this->assertSame(2, SitePlugins::activeCount([
            $this->plugin('a/a', 'A', 'active'),
            $this->plugin('b/b', 'B'),
            $this->plugin('c/c', 'C', 'active'),
        ]));
    }

    /* Toggling ------------------------------------------------------------------------ */

    /**
     * 🔴 The slash in `akismet/akismet` is part of the identifier, not a path
     * separator. Unencoded it is a 404 that reads as "no such plugin".
     */
    public function test_a_plugin_path_survives_into_the_url(): void
    {
        $this->site();
        $this->fakePlugins([$this->plugin('akismet/akismet', 'Akismet')]);

        (new SitePlugins(WordPressSite::require()))->setStatus('akismet/akismet', true);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'akismet%2Fakismet')
                && $request->data() === ['status' => 'active'];
        });
    }

    public function test_deactivating_sends_inactive(): void
    {
        $this->site();
        $this->fakePlugins([$this->plugin('akismet/akismet', 'Akismet', 'active')]);

        (new SitePlugins(WordPressSite::require()))->setStatus('akismet/akismet', false);

        Http::assertSent(fn ($request): bool => $request->data() === ['status' => 'inactive']);
    }

    /* The risk check -------------------------------------------------------------------- */

    public function test_plugins_that_look_like_they_handle_access_are_flagged(): void
    {
        $this->assertTrue(SitePlugins::isRisky($this->plugin('wordfence/wordfence', 'Wordfence Security')));
        $this->assertTrue(SitePlugins::isRisky($this->plugin('limit-login-attempts/x', 'Limit Login Attempts')));
        $this->assertTrue(SitePlugins::isRisky($this->plugin('x/x', 'Disable REST API')));

        $this->assertFalse(SitePlugins::isRisky($this->plugin('rank-math/rank-math', 'Rank Math SEO')));
        $this->assertFalse(SitePlugins::isRisky($this->plugin('classic-editor/classic-editor', 'Classic Editor')));
    }

    /**
     * 🔴 Deactivating a REST-auth plugin can end the connection this module
     * runs on, so the confirmation says what could happen rather than asking a
     * content-free question.
     */
    public function test_switching_off_something_that_handles_logging_in_asks_first(): void
    {
        $this->site();
        $this->fakePlugins([$this->plugin('wordfence/wordfence', 'Wordfence Security', 'active')]);

        Livewire::actingAs($this->actor())
            ->test('site::plugins')
            ->set('confirming', 'wordfence/wordfence')
            // Asserted on a phrase with no apostrophe in it. The page says
            // "Kargah's own connection", and matching that means agreeing with
            // the template about which apostrophe character it used — a test
            // that fails when somebody runs a typographic pass over the copy
            // and nothing about the behaviour has changed.
            ->assertSee('can change how the site authenticates');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_an_ordinary_plugin_switches_off_without_a_confirmation(): void
    {
        $this->site();
        $this->fakePlugins([$this->plugin('classic-editor/classic-editor', 'Classic Editor', 'active')]);

        Livewire::actingAs($this->actor())
            ->test('site::plugins')
            ->call('toggle', 'classic-editor/classic-editor', false);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST');
    }

    /**
     * A plugin going on or off is exactly what changes the site's REST
     * namespaces, so a five-minute-old snapshot would have the SEO and cache
     * pages disagreeing with this one.
     */
    public function test_toggling_a_plugin_forgets_the_cached_snapshot(): void
    {
        $account = $this->site();
        $this->fakePlugins([$this->plugin('a/a', 'A', 'active')]);

        Cache::put('site:snapshot:'.$account->getKey(), 'stale', now()->addMinutes(5));

        Livewire::actingAs($this->actor())
            ->test('site::plugins')
            ->call('toggle', 'a/a', false);

        $this->assertNull(Cache::get('site:snapshot:'.$account->getKey()));
    }

    /* The page --------------------------------------------------------------------------- */

    public function test_the_page_draws_what_is_installed_and_how_many_are_on(): void
    {
        $this->site();
        $this->fakePlugins([
            $this->plugin('rank-math/rank-math', 'Rank Math', 'active'),
            $this->plugin('classic-editor/classic-editor', 'Classic Editor'),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::plugins')
            ->assertOk()
            ->assertSee('Rank Math')
            ->assertSee('Classic Editor')
            ->assertSee('1 of 2 active');
    }

    public function test_what_is_not_offered_is_explained(): void
    {
        $this->site();
        $this->fakePlugins([$this->plugin('a/a', 'A')]);

        Livewire::actingAs($this->actor())
            ->test('site::plugins')
            ->assertOk()
            ->assertSee('Installing')
            ->assertSee('Updating and deleting')
            ->assertSee('white-screen a site');
    }

    public function test_the_page_names_why_the_endpoint_might_be_missing(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/plugins*' => Http::response([
            'code' => 'rest_cannot_view_plugins',
            'message' => 'Sorry, you are not allowed to manage plugins for this site.',
        ], 403)]);

        Livewire::actingAs($this->actor())
            ->test('site::plugins')
            ->assertOk()
            ->assertSee('not allowed to manage plugins')
            ->assertSee('activate_plugins')
            ->assertSee('before 5.5');
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::plugins')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
