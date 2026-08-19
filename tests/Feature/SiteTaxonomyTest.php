<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteTaxonomy;
use Modules\Site\Services\WordPressSite;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * Categories and tags.
 *
 * The two decisions worth guarding are both about what the page shows rather
 * than what it can do. Ordering by use instead of alphabetically is what turns
 * a list of two hundred tags into information, and never sending `hide_empty`
 * is what keeps the dead ones visible — an unused term is exactly the thing
 * this page exists to find, and WordPress's default would hide it.
 */
class SiteTaxonomyTest extends TestCase
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

    private function term(int $id, string $name, int $count): array
    {
        return ['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'count' => $count];
    }

    /* Listing ------------------------------------------------------------------- */

    /**
     * Alphabetical order tells you nothing about a site. Order by use and the
     * top says what it is about while the bottom says what went wrong.
     */
    public function test_terms_are_asked_for_most_used_first(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/categories*' => Http::response([])]);

        (new SiteTaxonomy(WordPressSite::require()))->list(SiteTaxonomy::CATEGORY);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'orderby=count')
            && str_contains($request->url(), 'order=desc'));
    }

    /**
     * 🔴 `hide_empty` is never sent. An unused term is what this page is for.
     */
    public function test_unused_terms_are_never_hidden(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/tags*' => Http::response([])]);

        (new SiteTaxonomy(WordPressSite::require()))->list(SiteTaxonomy::TAG);

        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'hide_empty'));
    }

    public function test_categories_and_tags_go_to_different_rest_bases(): void
    {
        $this->assertSame('wp/v2/categories', SiteTaxonomy::rest(SiteTaxonomy::CATEGORY));
        $this->assertSame('wp/v2/tags', SiteTaxonomy::rest(SiteTaxonomy::TAG));

        // An unknown taxonomy falls back rather than building a broken URL.
        $this->assertSame('wp/v2/categories', SiteTaxonomy::rest('genre'));
    }

    /* Counting ------------------------------------------------------------------- */

    public function test_unused_and_used_once_are_counted_separately(): void
    {
        $counts = SiteTaxonomy::thin([
            $this->term(1, 'Releases', 12),
            $this->term(2, 'Typo', 0),
            $this->term(3, 'Andriod', 1),
            $this->term(4, 'Dead', 0),
        ]);

        $this->assertSame(2, $counts['unused']);
        $this->assertSame(1, $counts['usedOnce']);
    }

    /* Writing --------------------------------------------------------------------- */

    public function test_creating_a_term_sends_its_name(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/categories*' => Http::response([$this->term(1, 'Releases', 0)]),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::taxonomies')
            ->set('newName', 'Release notes')
            ->call('add')
            ->assertSet('newName', '');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->data() === ['name' => 'Release notes']);
    }

    public function test_creating_nothing_sends_nothing(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/categories*' => Http::response([])]);

        Livewire::actingAs($this->actor())
            ->test('site::taxonomies')
            ->set('newName', '   ')
            ->call('add');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    /**
     * The slug is left alone on a rename, so archive URLs do not break. Sending
     * only the name is what guarantees it.
     */
    public function test_renaming_leaves_the_slug_alone(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/categories?*' => Http::response([$this->term(4, 'Releases', 3)]),
            self::SITE.'/wp-json/wp/v2/categories/4' => Http::response($this->term(4, 'Release notes', 3)),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::taxonomies')
            ->call('edit', 4, 'Releases')
            ->set('editName', 'Release notes')
            ->call('rename')
            ->assertSet('editing', null);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST' && $request->data() === ['name' => 'Release notes'];
        });
    }

    /**
     * Terms have no trash — `DELETE` without `force` is refused with
     * `rest_trash_not_supported` — so force is the only thing that works, and
     * it belongs in the query string where WordPress reads it.
     */
    public function test_deleting_a_term_always_forces(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/tags/9*' => Http::response(['deleted' => true])]);

        (new SiteTaxonomy(WordPressSite::require()))->delete(SiteTaxonomy::TAG, 9);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'force=true'));
    }

    public function test_deleting_takes_two_clicks_and_says_the_posts_survive(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/categories?*' => Http::response([$this->term(4, 'Dead', 0)]),
            self::SITE.'/wp-json/wp/v2/categories/4*' => Http::response(['deleted' => true]),
        ]);

        $component = Livewire::actingAs($this->actor())
            ->test('site::taxonomies')
            ->set('confirming', 4)
            ->assertSee('Posts stay, the category goes');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');

        $component->call('delete', 4)->assertSet('confirming', null);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    /* The page --------------------------------------------------------------------- */

    public function test_the_page_leads_with_what_is_worth_acting_on(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/categories*' => Http::response(
                [$this->term(1, 'Releases', 12), $this->term(2, 'Dead', 0), $this->term(3, 'Andriod', 1)],
                headers: ['X-WP-Total' => '3', 'X-WP-TotalPages' => '1'],
            ),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::taxonomies')
            ->assertOk()
            ->assertSee('1 used by nothing')
            ->assertSee('1 used once')
            ->assertSee('Releases');
    }

    public function test_the_page_reports_a_failure_instead_of_breaking(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/categories*' => Http::response([
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that.',
        ], 401)]);

        Livewire::actingAs($this->actor())
            ->test('site::taxonomies')
            ->assertOk()
            ->assertSee('not allowed to do that');
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::taxonomies')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
