<?php

namespace Modules\Accounting\Providers;

use Modules\Accounting\Contracts\ExpenseReader as ExpenseReaderContract;
use Modules\Accounting\Contracts\InvoiceReader as InvoiceReaderContract;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Services\ExpenseReader;
use Modules\Accounting\Services\InvoiceReader;
use Modules\Core\Support\MorphMap;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AccountingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Accounting';

    protected string $nameLower = 'accounting';

    /**
     * Command classes to register.
     *
     * Empty and deliberately present: `accounting:fetch-rates` lands here, and
     * having the array already means adding it is one line rather than a
     * question about where commands go.
     *
     * @var string[]
     */
    protected array $commands = [];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // The Platform API is the first consumer outside Accounting. Bound the
        // same way Core binds CustomerReader: an interface in Contracts, a
        // concrete class in Services, resolved by the container so a caller
        // never new()s the implementation directly.
        $this->app->bind(InvoiceReaderContract::class, InvoiceReader::class);
        $this->app->bind(ExpenseReaderContract::class, ExpenseReader::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names. `ledger_entries.reference_type` and the
        // rows in Core's `links` table outlive refactors, and a fully-qualified
        // class name in either column becomes an orphan the moment a model
        // moves — see Modules\Core\Support\MorphMap.
        //
        // Core calls enforce() once every module has had its turn, so anything
        // used polymorphically and missing here throws rather than quietly
        // writing a class name.
        MorphMap::register([
            'invoice' => Invoice::class,
            'invoice_line' => InvoiceLine::class,
            'payment' => Payment::class,
            'expense' => Expense::class,
            'ledger_entry' => LedgerEntry::class,
        ]);
    }
}
