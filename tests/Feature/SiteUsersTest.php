<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteUsers;
use Modules\Site\Services\WordPressSite;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The website's users.
 *
 * `test_demoting_the_last_administrator_is_refused` is the one that earns this
 * file. Every other failure in this module costs a page or an afternoon; that
 * one costs the site. Demote the last administrator and nobody left can promote
 * anybody back — not from this panel, not from wp-admin — and the only way in
 * is editing the database by hand.
 *
 * The rest guards two decisions about what is deliberately absent. Creating a
 * user means composing somebody's password here; deleting one means deciding
 * what happens to everything they ever wrote, with the wrong answer destroying
 * it. Neither is built, and the page says why rather than looking unfinished.
 */
class SiteUsersTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://lavzen.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function site(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::WORDPRESS)->create([
            'handle' => 'lavzen.test',
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

    private function person(int $id, string $name, string $role): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'slug' => strtolower($name),
            'email' => strtolower($name).'@lavzen.test',
            'roles' => [$role],
        ];
    }

    private function fakeUsers(array $people): void
    {
        Http::fake([
            self::SITE.'/wp-json/wp/v2/users*' => Http::response(
                $people,
                headers: ['X-WP-Total' => (string) count($people), 'X-WP-TotalPages' => '1'],
            ),
        ]);
    }

    /* Reading ---------------------------------------------------------------------- */

    /**
     * Without `context=edit` WordPress omits `roles` and `email` entirely, and
     * a user list with neither is a list of names.
     */
    public function test_the_list_asks_for_the_fields_that_make_it_a_user_list(): void
    {
        $this->site();
        $this->fakeUsers([]);

        (new SiteUsers(WordPressSite::require()))->list();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'context=edit'));
    }

    /**
     * A WordPress install can have any roles at all — WooCommerce adds two, a
     * membership plugin adds more, and core has no REST endpoint listing them.
     */
    public function test_roles_found_on_real_users_are_offered_alongside_cores_five(): void
    {
        $roles = SiteUsers::rolesFound([
            $this->person(1, 'Nima', 'administrator'),
            $this->person(2, 'Sara', 'shop_manager'),
        ]);

        foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $core) {
            $this->assertArrayHasKey($core, $roles);
        }

        $this->assertArrayHasKey('shop_manager', $roles);
        $this->assertSame('Shop Manager', $roles['shop_manager']);
    }

    /* Changing a role ---------------------------------------------------------------- */

    /**
     * WordPress models roles as a list. A bare string is a 400 that says
     * `rest_invalid_param` and nothing about which param.
     */
    public function test_a_role_is_sent_as_an_array_even_for_one_role(): void
    {
        $this->site();
        $this->fakeUsers([$this->person(3, 'Sara', 'contributor')]);

        (new SiteUsers(WordPressSite::require()))->setRole(3, 'author');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->data() === ['roles' => ['author']]);
    }

    public function test_changing_a_role_from_the_page_reaches_the_site(): void
    {
        $this->site();
        $this->fakeUsers([
            $this->person(1, 'Nima', 'administrator'),
            $this->person(3, 'Sara', 'contributor'),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::users')
            ->call('edit', 3, 'contributor')
            ->set('newRole', 'author')
            ->call('saveRole')
            ->assertSet('editing', null);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->data() === ['roles' => ['author']]);
    }

    /* The guard ------------------------------------------------------------------------ */

    /**
     * 🔴 The failure that is not survivable.
     */
    public function test_demoting_the_last_administrator_is_refused(): void
    {
        $this->site();
        $this->fakeUsers([
            $this->person(1, 'Nima', 'administrator'),
            $this->person(3, 'Sara', 'contributor'),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::users')
            ->call('edit', 1, 'administrator')
            ->set('newRole', 'editor')
            ->call('saveRole')
            ->assertDispatched('toast');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_demoting_one_of_two_administrators_is_allowed(): void
    {
        $this->site();
        $this->fakeUsers([
            $this->person(1, 'Nima', 'administrator'),
            $this->person(2, 'Hesam', 'administrator'),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::users')
            ->call('edit', 1, 'administrator')
            ->set('newRole', 'editor')
            ->call('saveRole');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST');
    }

    /** Promoting somebody *to* administrator can never remove the last one. */
    public function test_promoting_to_administrator_is_never_blocked(): void
    {
        $this->assertFalse(SiteUsers::wouldRemoveLastAdministrator(
            [$this->person(1, 'Nima', 'administrator')],
            1,
            'administrator',
        ));
    }

    public function test_the_guard_ignores_users_who_are_not_administrators(): void
    {
        $this->assertFalse(SiteUsers::wouldRemoveLastAdministrator(
            [$this->person(1, 'Nima', 'administrator'), $this->person(3, 'Sara', 'contributor')],
            3,
            'subscriber',
        ));
    }

    /* The page --------------------------------------------------------------------------- */

    public function test_the_page_draws_people_with_their_role_and_email(): void
    {
        $this->site();
        $this->fakeUsers([$this->person(1, 'Nima', 'administrator')]);

        Livewire::actingAs($this->actor())
            ->test('site::users')
            ->assertOk()
            ->assertSee('Nima')
            ->assertSee('nima@lavzen.test')
            ->assertSee('Administrator');
    }

    public function test_what_is_not_offered_is_explained_rather_than_missing(): void
    {
        $this->site();
        $this->fakeUsers([$this->person(1, 'Nima', 'administrator')]);

        Livewire::actingAs($this->actor())
            ->test('site::users')
            ->assertOk()
            ->assertSee('Adding a person')
            ->assertSee('they set their own')
            ->assertSee('Deleting a person')
            ->assertSee('deletes their posts');
    }

    public function test_the_page_names_the_capability_when_the_site_refuses(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/users*' => Http::response([
            'code' => 'rest_user_cannot_view',
            'message' => 'Sorry, you are not allowed to list users.',
        ], 403)]);

        Livewire::actingAs($this->actor())
            ->test('site::users')
            ->assertOk()
            ->assertSee('not allowed to list users')
            ->assertSee('list_users');
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::users')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
