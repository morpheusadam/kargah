<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring invoice schedules.
 *
 * A schedule is a template plus a cadence. It is not an invoice and it never
 * becomes one by itself: `accounting:generate-recurring` raises a **draft** on
 * the due date and stops there, because issuing freezes an exchange rate and
 * freezing a rate is a decision a person makes.
 *
 * `next_run_on` is the whole idempotency story. It is the business key of the
 * next occurrence: the generator claims that date, writes the draft, and moves
 * the date forward inside the same transaction. A second run on the same day
 * finds nothing due, which is what makes the job safe on cron — where a missed
 * run and a doubled run are both normal.
 *
 * `lines` is a JSON template rather than a child table because nothing points
 * at an individual template line, and a row per line would buy referential
 * integrity for something that is copied and discarded on every run. The
 * amounts inside it are decimal **strings**, exactly as everywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();

            // Foreign keys point at Core, never sideways.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('title', 120);

            $table->string('currency', 10);
            $table->decimal('tax_percent', 9, 6)->default(0);

            // weekly | monthly | quarterly | yearly
            $table->string('cadence', 20)->default('monthly');

            // Which day a monthly, quarterly or yearly schedule lands on. Null
            // means "keep the day the schedule started on". Clamped to the
            // length of the month, so the 31st still bills in February.
            $table->unsignedTinyInteger('day_of_month')->nullable();

            // The date the next draft is due. Claimed and advanced by the
            // generator; nothing else writes it.
            $table->date('next_run_on');
            $table->date('last_run_on')->nullable();

            // [{description, quantity, unit_price}, …] — decimal strings.
            $table->json('lines');

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Pausing a retainer is not deleting it: a paused retainer usually
            // comes back, and the history of what it raised has to survive.
            $table->softDeletes();

            $table->index(['is_active', 'next_run_on']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
