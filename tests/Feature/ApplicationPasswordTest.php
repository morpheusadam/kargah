<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Platform\Models\ApplicationPassword;
use Modules\Platform\Services\ApplicationPasswordIssuer;
use Modules\Platform\Support\Scopes;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Application passwords, from `project-guaid/spec/07-platform.md`.
 *
 * The properties this file exists to hold, in the order the spec puts them:
 *
 * 1. The secret is shown once and stored hashed. If it can be read back out of
 *    the database it is not a credential, it is a password lying in a table.
 * 2. It is named and individually revocable — revoking one touches neither the
 *    others nor the owner's own password.
 * 3. It carries scopes, and an endpoint refuses a credential without the one it
 *    asks for.
 * 4. Last used at, last used IP, and an activity entry on creation and on
 *    revocation. A credential nobody can audit is a credential nobody can trust.
 * 5. It never appears in a rendered page after the one-time reveal.
 */
class ApplicationPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY = 'KARGAH-CANARY-APPLICATION-PASSWORD-93f1ac';

    private function issuer(): ApplicationPasswordIssuer
    {
        return app(ApplicationPasswordIssuer::class);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{credential: ApplicationPassword, secret: string}
     */
    private function issue(User $user, string $name = 'Laptop CLI', array $scopes = [Scopes::CORE_READ]): array
    {
        return $this->issuer()->issue($user, $name, $scopes, null, $user);
    }

    /** `curl -u email:secret` — the interface the whole feature exists to provide. */
    private function basic(string $email, string $secret): array
    {
        return ['Authorization' => 'Basic '.base64_encode($email.':'.$secret)];
    }

    /* 1 — the secret is shown once and stored hashed ------------------------- */

    public function test_issuing_returns_a_plaintext_and_stores_only_a_hash(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential, 'secret' => $secret] = $this->issue($user);

        $this->assertNotSame('', $secret);
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9]{6}-[a-z0-9]{6}-[a-z0-9]{6}-[a-z0-9]{6}$/',
            $secret,
            'The secret must be four groups of six lower-case alphanumerics, so a person can retype it and a shell can quote it.',
        );

        $row = (array) DB::table('application_passwords')->where('id', $credential->id)->first();

        foreach ($row as $column => $value) {
            $this->assertNotSame($secret, $value, "The plaintext is sitting in `$column`.");
            $this->assertStringNotContainsString(
                $secret,
                (string) $value,
                "The plaintext is embedded in `$column`.",
            );
        }

        // The prefix identifies the row on the page. It is a sixth of the
        // secret, which is why it is stored and the rest is not.
        $this->assertSame(substr($secret, 0, 6), $credential->prefix);
        $this->assertNotSame($secret, $credential->getRawOriginal('token_hash'));
    }

    public function test_the_hash_accepts_the_issued_secret_and_rejects_a_wrong_one(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential, 'secret' => $secret] = $this->issue($user);

        $hash = (string) $credential->getRawOriginal('token_hash');

        $this->assertTrue(Hash::check($secret, $hash));
        $this->assertFalse(Hash::check($secret.'x', $hash));
        $this->assertFalse(Hash::check('k7m2xq-4bnv8t-zr93wd-6ehjs1', $hash));
    }

    public function test_two_credentials_never_share_a_secret(): void
    {
        $user = User::factory()->create();

        $secrets = [];

        for ($i = 0; $i < 25; $i++) {
            $secrets[] = $this->issue($user, 'Credential '.$i)['secret'];
        }

        $this->assertCount(25, array_unique($secrets));
    }

    /* 5 — it never appears in a rendered page after the one-time reveal ------ */

    public function test_the_page_shows_the_secret_once_and_never_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test('platform::application-passwords')
            ->call('openForm')
            ->set('name', 'Laptop CLI')
            ->set('selectedScopes', [Scopes::CORE_READ, Scopes::PROJECT_READ])
            ->call('create');

        preg_match('/[a-z0-9]{6}-[a-z0-9]{6}-[a-z0-9]{6}-[a-z0-9]{6}/', $component->html(), $matches);

        $this->assertNotEmpty($matches, 'The freshly issued secret was never rendered, so the owner has no way to get it.');

        $secret = $matches[0];

        // Any subsequent round trip. The secret is a protected property, so it
        // was never serialised into the component state and there is nothing
        // for the next render to bring back.
        $component->call('dismissSecret')->assertDontSee($secret);
        $component->call('$refresh')->assertDontSee($secret);

        // And a fresh page load, which is the case a Livewire property would
        // survive if somebody made it public.
        $this->get('/settings/application-passwords')->assertOk()->assertDontSee($secret);

        // The credential itself is real, and still authenticates.
        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertOk();
    }

    public function test_the_stored_hash_never_reaches_the_settings_page(): void
    {
        $user = User::factory()->create();
        $this->issue($user, 'Backup script');

        // Planted raw, bypassing every cast and accessor, so the page is tested
        // against whatever it would see if it read the column directly.
        DB::table('application_passwords')->update(['token_hash' => self::CANARY]);

        $this->actingAs($user)
            ->get('/settings/application-passwords')
            ->assertOk()
            ->assertSee('Backup script')
            ->assertDontSee(self::CANARY);
    }

    /* Authentication -------------------------------------------------------- */

    public function test_basic_auth_with_a_valid_credential_reaches_whoami(): void
    {
        $user = User::factory()->create(['name' => 'Nima Fazlipour']);

        ['secret' => $secret] = $this->issue($user, 'Laptop CLI', [Scopes::CORE_READ, Scopes::PROJECT_READ]);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertOk()
            ->assertJsonPath('token.name', 'Laptop CLI')
            ->assertJsonPath('user.email', $user->email)
            ->assertJson(['scopes' => [Scopes::CORE_READ, Scopes::PROJECT_READ]]);
    }

    public function test_whoami_is_unreachable_without_a_credential(): void
    {
        $response = $this->getJson('/api/v1/whoami');

        $response->assertStatus(401);
        $this->assertSame('Basic realm="Kargah"', $response->headers->get('WWW-Authenticate'));
    }

    public function test_a_session_is_not_enough(): void
    {
        // The one endpoint in Kargah that a signed-in browser cannot reach by
        // being signed in. An application password is the whole authentication.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/whoami')
            ->assertStatus(401);
    }

    public function test_a_revoked_credential_is_refused(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential, 'secret' => $secret] = $this->issue($user);

        $this->issuer()->revoke($credential, $user);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertStatus(401);
    }

    public function test_an_expired_credential_is_refused(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential, 'secret' => $secret] = $this->issue($user);

        $credential->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertStatus(401);
    }

    public function test_the_account_password_is_not_an_application_password(): void
    {
        $user = User::factory()->create(['password' => 'kargah1234']);

        // It signs the owner into the browser, and it opens nothing here. That
        // separation is the entire reason this feature exists.
        $this->assertTrue(Hash::check('kargah1234', $user->fresh()->password));

        $this->withHeaders($this->basic($user->email, 'kargah1234'))
            ->getJson('/api/v1/whoami')
            ->assertStatus(401);
    }

    public function test_one_owner_cannot_use_anothers_credential(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        ['secret' => $secret] = $this->issue($owner);

        $this->withHeaders($this->basic($other->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertStatus(401);
    }

    public function test_the_authenticator_never_looks_a_row_up_by_its_hash(): void
    {
        $user = User::factory()->create();

        ['secret' => $secret] = $this->issue($user);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertOk();

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString(
                'token_hash',
                $sql,
                "A hash is not a lookup key. This query asks the database to find a row by it:\n".$sql,
            );
        }

        $this->assertNotEmpty($queries, 'No queries ran, so this test is not watching what it thinks it is.');
    }

    /* 3 — scopes ------------------------------------------------------------ */

    public function test_a_credential_without_the_required_scope_is_refused(): void
    {
        $user = User::factory()->create();

        ['secret' => $secret] = $this->issue($user, 'Board reader', [Scopes::PROJECT_READ]);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertStatus(403)
            ->assertJsonPath('required.0', Scopes::CORE_READ)
            ->assertJsonPath('granted.0', Scopes::PROJECT_READ);
    }

    public function test_a_credential_with_the_required_scope_is_allowed(): void
    {
        $user = User::factory()->create();

        ['secret' => $secret] = $this->issue($user, 'Board reader', [Scopes::PROJECT_READ, Scopes::CORE_READ]);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertOk();
    }

    public function test_scopes_are_stored_canonically_and_junk_is_dropped(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential] = $this->issuer()->issue(
            $user,
            'Mixed bag',
            [Scopes::SOCIAL_WRITE, 'nonsense:everything', Scopes::CORE_READ, Scopes::CORE_READ],
            null,
            $user,
        );

        // Canonical order, no duplicates, nothing invented. Two credentials
        // carrying the same powers must store the same JSON, or every diff of
        // this table is noise.
        $this->assertSame([Scopes::CORE_READ, Scopes::SOCIAL_WRITE], $credential->scopes);
        $this->assertFalse($credential->hasScope('nonsense:everything'));
    }

    public function test_revealing_the_vault_is_a_scope_of_its_own(): void
    {
        // Listing the vault and decrypting an entry are different powers, and
        // the list must never imply the other.
        $this->assertContains(Scopes::DATA_READ, Scopes::all());
        $this->assertContains(Scopes::DATA_REVEAL, Scopes::all());
        $this->assertNotSame(Scopes::DATA_READ, Scopes::DATA_REVEAL);
    }

    /* 4 — last used, last IP, and the audit trail --------------------------- */

    public function test_last_used_at_and_last_used_ip_are_recorded(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential, 'secret' => $secret] = $this->issue($user);

        $this->assertNull($credential->last_used_at);
        $this->assertNull($credential->last_used_ip);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/whoami')
            ->assertOk();

        $credential->refresh();

        $this->assertNotNull($credential->last_used_at);
        $this->assertNotNull($credential->last_used_ip);
        $this->assertSame('127.0.0.1', $credential->last_used_ip);

        // Using a credential is not changing it: `updated_at` answers "when was
        // this last edited" and must not march forward on every request.
        $this->assertTrue($credential->updated_at->lessThan($credential->last_used_at->addSecond()));
        $this->assertSame(
            $credential->created_at->toDateTimeString(),
            $credential->updated_at->toDateTimeString(),
        );
    }

    public function test_a_failed_attempt_is_not_silently_ignored(): void
    {
        $user = User::factory()->create();

        Log::spy();

        $this->withHeaders($this->basic($user->email, 'aaaaaa-bbbbbb-cccccc-dddddd'))
            ->getJson('/api/v1/whoami')
            ->assertStatus(401);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($user): bool {
                return str_contains($message, 'Application password authentication failed')
                    && $context['email'] === $user->email
                    && $context['ip'] === '127.0.0.1'
                    // The presented secret is never in the record. A failed
                    // attempt is very often somebody's real password typed into
                    // the wrong box, and a log file keeps it for ever.
                    && ! str_contains(json_encode($context), 'aaaaaa-bbbbbb');
            })
            ->once();
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $headers = $this->basic($user->email, 'aaaaaa-bbbbbb-cccccc-dddddd');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withHeaders($headers)->getJson('/api/v1/whoami')->assertStatus(401);
        }

        // This endpoint is the only thing in Kargah reachable without a
        // session, so a guessing loop has to become a wait rather than a
        // throughput question.
        $this->withHeaders($headers)->getJson('/api/v1/whoami')->assertStatus(429);
    }

    public function test_creation_writes_an_activity_entry_naming_who_and_when(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential, 'secret' => $secret] = $this->issue($user, 'Deploy script');

        $activity = Activity::query()->where('event', 'application-password.created')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('application-password', $activity->log_name);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('user', $activity->causer_type);
        $this->assertSame('application_password', $activity->subject_type);
        $this->assertSame($credential->id, $activity->subject_id);
        $this->assertNotNull($activity->created_at);
        $this->assertStringContainsString('Deploy script', $activity->description);

        // The log is append-only, so a secret written into it could never be
        // withdrawn. It records the prefix and the scopes, and nothing else.
        $this->assertStringNotContainsString($secret, json_encode($activity->properties));
        $this->assertSame($credential->prefix, $activity->properties['prefix']);
    }

    public function test_revocation_writes_an_activity_entry_naming_who_and_when(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential] = $this->issue($user, 'Deploy script');

        $this->assertTrue($this->issuer()->revoke($credential, $user));

        $activity = Activity::query()->where('event', 'application-password.revoked')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($credential->id, $activity->subject_id);
        $this->assertNotNull($activity->created_at);
        $this->assertStringContainsString('Deploy script', $activity->description);
    }

    public function test_revoking_one_credential_leaves_the_others_alone(): void
    {
        $user = User::factory()->create();

        ['credential' => $laptop, 'secret' => $laptopSecret] = $this->issue($user, 'Laptop CLI');
        ['secret' => $assistantSecret] = $this->issue($user, 'The assistant');

        $this->issuer()->revoke($laptop, $user);

        $this->withHeaders($this->basic($user->email, $laptopSecret))
            ->getJson('/api/v1/whoami')
            ->assertStatus(401);

        $this->withHeaders($this->basic($user->email, $assistantSecret))
            ->getJson('/api/v1/whoami')
            ->assertOk();

        // And the owner's own password is untouched by any of it.
        $this->assertTrue($this->app['auth']->attempt(['email' => $user->email, 'password' => 'password']));
    }

    /* The "runs twice, changes nothing" rule --------------------------------- */

    public function test_revoking_an_already_revoked_credential_changes_nothing(): void
    {
        $user = User::factory()->create();

        ['credential' => $credential] = $this->issue($user, 'Laptop CLI');

        $this->assertTrue($this->issuer()->revoke($credential, $user));

        $revokedAt = $credential->fresh()->revoked_at;
        $this->assertNotNull($revokedAt);

        $this->travel(5)->minutes();

        // Second run. Same rule as every job in this project: it must not move
        // the timestamp and must not write a second line into an append-only
        // table.
        $this->assertFalse($this->issuer()->revoke($credential->fresh(), $user));

        $this->assertSame(
            $revokedAt->toDateTimeString(),
            $credential->fresh()->revoked_at->toDateTimeString(),
        );

        $this->assertSame(1, Activity::query()->where('event', 'application-password.revoked')->count());
    }

    public function test_the_page_does_not_claim_success_when_revoking_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        ['credential' => $credential] = $this->issue($user, 'Laptop CLI');
        $this->issuer()->revoke($credential, $user);

        Livewire::test('platform::application-passwords')
            ->call('revoke', $credential->id)
            ->assertDispatched('toast', function (string $event, array $payload): bool {
                // Never a success toast on a method that does nothing.
                return $payload[0]['type'] === 'warning';
            });

        $this->assertSame(1, Activity::query()->where('event', 'application-password.revoked')->count());
    }

    public function test_the_page_revokes_and_says_so(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        ['credential' => $credential] = $this->issue($user, 'Laptop CLI');

        Livewire::test('platform::application-passwords')
            ->call('revoke', $credential->id)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $this->assertNotNull($credential->fresh()->revoked_at);
    }

    public function test_the_page_refuses_a_credential_with_no_scopes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('platform::application-passwords')
            ->call('openForm')
            ->set('name', 'Does nothing')
            ->set('selectedScopes', [])
            ->call('create')
            ->assertHasErrors('selectedScopes');

        $this->assertSame(0, ApplicationPassword::query()->count());
    }

    /* The migration --------------------------------------------------------- */

    public function test_the_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('application_passwords'));

        $migration = require base_path('Modules/Platform/database/migrations/2026_08_01_000001_create_application_passwords_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('application_passwords'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('application_passwords'));

        foreach (['user_id', 'name', 'token_hash', 'prefix', 'scopes', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('application_passwords', $column),
                $column.' did not come back.',
            );
        }
    }

    public function test_deleting_the_owner_takes_the_credentials_with_it(): void
    {
        $user = User::factory()->create();
        $this->issue($user);

        $this->assertSame(1, ApplicationPassword::query()->count());

        $user->delete();

        $this->assertSame(0, ApplicationPassword::query()->count());
    }
}
