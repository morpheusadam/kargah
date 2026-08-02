<?php

use Illuminate\Support\Facades\Route;

/*
| Data module - files, credentials, links/bots, GitHub repos, backups.
*/

Route::middleware('auth')->prefix('data')->name('data.')->group(function () {
    Route::livewire('/files', 'data::files')->name('files');

    Route::livewire('/passwords', 'data::passwords')->name('passwords');
    Route::livewire('/passwords/create', 'data::credential-edit')->name('credential-create');

    Route::livewire('/links', 'data::links')->name('links');
    Route::livewire('/links/create', 'data::link-edit')->name('link-create');

    Route::livewire('/repos', 'data::repos')->name('repos');
    Route::livewire('/repos/{repo}', 'data::repo-show')->name('repo-show');

    Route::livewire('/backups', 'data::backups')->name('backups');
    Route::livewire('/backups/{backup}', 'data::backup-show')->name('backup-show');
});
