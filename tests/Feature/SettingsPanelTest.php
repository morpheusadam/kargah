<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Contracts\NotificationPreferences;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\ApplicationPasswordIssuer;
use Modules\Platform\Support\ConnectionHealth;
use Modules\Platform\Support\Scopes;
use Modules\Project\Models\Board;
use Modules\Project\Models\Label;
use Modules\Project\Support\Palette;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The six settings screens, held to the four properties that separate a
 * settings panel from a form.
 *
 * 1. **A saved setting changes something a person can see somewhere else.** The
 *    weakest possible settings test asserts the row was written; that passes
 *    just as happily for a column nothing reads. `colour_blind_mode` is the one
 *    preference on these pages whose effect is rendered by another module
 *    entirely, so it is the one worth spending a test on: toggle it here, and
 *    the class on a label chip on a board changes.
 * 2. **A refused value says what was wrong in a sentence.** `date_format:H:i`
 *    is a rule name, not an explanation. The assertions below are on the
 *    rendered words, not on the error key, because the error key was already
 *    right when the message was still unreadable.
 * 3. **A health indicator flips from real state in the database.** Every
 *    assertion here writes an expired `token_expires_at` or a populated
 *    `last_error` and then reads the page. Nothing is stubbed and there is no
 *    "is healthy" flag to set — if the state came from a boolean somebody set
 *    by hand, the indicator would be decoration.
 * 4. **A destructive button names its consequence before it runs.** Asserted on
 *    the `wire:confirm` text that actually ships in the markup.
 *
 * `Http::preventStrayRequests()` matters more here than it looks: the assistant
 * page has a Test button that really does call a provider, and a settings test
 * that quietly reached the network on somebody's laptop would be flaky for a
 * reason nobody would guess.
 */
class SettingsPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const PROFILE = 'pages::settings.profile';

    private const SECURITY = 'pages::settings.security';

    private const APPEARANCE = 'pages::settings.appearance';

    private const NOTIFICATIONS = 'pages::settings.notifications';

    private const PASSWORDS = 'platform::application-passwords';

    private const ASSISTANT = 'platform::assistant';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-03 09:30:00');
        Http::preventStrayRequests();

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour', 'email' => 'nima@kargah.test']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* Helpers ------------------------------------------------------------------- */

    /**
     * A connected account on a network with a real credential shape.
     *
     * Pinned to LinkedIn rather than the factory's random pick: the health
     * sentences differ between a network that renews its own token and one that
     * does not, and a test that drew a different network each run would be
     * asserting on whichever sentence it happened to get.
     */
    private function connectedAccount(array $attributes = []): SocialAccount
    {
        return SocialAccount::factory()
            ->onNetwork(Networks::LINKEDIN)
            ->connected()
            ->create($attributes);
    }

    private function boardWithARedLabel(): Board
    {
        $board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work', 'position' => 1]);

        Label::factory()->for($board)->create(['name' => 'Bug', 'colour' => 'red']);

        return $board;
    }

    /* 1 — a saved setting changes observable behaviour --------------------------- */

    public function test_turning_on_label_patterns_changes_what_a_board_renders_not_just_the_row(): void
    {
        $this->boardWithARedLabel();

        $pattern = Palette::pattern('red');
        $this->assertNotSame('', $pattern, 'Without a pattern class this test would pass vacuously.');

        // Before: a red label is a colour and nothing else, on the board page
        // as well as in the settings preview.
        Livewire::test('project::board-settings', ['board' => 'client-work'])
            ->assertDontSee($pattern, false);

        Livewire::test(self::APPEARANCE)
            ->assertSet('colourBlindMode', false)
            ->assertDontSee($pattern, false)
            ->call('toggleColourBlindMode')
            ->assertSet('colourBlindMode', true)
            ->assertSee($pattern, false)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        // The row was written…
        $this->assertTrue((bool) $this->user->fresh()->colour_blind_mode);

        // …and, the part that matters, a page in another module now draws the
        // label differently because of it.
        Livewire::test('project::board-settings', ['board' => 'client-work'])
            ->assertSee($pattern, false);
    }

    public function test_the_appearance_page_offers_nothing_it_cannot_actually_store(): void
    {
        // Five controls used to sit here with no column, no JavaScript and no
        // save() behind any of them. The four that were removed are still named
        // on the page, as facts with an em dash for a value, so nobody hunts for
        // a menu that never existed.
        $this->get('/settings/appearance')
            ->assertOk()
            ->assertSee('Not adjustable')
            ->assertSee('Accent colour')
            ->assertSee('Row density')
            ->assertSee(ConnectionHealth::UNKNOWN)
            // The dead controls themselves are gone: no live switch claims to
            // change spacing or motion.
            ->assertDontSee('Comfortable')
            ->assertDontSee('More rows on screen');
    }

    public function test_a_saved_time_zone_and_date_format_change_how_a_date_is_written(): void
    {
        Livewire::test(self::PROFILE)
            ->set('dateFormat', 'd M Y')
            ->assertSee('03 Aug 2026')
            ->set('dateFormat', 'Y-m-d')
            ->assertSee('2026-08-03')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Y-m-d', $this->user->fresh()->date_format);
    }

    /* 2 — a bad value is refused, and the reason names what was wrong ------------ */

    public function test_a_quiet_hours_time_that_is_not_a_time_is_refused_with_a_sentence(): void
    {
        Livewire::test(self::NOTIFICATIONS)
            ->set('quietHours', true)
            ->set('quietFrom', 'half past ten')
            ->call('save')
            ->assertHasErrors('quietFrom')
            // The rendered reason, not the error key: "must match the format
            // H:i" names a PHP format string at somebody who typed a time.
            ->assertSee('That is not a time Kargah can read. Quiet hours start at a 24-hour time like 22:00.')
            ->assertNotDispatched('toast');

        // Nothing was written, so the stored window is still the default.
        $stored = app(NotificationPreferences::class)->quietHours($this->user->id);
        $this->assertFalse($stored['enabled']);
    }

    public function test_a_digest_kargah_does_not_offer_is_refused_by_name(): void
    {
        Livewire::test(self::NOTIFICATIONS)
            ->set('digest', 'monthly')
            ->call('save')
            ->assertHasErrors('digest')
            ->assertSee('That is not one of the four delivery choices: immediately, daily, weekly, or not at all.');

        $this->assertSame('daily', app(NotificationPreferences::class)->digest($this->user->id));
    }

    public function test_an_address_that_is_not_an_email_is_refused_and_the_page_shows_a_worked_example(): void
    {
        Livewire::test(self::PROFILE)
            ->set('email', 'nima at kargah dot test')
            ->call('save')
            ->assertHasErrors('email')
            ->assertSee('That is not an email address Kargah can send to. It needs an @ and a domain, like nima@example.com.');

        $this->assertSame('nima@kargah.test', $this->user->fresh()->email);
    }

    public function test_an_expiry_in_the_past_is_refused_and_the_reason_says_what_would_happen(): void
    {
        Livewire::test(self::PASSWORDS)
            ->call('openForm')
            ->set('name', 'Backup script')
            ->set('expiresOn', '2026-07-01')
            ->call('create')
            ->assertHasErrors('expiresOn')
            ->assertSee('An expiry has to be in the future. A credential that expired yesterday would be refused the first time a script used it.');

        $this->assertDatabaseCount('application_passwords', 0);
    }

    /* 3 — health flips from real state in the database --------------------------- */

    public function test_a_healthy_account_reads_as_connected_before_anything_is_broken(): void
    {
        $this->connectedAccount(['handle' => 'in/nima-fazlipour', 'connected_at' => now()->subMonth()]);

        Livewire::test(self::NOTIFICATIONS)
            ->assertSee('Connected')
            ->assertDontSee('Token expired')
            ->assertDontSee('Last run failed');
    }

    public function test_an_expired_token_in_the_database_flips_the_connection_badge_to_unhealthy(): void
    {
        // Real state, not a flag: the only thing this test sets is the same
        // column `social:check-token-expiry` reads.
        $this->connectedAccount([
            'handle' => 'in/nima-fazlipour',
            'connected_at' => now()->subMonths(2),
            'token_expires_at' => now()->subDay(),
        ]);

        Livewire::test(self::NOTIFICATIONS)
            ->assertSee('Token expired')
            ->assertDontSee('Posts and token warnings for LinkedIn will reach you.');

        $health = ConnectionHealth::socialSummary();
        $this->assertSame('broken', $health['state']);
        $this->assertSame('Token expired', $health['accounts'][0]['headline']);
    }

    public function test_a_token_inside_the_scheduled_warning_window_reads_as_expiring_not_expired(): void
    {
        // Three days out — inside `SocialAccount::TOKEN_EXPIRY_WARNING_DAYS`,
        // which is the first threshold `CheckTokenExpiry` warns at. The page
        // must not invent its own window: if this ever disagrees with the
        // command, one of the two is lying to the owner.
        $this->connectedAccount([
            'handle' => 'in/nima-fazlipour',
            'token_expires_at' => now()->addDays(3),
        ]);

        Livewire::test(self::NOTIFICATIONS)
            ->assertSee('Token expiring')
            ->assertDontSee('Token expired');

        $this->assertSame('warning', ConnectionHealth::socialSummary()['state']);
    }

    public function test_a_recorded_last_error_flips_the_connection_badge_and_shows_the_error(): void
    {
        $this->connectedAccount([
            'handle' => 'in/nima-fazlipour',
            'last_checked_at' => now()->subHour(),
            'last_error' => 'LinkedIn refused the credential when the token was last renewed.',
        ]);

        Livewire::test(self::NOTIFICATIONS)
            ->assertSee('Last run failed')
            ->assertSee('LinkedIn refused the credential when the token was last renewed.');

        $this->assertSame('warning', ConnectionHealth::socialSummary()['state']);
    }

    public function test_an_account_with_no_credentials_reads_as_broken_rather_than_connected(): void
    {
        SocialAccount::factory()->onNetwork(Networks::LINKEDIN)->create(['handle' => 'in/nobody']);

        Livewire::test(self::NOTIFICATIONS)->assertSee('No credentials');

        $this->assertSame('broken', ConnectionHealth::socialSummary()['state']);
    }

    public function test_the_notifications_page_says_so_when_email_cannot_be_delivered(): void
    {
        // The test suite runs on the `array` mailer, so every email switch on
        // this page is honest about saving and dishonest about arriving unless
        // the page says which. This is the sentence that says it.
        config(['mail.default' => 'array']);

        Livewire::test(self::NOTIFICATIONS)
            ->assertSee('Email goes nowhere')
            ->assertSee('kept in memory and thrown away');

        config(['mail.default' => 'smtp']);

        Livewire::test(self::NOTIFICATIONS)
            ->assertSee('Email is deliverable')
            ->assertDontSee('Email goes nowhere');
    }

    public function test_an_untested_assistant_provider_is_not_reported_as_working(): void
    {
        AssistantProvider::query()->create([
            'name' => 'Gemini',
            'driver' => 'gemini',
            'api_key' => 'AIzaSyExampleKeyForTheSettingsPanelTest',
            'is_active' => true,
        ]);

        Livewire::test(self::ASSISTANT)
            ->assertSee('Untested')
            ->assertDontSee('replied the last time it was tested');

        $provider = AssistantProvider::query()->firstOrFail();

        // A recorded failure, written by `markTestResult` exactly as the Test
        // button writes it — not a hand-set flag.
        $provider->markTestResult(false, 'The provider refused the key.');

        Livewire::test(self::ASSISTANT)
            ->assertSee('Last test failed')
            ->assertSee('The provider refused the key.');
    }

    public function test_a_credential_close_to_expiry_warns_inside_the_same_window_the_social_check_uses(): void
    {
        $window = ConnectionHealth::warningWindowDays();
        $this->assertSame(SocialAccount::TOKEN_EXPIRY_WARNING_DAYS, $window);

        app(ApplicationPasswordIssuer::class)->issue(
            $this->user,
            'Backup script',
            [Scopes::CORE_READ],
            now()->addDays(2),
            $this->user,
        );

        Livewire::test(self::PASSWORDS)
            ->assertSee('Expiring')
            ->assertSee('every script still using it starts getting a 401 that day');
    }

    /* 4 — destructive actions name their consequence ----------------------------- */

    public function test_revoking_an_application_password_warns_that_live_scripts_stop_working(): void
    {
        app(ApplicationPasswordIssuer::class)->issue($this->user, 'Backup script', [Scopes::CORE_READ], null, $this->user);

        Livewire::test(self::PASSWORDS)
            ->assertSee('starts getting a 401 the moment you confirm')
            ->assertSee('The secret cannot be recovered');
    }

    public function test_removing_an_assistant_provider_warns_that_the_key_goes_with_it(): void
    {
        AssistantProvider::query()->create([
            'name' => 'Gemini',
            'driver' => 'gemini',
            'api_key' => 'AIzaSyExampleKeyForTheSettingsPanelTest',
            'is_active' => true,
        ]);

        Livewire::test(self::ASSISTANT)
            ->assertSee('Its stored API key is deleted with it and cannot be recovered');
    }

    public function test_the_security_page_names_the_consequence_of_every_irreversible_button(): void
    {
        // Two-factor on, so the destructive pair is rendered at all.
        $this->user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->get('/settings/security')
            ->assertOk()
            ->assertSee('Every code you have already written down or printed stops working', false)
            ->assertSee('your recovery codes are destroyed', false)
            ->assertSee('Every other signed-in device is signed out immediately', false);
    }

    /* The search, and the sentences ---------------------------------------------- */

    public function test_the_search_finds_a_setting_by_a_word_that_is_on_no_tab_label(): void
    {
        Livewire::test(self::PROFILE)
            // "Quiet hours" is a setting on Notifications; no tab is called
            // anything like it, which is the whole reason the box exists.
            ->set('settingsFilter', 'quiet')
            ->assertSee('Quiet hours')
            ->assertSee('Notifications')
            ->assertDontSee('Application passwords')
            ->assertDontSee('Assistant');
    }

    public function test_the_search_reaches_across_modules_for_one_word(): void
    {
        Livewire::test(self::SECURITY)
            ->set('settingsFilter', 'revoke')
            // The revoke action lives on Modules/Platform's page, three tabs
            // away from Security.
            ->assertSee('Application passwords')
            ->assertSee('Stops that credential immediately');
    }

    public function test_a_search_that_matches_nothing_says_so_and_offers_a_way_back(): void
    {
        Livewire::test(self::APPEARANCE)
            ->set('settingsFilter', 'kubernetes')
            ->assertSee('No setting matches')
            ->assertSee('Show every setting')
            ->set('settingsFilter', '')
            ->assertSee('Notifications');
    }

    public function test_every_settings_page_explains_what_its_switches_change(): void
    {
        $sentences = [
            '/settings/profile' => 'Changes the clock every due date, invoice date and "last active" time is read against.',
            '/settings/security' => 'Changing your password takes effect at once and signs every other device out',
            '/settings/appearance' => 'Puts a stripe or dot pattern on every label chip on cards and boards',
            '/settings/notifications' => 'Changes whether email arrives one message at a time, once a day, once a week, or never.',
        ];

        foreach ($sentences as $uri => $sentence) {
            $this->get($uri)->assertOk()->assertSee($sentence, false);
        }

        // The two Platform pages keep their forms closed until asked, so their
        // field-level sentences are asserted with the form open — the state a
        // person is in when the sentence matters.
        Livewire::test(self::PASSWORDS)
            ->call('openForm')
            ->assertSee('Sets the day this stops working on its own', false)
            ->assertSee('Each tick decides which part of Kargah this one credential may reach.', false);

        Livewire::test(self::ASSISTANT)
            ->call('openCreate')
            ->assertSee('Replaces the stored key, so a wrong one makes the assistant fail on the very next question.', false)
            ->assertSee("Unticking takes this provider out of the assistant's reach without deleting its stored key.", false);
    }

    /* Values the backend cannot know -------------------------------------------- */

    public function test_a_value_the_backend_cannot_know_renders_as_an_em_dash(): void
    {
        app(ApplicationPasswordIssuer::class)->issue($this->user, 'Backup script', [Scopes::CORE_READ], null, $this->user);

        // Nothing has ever authenticated with it, so there is no address it was
        // used from. Not "0.0.0.0", not "0", not "TODO".
        $this->get('/settings/application-passwords')
            ->assertOk()
            ->assertSee(ConnectionHealth::UNKNOWN);

        // The profile page has two of the same kind: no verification flow has
        // ever run, so `email_verified_at` is genuinely unknown rather than
        // false.
        $this->get('/settings/profile')
            ->assertOk()
            ->assertSee('Email confirmed')
            ->assertSee(ConnectionHealth::UNKNOWN);
    }

    public function test_an_account_that_has_never_been_checked_prints_a_dash_rather_than_a_date(): void
    {
        $account = $this->connectedAccount(['handle' => 'in/nima-fazlipour']);

        $health = ConnectionHealth::forSocialAccount($account);

        $this->assertSame(ConnectionHealth::UNKNOWN, $health['checked']);
        $this->assertSame(ConnectionHealth::UNKNOWN, $health['expires']);
    }

    /* Credentials never reach the payload ---------------------------------------- */

    public function test_no_settings_page_puts_a_decrypting_model_into_its_payload(): void
    {
        $this->connectedAccount(['handle' => 'in/nima-fazlipour']);

        // `credentials` decrypts on read, so a model in `with()` would print
        // the token into the page source. Everything the notifications page is
        // handed about an account is a scalar chosen by `ConnectionHealth`.
        $accounts = Livewire::test(self::NOTIFICATIONS)->viewData('social')['accounts'];

        foreach ($accounts as $account) {
            foreach ($account as $value) {
                $this->assertTrue(
                    $value === null || is_scalar($value),
                    'Only scalars may cross into the view: an Eloquent model here would serialise a decrypted credential.',
                );
            }
        }

        $this->get('/settings/notifications')
            ->assertOk()
            ->assertDontSee('test-credential');
    }
}
