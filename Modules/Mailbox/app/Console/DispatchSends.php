<?php

namespace Modules\Mailbox\Console;

use Illuminate\Console\Command;
use Modules\Mailbox\Jobs\SendCampaignChunk;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Services\Delivery\PreFlight;

/**
 * Push every campaign that is under way one step further along.
 *
 * **This command sends nothing.** It finds a bounded amount of outstanding work
 * and dispatches one small job per chunk, which is the pattern every long
 * operation in Kargah follows — see project-guaid/spec/01-architecture.md. A
 * command that sent the campaign itself would be a command that exceeds
 * `max_execution_time` the first time somebody schedules five hundred
 * recipients for nine o'clock, and on shared hosting that is how an account
 * gets suspended.
 *
 * Runs every minute, so a campaign scheduled for 09:30 begins within a minute
 * of 09:30 and a 500-recipient send completes across the ticks that follow.
 *
 * Re-running is harmless twice over: a scheduled campaign is moved to `sending`
 * before it is dispatched, and even a duplicate dispatch sends nothing extra
 * because every recipient is claimed by a conditional update and a claimed row
 * cannot be claimed again.
 *
 * A campaign whose pre-flight has *stopped* passing — the DNS was changed, the
 * provider's key was revoked — is paused rather than dispatched. Discovering
 * that at the top costs one check and saves five hundred rows each recording
 * the same sentence.
 */
class DispatchSends extends Command
{
    protected $signature = 'mailbox:dispatch-sends
        {--limit= : How many campaigns this tick may push along}
        {--chunks= : How many chunks to queue per campaign this tick}';

    protected $description = 'Queue the next chunk of every campaign that is scheduled or already sending';

    public function handle(PreFlight $preFlight): int
    {
        $limit = (int) ($this->option('limit') ?? config('mailbox.sending.campaigns_per_tick', 5));
        $chunks = (int) ($this->option('chunks') ?? config('mailbox.sending.chunks_per_tick', 1));

        $campaigns = Campaign::query()->due()->limit(max(1, $limit))->get();

        if ($campaigns->isEmpty()) {
            $this->components->info('No campaign is due.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $paused = 0;

        foreach ($campaigns as $campaign) {
            $problems = $preFlight->problems($campaign);

            if ($problems !== []) {
                $this->pause($campaign, $problems);
                $paused++;

                continue;
            }

            if (! $this->begin($campaign)) {
                continue;
            }

            $outstanding = $campaign->outstandingCount();

            if ($outstanding === 0) {
                // Nothing left to hand out. `syncCounters()` is what moves the
                // campaign to `sent`, and it derives that from the rows, so a
                // campaign finished by the last chunk closes itself here rather
                // than needing a separate 'am I done' flag that a crash could
                // leave wrong.
                $campaign->syncCounters();

                $this->components->info('Campaign '.$campaign->id.' has nothing outstanding.');

                continue;
            }

            for ($i = 0; $i < max(1, $chunks); $i++) {
                SendCampaignChunk::dispatch($campaign->id);
                $dispatched++;
            }

            $this->components->info(
                'Queued '.max(1, $chunks).' '.str('chunk')->plural(max(1, $chunks))
                .' for campaign '.$campaign->id.' — '.$outstanding.' still to go.',
            );
        }

        $summary = 'Queued '.$dispatched.' '.str('chunk')->plural($dispatched).'.';

        if ($paused > 0) {
            // Non-zero so cron, and whoever reads its mail, sees that a
            // campaign stopped. What was queued is already committed — the exit
            // code reports the gap, it does not undo the rest.
            $this->components->warn($summary.' '.$paused.' '.str('campaign')->plural($paused)
                .' were paused because they no longer pass the pre-flight.');

            return self::FAILURE;
        }

        $this->components->info($summary);

        return self::SUCCESS;
    }

    /**
     * Move a scheduled campaign onto `sending` before anything is queued.
     *
     * Conditional on the status this run actually read, so a second tick a
     * minute later does not start the same campaign twice while the first
     * job is still working. This is a tidiness measure rather than the safety
     * one — the safety is on `campaign_recipients` — which is why losing the
     * race simply skips the campaign.
     */
    private function begin(Campaign $campaign): bool
    {
        if ($campaign->status === Campaign::SENDING) {
            return true;
        }

        $started = Campaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', $campaign->status)
            ->update([
                'status' => Campaign::SENDING,
                'started_at' => $campaign->started_at ?? now(),
                'updated_at' => now(),
            ]);

        return $started === 1;
    }

    /**
     * @param  list<string>  $problems
     */
    private function pause(Campaign $campaign, array $problems): void
    {
        $campaign->forceFill(['status' => Campaign::PAUSED])->save();

        $this->components->warn(
            'Campaign '.$campaign->id.' was paused rather than sent. '.$problems[0]
            .(count($problems) > 1 ? ' ('.(count($problems) - 1).' more)' : ''),
        );
    }
}
