<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteSettings;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The website's own settings.
 *
 * Three things are being guarded and only one is ordinary.
 *
 * The ordinary one is the diff: `POST /wp/v2/settings` with one key changes one
 * setting, and sending the whole bag back would rewrite settings this panel
 * does not draw with whatever the read returned — which on a site whose plugins
 * registered their own settings undoes somebody's configuration silently.
 *
 * The second is the integer cast. A number input hands back a string and
 * WordPress registered `posts_per_page` as an integer, so `"10"` is a 400 that
 * reads like a permissions problem.
 *
 * The third is the admin email, which is the interesting one:
 * `test_a_setting_the_site_has_not_applied_is_reported_rather_than_claimed`.
 * Changing it is a completely successful save whose value does not change,
 * because WordPress emails the new address and keeps the old one until somebody
 * clicks the link. Claiming success there would be a lie, and reporting failure
 * would be a different lie.
 */
class SiteSettingsTest extends TestCase
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

    private function settings(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Bineret Web',
            'description' => 'AI marketplace',
            'email' => 'nima@bineret.com',
            'timezone' => 'Europe/Istanbul',
            'date_format' => 'F j, Y',
            'time_format' => 'H:i',
            'posts_per_page' => 10,
            'default_comment_status' => 'open',
            'language' => 'en_US',
            // A setting this panel does not draw, present to prove it survives.
            'start_of_week' => 1,
        ], $overrides);
    }

    /**
     * The settings endpoint, answering differently on each call.
     *
     * 🔴 A plain `Http::fake` keyed by URL cannot express this, and getting it
     * wrong is silent. Reading and writing settings are the *same* URL — a GET
     * and a POST to `/wp/v2/settings` — so a stub that returns the post-save
     * body also answers the initial read, the form loads already holding the
     * new value, the diff is empty and no write is ever sent. The test then
     * fails with "an expected request was not recorded", which reads like a
     * bug in the component and is not one. Two of these were written that way
     * first.
     *
     * A sequence says what each call answers, in order: the read, the write,
     * and the read the component does afterwards to show what the site now has.
     */
    private function fakeSettingsSequence(array ...$responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->push(...$response);
        }

        Http::fake([self::SITE.'/wp-json/wp/v2/settings*' => $sequence]);
    }

    /* Reading --------------------------------------------------------------------- */

    public function test_the_page_loads_what_the_site_has(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/settings*' => Http::response($this->settings())]);

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->assertOk()
            ->assertSet('loaded', true)
            ->assertSet('values.title', 'Bineret Web')
            ->assertSet('values.posts_per_page', '10');
    }

    /**
     * Reading settings needs `manage_options`. An editor's application password
     * can write posts and cannot see this page, and saying which is the
     * difference between a fixable problem and a mysterious one.
     */
    public function test_a_credential_without_manage_options_is_told_what_is_missing(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/settings*' => Http::response([
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that.',
        ], 403)]);

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->assertOk()
            ->assertSet('loaded', false)
            ->assertSee('did not return its settings')
            ->assertSee('manage_options');
    }

    /* Writing ---------------------------------------------------------------------- */

    /**
     * 🔴 Only the changed key is sent. Anything else this panel does not draw
     * would otherwise be rewritten with whatever the read returned.
     */
    public function test_a_save_sends_only_what_changed(): void
    {
        $this->site();

        $this->fakeSettingsSequence(
            [$this->settings()],
            [$this->settings(['title' => 'Bineret'])],
            [$this->settings(['title' => 'Bineret'])],
        );

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->set('values.title', 'Bineret')
            ->call('save');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST' && $request->data() === ['title' => 'Bineret'];
        });
    }

    /**
     * A number input hands back a string; WordPress registered this one as an
     * integer and answers 400 for `"25"`.
     */
    public function test_a_numeric_setting_is_sent_as_an_integer(): void
    {
        $this->site();

        $this->fakeSettingsSequence(
            [$this->settings()],
            [$this->settings(['posts_per_page' => 25])],
            [$this->settings(['posts_per_page' => 25])],
        );

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->set('values.posts_per_page', '25')
            ->call('save');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST' && $request->data() === ['posts_per_page' => 25];
        });
    }

    public function test_a_save_with_nothing_changed_sends_nothing(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/settings*' => Http::response($this->settings())]);

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->call('save');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    /**
     * 🔴 A completely successful save whose value does not change.
     *
     * WordPress emails the new admin address and keeps the old one until the
     * link is clicked. Claiming success is a lie; reporting failure is a
     * different one.
     */
    public function test_a_setting_the_site_has_not_applied_is_reported_rather_than_claimed(): void
    {
        $this->site();

        // Read, then a write the site accepts, then a read that still shows the
        // old address — which is what WordPress genuinely does here.
        $this->fakeSettingsSequence(
            [$this->settings()],
            [$this->settings()],
            [$this->settings()],
        );

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->set('values.email', 'new@bineret.com')
            ->call('save')
            ->assertSet('pending', ['email'])
            ->assertDispatched('toast');
    }

    /**
     * The comparison is by string, because `posts_per_page` goes out as an int
     * and comes back as an int while `title` is a string both ways — a strict
     * comparison across the two would report every numeric setting as pending
     * on a site that stored it perfectly.
     */
    public function test_a_numeric_setting_the_site_did_store_is_not_reported_as_pending(): void
    {
        $pending = SiteSettings::notApplied(
            ['posts_per_page' => 25],
            ['posts_per_page' => 25],
        );

        $this->assertSame([], $pending);
    }

    public function test_a_refused_save_says_what_the_site_said(): void
    {
        $this->site();

        $this->fakeSettingsSequence(
            [$this->settings()],
            [['code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): timezone.'], 400],
        );

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->set('values.timezone', 'Nowhere/Nothing')
            ->call('save')
            ->assertDispatched('toast');
    }

    /* What is refused ------------------------------------------------------------------ */

    /**
     * Both are things somebody looking for "site settings" expects, and finding
     * neither with no explanation reads as an unfinished panel. Saying why is
     * shorter than the feature.
     */
    public function test_the_two_dangerous_settings_are_named_as_refused_rather_than_silently_absent(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/settings*' => Http::response($this->settings())]);

        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->assertOk()
            ->assertSee('The front page')
            ->assertSee('blanks the front of the site')
            ->assertSee('Permalink structure')
            ->assertSee('invalidates every URL');
    }

    public function test_permalink_structure_is_not_an_editable_field(): void
    {
        $this->assertArrayNotHasKey('permalink_structure', SiteSettings::fields());
        $this->assertArrayNotHasKey('page_on_front', SiteSettings::fields());
        $this->assertArrayNotHasKey('show_on_front', SiteSettings::fields());
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::settings')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
