<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignLink;
use Modules\Mailbox\Models\CampaignLinkClick;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Services\Delivery\MessageBuilder;
use Modules\Mailbox\Services\Delivery\Tracking;
use Modules\Mailbox\Support\Tokens;
use Tests\TestCase;

/**
 * Open and click tracking, exercised through real requests.
 *
 * The two bugs this module has actually shipped both lived behind a green
 * suite: `Mail::fake()` records a mailable without calling `envelope()`, and
 * Laravel skips CSRF under `runningUnitTests()`. Neither shortcut is taken here.
 * The bodies are built by `MessageBuilder` itself rather than assembled by the
 * test, and every assertion about a route goes through `$this->get()` so the
 * `signed` middleware is the real one — which matters, because the signature is
 * half of what stops these two endpoints being forgeable.
 *
 * The property worth the most attention is the last group: **the redirect can
 * only ever reach a URL that is already a row in `campaign_links`.** A bare
 * `?url=` would make the sending domain an open redirect, and the tests below
 * are what would fail if somebody ever added one.
 */
class CampaignTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 10:00:00');

        config([
            'mailbox.tracking.opens' => true,
            'mailbox.tracking.clicks' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | What the message carries
    |--------------------------------------------------------------------------
    */

    public function test_every_link_is_registered_before_it_is_rewritten(): void
    {
        [$campaign, $recipient] = $this->campaign(
            '<p><a href="https://lavzen.com/pricing">Pricing</a> and '
            .'<a href="https://lavzen.com/contact">Contact</a></p>{{unsubscribe_url}}'
        );

        $html = $this->build($campaign, $recipient);

        $registered = CampaignLink::query()->where('campaign_id', $campaign->id)->pluck('url')->all();

        sort($registered);

        $this->assertSame(['https://lavzen.com/contact', 'https://lavzen.com/pricing'], $registered);

        // The destinations are gone from the body: what is left is Kargah's own
        // redirect, which is what makes the row the only way to reach them.
        $this->assertStringNotContainsString('https://lavzen.com/pricing', $html);
        $this->assertStringContainsString('/mail/c/', $html);
    }

    public function test_the_same_link_across_a_chunk_registers_once(): void
    {
        [$campaign, $first] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $second = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => 'grace@example.com',
            'status' => CampaignRecipient::PENDING,
        ]);

        $builder = $this->app->make(MessageBuilder::class);
        $provider = $campaign->provider;

        $firstHtml = $builder->build($campaign, $first, $provider)->html;
        $secondHtml = $builder->build($campaign, $second, $provider)->html;

        $this->assertSame(1, CampaignLink::query()->where('campaign_id', $campaign->id)->count());

        // One row, but a different URL each — the link is shared and the
        // recipient half of the token is not.
        $this->assertNotSame($firstHtml, $secondHtml);
    }

    public function test_the_pixel_is_appended_with_the_css_that_keeps_it_invisible(): void
    {
        [$campaign, $recipient] = $this->campaign('<html><body><p>Hello {{unsubscribe_url}}</p></body></html>');

        $html = $this->build($campaign, $recipient);

        $this->assertStringContainsString('display:none;max-height:0;max-width:0;opacity:0;', $html);
        $this->assertStringContainsString('width="1" height="1"', $html);

        // Inside the document rather than after it, because a few clients drop
        // what follows the closing tag when they sanitise a message.
        $this->assertStringContainsString('</p><img src=', $html);
        $this->assertStringEndsWith('></body></html>', trim($html));
    }

    public function test_the_unsubscribe_link_and_the_other_schemes_are_left_alone(): void
    {
        [$campaign, $recipient] = $this->campaign(
            '<a href="{{unsubscribe_url}}">Unsubscribe</a>'
            .'<a href="mailto:info@lavzen.com">Mail us</a>'
            .'<a href="tel:+441234567890">Ring us</a>'
            .'<a href="#top">Top</a>'
            .'<a href="javascript:alert(1)">No</a>'
        );

        $html = $this->build($campaign, $recipient);

        $this->assertSame(0, CampaignLink::query()->count());

        $this->assertStringContainsString('href="mailto:info@lavzen.com"', $html);
        $this->assertStringContainsString('href="tel:+441234567890"', $html);
        $this->assertStringContainsString('href="#top"', $html);

        // The one that matters: a `javascript:` href must never become a row,
        // because a row is somewhere the redirect is willing to send a person.
        $this->assertStringContainsString('href="javascript:alert(1)"', $html);

        // The one-click link is a real signed URL by now, and not behind a
        // redirect of ours — a mail client's automated check follows it itself.
        $this->assertStringContainsString('/mail/unsubscribe/', $html);
        $this->assertStringNotContainsString('/mail/c/', $html);
    }

    public function test_the_plain_text_body_is_never_rewritten(): void
    {
        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $campaign->forceFill([
            'body_text' => "Our pricing is at https://lavzen.com/pricing\n{{unsubscribe_url}}",
        ])->save();

        $message = $this->app->make(MessageBuilder::class)->build($campaign, $recipient, $campaign->provider);

        $this->assertStringContainsString('https://lavzen.com/pricing', $message->text);
        $this->assertStringNotContainsString('/mail/c/', (string) $message->text);
        $this->assertStringNotContainsString('<img', (string) $message->text);
    }

    public function test_the_entity_encoded_query_is_stored_decoded(): void
    {
        [$campaign, $recipient] = $this->campaign(
            '<a href="https://lavzen.com/x?a=1&amp;b=2">Go</a>{{unsubscribe_url}}'
        );

        $this->build($campaign, $recipient);

        // The row holds where the person is actually going. Storing the encoded
        // form would send them to a different page.
        $this->assertSame('https://lavzen.com/x?a=1&b=2', CampaignLink::query()->value('url'));
    }

    public function test_switching_tracking_off_leaves_the_body_as_written(): void
    {
        config(['mailbox.tracking.opens' => false, 'mailbox.tracking.clicks' => false]);

        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $html = $this->build($campaign, $recipient);

        $this->assertStringContainsString('href="https://lavzen.com/pricing"', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame(0, CampaignLink::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | The open pixel
    |--------------------------------------------------------------------------
    */

    public function test_the_pixel_records_an_open_and_returns_a_gif(): void
    {
        [, $recipient] = $this->campaign('<p>Hello</p>{{unsubscribe_url}}');

        $response = $this->get($this->app->make(Tracking::class)->openUrl($recipient));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/gif');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('GIF89a', substr((string) $response->getContent(), 0, 6));

        $recipient->refresh();

        $this->assertSame(1, $recipient->open_count);
        $this->assertTrue($recipient->hasOpened());
        $this->assertSame('2026-08-07 10:00:00', $recipient->opened_at->toDateTimeString());
    }

    public function test_a_second_open_counts_again_but_keeps_the_first_time(): void
    {
        [, $recipient] = $this->campaign('<p>Hello</p>{{unsubscribe_url}}');

        $url = $this->app->make(Tracking::class)->openUrl($recipient);

        $this->get($url)->assertOk();

        Carbon::setTestNow('2026-08-09 18:30:00');

        $this->get($url)->assertOk();

        $recipient->refresh();

        $this->assertSame(2, $recipient->open_count);
        $this->assertSame('2026-08-07 10:00:00', $recipient->opened_at->toDateTimeString());
        $this->assertSame('2026-08-09 18:30:00', $recipient->last_opened_at->toDateTimeString());
    }

    public function test_an_unsigned_pixel_url_records_nothing_and_still_returns_the_image(): void
    {
        [, $recipient] = $this->campaign('<p>Hello</p>{{unsubscribe_url}}');

        $unsigned = route('mail.open', ['token' => Tokens::for(Tokens::OPEN, (int) $recipient->id)]);

        $this->get($unsigned)->assertForbidden();

        $this->assertSame(0, $recipient->refresh()->open_count);
    }

    public function test_a_forged_pixel_token_records_nothing_and_still_returns_the_image(): void
    {
        [, $recipient] = $this->campaign('<p>Hello</p>{{unsubscribe_url}}');

        // Signed by Laravel but carrying a token Kargah never minted: the
        // signature alone is not what identifies a recipient.
        $forged = URL::signedRoute('mail.open', ['token' => '1-0123456789abcdef0123']);

        $response = $this->get($forged);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/gif');

        $this->assertSame(0, $recipient->refresh()->open_count);
    }

    public function test_the_unsubscribe_token_cannot_be_replayed_as_an_open(): void
    {
        [, $recipient] = $this->campaign('<p>Hello</p>{{unsubscribe_url}}');

        $recipient->ensureTokens();

        $this->get(URL::signedRoute('mail.open', ['token' => $recipient->unsubscribe_token]))->assertOk();

        $this->assertSame(0, $recipient->refresh()->open_count);
    }

    /*
    |--------------------------------------------------------------------------
    | The click redirect
    |--------------------------------------------------------------------------
    */

    public function test_a_click_redirects_to_the_registered_url_and_is_counted(): void
    {
        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $this->build($campaign, $recipient);

        $link = CampaignLink::query()->firstOrFail();

        $response = $this->get($this->app->make(Tracking::class)->clickUrl($recipient, $link));

        $response->assertRedirect('https://lavzen.com/pricing');

        $recipient->refresh();

        $this->assertSame(1, $recipient->click_count);
        $this->assertTrue($recipient->hasClicked());

        // A click is not an open. Images are blocked by default in most
        // clients, so counting one as the other would make the open rate a
        // number that cannot be compared with anything.
        $this->assertSame(0, $recipient->open_count);
        $this->assertFalse($recipient->hasOpened());

        $tally = CampaignLinkClick::query()->firstOrFail();

        $this->assertSame(1, $tally->clicks);
        $this->assertSame((int) $link->id, (int) $tally->campaign_link_id);
        $this->assertSame((int) $recipient->id, (int) $tally->campaign_recipient_id);
    }

    public function test_repeated_clicks_add_to_one_row_rather_than_making_more(): void
    {
        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $this->build($campaign, $recipient);

        $url = $this->app->make(Tracking::class)->clickUrl($recipient, CampaignLink::query()->firstOrFail());

        $this->get($url);

        Carbon::setTestNow('2026-08-08 09:00:00');

        $this->get($url);
        $this->get($url);

        $this->assertSame(1, CampaignLinkClick::query()->count());

        $tally = CampaignLinkClick::query()->firstOrFail();

        $this->assertSame(3, $tally->clicks);
        $this->assertSame('2026-08-07 10:00:00', $tally->first_clicked_at->toDateTimeString());
        $this->assertSame('2026-08-08 09:00:00', $tally->last_clicked_at->toDateTimeString());

        $this->assertSame(3, $recipient->refresh()->click_count);
    }

    /**
     * The open-redirect test, and the reason this endpoint takes an id.
     *
     * There is no parameter to put a URL in, so the only way to try is to name a
     * link that does not exist. The answer has to be somewhere on this install
     * and never somewhere the request asked for.
     */
    public function test_an_unregistered_link_cannot_be_redirected_to(): void
    {
        [, $recipient] = $this->campaign('<p>Hello</p>{{unsubscribe_url}}');

        $response = $this->get(URL::signedRoute('mail.click', [
            'token' => Tokens::for(Tokens::CLICK, (int) $recipient->id),
            'link' => Tokens::for(Tokens::LINK, 999),
        ]));

        $response->assertRedirect(config('app.url'));

        $this->assertSame(0, $recipient->refresh()->click_count);
        $this->assertSame(0, CampaignLinkClick::query()->count());
    }

    public function test_a_forged_link_token_cannot_be_redirected_to(): void
    {
        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $this->build($campaign, $recipient);

        $link = CampaignLink::query()->firstOrFail();

        // The right row id, signed by Laravel, but the link half of the token
        // was made up rather than minted here.
        $response = $this->get(URL::signedRoute('mail.click', [
            'token' => Tokens::for(Tokens::CLICK, (int) $recipient->id),
            'link' => base_convert((string) $link->id, 10, 36).'-0123456789abcdef0123',
        ]));

        $response->assertRedirect(config('app.url'));

        $this->assertSame(0, CampaignLinkClick::query()->count());
    }

    public function test_an_unsigned_click_url_is_refused(): void
    {
        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $this->build($campaign, $recipient);

        $link = CampaignLink::query()->firstOrFail();

        $this->get(route('mail.click', [
            'token' => Tokens::for(Tokens::CLICK, (int) $recipient->id),
            'link' => Tokens::for(Tokens::LINK, (int) $link->id),
        ]))->assertForbidden();

        $this->assertSame(0, CampaignLinkClick::query()->count());
    }

    /**
     * A seed test deletes its throwaway recipient before the message is sent, so
     * the person who receives it holds links naming a row that is gone. They
     * still have to work — the destination is a property of the link, and the
     * recipient is only who to attribute it to.
     */
    public function test_a_click_from_a_deleted_recipient_still_arrives(): void
    {
        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $this->build($campaign, $recipient);

        $url = $this->app->make(Tracking::class)->clickUrl($recipient, CampaignLink::query()->firstOrFail());

        $recipient->delete();

        $this->get($url)->assertRedirect('https://lavzen.com/pricing');

        $this->assertSame(0, CampaignLinkClick::query()->count());
    }

    public function test_a_recipient_cannot_be_counted_against_another_campaigns_link(): void
    {
        [$mine, $recipient] = $this->campaign('<p>Hello</p>{{unsubscribe_url}}');
        [$theirs] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}', 'other@example.com');

        $link = CampaignLink::query()->create([
            'campaign_id' => $theirs->id,
            'url' => 'https://lavzen.com/pricing',
            'url_hash' => CampaignLink::fingerprint('https://lavzen.com/pricing'),
        ]);

        $this->assertNotSame((int) $mine->id, (int) $theirs->id);

        $response = $this->get($this->app->make(Tracking::class)->clickUrl($recipient, $link));

        // Still sent to a registered destination — the person is not punished
        // for a URL somebody else assembled — but attributed to nobody.
        $response->assertRedirect('https://lavzen.com/pricing');

        $this->assertSame(0, $recipient->refresh()->click_count);
        $this->assertSame(0, CampaignLinkClick::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | The report
    |--------------------------------------------------------------------------
    */

    public function test_the_report_counts_people_and_times_separately(): void
    {
        [$campaign, $ada] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $grace = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => 'grace@example.com',
            'status' => CampaignRecipient::SENT,
        ]);

        $this->build($campaign, $ada);

        $tracking = $this->app->make(Tracking::class);
        $link = CampaignLink::query()->firstOrFail();

        $this->get($tracking->openUrl($ada));
        $this->get($tracking->openUrl($ada));
        $this->get($tracking->openUrl($grace));

        $this->get($tracking->clickUrl($ada, $link));
        $this->get($tracking->clickUrl($ada, $link));

        $this->assertSame(
            ['opened' => 2, 'clicked' => 1, 'opens' => 3, 'clicks' => 2],
            $campaign->trackingCounts(),
        );

        $rows = $campaign->linkBreakdown();

        $this->assertCount(1, $rows);
        $this->assertSame('https://lavzen.com/pricing', $rows[0]['url']);
        $this->assertSame(1, $rows[0]['people']);
        $this->assertSame(2, $rows[0]['clicks']);
    }

    /** And the report page itself, because a figure nobody can see is not a report. */
    public function test_the_report_page_shows_the_links_and_what_was_done_with_them(): void
    {
        [$campaign, $recipient] = $this->campaign('<a href="https://lavzen.com/pricing">Pricing</a>{{unsubscribe_url}}');

        $this->build($campaign, $recipient);

        $tracking = $this->app->make(Tracking::class);

        $this->get($tracking->openUrl($recipient));
        $this->get($tracking->clickUrl($recipient, CampaignLink::query()->firstOrFail()));

        $this->actingAs(User::factory()->create());

        Livewire::test('mailbox::campaign-show', ['campaign' => (string) $campaign->id])
            ->assertSee('Opened')
            ->assertSee('Clicked')
            ->assertSee('Links followed')
            ->assertSee('https://lavzen.com/pricing');
    }

    public function test_a_link_nobody_followed_is_still_on_the_report(): void
    {
        [$campaign, $recipient] = $this->campaign(
            '<a href="https://lavzen.com/pricing">Pricing</a><a href="https://lavzen.com/quiet">Quiet</a>{{unsubscribe_url}}'
        );

        $this->build($campaign, $recipient);

        $rows = $campaign->linkBreakdown();

        $this->assertCount(2, $rows);
        $this->assertSame([0, 0], array_column($rows, 'clicks'));
        $this->assertSame([0, 0], array_column($rows, 'people'));
    }

    /*
    |--------------------------------------------------------------------------
    | Scaffolding
    |--------------------------------------------------------------------------
    */

    /** The HTML `MessageBuilder` would hand a driver. */
    private function build(Campaign $campaign, CampaignRecipient $recipient): string
    {
        return (string) $this->app->make(MessageBuilder::class)
            ->build($campaign, $recipient, $campaign->provider)
            ->html;
    }

    /**
     * A campaign with one recipient on it, and the provider it would go through.
     *
     * @return array{0: Campaign, 1: CampaignRecipient}
     */
    private function campaign(string $html, string $email = 'ada@example.com'): array
    {
        $provider = DeliveryProvider::factory()->create([
            'from_email' => 'info@lavzen.com',
            'from_name' => 'Lavzen',
            'sending_domain' => 'lavzen.com',
        ]);

        $campaign = Campaign::factory()->create([
            'delivery_provider_id' => $provider->id,
            'subject' => 'What we have been up to',
            'body_html' => $html,
            'body_text' => 'What we have been up to. {{unsubscribe_url}}',
            'status' => Campaign::SENDING,
        ]);

        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => $email,
            'name' => 'Ada Lovelace',
            'status' => CampaignRecipient::SENT,
        ]);

        return [$campaign->load('provider'), $recipient];
    }
}
