<?php

use Illuminate\Support\Facades\Route;

/*
| Accounting module - invoices, expenses, clients, reports.
|
| Static segments are declared before wildcard ones so /invoices/create is never
| swallowed by /invoices/{invoice}.
*/

Route::middleware('auth')->prefix('accounting')->name('accounting.')->group(function () {
    Route::livewire('/invoices', 'accounting::invoices')->name('invoices');
    Route::livewire('/invoices/create', 'accounting::invoice-edit')->name('invoice-create');
    Route::livewire('/invoices/{invoice}/edit', 'accounting::invoice-edit')->name('invoice-edit');
    Route::livewire('/invoices/{invoice}', 'accounting::invoice-show')->name('invoice-show');

    Route::livewire('/recurring', 'accounting::recurring')->name('recurring');

    Route::livewire('/expenses', 'accounting::expenses')->name('expenses');
    Route::livewire('/expenses/create', 'accounting::expense-edit')->name('expense-create');

    Route::livewire('/clients', 'accounting::clients')->name('clients');
    Route::livewire('/clients/{client}', 'accounting::client-show')->name('client-show');

    Route::livewire('/reports', 'accounting::reports')->name('reports');
});
