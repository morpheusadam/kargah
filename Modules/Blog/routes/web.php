<?php

use Illuminate\Support\Facades\Route;

/*
| Blog - articles, and the composer that sends one to a WordPress site and to
| the social networks in a single intention.
|
| Under /blog rather than /social/articles: an article is not a social post that
| happens to be long, and the person writing one is doing a different job from
| the person firing off a build-log line. The publishing spine underneath is
| Social's either way — see Modules\Social\Support\Networks::WORDPRESS.
*/

Route::middleware('auth')->prefix('blog')->name('blog.')->group(function () {
    Route::livewire('/', 'blog::articles')->name('articles');
    Route::livewire('/compose', 'blog::compose')->name('compose');

    // Editing an article is a different verb from composing one and gets its own
    // page rather than a mode of the composer — the argument is in the docblock
    // on `blog::article-edit`, and the short version is that the composer is the
    // only path through which anything is published and a branch inside it is
    // the wrong place to be wrong.
    //
    // No collision with /compose: that is one segment and this is two, so the
    // static route cannot be swallowed however the router orders them.
    Route::livewire('/{article}/edit', 'blog::article-edit')->name('article-edit');
});
