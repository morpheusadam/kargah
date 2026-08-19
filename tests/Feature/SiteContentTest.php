<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteContent;
use Modules\Site\Services\SiteSeo;
use Modules\Site\Services\WordPressSite;
use Modules\Site\Support\PostTypes;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * Content on the website: listing it, editing it, and the SEO fields beside it.
 *
 * The tests worth reading first are the ones about what is *not* sent. A panel
 * that posts a whole form back to WordPress destroys every field it does not
 * draw — a featured image, a page template, a custom field — and does it with a
 * perfectly valid request that returns 200. `test_a_save_sends_only_what_changed`
 * and its SEO twin are the guards on that, and they are the reason `$original`
 * exists on the component at all.
 *
 * The second group is about the silent failure. Rank Math does not register its
 * meta for REST, so a site that has not been told to expose those keys accepts
 * a write, answers 200, and stores nothing.
 * `test_a_swallowed_seo_write_is_reported_rather_than_celebrated` is the one
 * that stops that being reported to somebody as a successful save.
 */
class SiteContentTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://bineret.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /* Helpers ----------------------------------------------------------------- */

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

    /**
     * One item as WordPress sends it under `context=edit`: every text field is
     * a `{raw, rendered}` object rather than a string.
     */
    private function item(array $overrides = [], array $meta = []): array
    {
        return array_replace([
            'id' => 12,
            'slug' => 'four-board-views',
            'status' => 'publish',
            'link' => self::SITE.'/four-board-views/',
            'modified' => '2026-08-16T10:00:00',
            'title' => ['raw' => 'Four board views', 'rendered' => 'Four board views'],
            'excerpt' => ['raw' => 'A short one.', 'rendered' => '<p>A short one.</p>'],
            'content' => ['raw' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->', 'rendered' => '<p>Body.</p>'],
            'meta' => $meta,
        ], $overrides);
    }

    /** Meta with every Rank Math key present, i.e. a site that has registered them. */
    private function seoMeta(array $overrides = []): array
    {
        return array_replace([
            'rank_math_title' => 'Four board views',
            'rank_math_description' => 'What each view cost.',
            'rank_math_focus_keyword' => 'board views',
            'rank_math_canonical_url' => '',
            'rank_math_facebook_title' => '',
            'rank_math_facebook_description' => '',
        ], $overrides);
    }

    /* Listing ------------------------------------------------------------------ */

    /**
     * `context=edit` is not a detail. Without it WordPress returns the rendered
     * body — filters applied, shortcodes expanded — and saving that back is how
     * a post fills with the residue of its own rendering.
     */
    public function test_a_listing_asks_for_the_stored_values_and_not_the_rendered_ones(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts*' => Http::response([$this->item()])]);

        (new SiteContent(WordPressSite::require()))->list(PostTypes::POST);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'context=edit'));
    }

    /**
     * A list that hid drafts would be a list somebody loses work in.
     */
    public function test_a_listing_asks_for_every_status_the_user_may_see(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts*' => Http::response([])]);

        (new SiteContent(WordPressSite::require()))->list(PostTypes::POST);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'status=any'));
    }

    public function test_pages_and_posts_go_to_different_rest_bases(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/pages*' => Http::response([]),
        ]);

        (new SiteContent(WordPressSite::require()))->list(PostTypes::PAGE);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/wp/v2/pages'));
    }

    /* Reading a field ----------------------------------------------------------- */

    public function test_a_field_prefers_what_is_stored_over_what_is_rendered(): void
    {
        $this->assertSame('Body.', SiteContent::text(['raw' => 'Body.', 'rendered' => '<p>Body.</p>']));
        $this->assertSame('<p>Only rendered.</p>', SiteContent::text(['rendered' => '<p>Only rendered.</p>']));
        $this->assertSame('A bare string', SiteContent::text('A bare string'));
        $this->assertSame('', SiteContent::text(null));
    }

    /* Trash and restore --------------------------------------------------------- */

    /**
     * 🔴 A post is never force-deleted from here.
     *
     * WordPress's trash is a better undo than anything this panel could build,
     * and it is where the owner will look. A panel that destroys a page on one
     * click is one nobody lets near their site.
     */
    public function test_trashing_never_forces_a_permanent_delete(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts/12*' => Http::response(['id' => 12, 'status' => 'trash'])]);

        (new SiteContent(WordPressSite::require()))->trash(PostTypes::POST, 12);

        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'force'));
    }

    /**
     * Restore comes back as a draft rather than as whatever it was.
     *
     * WordPress does not expose the previous status to the REST API, so the
     * choice is a guess or a safe answer. Nothing should silently reappear on a
     * live site because somebody clicked restore to look at it.
     */
    public function test_restoring_brings_something_back_unpublished(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts/12' => Http::response(['id' => 12, 'status' => 'draft'])]);

        (new SiteContent(WordPressSite::require()))->restore(PostTypes::POST, 12);

        Http::assertSent(fn ($request): bool => ($request->data()['status'] ?? null) === 'draft');
    }

    /* The editor ---------------------------------------------------------------- */

    public function test_the_editor_loads_what_the_site_stores(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts/12*' => Http::response($this->item(meta: $this->seoMeta()))]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 12])
            ->assertOk()
            ->assertSet('title', 'Four board views')
            ->assertSet('slug', 'four-board-views')
            ->assertSet('seoEditable', true)
            ->assertSet('seo.rank_math_description', 'What each view cost.')
            // The raw body, comments intact — not the rendered one.
            ->assertSet('content', '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->');
    }

    public function test_the_editor_reports_a_missing_item_instead_of_failing(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts/99*' => Http::response([
            'code' => 'rest_post_invalid_id',
            'message' => 'Invalid post ID.',
        ], 404)]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 99])
            ->assertOk()
            ->assertSet('found', false)
            ->assertSee('This could not be opened')
            ->assertSee('Invalid post ID');
    }

    /**
     * 🔴 The guard on the wrecking ball.
     *
     * WordPress treats an absent field as "leave it alone" and an empty one as
     * "make it empty". A save that posted the whole form back would therefore
     * clear the featured image, the template and every custom field this page
     * never drew — with a valid request and a 200 response.
     */
    public function test_a_save_sends_only_what_changed(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts/12?context=edit' => Http::response($this->item(meta: $this->seoMeta())),
            self::SITE.'/wp-json/wp/v2/posts/12' => Http::response($this->item(['title' => ['raw' => 'A new title']], $this->seoMeta())),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 12])
            ->set('title', 'A new title')
            ->call('save');

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $data = $request->data();

            return array_keys($data) === ['title'] && $data['title'] === 'A new title';
        });
    }

    public function test_a_save_with_nothing_changed_sends_nothing_at_all(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts/12*' => Http::response($this->item(meta: $this->seoMeta()))]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 12])
            ->call('save');

        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_a_refused_save_says_what_the_site_said(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts/12?context=edit' => Http::response($this->item(meta: $this->seoMeta())),
            self::SITE.'/wp-json/wp/v2/posts/12' => Http::response([
                'code' => 'rest_cannot_edit',
                'message' => 'Sorry, you are not allowed to edit this post.',
            ], 403),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 12])
            ->set('title', 'A new title')
            ->call('save')
            ->assertDispatched('toast');
    }

    /* SEO ------------------------------------------------------------------------ */

    /**
     * A site that has never registered Rank Math's keys sends no `meta` for
     * them, and the editor has to say so rather than draw inputs that discard
     * whatever is typed into them.
     */
    public function test_seo_fields_are_not_offered_when_the_site_does_not_expose_them(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts/12*' => Http::response($this->item(meta: []))]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 12])
            ->assertOk()
            ->assertSet('seoEditable', false)
            ->assertSee('not exposing Rank Math')
            ->assertSee('the few lines that fix it');
    }

    public function test_the_snippet_registers_every_field_the_panel_edits(): void
    {
        $snippet = SiteSeo::registrationSnippet();

        foreach (array_keys(SiteSeo::fields()) as $key) {
            $this->assertStringContainsString($key, $snippet);
        }

        // mu-plugins, not functions.php: a theme update empties the latter and
        // the symptom is SEO fields that worked for a month and then stopped.
        $this->assertStringContainsString('mu-plugins', $snippet);

        // Not left open to every authenticated user.
        $this->assertStringContainsString("current_user_can('edit_posts')", $snippet);
    }

    public function test_only_changed_seo_fields_are_sent(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts/12?context=edit' => Http::response($this->item(meta: $this->seoMeta())),
            self::SITE.'/wp-json/wp/v2/posts/12' => Http::response(
                $this->item(meta: $this->seoMeta(['rank_math_description' => 'Rewritten.'])),
            ),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 12])
            ->set('seo.rank_math_description', 'Rewritten.')
            ->call('save');

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $meta = $request->data()['meta'] ?? null;

            return is_array($meta) && array_keys($meta) === ['rank_math_description'];
        });
    }

    /**
     * 🔴 The failure mode this module exists to not have.
     *
     * WordPress answers 200 and drops an unregistered meta key without a word.
     * Comparing what was asked for against what came back is the only way to
     * know from outside the site, and reporting it is the difference between a
     * panel and a lie.
     */
    public function test_a_swallowed_seo_write_is_reported_rather_than_celebrated(): void
    {
        $this->site();

        Http::fake([
            // The read shows the keys, so the editor offers them…
            self::SITE.'/wp-json/wp/v2/posts/12?context=edit' => Http::response($this->item(meta: $this->seoMeta())),
            // …and the write comes back 200 with the old value still in place.
            self::SITE.'/wp-json/wp/v2/posts/12' => Http::response($this->item(meta: $this->seoMeta())),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::content-edit', ['type' => 'post', 'id' => 12])
            ->set('seo.rank_math_description', 'Rewritten.')
            ->call('save')
            ->assertSet('showSnippet', true)
            ->assertDispatched('toast');
    }

    public function test_a_meta_value_stored_as_an_array_is_read_as_its_first_element(): void
    {
        $values = SiteSeo::read(['meta' => ['rank_math_title' => ['From an array']]]);

        $this->assertSame('From an array', $values['rank_math_title']);
    }

    public function test_rejected_names_only_the_keys_the_site_did_not_keep(): void
    {
        $rejected = SiteSeo::rejected(
            ['rank_math_title' => 'Kept', 'rank_math_description' => 'Dropped'],
            ['meta' => ['rank_math_title' => 'Kept', 'rank_math_description' => 'Something else']],
        );

        $this->assertSame(['rank_math_description'], $rejected);
    }

    /* The list page --------------------------------------------------------------- */

    public function test_the_list_draws_what_the_site_returned(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts*' => Http::response(
                [$this->item()],
                headers: ['X-WP-Total' => '1', 'X-WP-TotalPages' => '1'],
            ),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::content')
            ->assertOk()
            ->assertSee('Four board views')
            ->assertSee('four-board-views');
    }

    /**
     * The page keeps its heading, its type switcher and its search box when the
     * site is down. Losing them would strand somebody on an error screen.
     */
    public function test_the_list_reports_a_failure_where_the_rows_would_have_been(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts*' => Http::response([
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that.',
        ], 401)]);

        Livewire::actingAs($this->actor())
            ->test('site::content')
            ->assertOk()
            ->assertSee('Content')
            ->assertSee('not allowed to do that');
    }

    public function test_the_list_explains_itself_when_nothing_is_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::content')
            ->assertOk()
            ->assertSee('No website is connected');
    }

    public function test_switching_type_returns_to_the_first_page(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/*' => Http::response([], headers: ['X-WP-TotalPages' => '5'])]);

        Livewire::actingAs($this->actor())
            ->test('site::content')
            ->call('goToPage', 3)
            ->assertSet('page', 3)
            ->set('type', PostTypes::PAGE)
            ->assertSet('page', 1);
    }
}
