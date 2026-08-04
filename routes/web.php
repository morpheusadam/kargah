<?php

use App\Http\Middleware\RequireTwoFactorChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Application routes
|--------------------------------------------------------------------------
| Kargah has no public landing page. The root URL sends visitors straight to
| the admin login. Module routes are registered by each module's own
| RouteServiceProvider, not here.
*/

/*
 * 🔴 A named redirect, not `Route::redirect('/', '/login')`.
 *
 * `Route::redirect()` hands its target to `RedirectController` as a literal
 * string and Laravel emits it unchanged, so an install served from a
 * subdirectory answers `Location: /login` — the site root, not the panel's.
 * Measured on 5 August 2026 deploying to `lavzen.com/panel`: the root URL sent
 * the browser to the WordPress login page one level up, while every other route
 * redirected correctly, because every other route builds its URL through
 * `route()` and `route()` knows the base path.
 *
 * `redirect()->route()` resolves against the request, so this works at a domain
 * root and in a subdirectory without either of them being configured.
 */
Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::login')->name('login');

    // The second half of signing in. Behind `guest` because nobody is logged in
    // yet — a correct password with a confirmed second factor buys a pending
    // challenge, not a session — and behind RequireTwoFactorChallenge so the URL
    // is not a page for anyone who has not got that far. The class is named
    // rather than aliased: one route uses it, and an alias would only be a
    // second place to look. See `App\Support\TwoFactorChallenge`.
    Route::livewire('/two-factor-challenge', 'pages::two-factor-challenge')
        ->middleware(RequireTwoFactorChallenge::class)
        ->name('two-factor.challenge');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');

    Route::prefix('settings')->name('settings.')->group(function () {
        // Named, for the reason the root route above gives at length.
        Route::get('/', fn () => redirect()->route('settings.profile'));
        Route::livewire('/profile', 'pages::settings.profile')->name('profile');
        Route::livewire('/security', 'pages::settings.security')->name('security');
        Route::livewire('/appearance', 'pages::settings.appearance')->name('appearance');
        Route::livewire('/notifications', 'pages::settings.notifications')->name('notifications');
    });

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
