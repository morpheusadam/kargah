<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklists, their items, and the comment thread.
 *
 * A card carries at least one checklist; the drawer shows them flattened, so a
 * card with a single unnamed checklist reads exactly as the fixture did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table) {
            $table->id();

            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();

            $table->string('name', 120)->default('Checklist');
            $table->decimal('position', 20, 10)->default(0);

            $table->timestamps();

            $table->index(['card_id', 'position']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();

            $table->string('text', 255);
            $table->boolean('is_done')->default(false);
            $table->decimal('position', 20, 10)->default(0);

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['checklist_id', 'position']);
        });

        Schema::create('card_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_comments');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklists');
    }
};
