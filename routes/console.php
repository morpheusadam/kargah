<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Accounting\Console\FetchRates;

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
