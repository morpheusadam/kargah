<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A board is a wall of lists. It may belong to a company, or be personal.
 *
 * `slug` exists because the board lives in the address bar (`/projects?board=…`)
 * and a URL that survives a rename is worth more than one that reads as an id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 190)->unique();
            $table->string('name', 190);

            // A palette key, never a CSS class. Class names are resolved through
            // a PHP map so the Tailwind scanner can see every one of them.
            $table->string('colour', 30)->default('primary');

            $table->text('description')->nullable();

            // Foreign keys point at Core, never sideways.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->unsignedInteger('position')->default(0);

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['archived_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
