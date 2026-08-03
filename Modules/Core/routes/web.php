<?php

use Illuminate\Support\Facades\Route;

/*
| Core has almost no pages of its own.
|
| It is the spine — companies, customers, links, activity, search — and each of
| those is reached through the module that shows it: a customer through
| Accounting's client pages, a card's links through the board. Core exposes
| contracts, not routes.
|
| The one exception is the notification feed. It has to live here because the
| table does: a notification's subject may be a card, an invoice, an email or a
| post, so the only module allowed to own it is the one every other module
| already depends on. There is no module whose page this could otherwise be.
|
| Note the two Notifications pages in Kargah are different things and neither is
| the other's fallback. `/social/notifications` (`social.notifications`) is what
| Mastodon and Bluesky said about you; `/notifications` (`core.notifications`)
| is what Kargah has to tell you.
|
| What used to be here was nwidart's scaffolded `Route::resource('cores', …)`
| pointing at a placeholder controller whose index/create/show/edit rendered
| views that were never written, so a signed-in request got a 500.
| tests/Feature/NoDeadEndpointsTest.php is what stops it coming back.
*/

Route::middleware('auth')->group(function () {
    Route::livewire('/notifications', 'core::notifications')->name('core.notifications');
});
