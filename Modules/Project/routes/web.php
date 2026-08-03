<?php

use Illuminate\Support\Facades\Route;
use Modules\Project\Http\Controllers\CalendarFeedController;

/*
| Project module - Trello-style boards.
| Namespace `project::` maps to Modules/Project/resources/views/components.
*/

Route::middleware('auth')->prefix('projects')->name('projects.')->group(function () {
    Route::livewire('/', 'project::boards')->name('boards');
    Route::livewire('/archive', 'project::archive')->name('archive');
    Route::livewire('/table', 'project::table')->name('table');
    Route::livewire('/calendar', 'project::calendar')->name('calendar');
    Route::livewire('/dashboard', 'project::board-dashboard')->name('dashboard');
    Route::livewire('/{board}/settings', 'project::board-settings')->name('board-settings');
});

/*
 * The calendar's `.ics` subscription feed.
 *
 * Outside the `auth` group on purpose, exactly like `data.file-share`: the
 * signature is half the authorisation and a `token` column is the other half,
 * because the feed is polled by software that has never seen — and never
 * will see — a Kargah session. See CalendarFeedController for what a holder
 * of this URL can read.
 */
Route::get('/projects/{board}/feed.ics', CalendarFeedController::class)
    ->where('board', '[A-Za-z0-9-]+')
    ->middleware('signed')
    ->name('projects.calendar-feed');
