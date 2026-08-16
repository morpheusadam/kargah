<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The site-wide SEO audit.
 *
 * The test that carries the most weight here is
 * `test_pages_whose_seo_cannot_be_read_are_not_reported_as_clean`. An audit
 * that says "nothing missing" because it could not read anything uses the same
 * words as success, and somebody reading it would reasonably close the tab and
 * do nothing. Skipping and counting is the only honest option.
 *
 * The rest is arithmetic on the checks, and one ordering test: a page missing
 * three things deserves to be above one missing a focus keyword, because the
 * list is read from the top and the top ten minutes are the ones that get spent.
 */
class SiteSeoAuditTest extends TestCase
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

    /**
     * @param  array<string, string>|null  $meta  null means the site exposes nothing
     */
    private function item(int $id, string $title, ?array $meta): array
    {
        return [
            'id' => $id,
            'status' => 'publish',
            'title' => ['raw' => $title, 'rendered' => $title],
            'meta' => $meta ?? [],
        ];
    }

    /** @param array<string, string> $overrides */
    private function completeMeta(array $overrides = []): array
    {
        return array_replace([
            'rank_math_title' => 'A good title',
            'rank_math_description' => 'A description that is comfortably inside the limit and says what the page is.',
            'rank_math_focus_keyword' => 'board views',
            'rank_math_canonical_url' => '',
            'rank_math_facebook_title' => '',
            'rank_math_facebook_description' => '',
        ], $overrides);
    }

    private function fakeList(array $items, int $total = null): void
    {
        Http::fake([
            self::SITE.'/wp-json/wp/v2/posts*' => Http::response(
                $items,
                headers: ['X-WP-Total' => (string) ($total ?? count($items)), 'X-WP-TotalPages' => '1'],
            ),
            self::SITE.'/wp-json/wp/v2/pages*' => Http::response($items, headers: ['X-WP-Total' => (string) count($items)]),
        ]);
    }

    /* The audit ------------------------------------------------------------------ */

    public function test_a_complete_page_produces_no_findings(): void
    {
        $this->site();
        $this->fakeList([$this->item(1, 'Four board views', $this->completeMeta())]);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('Nothing missing');
    }

    public function test_a_missing_description_is_named_with_what_happens_without_it(): void
    {
        $this->site();
        $this->fakeList([$this->item(1, 'Four board views', $this->completeMeta(['rank_math_description' => '']))]);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('No meta description')
            ->assertSee('Four board views');
    }

    public function test_an_over_long_title_is_reported_with_its_length(): void
    {
        $this->site();
        $this->fakeList([$this->item(1, 'Long', $this->completeMeta(['rank_math_title' => str_repeat('a', 75)]))]);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('SEO title is too long')
            ->assertSee('It is 75 characters');
    }

    public function test_a_missing_focus_keyword_is_reported(): void
    {
        $this->site();
        $this->fakeList([$this->item(1, 'No keyword', $this->completeMeta(['rank_math_focus_keyword' => '']))]);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('No focus keyword');
    }

    /**
     * 🔴 The one that matters.
     *
     * "Nothing missing" and "nothing readable" are the same words to somebody
     * skimming, and one of them means close the tab.
     */
    public function test_pages_whose_seo_cannot_be_read_are_not_reported_as_clean(): void
    {
        $this->site();
        $this->fakeList([
            $this->item(1, 'Unexposed one', null),
            $this->item(2, 'Unexposed two', null),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('Nothing here can be checked')
            ->assertDontSee('Nothing missing');
    }

    public function test_a_partially_readable_site_reports_what_it_skipped(): void
    {
        $this->site();
        $this->fakeList([
            $this->item(1, 'Readable', $this->completeMeta()),
            $this->item(2, 'Unexposed', null),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('did not expose their SEO fields and were');
    }

    /**
     * The list is read from the top, so the top is where the expensive problems
     * belong.
     */
    public function test_the_worst_page_is_listed_first(): void
    {
        $this->site();
        $this->fakeList([
            $this->item(1, 'One problem only', $this->completeMeta(['rank_math_focus_keyword' => ''])),
            $this->item(2, 'Three problems here', $this->completeMeta([
                'rank_math_title' => '',
                'rank_math_description' => '',
                'rank_math_focus_keyword' => '',
            ])),
        ]);

        $rendered = Livewire::actingAs($this->actor())->test('site::seo')->assertOk()->html();

        $this->assertLessThan(
            strpos($rendered, 'One problem only'),
            strpos($rendered, 'Three problems here'),
            'The page with more problems should be listed above the one with fewer.',
        );
    }

    /**
     * The audit examines one page of results, and the heading has to say so
     * rather than implying a whole-site sweep.
     */
    public function test_the_scope_of_the_audit_is_stated_rather_than_implied(): void
    {
        $this->site();
        $this->fakeList([$this->item(1, 'One', $this->completeMeta())], total: 600);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('not the whole site');
    }

    /**
     * Only published things are audited. A draft with no meta description is
     * not a problem; it is a draft.
     */
    public function test_only_published_content_is_audited(): void
    {
        $this->site();
        $this->fakeList([]);

        Livewire::actingAs($this->actor())->test('site::seo')->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'status=publish'));
    }

    public function test_the_page_reports_a_failure_instead_of_an_empty_clean_bill(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/posts*' => Http::response([
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that.',
        ], 401)]);

        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('did not return anything to audit')
            ->assertDontSee('Nothing missing');
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::seo')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
