<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, the crypto detail that hangs off one, and expenses.
 *
 * A payment may arrive in a different currency from the invoice: a USD invoice
 * settled in USDT, or a TRY invoice paid weeks later at a different rate.
 * `fx_gain_loss` is the whole of realised foreign-exchange accounting for a
 * one-person business. Unrealised revaluation — restating still-open invoices
 * at today's rate — is a *report*, computed on demand and written nowhere,
 * because nothing has actually happened yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('currency', 10);
            $table->decimal('amount', 20, 6);

            // To the invoice's currency, frozen at payment.
            $table->decimal('settlement_rate', 20, 6)->default(1);
            $table->decimal('applied_amount', 20, 6);      // what it settled, in the invoice's currency
            $table->decimal('fx_gain_loss', 20, 6)->default(0);

            $table->string('method', 30)->default('bank'); // bank | wise | crypto | cash
            $table->timestamp('paid_at');
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['invoice_id', 'paid_at']);
        });

        Schema::create('crypto_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();

            // Not cosmetic. USDT exists on both Tron and Ethereum with
            // different addresses; sending to the wrong network destroys the
            // funds, so the record has to say which.
            $table->string('chain', 20);            // tron | ethereum
            $table->string('token_standard', 20);   // TRC-20 | ERC-20

            $table->string('tx_hash', 100)->unique();
            $table->string('from_address', 100)->nullable();
            $table->string('to_address', 100)->nullable();

            // Stored separately from the invoice amount on purpose: wallets
            // round differently and under- or over-payment by a few micro-units
            // is normal. The delta is a business decision, not something to
            // paper over by assuming they match.
            $table->decimal('amount', 20, 6);
            $table->decimal('network_fee', 20, 6)->nullable();

            $table->unsignedBigInteger('block_number')->nullable();
            $table->unsignedInteger('confirmations')->default(0);
            $table->string('status', 20)->default('pending');   // pending | confirmed | failed
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'chain']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('vendor', 190);
            $table->string('category', 60)->nullable();
            $table->text('description')->nullable();

            $table->string('currency', 10);
            $table->decimal('amount', 20, 6);

            $table->string('reporting_currency', 10)->nullable();
            $table->decimal('reporting_rate', 20, 6)->nullable();
            $table->decimal('reporting_amount', 20, 6)->nullable();

            $table->boolean('is_billable')->default(false);
            $table->foreignId('rebilled_on_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->date('spent_on');
            $table->string('receipt_reference', 190)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['spent_on', 'category']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('crypto_payments');
        Schema::dropIfExists('payments');
    }
};
