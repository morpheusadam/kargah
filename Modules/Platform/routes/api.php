<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\WhoamiController;

/*
| The HTTP API — one endpoint, and it is real.
|
| Thirty scaffolded endpoints were removed in phase 7 because they were dead:
| nwidart writes an apiResource and a placeholder controller into every new
| module, pointing at views nobody wrote. tests/Feature/NoDeadEndpointsTest.php
| walks the routing table to stop them coming back, and it knows about this one
| by name.
|
| Boards, invoices, mail and search come later, on top of this authentication.
| Every one of them will declare the scope it needs the same way whoami does —
| an endpoint reachable without a scope is an endpoint nobody can revoke access
| to short of deleting the credential.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('/whoami', WhoamiController::class)
        ->middleware(['app-password', 'scope:core:read'])
        ->name('whoami');
});
