<?php

use Illuminate\Support\Facades\Route;
use Modules\Social\Http\Controllers\SocialController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('socials', SocialController::class)->names('social');
});
