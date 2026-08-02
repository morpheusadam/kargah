<?php

use Illuminate\Support\Facades\Route;

/*
| Social module - unified notifications and cross-network publishing.
*/

Route::middleware('auth')->prefix('social')->name('social.')->group(function () {
    Route::livewire('/notifications', 'social::notifications')->name('notifications');
    Route::livewire('/publish', 'social::publish')->name('publish');
    Route::livewire('/accounts', 'social::accounts')->name('accounts');
});
