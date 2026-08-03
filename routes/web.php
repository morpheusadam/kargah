<?php

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

Route::redirect('/', '/login')->name('home');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::login')->name('login');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::redirect('/', '/settings/profile');
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
