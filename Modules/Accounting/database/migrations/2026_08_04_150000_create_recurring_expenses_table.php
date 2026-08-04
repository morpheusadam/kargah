<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring expense schedules — hosting, a domain, a design tool, the
 * accountant's monthly fee.
 *
 * Deliberately the same shape as `recurring_invoices`, column for column where
 * the two mean the same thing: a template plus a cadence, `next_run_on` as the
 * business key of the next occurrence, `is_active` as a pause that is not a
 * delete, `deleted_at` because a cancelled subscription usually comes back.
 * Kargah already solved this problem for income; a second solution that looked
 * nothing like the first would leave two ways to read the same idea.
 *
 * 🔴 **No rate columns, on purpose.** A schedule never carries an exchange
 * rate. Every expense it records freezes its own `reporting_rate` on its own
 * date, exactly as `⚡expense-edit` freezes one on the date a person types. A
 * rate stored here would be reused for every month the schedule ever ran, and a
 * year of costs would all report at whatever the lira happened to be doing on
 * the afternoon the subscription was set up.
 *
 * 🔴 **No `recurring_expense_id` on `expenses`, either.** An expense recorded
 * here is an ordinary expense from the moment it exists — the same rule the
 * invoice side follows, where a raised draft is an ordinary invoice and
 * deleting the schedule must never reach into the book. The cost is that the
 * expenses list cannot yet say which rows came from a schedule; that is
 * reported rather than solved by a column this task was not asked to add.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();

            // Foreign keys point at Core, never sideways.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('vendor', 190);
            $table->string('category', 60)->nullable();
            $table->text('description')->nullable();

            $table->string('currency', 10);
            $table->decimal('amount', 20, 6);

            // Whether the client agreed to cover it. A recurring cost that is
            // rebilled every month is a real case — a client's own hosting —
            // and so is one that never is, which is why this is a per-schedule
            // decision rather than an assumption either way. `rebilled_on_
            // invoice_id` is deliberately absent: a schedule is never on an
            // invoice, only the individual expenses it records can be.
            $table->boolean('is_billable')->default(false);

            // weekly | monthly | quarterly | yearly
            $table->string('cadence', 20)->default('monthly');

            // Which day a monthly, quarterly or yearly schedule lands on. Null
            // means "keep the day the schedule started on". Clamped to the
            // length of the month, so a bill due on the 31st still lands in
            // February.
            $table->unsignedTinyInteger('day_of_month')->nullable();

            // The date the next expense is due. Claimed and advanced by the
            // generator; nothing else writes it.
            $table->date('next_run_on');
            $table->date('last_run_on')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Cancelling a subscription is not the same as never having had it:
            // the expenses this schedule already recorded are money that really
            // left, and they have to keep making sense.
            $table->softDeletes();

            $table->index(['is_active', 'next_run_on']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
