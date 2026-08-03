<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only rate history.
 *
 * Rows are never updated. A correction is a new row, and the unique key is on
 * the business date rather than the fetch time, so re-running the fetch job is
 * safe — which is what makes it safe to run from cron.
 *
 * `rate_type` exists because the Turkish central bank publishes a buying rate
 * and a selling rate, and which one an invoice must use is a legal question,
 * not a preference. Keeping them as distinct rows means never having to guess
 * which one a stored number was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            $table->string('base_currency', 10);
            $table->string('quote_currency', 10);

            $table->decimal('rate', 20, 6);

            $table->string('rate_type', 20)->default('market');   // market | tcmb_buy | tcmb_sell
            $table->string('source', 30);                          // frankfurter | tcmb_evds | coingecko | manual

            $table->date('as_of');
            $table->timestamp('fetched_at');

            $table->unique(['base_currency', 'quote_currency', 'rate_type', 'as_of'], 'exchange_rates_business_key');
            $table->index(['base_currency', 'quote_currency', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
