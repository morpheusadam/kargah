<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices and their lines.
 *
 * The rule everything here follows from: **an issued invoice never changes its
 * numbers.** The exchange rate is captured at the moment of issue and frozen
 * onto the row, so a later rate move cannot retroactively alter what an invoice
 * says. That is the difference between an accounting record and a spreadsheet
 * that lies.
 *
 * Every money column is `decimal(20,6)`. Never float, never double.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->string('number', 40)->unique();

            // Foreign keys point at Core, never sideways. A card becomes an
            // invoice line through Core's `links` table, not a column here.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('status', 20)->default('draft');   // draft|sent|paid|part_paid|overdue|void

            // What the client pays in.
            $table->string('currency', 10);
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('tax_percent', 9, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total', 20, 6)->default(0);

            // The owner's own profit-and-loss currency, frozen at issue. Set
            // once in settings; changing it never rewrites history.
            $table->string('reporting_currency', 10)->nullable();
            $table->decimal('reporting_rate', 20, 6)->nullable();
            $table->decimal('reporting_amount', 20, 6)->nullable();

            // Filled only when the buyer is a domestic Turkish company. Turkish
            // tax procedure requires the lira equivalent at the TCMB buying
            // rate for the invoice date, and the liability sits with the issuer.
            $table->decimal('issue_rate_to_try', 20, 6)->nullable();
            $table->string('issue_rate_source', 30)->nullable();
            $table->date('issue_rate_date')->nullable();
            $table->decimal('try_equivalent', 20, 6)->nullable();
            $table->text('rate_note')->nullable();

            // A date, not an instant: an invoice issued on 31 July is issued on
            // 31 July wherever it is read.
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'issued_on']);
            $table->index(['customer_id', 'status']);
            $table->index('due_on');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('description', 255);
            $table->decimal('quantity', 20, 6)->default(1);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('amount', 20, 6)->default(0);

            $table->decimal('position', 20, 10)->default(0);

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
