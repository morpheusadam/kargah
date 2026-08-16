<?php

use Illuminate\Support\Facades\Route;

/*
| Site — the website itself, operated from Kargah instead of wp-admin.
|
| Under /site rather than /blog/…: Blog is where an article is written and sent,
| and this is where the thing it was sent to is administered. The two share a
| credential and nothing else, and a person doing one is not doing the other.
|
| Every page here degrades to an explanation rather than an error when no
| WordPress site is connected — see the `site::overview` component. That is the
| state a fresh install is in, so it is the state the routes have to survive.
*/

Route::middleware('auth')->prefix('site')->name('site.')->group(function () {
    Route::livewire('/', 'site::overview')->name('overview');
});
