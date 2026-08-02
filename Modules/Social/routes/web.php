<?php

use Illuminate\Support\Facades\Route;

/*
| Social module - unified notifications and cross-network publishing.
*/

Route::middleware('auth')->prefix('social')->name('social.')->group(function () {
    Route::livewire('/notifications', 'social::notifications')->name('notifications');
    Route::livewire('/publish', 'social::publish')->name('publish');
    Route::livewire('/calendar', 'social::calendar')->name('calendar');
    Route::livewire('/posts', 'social::posts')->name('posts');
    Route::livewire('/posts/{post}', 'social::post-show')->name('post-show');
    Route::livewire('/accounts', 'social::accounts')->name('accounts');
    Route::livewire('/accounts/connect', 'social::account-connect')->name('account-connect');
});
