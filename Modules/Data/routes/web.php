<?php

use Illuminate\Support\Facades\Route;

/*
| Data module - files, credentials, links/bots, GitHub repos, backups.
*/

Route::middleware('auth')->prefix('data')->name('data.')->group(function () {
    Route::livewire('/files', 'data::files')->name('files');
    Route::livewire('/passwords', 'data::passwords')->name('passwords');
    Route::livewire('/links', 'data::links')->name('links');
    Route::livewire('/repos', 'data::repos')->name('repos');
    Route::livewire('/backups', 'data::backups')->name('backups');
});
