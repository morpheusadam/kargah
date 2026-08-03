<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer is a person. They may belong to a company or stand alone.
 *
 * `email` is indexed because Mailbox resolves an incoming message to a customer
 * by matching the sender address — that join is what turns an inbox into a CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('name', 190);
            $table->string('email', 190)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('role', 120)->nullable();

            $table->string('avatar_path', 255)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
            $table->index('name');
            $table->index(['company_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
