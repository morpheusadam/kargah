<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The freelance work-scope invoice: a priced line, the work it covers, and the
 * period it covers.
 *
 * The owner bills the way a developer actually bills: one line carries the
 * price and the name of the engagement — "Full website SEO" — and underneath it
 * sits the list of what will be done, with **no price against any of it**. The
 * client reads a single figure and the work behind it, which is the shape a
 * proposal-style invoice has always had and which `invoice_lines` could not
 * express: it had `description` and nothing else to hang the scope on.
 *
 * Three columns, all added, none dropped and none altered.
 *
 * 🔴 That is not a stylistic choice. On SQLite, dropping a foreign-keyed column
 * makes Laravel recreate the table and copy the rows, which fires every
 * `ON DELETE CASCADE` pointing at it — and `PRAGMA foreign_keys` is a
 * documented no-op inside an open transaction, so a test that wraps its
 * migrations silently takes the child rows with it. `card_placements`'
 * migration records the measurement. Adding a column is a plain
 * `ALTER TABLE … ADD COLUMN` with none of that.
 *
 * **`tasks` is JSON, not a text blob split on newlines.** The list is a list —
 * it is reordered, counted and rendered as one `<li>` per item — and a
 * newline-delimited string turns every one of those into a parse. It is
 * nullable rather than defaulting to `[]` so "this line has no scope" and "this
 * line's scope is empty" stay distinguishable; only the first is what an
 * invoice raised before today means.
 *
 * **`starts_on` / `ends_on` are on the invoice, not the line.** The period is a
 * property of the engagement the client agreed to, and putting it on the line
 * would let two lines on one invoice disagree about when the work happened —
 * which is a question nobody could then answer. They are `date`, not
 * `datetime`: a working period is measured in days, and a stored clock time
 * would be a precision the person never typed.
 *
 * None of the three touches a money column, so nothing here changes a total, a
 * frozen reporting figure or a ledger entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->json('tasks')->nullable()->after('description');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->date('starts_on')->nullable()->after('issued_on');
            $table->date('ends_on')->nullable()->after('starts_on');
        });
    }

    /**
     * The down path drops the three columns it added.
     *
     * Safe here despite the warning above, and the difference is worth stating:
     * none of these three carries a foreign key or sits in a composite index, so
     * SQLite's own `DROP COLUMN` handles them and Laravel never rebuilds the
     * table. Nothing cascades because nothing is recreated.
     */
    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn('tasks');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
