<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Site\Services\SiteMedia;
use Modules\Site\Services\WordPressSite;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The website's media library.
 *
 * Two things here are worth more than the rest.
 *
 * `test_deleting_an_attachment_always_forces` is not a preference: WordPress
 * refuses a trash on an attachment outright, so a delete that politely omitted
 * `force` would fail every time with `rest_trash_not_supported`, and the panel
 * would look broken rather than careful.
 *
 * `test_a_grid_asks_for_thumbnails_rather_than_originals` is about somebody's
 * data allowance. Without the size fallback, a grid of twenty-four photographs
 * downloads twenty-four full-resolution originals to draw a contact sheet.
 */
class SiteMediaTest extends TestCase
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

    private function attachment(array $overrides = []): array
    {
        return array_replace([
            'id' => 31,
            'slug' => 'dashboard-png',
            'media_type' => 'image',
            'alt_text' => '',
            'title' => ['raw' => 'dashboard', 'rendered' => 'dashboard'],
            'source_url' => self::SITE.'/wp-content/uploads/2026/08/dashboard.png',
            'media_details' => [
                'sizes' => [
                    'thumbnail' => ['source_url' => self::SITE.'/wp-content/uploads/2026/08/dashboard-150x150.png'],
                    'medium' => ['source_url' => self::SITE.'/wp-content/uploads/2026/08/dashboard-300x300.png'],
                ],
            ],
        ], $overrides);
    }

    /* Listing ------------------------------------------------------------------- */

    public function test_the_library_is_asked_for_newest_first(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/media*' => Http::response([$this->attachment()])]);

        (new SiteMedia(WordPressSite::require()))->list();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'orderby=date')
            && str_contains($request->url(), 'order=desc'));
    }

    /**
     * WordPress takes only five values for `media_type` and answers 400 for
     * anything else, so an unknown filter is dropped rather than forwarded.
     */
    public function test_an_unknown_media_type_filter_is_dropped_rather_than_sent(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/media*' => Http::response([])]);

        (new SiteMedia(WordPressSite::require()))->list(['media_type' => 'spreadsheet']);

        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'media_type'));
    }

    public function test_a_known_media_type_filter_is_sent(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/media*' => Http::response([])]);

        (new SiteMedia(WordPressSite::require()))->list(['media_type' => 'image']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'media_type=image'));
    }

    /* Thumbnails ----------------------------------------------------------------- */

    public function test_a_grid_asks_for_thumbnails_rather_than_originals(): void
    {
        $this->assertSame(
            self::SITE.'/wp-content/uploads/2026/08/dashboard-150x150.png',
            SiteMedia::thumbnail($this->attachment()),
        );
    }

    public function test_a_thumbnail_falls_back_through_medium_to_the_original(): void
    {
        $noThumbnail = $this->attachment([
            'media_details' => ['sizes' => ['medium' => ['source_url' => 'https://x.test/m.png']]],
        ]);

        $this->assertSame('https://x.test/m.png', SiteMedia::thumbnail($noThumbnail));

        $noSizes = $this->attachment(['media_details' => []]);

        $this->assertSame(self::SITE.'/wp-content/uploads/2026/08/dashboard.png', SiteMedia::thumbnail($noSizes));
    }

    public function test_an_attachment_with_no_url_at_all_has_no_thumbnail(): void
    {
        $this->assertNull(SiteMedia::thumbnail(['id' => 1]));
    }

    /* Alt text -------------------------------------------------------------------- */

    public function test_only_images_are_counted_as_missing_alt_text(): void
    {
        $items = [
            $this->attachment(['id' => 1, 'alt_text' => '']),
            $this->attachment(['id' => 2, 'alt_text' => 'A dashboard']),
            $this->attachment(['id' => 3, 'media_type' => 'application', 'alt_text' => '']),
        ];

        $this->assertSame(1, SiteMedia::missingAltText($items));
    }

    public function test_whitespace_is_not_alternative_text(): void
    {
        $this->assertSame(1, SiteMedia::missingAltText([$this->attachment(['alt_text' => '   '])]));
    }

    public function test_saving_alt_text_sends_only_that_field(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/media*' => Http::response([$this->attachment()]),
            self::SITE.'/wp-json/wp/v2/media/31' => Http::response($this->attachment(['alt_text' => 'A dashboard'])),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::media')
            ->call('edit', 31, '')
            ->set('altText', 'A dashboard')
            ->call('saveAltText')
            ->assertSet('editing', null);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST' && $request->data() === ['alt_text' => 'A dashboard'];
        });
    }

    /* Deleting --------------------------------------------------------------------- */

    /**
     * 🔴 Not a preference. WordPress refuses a trash on an attachment with
     * `rest_trash_not_supported`, so a delete that omitted `force` would fail
     * every single time.
     */
    public function test_deleting_an_attachment_always_forces(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/media/31*' => Http::response(['deleted' => true])]);

        (new SiteMedia(WordPressSite::require()))->delete(31);

        // In the query string, where WordPress reads it for a DELETE — not in
        // the body, which is where `Http::delete($url, $data)` would put it.
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'force=true'));
    }

    public function test_deleting_takes_two_clicks(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/media*' => Http::response([$this->attachment()]),
            self::SITE.'/wp-json/wp/v2/media/31*' => Http::response(['deleted' => true]),
        ]);

        $component = Livewire::actingAs($this->actor())
            ->test('site::media')
            ->set('confirming', 31);

        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');

        $component->call('delete', 31)->assertSet('confirming', null);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    /* Uploading --------------------------------------------------------------------- */

    /**
     * The filename and the mime come off the staged file, not off whatever the
     * browser claimed — the lesson commit 3daeb66 recorded for social uploads,
     * where a browser announcing `application/octet-stream` for a JPEG had the
     * network refuse it.
     */
    public function test_an_upload_streams_to_the_site_with_its_real_name_and_mime(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/media*' => Http::response(
                $this->attachment(),
                201,
            ),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::media')
            ->set('upload', UploadedFile::fake()->image('dashboard.png', 40, 40))
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('upload', null);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->hasHeader('Content-Disposition', 'attachment; filename="dashboard.png"')
                && str_starts_with((string) $request->header('Content-Type')[0], 'image/');
        });
    }

    /**
     * A limit Kargah enforces produces a sentence under the field. One the host
     * enforces produces a 500 with an HTML body, several seconds after the
     * upload appeared to be working.
     */
    public function test_a_file_over_the_limit_is_refused_before_it_leaves(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/media*' => Http::response([])]);

        Livewire::actingAs($this->actor())
            ->test('site::media')
            ->set('upload', UploadedFile::fake()->create('huge.zip', 9000))
            ->call('send')
            ->assertHasErrors('upload');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    /* The page ------------------------------------------------------------------------ */

    public function test_the_page_counts_what_is_missing_alt_text(): void
    {
        $this->site();

        Http::fake([
            self::SITE.'/wp-json/wp/v2/media*' => Http::response(
                [$this->attachment(['id' => 1]), $this->attachment(['id' => 2, 'alt_text' => 'Set'])],
                headers: ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1'],
            ),
        ]);

        Livewire::actingAs($this->actor())
            ->test('site::media')
            ->assertOk()
            ->assertSee('have no alt text')
            ->assertSee('No alternative text');
    }

    public function test_the_page_reports_a_failure_instead_of_breaking(): void
    {
        $this->site();

        Http::fake([self::SITE.'/wp-json/wp/v2/media*' => Http::response([
            'code' => 'rest_forbidden',
            'message' => 'Sorry, you are not allowed to do that.',
        ], 401)]);

        Livewire::actingAs($this->actor())
            ->test('site::media')
            ->assertOk()
            ->assertSee('not allowed to do that');
    }

    public function test_the_page_explains_itself_with_nothing_connected(): void
    {
        Livewire::actingAs($this->actor())
            ->test('site::media')
            ->assertOk()
            ->assertSee('No website is connected');
    }
}
