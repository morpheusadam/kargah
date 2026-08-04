<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates — the document that comes before an invoice.
 *
 * An estimate is a quote: what the work would cost if the client says yes.
 * Nothing has been transacted, so three things that are true of `invoices` are
 * deliberately absent here, and their absence is the design rather than an
 * omission:
 *
 * **No frozen exchange rate.** `invoices` carries `reporting_rate`,
 * `issue_rate_to_try` and their dates because issuing is the moment a figure
 * stops being allowed to move. An estimate has no such moment — the rate that
 * matters is the one in force when the invoice is *issued*, weeks later. A rate
 * frozen here would be a rate nobody agreed to, carried onto a document that is
 * still a proposal.
 *
 * **No tax columns.** KDV is a per-invoice judgement (export of services can be
 * zero-rated, and whether the four conditions hold is the freelancer's call, not
 * software's). The tax rate is set on the invoice the estimate converts into,
 * where it can be argued about with the real dates in front of you.
 *
 * **No subtotal.** With no tax line, a subtotal column would be a second copy of
 * `total` that is always equal to it — two columns that agree today and diverge
 * the first time somebody edits one of them.
 *
 * The conversion link is three columns rather than one, for a reason spelled out
 * on `Estimate::isConverted()`: the foreign key is nulled if an invoice is ever
 * *hard*-deleted, and the fact that this estimate was already converted has to
 * outlive that. `converted_at` is the fact; `converted_invoice_number` is the
 * name it can still say out loud; `converted_invoice_id` is the live link while
 * the row exists, soft-deleted or not.
 *
 * Every money column is `decimal(20,6)`. Never float, never double.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();

            // Its own sequence, EST-0001. An estimate must never consume an
            // invoice number: a declined quote would leave a gap in the invoice
            // book that no rule can account for. See Estimate::nextNumber().
            $table->string('number', 40)->unique();

            // Foreign keys point at Core, never sideways.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // draft|sent|accepted|declined. "expired" is deliberately not one of
            // them — it is a date having passed, derived on read exactly as
            // `Invoice::isOverdue()` is. See Estimate::isExpired().
            $table->string('status', 20)->default('draft');

            $table->string('currency', 10);
            $table->decimal('total', 20, 6)->default(0);

            // Null means the quote was given no expiry, which is a real answer
            // and not the same as one that expired today.
            $table->date('valid_until')->nullable();

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            // What it became. nullOnDelete rather than restrict: force-deleting
            // an invoice must not take the estimate with it, and `converted_at`
            // carries the fact of conversion on its own.
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('converted_invoice_number', 40)->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'valid_until']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('estimate_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();

            $table->string('description', 255);
            $table->decimal('quantity', 20, 6)->default(1);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('amount', 20, 6)->default(0);

            // The same fractional ordering the boards and invoice lines use.
            $table->decimal('position', 20, 10)->default(0);

            $table->timestamps();

            $table->index(['estimate_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_lines');
        Schema::dropIfExists('estimates');
    }
};
