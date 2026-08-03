<?php

use Illuminate\Support\Facades\Route;

/*
| Platform - the settings page where application passwords are issued.
|
| Under /settings because that is where a person looks for their own
| credentials, not under /platform: the module name is an implementation
| detail and the URL is not.
*/

Route::middleware('auth')->prefix('settings')->group(function () {
    Route::livewire('/application-passwords', 'platform::application-passwords')
        ->name('platform.application-passwords');
});
