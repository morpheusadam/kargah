<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Accounting\Console\FetchRates;
use Modules\Accounting\Console\GenerateRecurringInvoices;

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
