<?php

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\Notifier;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Services\Curation\CurationReport;
use Modules\Social\Services\Curation\DailyCurator;

/**
 * Choose the day's story and schedule it.
 *
 * **This command publishes nothing.** It reads the sources, picks one story,
 * writes the copy and creates scheduled posts; `social:publish-due` sends them
 * when their hour arrives, which is the pattern every long operation in Kargah
 * follows. A command that published inline would hold the cron minute open across
 * four networks' API calls, and on shared hosting that is how an account gets
 * suspended.
 *
 * Runs once a day, early, so that the earliest window of the day — LinkedIn's
 * weekday morning — has not already passed by the time the story is chosen.
 *
 * Re-running the same day is safe and does nothing: the story is in
 * `curated_stories` with a unique `url_key`, and `curated_story_posts` has a
 * unique index on (story, network).
 */
class CurateDaily extends Command
{
    protected $signature = 'social:curate-daily
        {--dry-run : Choose and write, but create nothing and remember nothing}
        {--explain : Print the ranking table and every network\'s copy}
        {--force : Run even when curation is switched off in settings}';

    protected $description = 'Pick the day\'s story, write it for each network, and schedule each at its own hour';

    public function handle(DailyCurator $curator, Notifier $notifier): int
    {
        $settings = CurationSetting::current();

        if (! $settings->is_enabled && ! $this->option('force')) {
            $this->components->info(
                'Curation is switched off. Turn it on at Settings → Curation, or pass --force for one run.',
            );

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $report = $curator->run($dryRun);

        $this->reportProblems($report);

        if ($this->option('explain')) {
            $this->explain($report);
        }

        if ($report->chosen === null) {
            // Not an error exit. A day with nothing worth posting is an ordinary
            // outcome, and a non-zero status from cron is a mail to somebody's
            // inbox every morning until they stop reading it.
            $this->components->warn($report->stoppedBecause ?? 'Nothing was chosen.');

            $this->warnOperator($notifier, $report);

            return self::SUCCESS;
        }

        $this->components->info(
            'Chose “'.$report->chosen->story->title.'” — '
            .$report->chosen->sources.' '.str('outlet')->plural($report->chosen->sources)
            .', score '.round($report->chosen->score, 4).'.',
        );

        foreach ($report->copy as $network => $copy) {
            $slot = $report->slots[$network] ?? null;

            $this->components->twoColumnDetail(
                $network.($dryRun ? ' (not created)' : ''),
                ($slot?->copy()->setTimezone($settings->timezone)->format('H:i') ?? '—')
                .'  ·  '.$copy->length().' chars'
                .'  ·  '.count($copy->hashtags).' tags',
            );
        }

        if ($dryRun) {
            $this->components->warn('Dry run — nothing was created and nothing was remembered.');
        }

        return self::SUCCESS;
    }

    private function reportProblems(CurationReport $report): void
    {
        foreach ($report->problems as $problem) {
            $this->components->warn($problem);
        }

        // Half the catalogue failing is a different event from one feed failing,
        // and it is the one worth finding in a log later.
        if (count($report->problems) > 10) {
            Log::warning('social:curate-daily saw '.count($report->problems).' source problems in one run.');
        }
    }

    /** The ranking table and the copy, for tuning the windows before anything is live. */
    private function explain(CurationReport $report): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>stories read</>', (string) $report->storiesRead);
        $this->components->twoColumnDetail('<fg=gray>stories after clustering</>', (string) $report->clustersFound);
        $this->newLine();

        foreach (array_slice($report->considered, 0, 10) as $index => $ranked) {
            $this->components->twoColumnDetail(
                sprintf('%2d. %s', $index + 1, mb_substr($ranked->story->title, 0, 60)),
                sprintf('%.4f  %s', $ranked->score, $ranked->explain()),
            );
        }

        foreach ($report->refused as $refusal) {
            $this->components->twoColumnDetail(
                '<fg=yellow>refused</> '.mb_substr($refusal['title'], 0, 55),
                $refusal['reason'],
            );
        }

        foreach ($report->copy as $network => $copy) {
            $this->newLine();
            $this->line('<fg=cyan>── '.$network.' ──</>');
            $this->line($copy->text());
        }

        $this->newLine();
    }

    /**
     * Tell somebody when the curator could not run at all.
     *
     * Only for the causes a person can fix — no provider configured, no account
     * connected — and never for a quiet news day, which is not a fault and would
     * otherwise produce a notification every slow Sunday until nobody reads them.
     *
     * The dedupe key is the reason rather than the date, so a provider that has
     * been missing for a fortnight is one notification and not fourteen.
     */
    private function warnOperator(Notifier $notifier, CurationReport $report): void
    {
        $reason = $report->stoppedBecause;

        if ($reason === null || $report->refused !== []) {
            return;
        }

        $isFixable = str_contains($reason, 'provider')
            || str_contains($reason, 'account')
            || str_contains($reason, 'refused');

        if (! $isFixable) {
            return;
        }

        $userId = \App\Models\User::query()->orderBy('id')->value('id');

        if ($userId === null) {
            return;
        }

        $notifier->notify(
            (int) $userId,
            'social.curation_blocked',
            'The daily post could not be prepared',
            [
                'body' => $reason,
                'url' => '/settings/curation',
                'dedupe_key' => 'curation-blocked:'.md5($reason),
            ],
        );
    }
}
