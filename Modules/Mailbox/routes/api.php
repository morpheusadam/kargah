<?php

use Illuminate\Support\Facades\Route;
use Modules\Mailbox\Http\Controllers\MailboxController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('mailboxes', MailboxController::class)->names('mailbox');
});
