<?php

namespace Modules\Mailbox\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Services\Delivery\CampaignSender;
use Modules\Mailbox\Services\Delivery\SendReport;

/**
 * Send the next chunk of one campaign.
 *
 * One job per chunk, dispatched by `mailbox:dispatch-sends` or by the campaign
 * page. Small on purpose: a chunk is fifty messages by default, which finishes
 * well inside `max_execution_time` on shared hosting where the only runner is a
 * `queue:work --stop-when-empty --max-time=50` started by cron. A
 * 500-recipient campaign is therefore ten of these across as many ticks as it
 * takes, and no single tick is longer than any other.
 *
 * **Idempotent, and not by being careful.** Every recipient is claimed by a
 * conditional update that only a `pending` row can match, so running this job
 * twice sends once. A duplicate dispatch — two cron ticks racing, a worker
 * killed after sending — costs a wasted query and nothing else, and it is why
 * there is no unique-job lock here to go stale and block the retry.
 *
 * The id travels rather than the model: `SerializesModels` would re-query the
 * campaign anyway, and an id is what survives the campaign being edited between
 * dispatch and run.
 */
class SendCampaignChunk implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $limit  How many recipients this chunk may take, or the configured default.
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly ?int $limit = null,
    ) {}

    public function handle(CampaignSender $sender): SendReport
    {
        $campaign = Campaign::query()->find($this->campaignId);

        // Deleted between dispatch and run. Nothing to do and nothing wrong —
        // the queue must not retry a campaign that no longer exists.
        if ($campaign === null) {
            return new SendReport;
        }

        return $sender->sendChunk($campaign, $this->limit);
    }
}
