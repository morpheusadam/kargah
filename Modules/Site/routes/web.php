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

    // The type is a query parameter on the list and a path segment on the
    // editor, and that asymmetry is deliberate rather than an oversight. On the
    // list it is a filter somebody flips between and `#[Url]` already keeps it
    // shareable; on the editor it is half the identity of what is being edited,
    // because id 12 is a different thing under `post` than under `page` and a
    // URL that did not say which would open the wrong one.
    Route::livewire('/content', 'site::content')->name('content');
    Route::livewire('/content/{type}/{id}', 'site::content-edit')->name('content-edit');

    Route::livewire('/comments', 'site::comments')->name('comments');
    Route::livewire('/terms', 'site::taxonomies')->name('taxonomies');
    Route::livewire('/media', 'site::media')->name('media');
    Route::livewire('/seo', 'site::seo')->name('seo');
    Route::livewire('/cache', 'site::cache')->name('cache');
    Route::livewire('/settings', 'site::settings')->name('settings');
});
