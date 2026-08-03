<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The card. The thing every other module eventually wants to point at.
 *
 * `position` is `decimal(20,10)` rather than an integer on purpose. Reordering
 * by renumbering is O(n) writes per drag; taking the midpoint between the two
 * neighbours is one write, whatever the list holds. The scale is what bounds
 * how many times a gap can be halved before a rebalance is needed — see
 * `Modules\Project\Support\Position` and `project:rebalance`.
 *
 * `customer_id` is a real foreign key because a card belonging to a customer is
 * ownership, and customers live in Core. A card belonging to an *invoice* is
 * not ownership and goes through Core's `links` table instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_list_id')->constrained('board_lists')->cascadeOnDelete();

            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->decimal('position', 20, 10)->default(0);

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            // A date, not an instant: a card due on 31 July is due on 31 July
            // wherever it is read.
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['board_list_id', 'archived_at', 'position']);
            $table->index('customer_id');
            $table->index('due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
