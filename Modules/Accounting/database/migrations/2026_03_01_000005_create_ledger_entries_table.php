<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger. Append only.
 *
 * Not double-entry: a one-person business does not need a chart of accounts,
 * and the complexity buys correctness guarantees it will not use. What it does
 * need is one table where a balance is *read* rather than recomputed by summing
 * three other tables and hoping.
 *
 * Never updated, never deleted — and deliberately no `deleted_at`. A mistake is
 * corrected by a reversing entry, which is the only way an audit trail stays
 * true. Deleting an invoice does not delete its ledger entries; that is what
 * `reference_type`/`reference_id` being a plain morph rather than a foreign key
 * is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->string('entry_type', 30);   // invoice_payment | expense | fx_conversion | adjustment | reversal

            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('currency', 10);

            // Signed: positive in, negative out.
            $table->decimal('amount', 20, 6);

            $table->string('reporting_currency', 10)->nullable();
            $table->decimal('reporting_amount', 20, 6)->nullable();

            $table->string('description', 255)->nullable();

            // A reversing entry points at the one it undoes, so a corrected
            // mistake reads as two rows rather than a gap.
            $table->foreignId('reverses_id')->nullable()->constrained('ledger_entries')->nullOnDelete();

            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['entry_type', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
