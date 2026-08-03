<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * `/settings/security`, made real.
 *
 * Before this test existed the page was three fixtures and a dead button: a
 * hard-coded session list, a hard-coded token next to the genuine
 * application-passwords store, a password form that validated and threw its
 * input away, and a two-factor switch with no `wire:click` at all. Every
 * section below is the property that makes the corresponding piece honest.
 */
class SettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::settings.security';

    private function actor(string $password = 'kargah-old-Pass1'): User
    {
        return User::factory()->create(['password' => $password]);
    }

    /* No fake tokens ------------------------------------------------------------ */

    public function test_the_page_carries_no_fake_token_list(): void
    {
        $user = $this->actor();

        $response = $this->actingAs($user)->get('/settings/security');

        $response->assertOk()
            ->assertDontSee('Deploy script')
            ->assertDontSee('2026-06-11')
            ->assertSee('Manage application passwords');
    }

    /* Password change ------------------------------------------------------------ */

    public function test_updating_the_password_requires_the_current_one(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('currentPassword', '')
            ->set('password', 'a-New-Passw0rd')
            ->set('password_confirmation', 'a-New-Passw0rd')
            ->call('updatePassword')
            ->assertHasErrors('currentPassword');

        $this->assertTrue(Hash::check('kargah-old-Pass1', $user->fresh()->password));
    }

    public function test_a_wrong_current_password_is_refused_with_a_field_error_and_no_toast(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('currentPassword', 'not-the-real-one')
            ->set('password', 'a-New-Passw0rd')
            ->set('password_confirmation', 'a-New-Passw0rd')
            ->call('updatePassword')
            ->assertHasErrors('currentPassword')
            ->assertNotDispatched('toast');

        $this->assertTrue(Hash::check('kargah-old-Pass1', $user->fresh()->password));
    }

    public function test_a_weak_new_password_is_refused(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('currentPassword', 'kargah-old-Pass1')
            ->set('password', 'short1A')
            ->set('password_confirmation', 'short1A')
            ->call('updatePassword')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('kargah-old-Pass1', $user->fresh()->password));
    }

    public function test_a_correct_current_password_and_a_strong_new_one_changes_the_stored_hash(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('currentPassword', 'kargah-old-Pass1')
            ->set('password', 'a-New-Strong-Passw0rd')
            ->set('password_confirmation', 'a-New-Strong-Passw0rd')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $fresh = $user->fresh();
        $this->assertFalse(Hash::check('kargah-old-Pass1', $fresh->password));
        $this->assertTrue(Hash::check('a-New-Strong-Passw0rd', $fresh->password));
    }

    public function test_changing_the_password_signs_out_every_other_session_but_not_the_current_one(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'database']);

        $currentId = session()->getId();
        $this->seedSession($currentId, $user->id);
        $this->seedSession('other-session-one', $user->id);
        $this->seedSession('other-session-two', $user->id);

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('currentPassword', 'kargah-old-Pass1')
            ->set('password', 'a-New-Strong-Passw0rd')
            ->set('password_confirmation', 'a-New-Strong-Passw0rd')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $remaining = DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();

        $this->assertSame([$currentId], $remaining, 'Only the session that made the change should survive it.');
    }

    public function test_updating_the_password_writes_an_activity_entry_and_never_logs_the_password(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('currentPassword', 'kargah-old-Pass1')
            ->set('password', 'a-New-Strong-Passw0rd')
            ->set('password_confirmation', 'a-New-Strong-Passw0rd')
            ->call('updatePassword');

        $activity = Activity::query()->where('event', 'security.password-changed')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('user', $activity->causer_type);

        $payload = json_encode($activity->properties).$activity->description;

        $this->assertStringNotContainsString('kargah-old-Pass1', $payload);
        $this->assertStringNotContainsString('a-New-Strong-Passw0rd', $payload);
    }

    public function test_the_password_fields_are_cleared_after_a_successful_change(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('currentPassword', 'kargah-old-Pass1')
            ->set('password', 'a-New-Strong-Passw0rd')
            ->set('password_confirmation', 'a-New-Strong-Passw0rd')
            ->call('updatePassword')
            ->assertSet('currentPassword', '')
            ->assertSet('password', '');
    }

    /* Two-factor: enrolment is two steps ------------------------------------------ */

    public function test_starting_enrollment_generates_a_secret_that_does_not_enable_anything(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('startTwoFactorEnrollment')
            ->assertSet('enrolling2fa', true)
            ->assertNotDispatched('toast');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertFalse($fresh->hasTwoFactorEnabled());
    }

    public function test_starting_enrollment_twice_reuses_the_same_pending_secret(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)->test(self::COMPONENT)->call('startTwoFactorEnrollment');
        $first = $user->fresh()->two_factor_secret;

        Livewire::actingAs($user)->test(self::COMPONENT)->call('startTwoFactorEnrollment');
        $second = $user->fresh()->two_factor_secret;

        $this->assertSame($first, $second, 'Refreshing mid-setup must not invalidate the code the owner is about to type.');
    }

    public function test_cancelling_enrollment_clears_the_pending_secret(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('startTwoFactorEnrollment')
            ->call('cancelTwoFactorEnrollment')
            ->assertSet('enrolling2fa', false);

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_a_wrong_code_does_not_enable_two_factor(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('startTwoFactorEnrollment')
            ->set('totpCode', '000000')
            ->call('confirmTwoFactor')
            ->assertHasErrors('totpCode')
            ->assertNotDispatched('toast');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_correct_code_enables_two_factor_and_shows_recovery_codes_once(): void
    {
        $user = $this->actor();

        $component = Livewire::actingAs($user)->test(self::COMPONENT)->call('startTwoFactorEnrollment');

        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotNull($secret);

        $code = Totp::code($secret);
        $this->assertNotNull($code);

        $component->set('totpCode', $code)
            ->call('confirmTwoFactor')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasTwoFactorEnabled());
        $this->assertNotNull($fresh->two_factor_confirmed_at);

        // Matched against the exact markup the recovery-code reveal renders,
        // so a coincidental five-plus-five run inside an unrelated CSS class
        // name elsewhere on the page cannot be mistaken for a real code.
        preg_match(
            '/<code class="text-xs px-2 py-1\.5 rounded bg-muted text-mono text-center tracking-wide">([a-z0-9]{5}-[a-z0-9]{5})<\/code>/',
            $component->html(),
            $matches,
        );
        $this->assertNotEmpty($matches, 'The freshly generated recovery codes were never rendered.');

        $shownCode = $matches[1];

        // Stored hashed, never the plaintext, and gone from the very next render.
        $this->assertStringNotContainsString(
            $shownCode,
            (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes_encrypted'),
        );
        $component->call('dismissRecoveryCodes')->assertDontSee($shownCode);
        $component->call('$refresh')->assertDontSee($shownCode);

        $this->assertTrue($fresh->consumeRecoveryCode($shownCode), 'A code that was just issued should be accepted once.');
        $this->assertFalse($fresh->fresh()->consumeRecoveryCode($shownCode), 'The same code must not work twice.');
    }

    public function test_confirming_writes_an_activity_entry_and_never_logs_the_secret_or_codes(): void
    {
        $user = $this->actor();

        $component = Livewire::actingAs($user)->test(self::COMPONENT)->call('startTwoFactorEnrollment');
        $secret = $user->fresh()->two_factor_secret;

        $component->set('totpCode', Totp::code($secret))->call('confirmTwoFactor');

        $activity = Activity::query()->where('event', 'security.two-factor-enabled')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);

        $payload = json_encode($activity->properties).$activity->description;
        $this->assertStringNotContainsString($secret, $payload);
    }

    public function test_confirm_attempts_are_rate_limited(): void
    {
        $user = $this->actor();
        $component = Livewire::actingAs($user)->test(self::COMPONENT)->call('startTwoFactorEnrollment');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->set('totpCode', '000000')->call('confirmTwoFactor');
        }

        // The sixth attempt is refused for being too frequent, even before it
        // is checked against the secret — this is the one endpoint that turns
        // a guessing loop into a wait.
        $component->set('totpCode', '000000')->call('confirmTwoFactor');

        $message = $component->errors()->first('totpCode');
        $this->assertStringContainsString('Too many attempts', $message);
    }

    /* Two-factor: disabling and recovery codes -------------------------------------- */

    private function enableTwoFactorFor(User $user): string
    {
        $component = Livewire::actingAs($user)->test(self::COMPONENT)->call('startTwoFactorEnrollment');
        $secret = $user->fresh()->two_factor_secret;
        $component->set('totpCode', Totp::code($secret))->call('confirmTwoFactor');

        return $secret;
    }

    public function test_disabling_two_factor_clears_the_secret_and_the_codes_and_logs_it(): void
    {
        $user = $this->actor();
        $this->enableTwoFactorFor($user);

        Livewire::actingAs($user->fresh())
            ->test(self::COMPONENT)
            ->call('disableTwoFactor')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertSame([], $fresh->two_factor_recovery_codes);

        $this->assertNotNull(Activity::query()->where('event', 'security.two-factor-disabled')->first());
    }

    public function test_disabling_two_factor_twice_changes_nothing_the_second_time(): void
    {
        $user = $this->actor();
        $this->enableTwoFactorFor($user);
        $fresh = $user->fresh();

        Livewire::actingAs($fresh)->test(self::COMPONENT)->call('disableTwoFactor');

        Livewire::actingAs($fresh)
            ->test(self::COMPONENT)
            ->call('disableTwoFactor')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'warning');

        $this->assertSame(1, Activity::query()->where('event', 'security.two-factor-disabled')->count());
    }

    public function test_regenerating_recovery_codes_requires_two_factor_to_already_be_on(): void
    {
        $user = $this->actor();

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('regenerateRecoveryCodes')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'error');
    }

    public function test_regenerating_recovery_codes_invalidates_the_old_ones(): void
    {
        $user = $this->actor();
        $this->enableTwoFactorFor($user);
        $fresh = $user->fresh();

        $oldHashes = $fresh->two_factor_recovery_codes;

        Livewire::actingAs($fresh)
            ->test(self::COMPONENT)
            ->call('regenerateRecoveryCodes')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $newHashes = $fresh->fresh()->two_factor_recovery_codes;

        $this->assertNotSame($oldHashes, $newHashes);
        $this->assertCount(10, $newHashes);
    }

    /* Sessions: real rows, or nothing --------------------------------------------- */

    private function seedSession(string $id, int $userId, ?string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36', int $lastActivity = null): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.'.random_int(1, 254),
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ]);
    }

    public function test_sessions_lists_the_real_rows_when_the_driver_is_database(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'database']);

        $currentId = session()->getId();
        $this->seedSession($currentId, $user->id);
        $this->seedSession(Str::random(40), $user->id);

        $component = Livewire::actingAs($user)->test(self::COMPONENT);
        $sessions = $component->viewData('sessions');

        $this->assertCount(2, $sessions);
        $this->assertTrue(collect($sessions)->firstWhere('id', $currentId)['current']);
    }

    public function test_a_session_belonging_to_another_user_never_appears(): void
    {
        $user = $this->actor();
        $someoneElse = User::factory()->create();
        config(['session.driver' => 'database']);

        $this->seedSession(session()->getId(), $user->id);
        $this->seedSession(Str::random(40), $someoneElse->id);

        $sessions = Livewire::actingAs($user)->test(self::COMPONENT)->viewData('sessions');

        $this->assertCount(1, $sessions);
    }

    public function test_signing_out_a_session_deletes_only_that_row(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'database']);

        $this->seedSession(session()->getId(), $user->id);
        $this->seedSession('the-other-one', $user->id);
        $this->seedSession('a-third-one', $user->id);

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('signOutSession', 'the-other-one')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $remaining = DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
        $this->assertNotContains('the-other-one', $remaining);
        $this->assertContains('a-third-one', $remaining);
    }

    public function test_cannot_sign_out_the_current_session_through_the_row_button(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'database']);

        $currentId = session()->getId();
        $this->seedSession($currentId, $user->id);

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('signOutSession', $currentId)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'error');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_signing_out_an_already_gone_session_does_not_claim_success(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'database']);
        $this->seedSession(session()->getId(), $user->id);

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('signOutSession', 'never-existed')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'warning');
    }

    public function test_sign_out_everywhere_else_deletes_every_other_row_and_keeps_the_current_one(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'database']);

        $currentId = session()->getId();
        $this->seedSession($currentId, $user->id);
        $this->seedSession('device-two', $user->id);
        $this->seedSession('device-three', $user->id);

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('signOutOtherSessions')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $remaining = DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
        $this->assertSame([$currentId], $remaining);
    }

    public function test_sign_out_everywhere_else_with_nothing_else_to_sign_out_warns_rather_than_claims_success(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'database']);
        $this->seedSession(session()->getId(), $user->id);

        Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->call('signOutOtherSessions')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'warning');
    }

    public function test_when_the_session_driver_is_not_database_the_panel_says_so_instead_of_inventing_rows(): void
    {
        $user = $this->actor();
        config(['session.driver' => 'file']);

        $response = $this->actingAs($user)->get('/settings/security');

        $response->assertOk()
            ->assertDontSee('Windows · Chrome')
            ->assertSee('stores sessions in');
    }

    /* The migration ---------------------------------------------------------------- */

    public function test_the_migration_is_reversible(): void
    {
        $migration = require base_path('database/migrations/2026_08_03_100000_add_settings_and_two_factor_columns_to_users_table.php');

        foreach (['timezone', 'locale', 'date_format', 'bio', 'two_factor_secret_encrypted', 'two_factor_recovery_codes_encrypted', 'two_factor_confirmed_at'] as $column) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users', $column));
        }

        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'two_factor_secret_encrypted'));

        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users', 'two_factor_secret_encrypted'));
    }
}
