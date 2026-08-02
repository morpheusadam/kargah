<?php

use Illuminate\Support\Facades\Route;

/*
| Mailbox module - IMAP inbox plus bulk campaign engine.
*/

Route::middleware('auth')->prefix('mail')->name('mail.')->group(function () {
    Route::livewire('/inbox', 'mailbox::inbox')->name('inbox');
    Route::livewire('/campaigns', 'mailbox::campaigns')->name('campaigns');
    Route::livewire('/contacts', 'mailbox::contacts')->name('contacts');
    Route::livewire('/providers', 'mailbox::providers')->name('providers');
});
