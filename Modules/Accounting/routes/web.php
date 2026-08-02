<?php

use Illuminate\Support\Facades\Route;

/*
| Accounting module - invoices, expenses, clients, reports.
*/

Route::middleware('auth')->prefix('accounting')->name('accounting.')->group(function () {
    Route::livewire('/invoices', 'accounting::invoices')->name('invoices');
    Route::livewire('/expenses', 'accounting::expenses')->name('expenses');
    Route::livewire('/clients', 'accounting::clients')->name('clients');
    Route::livewire('/reports', 'accounting::reports')->name('reports');
});
