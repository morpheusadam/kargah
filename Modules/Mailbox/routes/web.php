<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\Mailbox\Http\Controllers\DeliveryWebhookController;
use Modules\Mailbox\Http\Controllers\InboundMailController;
use Modules\Mailbox\Http\Controllers\UnsubscribeController;

/*
| Mailbox module - IMAP inbox plus bulk campaign engine.
*/

Route::middleware('auth')->prefix('mail')->name('mail.')->group(function () {
    Route::livewire('/inbox', 'mailbox::inbox')->name('inbox');

    // `mailbox::compose` has no route on purpose. It is a modal nested in the
    // inbox and opened by an `open-compose` event, so a route would render a
    // window nobody asked for on a page with nothing behind it.

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
| The endpoints nobody signs in for.
|
| All of them sit outside the `auth` group because the caller is a mail client,
| a delivery provider or a Cloudflare Worker, not a person with a session, and
| all of them drop CSRF because none of those callers has ever seen a Kargah
| page to take a token from. Dropping it per route rather than in
| `bootstrap/app.php` keeps the exemption next to the thing it exempts — a
| global exclusion list is where a stale entry survives the route it was added
| for.
|
| ⚠️ The class named below must be the one the `web` group actually contains,
| which is `PreventRequestForgery`. `ValidateCsrfToken` is a deprecated empty
| subclass of it and still autoloads, so naming that instead is not an error
| anywhere — it simply never matches, and `withoutMiddleware` silently does
| nothing. These three routes answered 419 in production for exactly that
| reason, and no test caught it: Laravel skips CSRF entirely under
| `runningUnitTests()`, so every one of them passed against a middleware that
| was never running.
|
| What replaces the session in each case is written up in the controllers:
| the unsubscribe route is signed *and* carries an HMAC token, the delivery
| webhook is verified against the provider's own signature where it has one and
| against a shared secret where it does not, and the inbound route is verified
| against a shared secret that also decides whether it exists at all.
*/
Route::prefix('mail')->name('mail.')->group(function () {
    Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
        ->middleware('signed')
        ->name('unsubscribe');

    // The one-click POST a mail client makes on the person's behalf, as
    // promised by the `List-Unsubscribe-Post` header.
    Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
        ->middleware('signed')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->name('unsubscribe-post');

    // Per provider row rather than per driver: two accounts on the same
    // provider have two different secrets, and a callback has to be checked
    // against the credentials of the account it claims to come from.
    Route::post('/webhooks/{provider}', DeliveryWebhookController::class)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->name('webhook');

    // Every message the domain receives, handed over by the Email Worker the
    // moment it arrives. One route for all of them: which account a message
    // belongs to is read from the envelope recipient, not from the URL, so
    // adding an address is a routing rule in Cloudflare and nothing here.
    Route::post('/inbound', InboundMailController::class)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->name('inbound');
});
