<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\CustomerController;
use Modules\Platform\Http\Controllers\EmailController;
use Modules\Platform\Http\Controllers\ExpenseController;
use Modules\Platform\Http\Controllers\InvoiceController;
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
| credential. Boards, lists, cards, the vault and search are not here: the
| contracts they would read through either do not exist yet (Project's board
| surface is mid-refactor; Data has no reader contract for the vault) or are
| too thin to build on (Mailbox's EmailReader). See the report for exactly
| what each would need.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('/whoami', WhoamiController::class)
        ->middleware(['app-password', 'scope:core:read'])
        ->name('whoami');

    Route::middleware('app-password')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('scope:core:read')
            ->name('customers.index');

        Route::get('/customers/{customer}', [CustomerController::class, 'show'])
            ->middleware('scope:core:read')
            ->name('customers.show');

        Route::get('/customers/{customer}/emails', [EmailController::class, 'index'])
            ->middleware('scope:mailbox:read')
            ->name('customers.emails');

        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware('scope:accounting:read')
            ->name('invoices.index');

        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware('scope:accounting:read')
            ->name('invoices.show');

        // Its own endpoint and its own scope: issuing freezes an exchange rate
        // onto a legal document, which is not something a read-scoped credential
        // — or a PATCH that happens to include the right field — should reach.
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
            ->middleware('scope:accounting:write')
            ->name('invoices.issue');

        Route::get('/expenses', [ExpenseController::class, 'index'])
            ->middleware('scope:accounting:read')
            ->name('expenses.index');

        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])
            ->middleware('scope:accounting:read')
            ->name('expenses.show');
    });
});
