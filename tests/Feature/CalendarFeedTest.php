<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Services\BoardCalendar;
use Modules\Project\Services\CardService;
use Tests\TestCase;

/**
 * The `.ics` subscription endpoint.
 *
 * `signed` is the router's half of the authorisation: it rejects a request
 * whose query string does not match what was signed, so tampering with
 * anything — the board, the token — fails before `CalendarFeedController`
 * ever runs. `feed_token` is the other half, the one that makes the link
 * *revocable*: a signed URL has no server-side state and stays valid for
 * ever on its own, so regenerating the token is what lets an old, still
 * validly-signed URL stop working.
 */
class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private Board $board;

    private BoardList $backlog;

    private CardService $cards;

    protected function setUp(): void
    {
        parent::setUp();

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog', 'position' => 1]);
        $this->cards = app(CardService::class);

        // Deliberately no `actingAs()` anywhere in this file: `created_by` is
        // nullable and `auth()->id()` is simply null with nobody signed in,
        // which is the point — every request in this file, fixtures and all,
        // genuinely carries no session.
    }

    private function feedUrl(): string
    {
        return app(BoardCalendar::class)->feedUrl($this->board->fresh());
    }

    public function test_the_feed_returns_text_calendar_without_a_session(): void
    {
        $response = $this->get($this->feedUrl());

        $response->assertOk();
        $this->assertStringStartsWith('text/calendar', (string) $response->headers->get('content-type'));
    }

    public function test_an_unsigned_url_is_refused(): void
    {
        $token = app(BoardCalendar::class)->tokenFor($this->board);

        $this->get('/projects/'.$this->board->slug.'/feed.ics?token='.$token)
            ->assertForbidden();
    }

    public function test_a_tampered_url_is_refused(): void
    {
        $url = $this->feedUrl();

        // Change the token without re-signing: the signature no longer
        // matches the query string it was computed over.
        $tampered = preg_replace('/token=[^&]+/', 'token=something-else', $url);

        $this->assertNotSame($url, $tampered);

        $this->get($tampered)->assertForbidden();
    }

    public function test_a_revoked_link_is_refused_even_though_its_signature_is_still_valid(): void
    {
        $url = $this->feedUrl();

        // The signature was genuinely computed over this exact query string,
        // so `signed` alone would still accept it. Only the token comparison
        // catches this.
        app(BoardCalendar::class)->regenerateToken($this->board->fresh());

        $this->get($url)->assertForbidden();
    }

    public function test_a_freshly_signed_url_after_regeneration_works(): void
    {
        app(BoardCalendar::class)->regenerateToken($this->board->fresh());

        $this->get($this->feedUrl())->assertOk();
    }

    public function test_two_generations_of_an_unchanged_feed_are_byte_identical_with_a_matching_etag(): void
    {
        $card = $this->cards->append($this->backlog, 'Send the retainer proposal');
        $card->forceFill(['due_on' => now()->addDays(5)->toDateString()])->save();

        $url = $this->feedUrl();

        $first = $this->get($url);
        $second = $this->get($url);

        $first->assertOk();
        $second->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertNotEmpty($first->headers->get('ETag'));
        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function test_a_mirrored_card_appears_once_in_the_feed(): void
    {
        $doing = BoardList::factory()->for($this->board)->create(['name' => 'Doing', 'position' => 2]);

        $card = $this->cards->append($this->backlog, 'Shared onboarding checklist');
        $card->forceFill(['due_on' => now()->addDays(2)->toDateString()])->save();
        $this->cards->mirror($card, $doing);

        $body = $this->get($this->feedUrl())->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, 'SUMMARY:Shared onboarding checklist'));
        $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'));
    }

    public function test_the_uid_is_derived_from_the_card_id_not_the_placement(): void
    {
        $card = $this->cards->append($this->backlog, 'Stable UID card');
        $card->forceFill(['due_on' => now()->addDay()->toDateString()])->save();

        $body = $this->get($this->feedUrl())->assertOk()->getContent();

        $this->assertStringContainsString('UID:card-'.$card->id.'@', $body);
    }

    public function test_an_archived_card_does_not_appear_on_the_feed(): void
    {
        $card = $this->cards->append($this->backlog, 'Archived card');
        $card->forceFill(['due_on' => now()->addDay()->toDateString(), 'archived_at' => now()])->save();

        $body = $this->get($this->feedUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('Archived card', $body);
    }

    public function test_a_card_with_no_due_date_does_not_appear_on_the_feed(): void
    {
        $this->cards->append($this->backlog, 'Undated card');

        $body = $this->get($this->feedUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('Undated card', $body);
    }

    public function test_a_conditional_request_with_a_matching_etag_gets_a_304(): void
    {
        $card = $this->cards->append($this->backlog, 'Conditional request card');
        $card->forceFill(['due_on' => now()->addDay()->toDateString()])->save();

        $url = $this->feedUrl();
        $first = $this->get($url);
        $etag = $first->headers->get('ETag');

        $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
    }
}
