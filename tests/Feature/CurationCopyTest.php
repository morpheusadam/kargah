<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Core\Contracts\TextGenerationFailed;
use Modules\Core\Contracts\TextGenerator;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\Assistant;
use Modules\Platform\Services\Assistant\FakeAssistantDriver;
use Modules\Platform\Support\AssistantDrivers;
use Modules\Social\Models\CurationWindow;
use Modules\Social\Services\Curation\Copy;
use Modules\Social\Services\Curation\Copywriter;
use Modules\Social\Services\Curation\NetworkBrief;
use Modules\Social\Services\Curation\Story;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * Writing the day's Persian copy, one network at a time, from one request.
 *
 * The tests that matter here are the ones about **budgets being enforced rather
 * than requested**. Everything the prompt asks for, a model may ignore: it will
 * write eleven hashtags when told three, invent a Persian tag that is spelled a
 * new way, wrap its JSON in a code fence, and overrun a character limit. None of
 * those is worth a second request against a daily free-tier quota, and all of them
 * are correctable in one direction — so they are corrected, and asserted.
 *
 * The hashtag ceiling is the one with a measured cost behind it: on LinkedIn, ten
 * or more hashtags risks a 30–50% reach penalty, and LinkedIn is the network the
 * owner named as most important. A prompt that politely asks for three is not a
 * mechanism.
 */
class CurationCopyTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistantDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 06:00:00');
        Http::preventStrayRequests();

        // The real drivers are never constructed: the registry hands out
        // factories, so swapping here means `GeminiDriver` does not exist for the
        // length of this test — which on a machine with no CA bundle is the
        // difference between a clean run and cURL error 60.
        $this->driver = new FakeAssistantDriver(AssistantDrivers::GEMINI);
        app(Assistant::class)->swap($this->driver);

        AssistantProvider::factory()->create([
            'name' => 'Gemini',
            'driver' => AssistantDrivers::GEMINI,
            'api_key' => 'AIza-test',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ───────────────────────────────────────── The contract, and its boundary

    public function test_a_feature_module_reaches_the_assistant_without_depending_on_platform(): void
    {
        $this->driver->willReply('چند کلمه فارسی');

        // The whole point of Modules\Core\Contracts\TextGenerator: Social asks
        // Core for text and never names Platform, which nothing may depend on.
        $text = app(TextGenerator::class)->generate('بنویس');

        $this->assertSame('چند کلمه فارسی', $text);
    }

    public function test_with_no_provider_configured_the_reason_says_where_to_fix_it(): void
    {
        AssistantProvider::query()->forceDelete();

        $reason = app(TextGenerator::class)->unavailableReason();

        // Read by whoever is looking at cron output at the point the channel went
        // quiet, so it has to name the page rather than the fault.
        $this->assertStringContainsString('/settings/assistant', (string) $reason);

        $this->expectException(TextGenerationFailed::class);
        app(TextGenerator::class)->generate('بنویس');
    }

    // ──────────────────────────────────────────────────────── Writing the copy

    public function test_one_request_produces_copy_for_every_network(): void
    {
        $this->driver->willReply($this->answer([
            'linkedin' => ['body' => 'یک تحلیل حرفه‌ای درباره‌ی حمله.', 'hashtags' => ['SECURITY', 'RANSOMWARE']],
            'instagram' => ['body' => 'حمله‌ی باج‌افزاری به Acme.', 'hashtags' => ['SECURITY', 'RANSOMWARE', 'MALWARE']],
        ]));

        $copy = $this->writer()->write($this->story(), 'The full article text.', [
            $this->brief(Networks::LINKEDIN),
            $this->brief(Networks::INSTAGRAM),
        ]);

        // Four networks would otherwise be four requests against a daily quota,
        // and four independently written readings of one story.
        $this->assertCount(1, $this->driver->requests);
        $this->assertArrayHasKey(Networks::LINKEDIN, $copy);
        $this->assertArrayHasKey(Networks::INSTAGRAM, $copy);
    }

    public function test_the_prompt_carries_each_networks_own_budget(): void
    {
        $this->driver->willReply($this->answer(['linkedin' => ['body' => 'متن', 'hashtags' => ['TECH']]]));

        $this->writer()->write($this->story(), null, [
            $this->brief(Networks::LINKEDIN),
            $this->brief(Networks::X),
        ]);

        $prompt = $this->driver->requests[0]['request']->messages[1]->content;

        // The numbers come from `curation_windows` rows the operator edits, so a
        // prompt with them hardcoded would drift from the settings page the first
        // time a slider moved.
        $this->assertStringContainsString('`linkedin`', $prompt);
        $this->assertStringContainsString('`x`', $prompt);
        $this->assertStringContainsString('۱۲۵ کاراکتر اول', $prompt);
    }

    public function test_a_story_the_model_judges_off_topic_is_not_published(): void
    {
        $this->driver->willReply('{"skip": true, "reason": "خبر ورزشی است."}');

        // A real editorial answer rather than a failure: the caller moves to the
        // next candidate, which is what `spare_candidates` is for.
        $this->assertNull($this->writer()->write($this->story(), null, [$this->brief(Networks::LINKEDIN)]));
    }

    // ───────────────────────────────────────────────── Budgets, enforced

    public function test_linkedin_never_receives_more_hashtags_than_its_budget(): void
    {
        $this->driver->willReply($this->answer([
            'linkedin' => [
                'body' => 'متن حرفه‌ای.',
                'hashtags' => ['SECURITY', 'RANSOMWARE', 'MALWARE', 'BREACH', 'PRIVACY', 'CLOUD', 'IRAN', 'VPN', 'LINUX', 'WINDOWS', 'APPLE'],
            ],
        ]));

        $copy = $this->writer()->write($this->story(), null, [$this->brief(Networks::LINKEDIN)]);

        // 🔴 Eleven hashtags is the measured penalty case. The prompt asks for
        // three; this asserts that asking is not the mechanism.
        $this->assertLessThanOrEqual(3, count($copy[Networks::LINKEDIN]->hashtags));
    }

    public function test_instagram_is_allowed_the_dense_tagging_linkedin_is_not(): void
    {
        $window = CurationWindow::query()->create([
            'network' => Networks::INSTAGRAM,
            'starts_at' => '19:00',
            'ends_at' => '23:00',
            'hashtags_min' => 18,
            'hashtags_max' => 25,
        ]);

        $this->driver->willReply($this->answer([
            'instagram' => [
                'body' => 'حمله‌ی باج‌افزاری.',
                'hashtags' => [...array_slice(array_keys(\Modules\Social\Services\Curation\Hashtags::SPECIFIC), 0, 20), 'SECURITY'],
            ],
        ]));

        $copy = $this->writer()->write($this->story(), null, [
            NetworkBrief::for(Networks::INSTAGRAM, $window, withImage: true),
        ]);

        // Both halves of the owner's instruction are obeyed, per network.
        $this->assertGreaterThanOrEqual(18, count($copy[Networks::INSTAGRAM]->hashtags));
    }

    public function test_a_hashtag_the_model_invented_is_dropped(): void
    {
        $this->driver->willReply($this->answer([
            'linkedin' => ['body' => 'متن', 'hashtags' => ['SECURITY', '#هوش‌مصنوعی_جدید', 'NOT_A_REAL_KEY']],
        ]));

        $copy = $this->writer()->write($this->story(), null, [$this->brief(Networks::LINKEDIN)]);

        // A Persian tag spelled a new way each day accumulates nothing: Telegram
        // and Instagram both match the exact string, so two spellings are two tags
        // with one post each.
        $this->assertSame(['#امنیت'], $copy[Networks::LINKEDIN]->hashtags);
    }

    public function test_a_latin_proper_noun_from_the_article_is_allowed_past_the_closed_vocabulary(): void
    {
        $this->driver->willReply($this->answer([
            'instagram' => ['body' => 'متن', 'hashtags' => ['SECURITY', '#Cloudflare', '#Fabricorp']],
        ]));

        $copy = $this->writer()->write(
            $this->story(),
            'The attackers routed traffic through Cloudflare before the breach.',
            [$this->brief(Networks::INSTAGRAM)],
        )[Networks::INSTAGRAM];

        // Cloudflare is in the article: one spelling, cannot drift, and it is how
        // an eighteen-tag caption is reachable without an eighteen-topic list.
        $this->assertContains('#Cloudflare', $copy->hashtags);
        // Fabricorp is not. Without that check the exception is a hole the size of
        // "any Latin word", and an invented company name gets published as a tag.
        $this->assertNotContains('#Fabricorp', $copy->hashtags);
    }

    public function test_copy_that_overran_is_trimmed_on_a_word_boundary_with_its_tags_intact(): void
    {
        $long = str_repeat('کلمه ', 200);

        $this->driver->willReply($this->answer([
            'x' => ['body' => $long, 'hashtags' => ['TECH']],
        ]));

        $copy = $this->writer()->write($this->story(), null, [$this->brief(Networks::X)])[Networks::X];

        $this->assertLessThanOrEqual(280, $copy->length());
        // The tags are a small deliberate set and dropping one changes what the
        // post is filed under; the body's last sentence is the least important
        // thing in it.
        $this->assertSame(['#تکنولوژی'], $copy->hashtags);
        $this->assertStringEndsWith('…', $copy->body);
    }

    public function test_telegram_is_written_to_the_caption_limit_when_a_picture_is_attached(): void
    {
        $withPicture = NetworkBrief::for(Networks::TELEGRAM, null, withImage: true);
        $withoutPicture = NetworkBrief::for(Networks::TELEGRAM, null, withImage: false);

        // 4,096 for a message, 1,024 for a caption. Writing 3,000 characters and
        // then attaching a picture is a 400 at send time, which is how this was
        // found the first time.
        $this->assertSame(1024, $withPicture->limit);
        $this->assertSame(4096, $withoutPicture->limit);
    }

    // ─────────────────────────────────────────── The shapes a model answers in

    public function test_json_wrapped_in_a_code_fence_is_still_read(): void
    {
        $this->driver->willReply("```json\n".$this->answer([
            'linkedin' => ['body' => 'متن فارسی', 'hashtags' => ['TECH']],
        ])."\n```");

        $copy = $this->writer()->write($this->story(), null, [$this->brief(Networks::LINKEDIN)]);

        // Models fence JSON whatever the prompt says, and it is not worth losing a
        // day's post over.
        $this->assertSame('متن فارسی', $copy[Networks::LINKEDIN]->body);
    }

    public function test_an_answer_that_is_not_json_at_all_fails_rather_than_being_guessed_at(): void
    {
        $this->driver->willReply('I am afraid I cannot help with that.');

        // The line between recovering a wrapper and inventing content: guessing
        // here would publish whatever the model happened to say.
        $this->expectException(TextGenerationFailed::class);

        $this->writer()->write($this->story(), null, [$this->brief(Networks::LINKEDIN)]);
    }

    public function test_a_network_the_model_forgot_is_simply_absent(): void
    {
        $this->driver->willReply($this->answer(['linkedin' => ['body' => 'متن', 'hashtags' => ['TECH']]]));

        $copy = $this->writer()->write($this->story(), null, [
            $this->brief(Networks::LINKEDIN),
            $this->brief(Networks::TELEGRAM),
        ]);

        // Better one real post than two, one of which is empty.
        $this->assertArrayHasKey(Networks::LINKEDIN, $copy);
        $this->assertArrayNotHasKey(Networks::TELEGRAM, $copy);
    }

    public function test_the_hashtags_sit_after_the_body_and_never_before_it(): void
    {
        $copy = new Copy(Networks::INSTAGRAM, 'خط اول با کلیدواژه.', ['#امنیت', '#باج_افزار']);

        // Instagram shows 125 characters before "more" and reads the opening as
        // search text; a tag block at the top spends the only part anybody sees.
        $this->assertStringStartsWith('خط اول', $copy->text());
        $this->assertStringEndsWith('#امنیت #باج_افزار', $copy->text());
    }

    // ───────────────────────────────────────────────────────────────── Helpers

    private function writer(): Copywriter
    {
        return new Copywriter(app(TextGenerator::class));
    }

    private function brief(string $network): NetworkBrief
    {
        return NetworkBrief::for($network, CurationWindow::query()->where('network', $network)->first(), withImage: false);
    }

    private function story(): Story
    {
        return new Story(
            uid: 'test-1',
            label: 'BleepingComputer',
            authority: 0.9,
            title: 'Ransomware group breaches Acme Corporation payroll',
            summary: 'The intrusion exposed payroll records.',
            url: 'https://bleeping.test/acme',
            publishedAt: Carbon::now()->subHour(),
            publisher: 'bleeping.test',
        );
    }

    /** @param  array<string, array{body: string, hashtags: list<string>}>  $networks */
    private function answer(array $networks): string
    {
        return json_encode(['skip' => false, 'networks' => $networks], JSON_UNESCAPED_UNICODE);
    }
}
