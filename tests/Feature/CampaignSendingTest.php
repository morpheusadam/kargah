<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Modules\Mailbox\Jobs\SendCampaignChunk;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\Contact;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Services\Delivery\CampaignSender;
use Modules\Mailbox\Services\Delivery\Delivery;
use Modules\Mailbox\Services\Delivery\DeliveryEvent;
use Modules\Mailbox\Services\Delivery\FakeMailer;
use Modules\Mailbox\Services\Delivery\MessageBuilder;
use Modules\Mailbox\Services\Delivery\PreFlight;
use Modules\Mailbox\Services\Delivery\WorkerKilled;
use Modules\Mailbox\Support\Senders;
use Modules\Mailbox\Support\Tokens;
use Tests\TestCase;

/**
 * Sending mail from a host that will kill you.
 *
 * The four properties this file exists to prove are the four acceptance
 * criteria in project-guaid/spec/05-build-order.md, and each has a test named
 * after it:
 *
 * 1. A 500-recipient campaign completes across cron ticks with no recipient
 *    sent twice, proven by killing the worker mid-run.
 * 2. A hard bounce on one provider blocks that address on every provider.
 * 3. Exhausting one provider's quota moves the remainder to the next, and the
 *    report shows the split.
 * 4. The pre-flight refuses to send when SPF, DKIM or unsubscribe are missing.
 *
 * The ticks are real. Each one runs the scheduled command and then drains the
 * queue with `--stop-when-empty`, which is exactly what `routes/console.php`
 * puts on cron — no daemon anywhere, and a job that dies takes only itself
 * down. A `sync` queue would have hidden the one thing worth checking, which is
 * that the command and the work are separate.
 *
 * **Nothing here can reach a mail server.** Every driver is swapped for
 * `FakeMailer` through the `Delivery` registry, which holds factories rather
 * than instances, so the real drivers are never constructed and no Symfony
 * transport for them is ever built. `Mail::fake()` is on top of that as a second
 * floor, and there are no credentials on a developer's machine to send with in
 * any case.
 */
class CampaignSendingTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailer $brevo;

    private FakeMailer $mailgun;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-01 09:00:00');

        Mail::fake();

        $this->brevo = new FakeMailer(Senders::BREVO);
        $this->mailgun = new FakeMailer(Senders::MAILGUN);

        $delivery = $this->app->make(Delivery::class);
        $delivery->swap($this->brevo);
        $delivery->swap($this->mailgun);

        config([
            'mailbox.sending.chunk_size' => 50,
            'mailbox.sending.chunks_per_tick' => 1,
            'mailbox.sending.campaigns_per_tick' => 5,

            // A tick is a cron minute: the scheduler dispatches, a worker
            // drains what is waiting and exits. Nothing stays alive between
            // ticks, which is the whole hosting constraint.
            'queue.default' => 'database',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* Fixtures ---------------------------------------------------------------- */

    private function provider(string $driver = Senders::BREVO, array $attributes = []): DeliveryProvider
    {
        return DeliveryProvider::factory()->driver($driver)->ready()->create($attributes);
    }

    private function campaign(DeliveryProvider $provider, int $recipients = 3, array $attributes = []): Campaign
    {
        $campaign = Campaign::factory()->through($provider)->sending()->create($attributes);

        CampaignRecipient::factory()->count($recipients)->forCampaign($campaign)->create();

        return $campaign->refresh();
    }

    /** One cron minute: the scheduler dispatches, then a worker drains and exits. */
    private function tick(): void
    {
        Artisan::call('mailbox:dispatch-sends');

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--tries' => 1,
        ]);
    }

    /** One chunk, run here rather than on a worker, for the tests that need to watch it. */
    private function chunk(Campaign $campaign, ?int $limit = null): void
    {
        (new SendCampaignChunk($campaign->id, $limit))->handle($this->app->make(CampaignSender::class));
    }

    /** How many addresses this campaign sent to more than once, according to the driver. */
    private function duplicateSends(): int
    {
        $addresses = array_merge($this->brevo->recipients(), $this->mailgun->recipients());

        return count($addresses) - count(array_unique($addresses));
    }

    /* 1 — no recipient sent twice, proven by killing the worker mid-run -------- */

    /**
     * A 500-recipient campaign completes across cron ticks with no recipient
     * sent twice, proven by killing the worker mid-run.
     *
     * The kill is real: `FakeMailer::dieAfter()` raises a `WorkerKilled`, which
     * is an `Error` and therefore one of the two things `CampaignSender`
     * deliberately does not catch. It lands at the top of a recipient, before
     * that recipient has been claimed — which is the state a stopped worker
     * actually leaves, because the claim is taken immediately before the send
     * and nothing sits between the two.
     *
     * The proof is the driver's own record rather than the rows: a row that
     * says `sent` cannot tell a single send from a resend that happened to
     * write the same values, and `FakeMailer` can.
     */
    public function test_a_500_recipient_campaign_completes_across_cron_ticks_with_no_recipient_sent_twice(): void
    {
        $provider = $this->provider();
        $campaign = $this->campaign($provider, 500);

        $this->tick();
        $this->tick();
        $this->tick();

        $this->assertSame(150, $this->brevo->sendCount(), 'Three ticks of fifty should have sent 150.');
        $this->assertSame(350, $campaign->refresh()->outstandingCount());

        // The worker is killed twenty messages into the fourth chunk.
        $this->brevo->dieAfter(170);

        try {
            $this->chunk($campaign);

            $this->fail('The worker should have been stopped mid-chunk.');
        } catch (WorkerKilled) {
            // Exactly what a SIGKILL looks like from the campaign's side.
        }

        $this->assertSame(170, $this->brevo->sendCount());
        $this->assertSame(
            330,
            $campaign->refresh()->outstandingCount(),
            'Everything the stopped worker had not started must still be pending.',
        );

        // The next cron tick starts a fresh worker and picks up where it left off.
        $this->brevo->revive();

        for ($i = 0; $i < 12 && $campaign->refresh()->outstandingCount() > 0; $i++) {
            $this->tick();
        }

        $this->assertSame(0, $campaign->refresh()->outstandingCount(), 'The campaign should have drained.');

        $this->assertSame(500, $this->brevo->sendCount(), 'Exactly one message per recipient left the process.');
        $this->assertSame(0, $this->duplicateSends(), 'No address may appear twice in what was actually sent.');

        $this->assertSame(500, $campaign->recipients()->where('status', CampaignRecipient::SENT)->count());
        $this->assertSame(0, $campaign->recipients()->where('status', '!=', CampaignRecipient::SENT)->count());

        $this->assertSame(Campaign::SENT, $campaign->refresh()->status);
        $this->assertSame(500, $campaign->sent_count);
    }

    /**
     * Running the same chunk twice changes nothing the second time.
     *
     * The property every job in Kargah has to have, and here it is not achieved
     * by remembering anything: the second run claims nothing because a `sent`
     * row cannot match `WHERE status = 'pending'`.
     */
    public function test_running_the_same_chunk_twice_sends_nothing_the_second_time(): void
    {
        $campaign = $this->campaign($this->provider(), 5);

        $this->chunk($campaign);

        $this->assertSame(5, $this->brevo->sendCount());

        $before = $campaign->refresh()->recipients()->orderBy('id')->get(['id', 'status', 'sent_at', 'message_id', 'updated_at']);
        $campaignBefore = $campaign->refresh()->updated_at;

        Carbon::setTestNow(now()->addMinutes(5));

        $this->chunk($campaign);

        $this->assertSame(5, $this->brevo->sendCount(), 'The second run must not send anything.');

        $after = $campaign->refresh()->recipients()->orderBy('id')->get(['id', 'status', 'sent_at', 'message_id', 'updated_at']);

        $this->assertEquals($before->toArray(), $after->toArray(), 'The second run must not touch a single row.');
        $this->assertEquals($campaignBefore, $campaign->refresh()->updated_at, 'The campaign itself must not be re-saved.');
    }

    /**
     * A claim nobody came back for is written off, never repeated.
     *
     * The other half of criterion one. A worker killed *between* the claim and
     * the send leaves a row that cannot be known about, and the choice made
     * here is to fail it rather than retry it: sending a campaign twice to the
     * same person is a worse outcome than not sending it at all.
     */
    public function test_a_claim_left_by_a_stopped_worker_is_failed_rather_than_sent_again(): void
    {
        $campaign = $this->campaign($this->provider(), 1);

        $campaign->recipients()->first()->forceFill([
            'status' => CampaignRecipient::CLAIMED,
            'claimed_at' => now()->subHour(),
        ])->save();

        $this->chunk($campaign);

        $this->assertSame(0, $this->brevo->sendCount(), 'An abandoned claim must never be sent again.');

        $recipient = $campaign->recipients()->first();

        $this->assertSame(CampaignRecipient::FAILED, $recipient->status);
        $this->assertStringContainsString('has not been re-sent', (string) $recipient->error);
    }

    /* 2 — a hard bounce on one provider blocks that address everywhere --------- */

    /**
     * A hard bounce on one provider blocks that address on every provider.
     *
     * Two providers, two campaigns, one address. The bounce arrives through
     * Brevo's callback; the second campaign goes out through Mailgun and must
     * refuse to touch that address without ever asking the driver.
     */
    public function test_a_hard_bounce_on_one_provider_blocks_that_address_on_every_provider(): void
    {
        $brevo = $this->provider(Senders::BREVO);
        $mailgun = $this->provider(Senders::MAILGUN, ['priority' => 2]);

        $doomed = 'gone@brightlab.example';

        $first = Campaign::factory()->through($brevo)->sending()->create();
        CampaignRecipient::factory()->forCampaign($first)->withEmail($doomed)->create();

        $this->chunk($first);

        $this->assertSame(1, $this->brevo->sendCount());

        // Brevo reports the hard bounce.
        $this->brevo->willReport([
            new DeliveryEvent(
                kind: DeliveryEvent::HARD_BOUNCE,
                email: $doomed,
                messageId: $first->recipients()->first()->message_id,
                detail: '550 5.1.1 The email account that you tried to reach does not exist.',
            ),
        ]);

        $this->postJson(route('mail.webhook', $brevo).'?token=test-credential', [])->assertOk();

        $this->assertTrue(Suppression::blocks($doomed), 'The bounce must reach the shared suppression list.');
        $this->assertSame(Suppression::HARD_BOUNCE, Suppression::query()->where('email', $doomed)->value('reason'));

        // A second campaign, a different provider, the same address.
        $second = Campaign::factory()->through($mailgun)->sending()->create();
        CampaignRecipient::factory()->forCampaign($second)->withEmail($doomed)->create();
        CampaignRecipient::factory()->forCampaign($second)->withEmail('fine@studio-nord.example')->create();

        $this->chunk($second);

        $this->assertSame(
            ['fine@studio-nord.example'],
            $this->mailgun->recipients(),
            'The blocked address must not reach the second provider at all.',
        );

        $blocked = $second->recipients()->where('email', $doomed)->first();

        $this->assertSame(CampaignRecipient::SUPPRESSED, $blocked->status);
        $this->assertStringContainsString('suppression list', (string) $blocked->error);
    }

    /**
     * The same callback delivered twice changes nothing the second time.
     *
     * Providers redeliver — on a retry, on a redelivery from their console, on
     * a blip they could not tell from a failure — and a bounce counted twice is
     * a health score that lies.
     */
    public function test_a_bounce_webhook_delivered_twice_is_only_counted_once(): void
    {
        $provider = $this->provider();
        $campaign = $this->campaign($provider, 1);

        $this->chunk($campaign);

        $recipient = $campaign->recipients()->first();

        $this->brevo->willReport([
            new DeliveryEvent(
                kind: DeliveryEvent::HARD_BOUNCE,
                email: (string) $recipient->email,
                messageId: $recipient->message_id,
                detail: 'No such user.',
            ),
        ]);

        $url = route('mail.webhook', $provider).'?token=test-credential';

        $this->postJson($url, [])->assertOk()->assertJsonPath('applied', 1);
        $this->postJson($url, [])->assertOk()->assertJsonPath('applied', 0);

        $this->assertSame(1, Suppression::query()->where('email', $recipient->email)->count());
        $this->assertSame(1, $provider->refresh()->bounce_count, 'A redelivered callback must not double the count.');
        $this->assertSame(1, $campaign->refresh()->bounced_count);
    }

    /** A callback that cannot be verified is refused before its body is read. */
    public function test_an_unverified_webhook_is_refused(): void
    {
        $provider = $this->provider();

        $this->brevo->rejectWebhooks()->willReport([
            new DeliveryEvent(kind: DeliveryEvent::HARD_BOUNCE, email: 'anyone@studio-nord.example'),
        ]);

        $this->postJson(route('mail.webhook', $provider), [])->assertForbidden();

        $this->assertFalse(Suppression::blocks('anyone@studio-nord.example'));
    }

    /** A complaint suppresses too, and costs the provider more health than a bounce. */
    public function test_a_complaint_suppresses_the_address_and_costs_more_health_than_a_bounce(): void
    {
        $provider = $this->provider();
        $campaign = $this->campaign($provider, 1);

        $this->chunk($campaign);

        $recipient = $campaign->recipients()->first();
        $before = $provider->refresh()->health_score;

        $this->brevo->willReport([
            new DeliveryEvent(
                kind: DeliveryEvent::COMPLAINT,
                email: (string) $recipient->email,
                messageId: $recipient->message_id,
            ),
        ]);

        $this->postJson(route('mail.webhook', $provider).'?token=test-credential', [])->assertOk();

        $this->assertSame(Suppression::COMPLAINT, Suppression::query()->where('email', $recipient->email)->value('reason'));
        $this->assertSame(CampaignRecipient::COMPLAINED, $campaign->recipients()->first()->status);
        $this->assertSame(
            $before - DeliveryProvider::COMPLAINT_PENALTY,
            $provider->refresh()->health_score,
        );
    }

    /* 3 — a quota that runs out moves the rest to the next provider ------------ */

    /**
     * Exhausting one provider's quota moves the remainder to the next, and the
     * report shows the split.
     *
     * Brevo has an allowance of ten and the campaign has thirty recipients.
     * Ten go through Brevo, the router stops considering it, and Mailgun takes
     * the other twenty — which is visible in the driver's record, in
     * `campaign_recipients.delivery_provider_id`, and on the report page.
     */
    public function test_exhausting_one_providers_quota_moves_the_remainder_to_the_next_and_the_report_shows_the_split(): void
    {
        $brevo = $this->provider(Senders::BREVO, ['daily_quota' => 10, 'priority' => 1]);
        $mailgun = $this->provider(Senders::MAILGUN, ['daily_quota' => 0, 'priority' => 2]);

        $campaign = $this->campaign($brevo, 30);

        $this->chunk($campaign);

        $this->assertSame(10, $this->brevo->sendCount(), 'Brevo may carry exactly its allowance.');
        $this->assertSame(20, $this->mailgun->sendCount(), 'Everything past the allowance goes to the next provider.');

        $this->assertSame(10, $campaign->recipients()->where('delivery_provider_id', $brevo->id)->count());
        $this->assertSame(20, $campaign->recipients()->where('delivery_provider_id', $mailgun->id)->count());
        $this->assertSame(30, $campaign->recipients()->where('status', CampaignRecipient::SENT)->count());

        // The report has to be able to say so, which is the other half of the
        // criterion — a split nobody can see is a split nobody can act on.
        $breakdown = collect($campaign->refresh()->providerBreakdown())->keyBy('id');

        $this->assertSame(20, $breakdown[$mailgun->id]['carried']);
        $this->assertSame(10, $breakdown[$brevo->id]['carried']);
        $this->assertSame(67, $breakdown[$mailgun->id]['share']);

        $this->actingAs(User::factory()->create());

        Livewire::test('mailbox::campaign-show', ['campaign' => (string) $campaign->id])
            ->assertSee('Brevo')
            ->assertSee('Mailgun')
            ->assertSeeInOrder(['Mailgun', '20', 'Brevo', '10']);
    }

    /** With every provider out of quota, the rest stay pending rather than failing. */
    public function test_a_campaign_waits_rather_than_fails_when_every_provider_is_out_of_quota(): void
    {
        $brevo = $this->provider(Senders::BREVO, ['daily_quota' => 2]);
        $campaign = $this->campaign($brevo, 5);

        $this->chunk($campaign);

        $this->assertSame(2, $this->brevo->sendCount());
        $this->assertSame(3, $campaign->recipients()->where('status', CampaignRecipient::PENDING)->count());
        $this->assertSame(Campaign::SENDING, $campaign->refresh()->status);

        // Tomorrow the window rolls and the rest go out — with nothing sent twice.
        Carbon::setTestNow(now()->addDay());

        $this->chunk($campaign);

        $this->assertSame(5, $this->brevo->sendCount());
        $this->assertSame(0, $this->duplicateSends());
        $this->assertSame(Campaign::SENT, $campaign->refresh()->status);
    }

    /* 4 — the pre-flight refuses ---------------------------------------------- */

    /**
     * The pre-flight refuses to send when SPF, DKIM or unsubscribe are missing.
     *
     * All three at once, because all three are found at once: someone setting a
     * campaign up should learn about every problem in one go rather than one
     * press of the button at a time. The campaign must not move off `draft` and
     * nothing may reach a driver.
     */
    public function test_the_preflight_refuses_to_send_when_spf_dkim_or_unsubscribe_are_missing(): void
    {
        $provider = DeliveryProvider::factory()->driver(Senders::BREVO)->configured()->create();

        $campaign = Campaign::factory()
            ->through($provider)
            ->withoutUnsubscribeLink()
            ->create(['status' => Campaign::DRAFT]);

        CampaignRecipient::factory()->count(3)->forCampaign($campaign)->create();

        $problems = $this->app->make(CampaignSender::class)->start($campaign);

        $this->assertCount(3, $problems);
        $this->assertStringContainsString('unsubscribe link', $problems[0]);
        $this->assertStringContainsString('SPF is not verified', $problems[1]);
        $this->assertStringContainsString('DKIM is not verified', $problems[2]);

        $this->assertSame(Campaign::DRAFT, $campaign->refresh()->status, 'A refused campaign must not start.');

        $this->chunk($campaign);

        $this->assertSame(0, $this->brevo->sendCount(), 'A refused campaign must send nothing at all.');
    }

    /** Each of the three refuses on its own, so none of them is carried by the others. */
    public function test_each_preflight_failure_refuses_on_its_own(): void
    {
        $preFlight = $this->app->make(PreFlight::class);

        $noSpf = $this->provider();
        $noSpf->forceFill(['spf_verified' => false])->save();

        $campaign = $this->campaign($noSpf, 1);

        $this->assertStringContainsString('SPF is not verified', implode(' ', $preFlight->problems($campaign)));

        $noDkim = $this->provider(Senders::MAILGUN);
        $noDkim->forceFill(['dkim_verified' => false])->save();

        $second = $this->campaign($noDkim, 1);

        $this->assertStringContainsString('DKIM is not verified', implode(' ', $preFlight->problems($second)));

        $third = Campaign::factory()->through($this->provider(Senders::POSTMARK))->withoutUnsubscribeLink()->create();
        CampaignRecipient::factory()->forCampaign($third)->create();

        $this->assertStringContainsString('no unsubscribe link', implode(' ', $preFlight->problems($third)));
    }

    /** A campaign that passes starts, and the pre-flight says nothing. */
    public function test_a_campaign_that_passes_the_preflight_starts(): void
    {
        $campaign = Campaign::factory()->through($this->provider())->create(['status' => Campaign::DRAFT]);
        CampaignRecipient::factory()->forCampaign($campaign)->create();

        $this->assertSame([], $this->app->make(CampaignSender::class)->start($campaign));
        $this->assertSame(Campaign::SENDING, $campaign->refresh()->status);
    }

    /**
     * A campaign whose pre-flight stops passing is paused, not pushed on.
     *
     * DNS changes and keys get revoked between the campaign starting and the
     * next tick, and discovering that at the top of the command costs one check
     * instead of five hundred rows each recording the same sentence.
     */
    public function test_the_scheduler_pauses_a_campaign_that_no_longer_passes_the_preflight(): void
    {
        $provider = $this->provider();
        $campaign = $this->campaign($provider, 3);

        $provider->forceFill(['dkim_verified' => false])->save();

        $this->tick();

        $this->assertSame(Campaign::PAUSED, $campaign->refresh()->status);
        $this->assertSame(0, $this->brevo->sendCount());
    }

    /* Headers, tokens and the one-click unsubscribe ---------------------------- */

    /** Every message carries the two headers Gmail and Yahoo require of bulk senders. */
    public function test_every_message_carries_list_unsubscribe_headers_and_a_signed_reply_to(): void
    {
        $campaign = $this->campaign($this->provider(), 1);

        $this->chunk($campaign);

        $sent = $this->brevo->sent[0];

        $this->assertSame('List-Unsubscribe=One-Click', $sent['headers']['List-Unsubscribe-Post']);
        $this->assertStringContainsString('/mail/unsubscribe/', $sent['headers']['List-Unsubscribe']);
        $this->assertStringContainsString('signature=', $sent['headers']['List-Unsubscribe']);
        $this->assertStringContainsString('mailto:', $sent['headers']['List-Unsubscribe']);
        $this->assertSame('bulk', $sent['headers']['Precedence']);

        $recipient = $campaign->recipients()->first();

        $this->assertNotNull($recipient->unsubscribe_token);
        $this->assertNotNull($recipient->reply_token);

        // The Reply-To carries a token that names this recipient and verifies,
        // which is what lets a reply thread back to the campaign it answers.
        $this->assertSame((int) $recipient->id, Tokens::recipientFromAddress((string) $sent['replyTo']));
        $this->assertNull(Tokens::recipientFromAddress('nima+forged-0000000000000000@news.kargah.dev'));
    }

    /** The tokens are derived, so a re-run mints the same ones rather than invalidating a link. */
    public function test_a_rerun_reissues_the_same_tokens(): void
    {
        $campaign = $this->campaign($this->provider(), 1);

        $this->chunk($campaign);

        $recipient = $campaign->recipients()->first();
        $before = [$recipient->unsubscribe_token, $recipient->reply_token, $recipient->message_id];

        $this->chunk($campaign);

        $recipient->refresh();

        $this->assertSame($before, [$recipient->unsubscribe_token, $recipient->reply_token, $recipient->message_id]);
    }

    /** One click, no login, and the address is blocked everywhere. */
    public function test_the_one_click_unsubscribe_needs_no_login_and_writes_a_suppression(): void
    {
        $campaign = $this->campaign($this->provider(), 1);

        Contact::factory()->withEmail('reader@studio-nord.example')->create();

        $recipient = $campaign->recipients()->first();
        $recipient->forceFill(['email' => 'reader@studio-nord.example'])->save();

        $url = $this->app->make(MessageBuilder::class)->unsubscribeUrl($recipient);

        // A mail client posting on the person's behalf, with no session at all.
        $this->post($url)->assertOk();

        $this->assertTrue(Suppression::blocks('reader@studio-nord.example'));
        $this->assertSame(
            Suppression::UNSUBSCRIBE,
            Suppression::query()->where('email', 'reader@studio-nord.example')->value('reason'),
        );
        $this->assertFalse(
            (bool) Contact::query()->where('email', 'reader@studio-nord.example')->value('is_subscribed'),
            'The contact record has to agree with what the person asked for.',
        );

        // A client that prefetches the link and then follows it writes one row.
        $this->get($url)->assertOk()->assertSee('unsubscribed', false);

        $this->assertSame(1, Suppression::query()->where('email', 'reader@studio-nord.example')->count());
    }

    /** A tampered unsubscribe URL is refused by the signature before anything is read. */
    public function test_a_tampered_unsubscribe_url_is_refused(): void
    {
        $campaign = $this->campaign($this->provider(), 1);

        $recipient = $campaign->recipients()->first()->ensureTokens();

        $tampered = URL::signedRoute('mail.unsubscribe', ['token' => $recipient->unsubscribe_token]).'x';

        $this->get($tampered)->assertForbidden();

        $this->assertFalse(Suppression::blocks((string) $recipient->email));
    }

    /* Failing cleanly, with no credentials anywhere ---------------------------- */

    /**
     * A provider with no credentials fails into the row, not out of the job.
     *
     * The state of this machine and of every fresh install, so it has to be an
     * ordinary recorded outcome rather than an exception — and the rest of the
     * chunk has to carry on.
     */
    public function test_a_provider_with_no_credentials_fails_into_the_row_rather_than_throwing(): void
    {
        $unconfigured = DeliveryProvider::factory()->driver(Senders::BREVO)->verified()->create();

        $campaign = Campaign::factory()->through($unconfigured)->sending()->create();
        CampaignRecipient::factory()->count(2)->forCampaign($campaign)->create();

        $this->chunk($campaign);

        $this->assertSame(0, $this->brevo->sendCount());
        $this->assertSame(2, $campaign->recipients()->where('status', CampaignRecipient::PENDING)->count());

        // Nothing was attempted, because the router will not route through a
        // provider nobody filled in — the refusal names the situation rather
        // than blaming the recipient.
        $this->assertSame(0, $campaign->recipients()->where('status', CampaignRecipient::FAILED)->count());
    }

    /** A driver that refuses one message records it and leaves the rest of the chunk alone. */
    public function test_a_refused_message_is_recorded_and_the_chunk_carries_on(): void
    {
        $campaign = $this->campaign($this->provider(), 3);

        $this->brevo->failWith('the recipient domain does not accept mail');

        $this->chunk($campaign);

        $this->assertSame(3, $campaign->recipients()->where('status', CampaignRecipient::FAILED)->count());
        $this->assertSame(3, $campaign->refresh()->failed_count);

        $this->brevo->succeed();

        // Failed rows are not retried on their own; re-queueing them is a
        // deliberate act by somebody who has read the error.
        $this->chunk($campaign);

        $this->assertSame(0, $this->brevo->sendCount());

        $this->app->make(CampaignSender::class)->requeueFailed($campaign);
        $this->chunk($campaign);

        $this->assertSame(3, $this->brevo->sendCount());
        $this->assertSame(0, $this->duplicateSends());
    }

    /** No test in this file may construct a real transport or reach a mail server. */
    public function test_nothing_in_this_file_reaches_a_mail_server(): void
    {
        $campaign = $this->campaign($this->provider(), 3);

        $this->chunk($campaign);

        Mail::assertNothingOutgoing();
    }
}
