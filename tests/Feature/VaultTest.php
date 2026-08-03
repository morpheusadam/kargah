<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Data\Models\Credential;
use Modules\Data\Models\CredentialCategory;
use Modules\Data\Services\Vault;
use Modules\Data\Support\Totp;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Phase 6 acceptance criteria for the vault, from
 * `project-guaid/spec/05-build-order.md`:
 *
 * - No secret appears in any rendered HTML — asserted by a test.
 * - Every reveal appears in the activity log with who and when.
 *
 * The first is the one that matters. A vault that leaks its contents into the
 * markup is worse than no vault at all, because it looks like one.
 */
class VaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A value distinctive enough that finding it anywhere is unambiguous.
     *
     * Not a realistic password on purpose: a realistic one might collide with a
     * fragment of a session token or a CSS class and make the assertion lie in
     * either direction.
     */
    private const SECRET = 'zqx-VAULT-LEAK-CANARY-7731-qzx';

    private const TOTP_SEED = 'JBSWY3DPEHPK3PXP';

    private const NOTES = 'zqx-NOTES-LEAK-CANARY-4402-qzx';

    private function seedCredential(): Credential
    {
        $category = CredentialCategory::factory()->create(['name' => 'Hosting']);

        return Credential::factory()->create([
            'name' => 'Hostinger hPanel',
            'username' => 'morph',
            'url' => 'https://hpanel.hostinger.com',
            'category_id' => $category->id,
            'secret' => self::SECRET,
            'totp' => self::TOTP_SEED,
            'notes' => self::NOTES,
        ]);
    }

    // ------------------------------------------------------ encryption at rest

    public function test_the_secret_is_encrypted_in_the_column_and_readable_through_the_model(): void
    {
        $credential = $this->seedCredential();

        $stored = DB::table('credentials')->where('id', $credential->id)->first();

        $this->assertNotSame(self::SECRET, $stored->secret_encrypted);
        $this->assertStringNotContainsString(self::SECRET, $stored->secret_encrypted);
        $this->assertSame(self::SECRET, Crypt::decryptString($stored->secret_encrypted));

        $this->assertSame(self::SECRET, $credential->fresh()->secret);
        $this->assertSame(self::TOTP_SEED, $credential->fresh()->totp);
        $this->assertSame(self::NOTES, $credential->fresh()->notes);
    }

    public function test_the_encrypted_columns_are_hidden_from_array_and_json_output(): void
    {
        $credential = $this->seedCredential();

        $array = $credential->toArray();

        $this->assertArrayNotHasKey('secret_encrypted', $array);
        $this->assertArrayNotHasKey('totp_encrypted', $array);
        $this->assertArrayNotHasKey('notes_encrypted', $array);
        $this->assertArrayNotHasKey('secret', $array);

        $this->assertStringNotContainsString(self::SECRET, $credential->toJson());
        $this->assertStringNotContainsString(self::NOTES, $credential->toJson());
    }

    // ----------------------------------- "No secret appears in any rendered HTML"

    public function test_no_secret_appears_in_any_rendered_html(): void
    {
        $this->seedCredential();
        $user = User::factory()->create();

        foreach (['/data/passwords', '/data/passwords/create'] as $url) {
            $body = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(self::SECRET, $body, $url.' rendered the secret.');
            $this->assertStringNotContainsString(self::TOTP_SEED, $body, $url.' rendered the TOTP seed.');
            $this->assertStringNotContainsString(self::NOTES, $body, $url.' rendered the encrypted notes.');
        }
    }

    public function test_the_list_renders_a_mask_and_not_the_secret_behind_one(): void
    {
        $credential = $this->seedCredential();
        $user = User::factory()->create();

        $body = $this->actingAs($user)->get('/data/passwords')->assertOk()->getContent();

        // Present: the entry itself. Absent: anything that could be decrypted
        // client side, including the ciphertext.
        $this->assertStringContainsString('Hostinger hPanel', $body);
        $this->assertStringContainsString('••••••••••••', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString($credential->getRawOriginal('secret_encrypted'), $body);
    }

    public function test_the_secret_is_absent_from_the_livewire_component_payload(): void
    {
        $credential = $this->seedCredential();
        $user = User::factory()->create();

        $body = $this->actingAs($user)->get('/data/passwords')->assertOk()->getContent();

        // Livewire embeds the component's serialised state in the page as
        // `wire:snapshot`. Anything held in a public property is therefore in
        // the markup whether or not the template draws it, which is why the
        // list holds ids and masks and never a decrypted value.
        $this->assertStringContainsString('wire:snapshot', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString(self::TOTP_SEED, $body);

        Livewire::actingAs($user)->test('data::passwords')
            ->assertSet('revealedSecret', null)
            ->assertDontSee(self::SECRET);

        $this->assertSame(self::SECRET, $credential->fresh()->secret);
    }

    public function test_searching_and_filtering_never_puts_a_secret_on_the_page(): void
    {
        $this->seedCredential();
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('data::passwords')
            ->set('search', 'Hostinger')
            ->assertSee('Hostinger hPanel')
            ->assertDontSee(self::SECRET)
            ->set('category', 'all')
            ->assertDontSee(self::SECRET);
    }

    // ------------------------------------------- a reveal is a deliberate act

    public function test_a_reveal_puts_the_secret_on_the_page_and_hiding_takes_it_off_again(): void
    {
        $credential = $this->seedCredential();
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('data::passwords')
            ->assertDontSee(self::SECRET)
            ->call('reveal', $credential->id)
            ->assertSee(self::SECRET)
            ->call('reveal', $credential->id)
            ->assertDontSee(self::SECRET);
    }

    public function test_a_reveal_returns_one_secret_for_one_item(): void
    {
        $first = $this->seedCredential();
        $second = Credential::factory()->create(['name' => 'Brevo API', 'secret' => 'zqx-SECOND-CANARY-qzx']);

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('data::passwords')
            ->call('reveal', $first->id)
            ->assertSee(self::SECRET)
            // Revealing one entry must not reveal its neighbour.
            ->assertDontSee('zqx-SECOND-CANARY-qzx');

        $this->assertNotNull($second->fresh()->id);
    }

    // ------------------- "Every reveal appears in the activity log with who and when"

    public function test_every_reveal_appears_in_the_activity_log_with_who_and_when(): void
    {
        $credential = $this->seedCredential();
        $user = User::factory()->create(['name' => 'Nima Fazlipour']);

        $before = now()->subSecond();

        Livewire::actingAs($user)->test('data::passwords')->call('reveal', $credential->id);

        $activity = Activity::query()->where('event', 'credential.revealed')->latest('id')->first();

        $this->assertNotNull($activity, 'A reveal wrote no activity entry.');
        $this->assertSame('credential', $activity->log_name);

        // Who.
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('user', $activity->causer_type);

        // What.
        $this->assertSame('credential', $activity->subject_type);
        $this->assertSame($credential->id, $activity->subject_id);
        $this->assertSame('secret', $activity->properties['field']);

        // When.
        $this->assertTrue($activity->created_at->greaterThanOrEqualTo($before));
        $this->assertNotNull($credential->fresh()->last_revealed_at);

        // And never the value itself: this table is append-only.
        $this->assertStringNotContainsString(self::SECRET, json_encode($activity->toArray()));
    }

    public function test_a_copy_is_logged_exactly_like_a_reveal_and_never_reaches_the_markup(): void
    {
        $credential = $this->seedCredential();
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('data::passwords')
            ->call('copy', $credential->id)
            ->assertDispatched('copy-to-clipboard')
            // The value goes to the clipboard, not into the page.
            ->assertDontSee(self::SECRET);

        $this->assertSame(
            1,
            Activity::query()->where('event', 'credential.revealed')->count(),
            'Copying a secret was not logged.'
        );
    }

    public function test_two_reveals_write_two_log_entries(): void
    {
        $credential = $this->seedCredential();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test('data::passwords');
        $component->call('reveal', $credential->id);
        $component->call('reveal', $credential->id);   // hides
        $component->call('reveal', $credential->id);

        $this->assertSame(2, Activity::query()->where('event', 'credential.revealed')->count());
    }

    public function test_reading_a_credential_does_not_move_its_updated_at(): void
    {
        $credential = $this->seedCredential();
        $updatedAt = $credential->fresh()->updated_at;

        $this->travelTo(now()->addMinutes(5));

        app(Vault::class)->reveal($credential->fresh());

        $this->assertEquals($updatedAt, $credential->fresh()->updated_at, 'A read changed updated_at.');
        $this->assertNotNull($credential->fresh()->last_revealed_at);
    }

    // ------------------------------------------------------------------- TOTP

    public function test_totp_codes_match_the_rfc_6238_reference_vectors(): void
    {
        // RFC 6238 Appendix B, SHA-1, seed '12345678901234567890' as base32.
        $seed = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        // The RFC prints eight digits; six is what an authenticator shows, so
        // these are its values truncated the way `Totp::DIGITS` truncates them.
        $this->assertSame('287082', Totp::code($seed, 59));            // 94287082
        $this->assertSame('081804', Totp::code($seed, 1111111109));    // 07081804
        $this->assertSame('050471', Totp::code($seed, 1111111111));    // 14050471
        $this->assertSame('005924', Totp::code($seed, 1234567890));    // 89005924
        $this->assertSame('279037', Totp::code($seed, 2000000000));    // 69279037
    }

    public function test_a_totp_seed_produces_a_rolling_code_without_the_seed_reaching_the_page(): void
    {
        // Frozen, so the code cannot roll between being computed here and being
        // rendered below. One run in thirty would otherwise fail on the seam.
        $this->travelTo(now()->startOfMinute());

        $credential = $this->seedCredential();
        $user = User::factory()->create();

        $code = $credential->totpCode();

        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $body = $this->actingAs($user)->get('/data/passwords')->assertOk()->getContent();

        $this->assertStringContainsString($code, $body, 'The rolling code was not shown.');
        $this->assertStringNotContainsString(self::TOTP_SEED, $body, 'The seed reached the page.');
    }

    public function test_an_invalid_seed_yields_no_code_rather_than_an_error(): void
    {
        $this->assertNull(Totp::code('not base32 at all!'));
        $this->assertFalse(Totp::isValidSeed('11111'));
        $this->assertTrue(Totp::isValidSeed(self::TOTP_SEED));
    }

    // -------------------------------------------------------------- the form

    public function test_saving_a_credential_encrypts_it_and_never_stores_the_plaintext(): void
    {
        $user = User::factory()->create();
        $category = CredentialCategory::factory()->create(['name' => 'Hosting']);

        Livewire::actingAs($user)->test('data::credential-create')
            ->set('name', 'Namecheap')
            ->set('username', 'nima@kargah.test')
            ->set('secret', self::SECRET)
            ->set('categoryId', $category->id)
            ->call('save')
            ->assertRedirect(route('data.passwords'));

        $credential = Credential::query()->where('name', 'Namecheap')->firstOrFail();

        $this->assertSame(self::SECRET, $credential->secret);
        $this->assertSame($user->id, $credential->created_by);
        $this->assertStringNotContainsString(self::SECRET, $credential->getRawOriginal('secret_encrypted'));
    }

    public function test_the_generator_produces_a_secret_of_the_requested_shape(): void
    {
        $vault = app(Vault::class);

        $secret = $vault->generate(32, useUpper: true, useDigits: true, useSymbols: true, avoidAmbiguous: true);

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/[A-Z]/', $secret);
        $this->assertMatchesRegularExpression('/\d/', $secret);
        // Look-alikes are excluded, because a secret gets read down a phone.
        $this->assertDoesNotMatchRegularExpression('/[l1IO0]/', $secret);

        $this->assertNotSame($secret, $vault->generate(32));
    }

    public function test_a_credential_saved_with_a_rubbish_totp_seed_is_refused(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('data::credential-create')
            ->set('name', 'Bank')
            ->set('secret', self::SECRET)
            // '1' and '0' are not in the base32 alphabet, which is exactly the
            // kind of seed a person produces by transcribing one by hand.
            ->set('totp', '10101010')
            ->call('save')
            ->assertHasErrors('totp');

        $this->assertSame(0, Credential::query()->count());
    }
}
