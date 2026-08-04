<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which KDV exemption, if any, an invoice was raised under.
 *
 * Turkey's standard KDV on professional services is 20%. An invoice to a
 * foreign client *may* be zero-rated as an export of services under exemption
 * code 302, but only if four cumulative conditions all hold, and whether they
 * do is a judgement the freelancer makes with their accountant for each
 * invoice. So this column exists to record a decision somebody made, never to
 * carry one Kargah inferred: **null is the default and means the standard rate
 * applies.** Nothing writes '302' here except a person confirming all four
 * conditions on the invoice builder.
 *
 * A code rather than a boolean because the code is what the document has to
 * print for a tax office to read it, and because Turkey has more exemption
 * codes than this one — a second is a config line, not another column. The
 * codes and their wording live in `config/accounting.php` under
 * `tax.kdv_exemptions`.
 *
 * Additive only. 🔴 On SQLite, dropping a foreign-keyed column inside a
 * transaction silently deletes rows, and `invoices` has three foreign keys —
 * so `down()` guards the drop and Laravel's SQLite driver rebuilds the table
 * outside the transaction. Adding is the safe direction and is the only one
 * this migration is expected to take.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('kdv_exemption_code', 10)->nullable()->after('tax_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoices', 'kdv_exemption_code')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('kdv_exemption_code');
        });
    }
};
