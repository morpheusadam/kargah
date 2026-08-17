<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Social\Services\Curation\ArticleText;
use Modules\Social\Services\Curation\Cover;
use Modules\Social\Services\Curation\Story;
use Tests\TestCase;

/**
 * The article's own words, and a picture Instagram will accept.
 *
 * These two are the difference between a post that reads like a rewritten
 * headline with no image and one worth publishing. Neither is decorative:
 * extraction is the largest single lever on summary quality, and Instagram has no
 * text-only post at all, so a story with no usable picture is a story Instagram
 * does not get.
 *
 * The picture tests are mostly about **refusing the wrong image quietly**. A
 * 56-pixel logo served as `image/png` is entirely valid and entirely unpublishable,
 * and it is what a great many sites return first.
 */
class CurationMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 06:00:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────── Article text

    public function test_the_article_is_taken_and_the_furniture_around_it_is_not(): void
    {
        $html = <<<'HTML'
            <html><body>
              <nav><p>Home</p><p>Security</p><p>Subscribe to our newsletter today please</p></nav>
              <aside><p>Related: another story entirely about something else here</p></aside>
              <article>
                <p>A ransomware group breached the payroll provider on Tuesday morning, according to a filing.</p>
                <p>The intrusion exposed records belonging to several thousand employees across four countries.</p>
                <p>Investigators said the initial access came through an unpatched appliance at the network edge.</p>
                <p>The company said it had rotated every credential and engaged an external response team.</p>
                <p>Regulators in two jurisdictions have asked for a timeline of the incident and its disclosure.</p>
              </article>
              <footer><p>Copyright the publisher, all rights reserved worldwide forever</p></footer>
            </body></html>
            HTML;

        $text = (new ArticleText)->extract($html);

        $this->assertStringContainsString('ransomware group breached', $text);
        $this->assertStringNotContainsString('newsletter', $text);
        $this->assertStringNotContainsString('Related:', $text);
        $this->assertStringNotContainsString('Copyright', $text);
    }

    public function test_a_sidebar_of_many_short_teasers_does_not_beat_the_article(): void
    {
        $teasers = str_repeat('<p>A short teaser line here</p>', 30);

        $html = '<html><body><div id="side">'.$teasers.'</div>'
            .'<div id="story">'
            .'<p>'.str_repeat('The substantive reporting continues at length in this paragraph. ', 6).'</p>'
            .'<p>'.str_repeat('And it continues further in a second paragraph of real prose. ', 6).'</p>'
            .'</div></body></html>';

        $text = (new ArticleText)->extract($html);

        // Density by text length, not by paragraph count: a sidebar has more
        // `<p>` elements than an article and a fraction of the words.
        $this->assertStringContainsString('substantive reporting', $text);
        $this->assertStringNotContainsString('short teaser', $text);
    }

    public function test_a_paywall_page_reads_as_nothing_rather_than_as_an_article(): void
    {
        $html = '<html><body><article><p>Subscribe to continue reading this article.</p></article></body></html>';

        // The correct outcome: the caller falls back to the feed's standfirst. A
        // summary written from a paywall notice is not a shorter post, it is a
        // wrong one.
        $this->assertNull((new ArticleText)->extract($html));
    }

    public function test_bylines_and_share_prompts_are_too_short_to_count_as_prose(): void
    {
        $html = '<html><body><article>'
            .'<p>By Our Correspondent</p><p>Share this</p><p>3 min read</p>'
            .'<p>'.str_repeat('The actual reporting is in this paragraph and it runs on. ', 8).'</p>'
            .'</article></body></html>';

        $text = (new ArticleText)->extract($html);

        $this->assertStringNotContainsString('Share this', $text);
        $this->assertStringNotContainsString('min read', $text);
    }

    // ────────────────────────────────────────────────────────────── The cover

    public function test_the_picture_the_publisher_chose_is_preferred_over_the_ones_in_the_body(): void
    {
        Http::fake([
            'cdn.test/og.jpg' => Http::response($this->jpeg(1200, 800), 200, ['content-type' => 'image/jpeg']),
            'cdn.test/body.jpg' => Http::response($this->jpeg(1200, 800), 200, ['content-type' => 'image/jpeg']),
        ]);

        $html = '<html><head><meta property="og:image" content="https://cdn.test/og.jpg"></head>'
            .'<body><img src="https://cdn.test/body.jpg"></body></html>';

        $cover = (new Cover)->forStory($this->story(), $html);

        $this->assertNotNull($cover);
        // `og:image` is the picture the publisher wants shown when the article is
        // shared, which is nearly always the best one on the page.
        Http::assertSent(fn ($request): bool => $request->url() === 'https://cdn.test/og.jpg');
    }

    public function test_a_logo_sized_image_is_refused_and_the_next_candidate_is_tried(): void
    {
        Http::fake([
            'cdn.test/logo.png' => Http::response($this->jpeg(56, 56), 200, ['content-type' => 'image/png']),
            'cdn.test/real.jpg' => Http::response($this->jpeg(1200, 800), 200, ['content-type' => 'image/jpeg']),
        ]);

        $html = '<html><head><meta property="og:image" content="https://cdn.test/logo.png"></head>'
            .'<body><img src="https://cdn.test/real.jpg"></body></html>';

        $cover = (new Cover)->forStory($this->story(), $html);

        // A 56-pixel icon served as image/png is entirely valid and entirely
        // unpublishable. Measured from the decoded bytes, never trusted from the
        // content type.
        $this->assertNotNull($cover);
        $this->assertSame(1200, $cover['width']);
    }

    public function test_everything_becomes_a_jpeg_because_instagram_takes_nothing_else(): void
    {
        Http::fake(['cdn.test/*' => Http::response($this->png(1000, 800), 200, ['content-type' => 'image/png'])]);

        $cover = (new Cover)->forStory($this->story(image: 'https://cdn.test/a.png'));

        // Its container refuses a PNG with an error naming neither the file nor
        // the reason, so one format for every network is one thing to get wrong
        // instead of five.
        $this->assertSame('image/jpeg', $cover['mime']);
        $this->assertStringStartsWith("\xFF\xD8", $cover['contents']);
    }

    public function test_a_phone_screenshot_is_padded_into_the_proportions_instagram_accepts(): void
    {
        $tall = imagecreatetruecolor(1080, 2400);

        $padded = (new Cover)->padded($tall);

        $ratio = imagesx($padded) / imagesy($padded);

        // 1080×2400 is a ratio of 0.45 against a feed minimum of 0.8. The
        // alternatives were posting nothing that day or letting Instagram crop it
        // wherever it liked. Nothing is drawn over the picture and none of it is
        // hidden — the previous compositing cover stays removed.
        $this->assertGreaterThanOrEqual(Cover::MIN_RATIO, $ratio);
        $this->assertSame(2400, imagesy($padded));

        imagedestroy($tall);
        imagedestroy($padded);
    }

    public function test_a_picture_already_in_range_is_left_exactly_as_it_is(): void
    {
        $fine = imagecreatetruecolor(1200, 800);

        // The common case, and not worth copying a megabyte for.
        $this->assertSame($fine, (new Cover)->padded($fine));

        imagedestroy($fine);
    }

    public function test_a_story_with_no_usable_picture_anywhere_returns_nothing(): void
    {
        Http::fake(['cdn.test/*' => Http::response('not an image', 404)]);

        // An ordinary answer rather than a failure: every network except
        // Instagram publishes perfectly well without one.
        $this->assertNull((new Cover)->forStory($this->story(image: 'https://cdn.test/gone.jpg')));
    }

    // ───────────────────────────────────────────────────────────────── Helpers

    private function story(?string $image = null): Story
    {
        return new Story(
            uid: 'test-1',
            label: 'BleepingComputer',
            authority: 0.9,
            title: 'Ransomware group breaches Acme Corporation payroll',
            summary: 'The intrusion exposed payroll records.',
            url: 'https://bleeping.test/acme',
            publishedAt: Carbon::now()->subHour(),
            imageUrl: $image,
            publisher: 'bleeping.test',
        );
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
