<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\BoardController;
use Modules\Platform\Http\Controllers\CardController;
use Modules\Platform\Http\Controllers\CompanyController;
use Modules\Platform\Http\Controllers\CustomerController;
use Modules\Platform\Http\Controllers\EmailController;
use Modules\Platform\Http\Controllers\ExpenseController;
use Modules\Platform\Http\Controllers\InvoiceController;
use Modules\Platform\Http\Controllers\VaultController;
use Modules\Platform\Http\Controllers\WhoamiController;

/*
| The HTTP API.
|
| Thirty scaffolded endpoints were removed in phase 7 because they were dead:
| nwidart writes an apiResource and a placeholder controller into every new
| module, pointing at views nobody wrote. tests/Feature/NoDeadEndpointsTest.php
| walks the routing table to stop them coming back, and it knows every real
| endpoint below by name in its allowlist.
|
| Every route here declares the scope it needs — an endpoint reachable without
| one is an endpoint nobody can revoke access to short of deleting the whole
| credential.
|
| Everything is a GET except two: issuing an invoice, and revealing a vault
| entry. Both write — one freezes an exchange rate onto a legal document, the
| other writes an activity entry and moves `last_revealed_at` — and neither
| belongs behind a verb an intermediary is free to cache, prefetch or replay.
|
| Still absent, and each for the same reason rather than for want of a route:
| there is no contract to go through. Writing a card needs Project's
| `CardService`, sending mail needs Mailbox's `Delivery`, and search across
| everything needs an index nothing writes to. All three are concrete classes or
| absent features in namespaces Platform may not import. See the report.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('/whoami', WhoamiController::class)
        ->middleware(['app-password', 'scope:core:read'])
        ->name('whoami');

    Route::middleware('app-password')->group(function () {

        /* Core — customers and companies ---------------------------------- */

        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('scope:core:read')
            ->name('customers.index');

        Route::get('/customers/{customer}', [CustomerController::class, 'show'])
            ->middleware('scope:core:read')
            ->whereNumber('customer')
            ->name('customers.show');

        Route::get('/companies', [CompanyController::class, 'index'])
            ->middleware('scope:core:read')
            ->name('companies.index');

        Route::get('/companies/{company}', [CompanyController::class, 'show'])
            ->middleware('scope:core:read')
            ->whereNumber('company')
            ->name('companies.show');

        /* Project — boards, lists and cards -------------------------------- */

        Route::get('/boards', [BoardController::class, 'index'])
            ->middleware('scope:project:read')
            ->name('boards.index');

        // By slug, not by id: it is what the contract takes and what the board
        // page already puts in a URL, so a client can use what it read there.
        Route::get('/boards/{board}', [BoardController::class, 'show'])
            ->middleware('scope:project:read')
            ->name('boards.show');

        Route::get('/boards/{board}/lists', [BoardController::class, 'lists'])
            ->middleware('scope:project:read')
            ->name('boards.lists');

        Route::get('/lists/{list}/cards', [BoardController::class, 'cards'])
            ->middleware('scope:project:read')
            ->whereNumber('list')
            ->name('lists.cards');

        // `filter` is required — see CardController. There is no "every card".
        Route::get('/cards', [CardController::class, 'index'])
            ->middleware('scope:project:read')
            ->name('cards.index');

        Route::get('/cards/{card}', [CardController::class, 'show'])
            ->middleware('scope:project:read')
            ->whereNumber('card')
            ->name('cards.show');

        /* Accounting — invoices and expenses -------------------------------- */

        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware('scope:accounting:read')
            ->name('invoices.index');

        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware('scope:accounting:read')
            ->whereNumber('invoice')
            ->name('invoices.show');

        // Its own endpoint and its own scope: issuing freezes an exchange rate
        // onto a legal document, which is not something a read-scoped credential
        // — or a PATCH that happens to include the right field — should reach.
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
            ->middleware('scope:accounting:write')
            ->whereNumber('invoice')
            ->name('invoices.issue');

        Route::get('/expenses', [ExpenseController::class, 'index'])
            ->middleware('scope:accounting:read')
            ->name('expenses.index');

        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])
            ->middleware('scope:accounting:read')
            ->whereNumber('expense')
            ->name('expenses.show');

        /* Mailbox — messages and threads ------------------------------------ */

        Route::get('/customers/{customer}/emails', [EmailController::class, 'forCustomer'])
            ->middleware('scope:mailbox:read')
            ->whereNumber('customer')
            ->name('customers.emails');

        Route::get('/emails', [EmailController::class, 'index'])
            ->middleware('scope:mailbox:read')
            ->name('emails.index');

        Route::get('/emails/{email}', [EmailController::class, 'show'])
            ->middleware('scope:mailbox:read')
            ->whereNumber('email')
            ->name('emails.show');

        Route::get('/threads/{thread}', [EmailController::class, 'thread'])
            ->middleware('scope:mailbox:read')
            ->whereNumber('thread')
            ->name('threads.show');

        /* Data — the vault --------------------------------------------------- */

        Route::get('/vault', [VaultController::class, 'index'])
            ->middleware('scope:data:read')
            ->name('vault.index');

        // Before `/vault/{credential}`, and the parameter is numeric anyway —
        // two guards rather than one, because a route ordering bug here would
        // present as a 404 on a working endpoint rather than as anything
        // obvious.
        Route::get('/vault/categories', [VaultController::class, 'categories'])
            ->middleware('scope:data:read')
            ->name('vault.categories');

        Route::get('/vault/{credential}', [VaultController::class, 'show'])
            ->middleware('scope:data:read')
            ->whereNumber('credential')
            ->name('vault.show');

        // `data:reveal`, not `data:read`, and POST rather than GET. Listing the
        // vault and decrypting an entry are different powers, and a decrypt
        // writes an activity entry — it is an action, not a resource read. The
        // whole argument is in VaultController's docblock.
        Route::post('/vault/{credential}/reveal', [VaultController::class, 'reveal'])
            ->middleware('scope:data:reveal')
            ->whereNumber('credential')
            ->name('vault.reveal');
    });
});
