<?php

use Illuminate\Support\Facades\Route;

/*
| Mailbox module - IMAP inbox plus bulk campaign engine.
*/

Route::middleware('auth')->prefix('mail')->name('mail.')->group(function () {
    Route::livewire('/inbox', 'mailbox::inbox')->name('inbox');

    Route::livewire('/campaigns', 'mailbox::campaigns')->name('campaigns');
    Route::livewire('/campaigns/create', 'mailbox::campaign-edit')->name('campaign-create');
    Route::livewire('/campaigns/{campaign}', 'mailbox::campaign-show')->name('campaign-show');

    Route::livewire('/contacts', 'mailbox::contacts')->name('contacts');
    Route::livewire('/contacts/import', 'mailbox::contact-import')->name('contact-import');
    Route::livewire('/suppression', 'mailbox::suppression')->name('suppression');

    Route::livewire('/providers', 'mailbox::providers')->name('providers');
    Route::livewire('/providers/{provider}/edit', 'mailbox::provider-edit')->name('provider-edit');
});
