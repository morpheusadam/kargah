<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company is a legal entity you bill or are billed by.
 *
 * `default_currency` is a plain string rather than a foreign key to
 * accounting.currencies: Core may not depend on a feature module. Accounting
 * validates the value when it uses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            $table->string('name', 190);
            $table->string('legal_name', 190)->nullable();

            $table->string('tax_number', 50)->nullable();
            $table->string('tax_office', 120)->nullable();

            $table->char('country', 2)->nullable();
            $table->text('address')->nullable();
            $table->string('website', 190)->nullable();

            $table->string('default_currency', 10)->nullable();

            // Drives whether an invoice must carry a lira equivalent. See spec 03.
            $table->boolean('is_domestic')->default(false);

            $table->text('notes')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index(['archived_at', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
