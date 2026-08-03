<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Modules\Mailbox\Http\Controllers\DeliveryWebhookController;
use Modules\Mailbox\Http\Controllers\UnsubscribeController;

/*
| Mailbox module - IMAP inbox plus bulk campaign engine.
*/

Route::middleware('auth')->prefix('mail')->name('mail.')->group(function () {
    Route::livewire('/inbox', 'mailbox::inbox')->name('inbox');
    Route::livewire('/compose', 'mailbox::compose')->name('compose');

    Route::livewire('/campaigns', 'mailbox::campaigns')->name('campaigns');
    Route::livewire('/campaigns/create', 'mailbox::campaign-edit')->name('campaign-create');
    Route::livewire('/campaigns/{campaign}', 'mailbox::campaign-show')->name('campaign-show');
    Route::livewire('/campaigns/{campaign}/edit', 'mailbox::campaign-edit')->name('campaign-edit');

    Route::livewire('/contacts', 'mailbox::contacts')->name('contacts');
    Route::livewire('/contacts/import', 'mailbox::contact-import')->name('contact-import');
    Route::livewire('/suppression', 'mailbox::suppression')->name('suppression');

    Route::livewire('/providers', 'mailbox::providers')->name('providers');
    Route::livewire('/providers/{provider}/edit', 'mailbox::provider-edit')->name('provider-edit');
});

/*
| The two endpoints nobody signs in for.
|
| Both sit outside the `auth` group because the caller is a mail client or a
| delivery provider, not a person with a session, and both drop CSRF because
| neither caller has ever seen a Kargah page to take a token from. Dropping it
| per route rather than in `bootstrap/app.php` keeps the exemption next to the
| thing it exempts — a global exclusion list is where a stale entry survives the
| route it was added for.
|
| What replaces the session in each case is written up in the controllers:
| the unsubscribe route is signed *and* carries an HMAC token, and the webhook
| route is verified against the provider's own signature where it has one and
| against a shared secret where it does not.
*/
Route::prefix('mail')->name('mail.')->group(function () {
    Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
        ->middleware('signed')
        ->name('unsubscribe');

    // The one-click POST a mail client makes on the person's behalf, as
    // promised by the `List-Unsubscribe-Post` header.
    Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
        ->middleware('signed')
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->name('unsubscribe-post');

    // Per provider row rather than per driver: two accounts on the same
    // provider have two different secrets, and a callback has to be checked
    // against the credentials of the account it claims to come from.
    Route::post('/webhooks/{provider}', DeliveryWebhookController::class)
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->name('webhook');
});
