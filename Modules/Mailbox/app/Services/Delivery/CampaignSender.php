<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Support\Facades\Log;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\Suppression;

/**
 * The only thing in Kargah that sends a campaign message.
 *
 * Everything about this class is arranged around one requirement: **no
 * recipient is ever sent to twice, even when the worker is killed mid-run.**
 * The design that delivers it is on the row rather than in this code — the
 * unique index on (campaign_id, email) plus a status that only moves forward —
 * and this class's job is to never work around it.
 *
 * How one recipient is taken:
 *
 * 1. Everything that could refuse the send is checked *first*: the suppression
 *    list, whether any provider has quota left, whether that provider's driver
 *    is configured. None of those needs a claim, and doing them first keeps the
 *    claim window as short as it can be.
 * 2. A conditional `UPDATE … WHERE status = 'pending'` moves the row to
 *    `claimed`, and the affected-row count is checked. The database applies
 *    that atomically: two workers racing produce one row affected and one that
 *    got nothing, and the one that got nothing moves on. A row already `sent`
 *    matches no condition, which is the whole guarantee.
 * 3. The driver is called. Whatever `Exception` it raises is caught, because a
 *    job that dies takes the rest of its chunk with it.
 * 4. The outcome is written to the same row. `sent` is terminal; nothing here
 *    moves a recipient back out of it.
 *
 * **An `Error` is deliberately not caught.** See `WorkerKilled`: when PHP says
 * the process is unsound, the safe thing is to stop, because every recipient
 * not yet claimed is still `pending` and the next cron tick will take them.
 * Carrying on inside a broken process is how rows get marked `sent` for
 * messages that never left.
 *
 * A claim whose worker never came back is **not** repeated. `releaseStaleClaims`
 * moves it to `failed` and says so, because the one thing that cannot be known
 * about it is whether the provider already accepted the message — and sending a
 * campaign twice to the same person is a worse outcome than not sending it at
 * all.
 */
class CampaignSender
{
    public function __construct(
        private readonly Delivery $delivery,
        private readonly Router $router,
        private readonly MessageBuilder $builder,
        private readonly PreFlight $preFlight,
    ) {}

    /**
     * Send the next chunk of a campaign.
     *
     * Idempotent: called twice in a row, the second call claims nothing, sends
     * nothing, and leaves every row exactly as the first call left it —
     * including the campaign's `updated_at`, because the counters are
     * recomputed rather than incremented and the campaign is only saved when
     * the recomputation actually changed something.
     */
    public function sendChunk(Campaign $campaign, ?int $limit = null): SendReport
    {
        $report = new SendReport;

        $limit = max(1, $limit ?? (int) config('mailbox.sending.chunk_size', 50));

        $this->releaseStaleClaims($campaign, $report);

        // A paused campaign is a person's decision and outranks the schedule.
        // `sent` and `draft` have nothing outstanding by definition.
        if (! in_array($campaign->status, [Campaign::SENDING, Campaign::SCHEDULED], true)) {
            return $report;
        }

        $recipients = $campaign->recipients()
            ->claimable()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($recipients->isEmpty()) {
            $campaign->syncCounters();

            return $report;
        }

        // One query rather than one existence check per recipient. The
        // suppression list is shared across providers, so this is also where a
        // bounce reported through provider A stops a send through provider B.
        $blocked = Suppression::among($recipients->pluck('email')->all());

        foreach ($recipients as $recipient) {
            if (! $this->sendOne($campaign, $recipient, $blocked, $report)) {
                break;
            }
        }

        $campaign->syncCounters();

        return $report;
    }

    /**
     * Take one recipient and send to them, or record why nothing was sent.
     *
     * Returns false when the chunk should stop rather than move on. That
     * happens for exactly one reason — no provider anywhere has quota left —
     * and it is a property of the whole run rather than of this recipient, so
     * carrying on would write the same sentence onto forty-nine more rows.
     *
     * @param  array<string, Suppression>  $blocked
     */
    private function sendOne(Campaign $campaign, CampaignRecipient $recipient, array $blocked, SendReport $report): bool
    {
        $address = Suppression::normalise((string) $recipient->email);

        if (isset($blocked[$address])) {
            $this->settle($recipient, CampaignRecipient::SUPPRESSED, [
                'error' => 'Blocked on the shared suppression list: '.$blocked[$address]->reasonLabel().'.',
                'failed_at' => now(),
            ]);

            $report->recordSuppressed();

            return true;
        }

        $provider = $this->router->pick($campaign->provider);

        if ($provider === null) {
            // Not a failure of this recipient — there is nothing to send
            // through at all. The row stays `pending` so the next tick, or the
            // next quota window, picks it up unchanged.
            $report->recordFailed($this->router->refusalReason());

            return false;
        }

        $driver = $this->delivery->driverFor($provider->driver);

        if ($driver === null) {
            $this->fail($recipient, $report, 'Kargah has no driver for '.$provider->driver.', so nothing was sent.', $provider->getKey());

            return true;
        }

        // Asked before claiming rather than discovered afterwards: a provider
        // with no credentials is the ordinary state of a fresh install, not an
        // exception, and finding out before the claim means a stopped worker
        // leaves the row `pending` rather than in limbo.
        if ($reason = $driver->unavailableReason($provider)) {
            $this->fail($recipient, $report, $reason, $provider->getKey());

            return true;
        }

        $message = $this->builder->build($campaign, $recipient, $provider);

        if (! $this->claim($recipient, $provider->getKey())) {
            $report->recordUntouched();

            return true;
        }

        try {
            $sent = $driver->send($provider, $message);
        } catch (SendFailed $e) {
            $this->fail($recipient, $report, $e->getMessage(), $provider->getKey());

            return true;
        } catch (\Exception $e) {
            // A driver that raises something other than SendFailed is a bug in
            // the driver, and it is still this recipient's failure rather than
            // the chunk's — see the class docblock.
            Log::error('mailbox: the '.$provider->driver.' driver threw '.$e::class.': '.$e->getMessage());

            $this->fail(
                $recipient,
                $report,
                $provider->label().' failed in a way Kargah did not expect: '.$e->getMessage(),
                $provider->getKey(),
            );

            return true;
        }

        $this->settle($recipient, CampaignRecipient::SENT, [
            'message_id' => $sent->messageId,
            'error' => null,
            'sent_at' => now(),
        ]);

        $provider->recordSend();

        $report->recordSent();

        return true;
    }

    /**
     * Move a recipient to `claimed`, but only from `pending`.
     *
     * One statement, evaluated by the database. Reading the row and then
     * writing it would leave a window in which two workers both saw `pending`,
     * and that window is precisely the bug this whole design exists to remove.
     *
     * The provider is written here rather than after the send, so that a row
     * left behind by a stopped worker still names who was about to carry it.
     */
    private function claim(CampaignRecipient $recipient, int|string|null $providerId): bool
    {
        $claimed = CampaignRecipient::query()
            ->whereKey($recipient->getKey())
            ->where('status', CampaignRecipient::PENDING)
            ->update([
                'status' => CampaignRecipient::CLAIMED,
                'delivery_provider_id' => $providerId,
                // Read from the loaded model rather than computed in SQL: it is
                // a diagnostic, `LEAST` is not portable across SQLite and
                // MySQL, and the column is an unsigned tiny integer that would
                // overflow at 255 on a recipient nobody ever fixed.
                'attempts' => min($recipient->attempts + 1, CampaignRecipient::MAX_ATTEMPTS_RECORDED),
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        // The claim was written by a query, so the in-memory row is a version
        // behind. Everything after this reads `status` and `attempts`.
        $recipient->refresh();

        return true;
    }

    /**
     * Deal with claims nobody is coming back for.
     *
     * A worker killed between the claim and the send leaves a row on `claimed`
     * with no way to tell whether the provider accepted the message. It is
     * moved to `failed` and named, never retried: the acceptance criterion is
     * that no recipient is sent twice, and a retry here is the one place in the
     * design that could break it. The report says what happened so the campaign
     * page can offer to re-queue them as a deliberate act by a person who has
     * read the count.
     */
    public function releaseStaleClaims(Campaign $campaign, ?SendReport $report = null): int
    {
        $stale = $campaign->recipients()->staleClaims()->get();

        foreach ($stale as $recipient) {
            $this->settle($recipient, CampaignRecipient::FAILED, [
                'error' => 'The worker stopped while this message was being handed over, so Kargah cannot tell '
                    .'whether it went out. It has not been re-sent, because sending it twice would be worse than '
                    .'not sending it at all.',
                'failed_at' => now(),
            ]);

            $report?->recordAbandoned();
        }

        return $stale->count();
    }

    /**
     * Put a set of failed recipients back in the queue.
     *
     * A deliberate act by a person, which is why it is a separate method and
     * not something a tick does: a `failed` row may be a dead address or it may
     * be a message that already went out, and only somebody who has read the
     * error can tell the difference. The tokens are kept, so a re-queued
     * recipient receives the same unsubscribe link it would have had.
     */
    public function requeueFailed(Campaign $campaign): int
    {
        return $campaign->recipients()
            ->where('status', CampaignRecipient::FAILED)
            ->update([
                'status' => CampaignRecipient::PENDING,
                'error' => null,
                'claimed_at' => null,
                'failed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Start a campaign, or refuse and say why.
     *
     * The only door onto `sending`, and it goes through the pre-flight every
     * time — including for a campaign that was paused and resumed, because the
     * provider's DNS may have stopped verifying in the meantime.
     *
     * @return list<string> The problems that stopped it. Empty means it started.
     */
    public function start(Campaign $campaign): array
    {
        $problems = $this->preFlight->problems($campaign);

        if ($problems !== []) {
            return $problems;
        }

        $campaign->forceFill([
            'status' => Campaign::SENDING,
            'started_at' => $campaign->started_at ?? now(),
            'finished_at' => null,
        ])->save();

        return [];
    }

    private function fail(CampaignRecipient $recipient, SendReport $report, string $error, int|string|null $providerId = null): void
    {
        $this->settle($recipient, CampaignRecipient::FAILED, [
            'error' => $error,
            'failed_at' => now(),
            'delivery_provider_id' => $providerId ?? $recipient->delivery_provider_id,
        ]);

        $report->recordFailed($error);
    }

    /**
     * Write a terminal status onto a recipient row.
     *
     * `forceFill` rather than `update`, so the status is written whatever the
     * fillable list says — this is the module's own bookkeeping and must not
     * depend on a mass-assignment rule meant for form input.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function settle(CampaignRecipient $recipient, string $status, array $attributes): void
    {
        $recipient->forceFill(array_merge(['status' => $status], $attributes))->save();
    }
}
