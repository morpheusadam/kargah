<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Accounting\Console\FetchRates;
use Modules\Accounting\Console\GenerateRecurringInvoices;
use Modules\Data\Console\SyncRepos;
use Modules\Data\Console\TakeBackup;
use Modules\Mailbox\Console\DispatchSends;
use Modules\Mailbox\Console\SyncImap;
use Modules\Social\Console\PublishDue;
use Modules\Social\Console\SyncNotifications;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Exchange rates.
 *
 * Once a day, from the scheduler, and nowhere else — a page render must never
 * wait on someone else's API. `withoutOverlapping()` because cron will happily
 * start a second run while the first is still waiting on a slow provider, and
 * the daily hour is after the markets that feed these sources have opened.
 *
 * Re-running is harmless: the rate history is keyed on the business date, so a
 * second run of the same day writes no second row.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(FetchRates::class));

Schedule::command('accounting:fetch-rates')->dailyAt('09:00')->withoutOverlapping();

/*
 * Recurring invoices.
 *
 * After the rates, so a draft raised today is raised on a day whose rates are
 * already in the table — the draft freezes nothing, but the page that shows it
 * has a rate to quote.
 *
 * Re-running is harmless: the job claims an occurrence by advancing the
 * schedule's `next_run_on`, and the invoice number it derives from the schedule
 * and the occurrence date makes a second invoice for the same period impossible
 * either way.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(GenerateRecurringInvoices::class));

Schedule::command('accounting:generate-recurring')->dailyAt('09:30')->withoutOverlapping();

/*
 * Reading mail.
 *
 * Every five minutes rather than every minute, because a mailbox that gains a
 * message a minute still keeps up and an IMAP handshake per account per minute
 * is the kind of traffic a shared host notices.
 *
 * This command does not read any mail. It asks each folder for UIDNEXT and
 * queues a bounded number of small jobs; the worker below does the reading, one
 * chunk at a time, across as many ticks as it takes. A 2,000-message mailbox
 * therefore costs this tick no more than an empty one.
 *
 * `withoutOverlapping()` because a slow or hanging IMAP server would otherwise
 * let a second run start while the first is still waiting on a socket, and two
 * runs would queue the same chunk twice. Queueing it twice is harmless —
 * `emails.message_id` is unique and each job is idempotent — but it is wasted
 * work, and the lock is free.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(SyncImap::class));

Schedule::command('mailbox:sync-imap')->everyFiveMinutes()->withoutOverlapping();

/*
 * Sending mail.
 *
 * Every minute, because that is the resolution the promise is made at: a
 * campaign scheduled for 09:30 begins within a minute of 09:30, and a
 * 500-recipient send completes across the ticks that follow.
 *
 * This command sends nothing. It takes a bounded number of campaigns that are
 * scheduled or already sending and queues one small chunk each; the worker below
 * does the sending, fifty recipients at a time, across as many ticks as it
 * takes. A 500-recipient campaign therefore costs this tick no more than an
 * empty one.
 *
 * Re-running is harmless twice over. A scheduled campaign is claimed by a
 * conditional update before anything is queued, and even a duplicate chunk sends
 * nothing extra: every recipient is claimed the same way and a recipient already
 * on `sent` cannot be claimed at all. That is what makes 'no recipient sent
 * twice' true rather than hoped for — see the docblock on
 * `Modules\Mailbox\Services\Delivery\CampaignSender`.
 *
 * `withoutOverlapping()` because a tick that finds five sending campaigns can
 * outlast its minute, and the next tick starting alongside it would only queue
 * work the recipient claim then throws away.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(DispatchSends::class));

Schedule::command('mailbox:dispatch-sends')->everyMinute()->withoutOverlapping();

/*
 * The queue, run from cron.
 *
 * Shared hosting has no daemon to keep a worker alive, so the scheduler starts
 * one, it drains what is waiting, and it exits. There is exactly one cron entry
 * on the server:
 *
 *     * * * * * cd /path/to/kargah && php artisan schedule:run >> /dev/null 2>&1
 *
 * `--stop-when-empty` is load bearing. Without it cron stacks a new
 * never-exiting worker every minute until the host's process limit is hit,
 * which is a documented way to get an account suspended. `--max-time=50` keeps
 * a run inside the minute even when the queue is not empty, so the next tick
 * starts cleanly rather than being refused by `withoutOverlapping()`.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * GitHub repositories.
 *
 * Once a day, and nowhere else — the repositories page reads the local table so
 * a render never waits on GitHub. Late in the evening because it competes with
 * nothing else on the schedule and the numbers it caches (stars, open issues)
 * are read the next morning, not the same minute.
 *
 * Re-running is harmless in the strongest sense: an unchanged payload produces
 * no database write at all, so a doubled cron run costs one HTTP request and
 * touches nothing. With no `GITHUB_TOKEN` set the command says so and exits
 * successfully, which is why this entry is safe to leave enabled on a machine
 * that has never had a token.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(SyncRepos::class));

Schedule::command('data:sync-repos')->dailyAt('22:00')->withoutOverlapping();

/*
 * The nightly database backup.
 *
 * At three in the morning, which is the quietest hour on a shared host and well
 * clear of everything else here. The artefact lands on the `backups` disk —
 * `storage/app/backups`, outside the web root — with its size and a sha256
 * recorded, so a restore can verify the archive before it overwrites anything.
 *
 * `withoutOverlapping()` because a large dump can outlast the tick that started
 * it, and two concurrent dumps of the same database would both be valid and one
 * of them wasted.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(TakeBackup::class));

Schedule::command('data:backup')->dailyAt('03:00')->withoutOverlapping();

/*
 * Scheduled social posts.
 *
 * Every minute, because that is the resolution the promise is made at: a post
 * scheduled for 09:30 goes out within a minute of 09:30. The command does no
 * publishing — it takes a bounded number of posts whose time has passed and
 * dispatches one small job per post, which the worker above sends.
 *
 * Re-running is harmless twice over. The post is claimed by a conditional
 * update before it is dispatched, and even a duplicate dispatch sends nothing
 * extra: each target is claimed the same way and a target already on
 * `published` cannot be claimed at all. That is what stops a retry resending
 * the network that worked.
 *
 * `withoutOverlapping()` because a tick that finds fifty due posts can outlast
 * its minute, and the next tick starting alongside it would only duplicate work
 * the target claim then throws away.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(PublishDue::class));

Schedule::command('social:publish-due')->everyMinute()->withoutOverlapping();

/*
 * Social notifications, read back.
 *
 * Every fifteen minutes: often enough that a reply is waiting when you next
 * open the page, rare enough that four accounts do not cost sixty API calls an
 * hour on a rate limit somebody else set.
 *
 * Only Mastodon and Bluesky are asked. LinkedIn's notifications API needs
 * partner access nobody self-serving has, and Telegram's `getUpdates` would
 * consume the update queue the bot itself depends on, so the command skips both
 * by name rather than showing an empty feed that reads as 'nothing happened'.
 *
 * Re-running is harmless: every row is written by `updateOrCreate` on
 * (social_account_id, remote_id), which is the unique index the migration added
 * for exactly this, and `is_read` is never written by ingestion. An account with
 * no credentials is skipped with a reason rather than failing the run, which is
 * why this entry is safe on a machine that has never had a token.
 */
ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->resolve(SyncNotifications::class));

Schedule::command('social:sync-notifications')->everyFifteenMinutes()->withoutOverlapping();
