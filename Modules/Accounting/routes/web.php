<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Services\InvoiceDocument;

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

    // Not a Livewire page: the browser is being handed a file. Declared before
    // the wildcard show route so /invoices/41/pdf is not swallowed by it.
    Route::get('/invoices/{invoice}/pdf', function (Invoice $invoice, InvoiceDocument $document) {
        return $document->stream($invoice);
    })->name('invoice-pdf');

    Route::get('/invoices/{invoice}/pdf/download', function (Invoice $invoice, InvoiceDocument $document) {
        return $document->download($invoice);
    })->name('invoice-download');

    Route::livewire('/invoices/{invoice}', 'accounting::invoice-show')->name('invoice-show');

    Route::livewire('/recurring', 'accounting::recurring')->name('recurring');

    Route::livewire('/expenses', 'accounting::expenses')->name('expenses');
    Route::livewire('/expenses/create', 'accounting::expense-edit')->name('expense-create');
    Route::livewire('/expenses/{expense}/edit', 'accounting::expense-edit')->name('expense-edit');

    Route::livewire('/clients', 'accounting::clients')->name('clients');
    Route::livewire('/clients/{client}', 'accounting::client-show')->name('client-show');

    Route::livewire('/reports', 'accounting::reports')->name('reports');
});
