<?php

/*
| Core has no pages of its own.
|
| It is the spine — companies, customers, links, activity, search — and each of
| those is reached through the module that shows it: a customer through
| Accounting's client pages, a card's links through the board. Core exposes
| contracts, not routes.
|
| What used to be here was nwidart's scaffolded `Route::resource('cores', …)`
| pointing at a placeholder controller whose index/create/show/edit rendered
| views that were never written, so a signed-in request got a 500.
| tests/Feature/NoDeadEndpointsTest.php is what stops it coming back.
*/
