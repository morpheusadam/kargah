<?php

use Illuminate\Support\Facades\Route;

/*
| Social module - unified notifications and cross-network publishing.
*/

/*
| The curator's settings live under /settings, not under /social.
|
| Same reasoning `Modules\Platform` gives for putting application passwords
| there: the module name is an implementation detail and the URL is not. Somebody
| looking for "what does Kargah post, and when" looks in settings, alongside the
| assistant that writes it — which is also where the settings-nav search will
| find it, because that partial's tab list is keyed on the route name.
*/
Route::middleware('auth')->prefix('settings')->group(function () {
    Route::livewire('/curation', 'social::curation-settings')->name('social.curation-settings');
});

Route::middleware('auth')->prefix('social')->name('social.')->group(function () {
    Route::livewire('/notifications', 'social::notifications')->name('notifications');
    Route::livewire('/curated', 'social::curated')->name('curated');
    Route::livewire('/publish', 'social::publish')->name('publish');
    Route::livewire('/calendar', 'social::calendar')->name('calendar');
    Route::livewire('/posts', 'social::posts')->name('posts');
    Route::livewire('/posts/history', 'social::post-history')->name('post-history');
    Route::livewire('/posts/{post}', 'social::post-show')->name('post-show');
    Route::livewire('/accounts', 'social::accounts')->name('accounts');
    Route::livewire('/accounts/connect', 'social::account-connect')->name('account-connect');
});
