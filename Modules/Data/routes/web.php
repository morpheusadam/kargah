<?php

use Illuminate\Support\Facades\Route;
use Modules\Data\Http\Controllers\AttachmentController;
use Modules\Data\Http\Controllers\BackupController;

/*
| Data module - files, credentials, links/bots, GitHub repos, backups.
*/

Route::middleware('auth')->prefix('data')->name('data.')->group(function () {
    Route::livewire('/files', 'data::files')->name('files');

    // A plain HTTP response with its own headers, which a Livewire round trip
    // cannot produce. The service does the streaming; this is only the route.
    Route::get('/files/{attachment}/download', [AttachmentController::class, 'download'])
        ->whereNumber('attachment')
        ->name('file-download');

    /*
     * The same bytes, offered to the tab rather than to the downloads folder.
     *
     * A card cover is an `<img src>` pointing at a stored attachment, and it
     * wants a URL that is stable and does not expire. `file-download` sends
     * `Content-Disposition: attachment`, which is a request to save rather than
     * display; `file-share` is signed and expires, which is right for something
     * handed to an outsider and wrong for a picture on your own board.
     *
     * So: behind `auth`, inline, permanent. The service already knew how — it
     * takes an `inline` flag — there was simply no authenticated route asking
     * for it.
     */
    Route::get('/files/{attachment}/inline', [AttachmentController::class, 'inline'])
        ->whereNumber('attachment')
        ->name('file-inline');

    Route::livewire('/passwords', 'data::passwords')->name('passwords');
    Route::livewire('/passwords/create', 'data::credential-create')->name('credential-create');

    Route::livewire('/links', 'data::links')->name('links');
    Route::livewire('/links/create', 'data::link-create')->name('link-create');

    Route::livewire('/repos', 'data::repos')->name('repos');
    Route::livewire('/repos/{repo}', 'data::repo-show')->name('repo-show');

    Route::livewire('/backups', 'data::backups')->name('backups');

    // Before the `{backup}` page route, or `download` would be read as an id.
    Route::get('/backups/{backup}/download', [BackupController::class, 'download'])
        ->whereNumber('backup')
        ->name('backup-download');

    Route::livewire('/backups/{backup}', 'data::backup-show')->name('backup-show');
});

/*
 * Shared files.
 *
 * Outside the auth group on purpose: the signature is the authorisation, and it
 * carries an expiry. The alternative — making the storage disk public — would
 * grant permanent access to every file on it, including the ones nobody meant
 * to share.
 */
Route::get('data/share/{attachment}', [AttachmentController::class, 'share'])
    ->middleware('signed')
    ->whereNumber('attachment')
    ->name('data.file-share');
