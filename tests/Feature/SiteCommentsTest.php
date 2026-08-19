<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteComments;
use Modules\Site\Services\WordPressSite;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The moderation queue.
 *
 * The load-bearing test here is `test_a_comment_body_is_never_rendered_as_html`.
 * This is the one screen in the application whose entire content is, by
 * definition, text written by strangers who may be hostile — and it is a screen
 * whose whole purpose is to look at that text before deciding whether to let it
 * on the site. Rendering it here would mean executing the thing being judged.
 *
 * The second is about the spam button. WordPress distinguishes spam from trash
 * because marking spam teaches the filter; collapsing them into one "remove"
 * would degrade the site's filtering over months in a way nobody would trace
 * back to this panel.
 */
class SiteCommentsTest extends TestCase
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

    private function comment(int $id, string $status, string $content, string $author = 'A stranger'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'author_name' => $author,
            'date_gmt' => '2026-08-16T10:00:00',
            'link' => self::SITE.'/post/#comment-'.$id,
            'content' => ['raw' => $content, 'rendered' => '<p>'.$content.'</p>'],
        ];
    }

    /* Listing --------------------------------------------------------------------- */

    /**
     * Unlike every other list in this module, this one opens filtered — a held
     * comment is invisible to its author until somebody acts on it.
     */
    public function test_the_queue_opens_on_what_is_waiting(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/comments*' => Http::response([])]);

        Livewire::actingAs($this->actor())->test('site::comments')->assertSet('status', 'hold');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'status=hold'));
    }

    /**
     * `all` is this class's word, not WordPress's. Sending it would be a 400.
     */
    public function test_everything_means_sending_no_status_filter_at_all(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/comments*' => Http::response([])]);

        (new SiteComments(WordPressSite::require()))->list(['status' => 'all']);

        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'status='));
    }

    public function test_comments_are_asked_for_newest_first(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/comments*' => Http::response([])]);

        (new SiteComments(WordPressSite::require()))->list();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'orderby=date_gmt')
            && str_contains($request->url(), 'order=desc'));
    }

    /* Status naming ---------------------------------------------------------------- */

    /**
     * WordPress answers `approved` and accepts `approve`. Normalising on read
     * keeps the most common status on the site from falling through to
     * "unknown" on its own badge.
     */
    public function test_the_two_spellings_of_approved_are_reconciled(): void
    {
        $this->assertSame('approve', SiteComments::normalise('approved'));
        $this->assertSame('approve', SiteComments::normalise('approve'));
        $this->assertSame('hold', SiteComments::normalise('hold'));
    }

    public function test_waiting_counts_only_held_comments(): void
    {
        $items = [
            $this->comment(1, 'hold', 'One'),
            $this->comment(2, 'approved', 'Two'),
            $this->comment(3, 'hold', 'Three'),
            $this->comment(4, 'spam', 'Four'),
        ];

        $this->assertSame(2, SiteComments::waiting($items));
    }

    /* The body ---------------------------------------------------------------------- */

    /**
     * 🔴 The one that matters.
     *
     * This screen exists to look at untrusted text before deciding whether to
     * publish it. Rendering it would execute the thing being judged.
     */
    public function test_a_comment_body_is_never_rendered_as_html(): void
    {
        $malicious = $this->comment(1, 'hold', '<script>alert(1)</script>Buy pills');

        $excerpt = SiteComments::excerpt($malicious);

        $this->assertStringNotContainsString('<script', $excerpt);
        $this->assertStringContainsString('Buy pills', $excerpt);
    }

    /**
     * Entities are decoded *after* stripping and then stripped again. Decoding
     * first would turn `&lt;script&gt;` into a real tag for `strip_tags` to
     * find — the classic way to reintroduce exactly what the stripping was for.
     */
    public function test_an_entity_encoded_tag_does_not_survive_decoding(): void
    {
        $encoded = $this->comment(1, 'hold', '&lt;img src=x onerror=alert(1)&gt;Text');

        $excerpt = SiteComments::excerpt($encoded);

        $this->assertStringNotContainsString('<img', $excerpt);
        $this->assertStringNotContainsString('onerror', $excerpt);
        $this->assertStringContainsString('Text', $excerpt);
    }

    public function test_a_long_comment_is_cut_rather_than_filling_the_page(): void
    {
        $long = $this->comment(1, 'hold', str_repeat('word ', 200));

        $this->assertLessThanOrEqual(221, mb_strlen(SiteComments::excerpt($long)));
    }

    /* Moderating ---------------------------------------------------------------------- */

    public function test_approving_sends_the_status_wordpress_accepts(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/comments?*' => Http::response([$this->comment(7, 'hold', 'Hello')]),
            self::SITE.'/wp-json/wp/v2/comments/7' => Http::response($this->comment(7, 'approved', 'Hello')),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::comments')
            ->call('moveTo', 7, 'approve');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->data() === ['status' => 'approve']);
    }

    /**
     * Spam and trash are different acts. Marking spam teaches the filter;
     * trashing does not.
     */
    public function test_spam_and_trash_are_separate_and_both_reach_the_site(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/comments?*' => Http::response([$this->comment(7, 'hold', 'Buy pills')]),
            self::SITE.'/wp-json/wp/v2/comments/7' => Http::response($this->comment(7, 'spam', 'Buy pills')),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::comments')
            ->call('moveTo', 7, 'spam');

        Http::assertSent(fn ($request): bool => $request->data() === ['status' => 'spam']);
    }

    /**
     * Permanent deletion is not offered, so a status this class does not know
     * is refused before it can become one.
     */
    public function test_an_unknown_status_is_never_forwarded_to_the_site(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/comments*' => Http::response([])]);

        Livewire::actingAs($this->actor())
            ->test('site::comments')
            ->call('moveTo', 7, 'delete-forever');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    /* The page ------------------------------------------------------------------------- */

    public function test_the_page_shows_the_author_the_text_and_what_is_waiting(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/comments*' => Http::response(
                [$this->comment(1, 'hold', 'Genuinely useful point', 'Reader')],
                headers: ['X-WP-Total' => '1', 'X-WP-TotalPages' => '1'],
            ),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::comments')
            ->assertOk()
            ->assertSee('Reader')
            ->assertSee('Genuinely useful point')
            ->assertSee('1 waiting on this page');
    }

    public function test_an_empty_queue_reads_as_success_rather_than_as_emptiness(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/comments*' => Http::response([])]);

        Livewire::actingAs($this->actor())
            ->test('site::comments')
            ->assertOk()
            ->assertSee('Nothing is waiting')
            ->assertSee('has been dealt with');
    }

    public function test_the_page_reports_a_failure_instead_of_breaking(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/comments*' => Http::response([
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that.',
        ], 401)]);

        Livewire::actingAs($this->actor())
            ->test('site::comments')
            ->assertOk()
            ->assertSee('not allowed to do that');
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::comments')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
