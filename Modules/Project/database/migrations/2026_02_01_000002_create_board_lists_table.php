<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A column on a board.
 *
 * `position` is the same fractional type the cards use, for the same reason:
 * dropping a list between two others should write one row, not renumber the
 * board. See the Position service for the arithmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_lists', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();

            $table->string('name', 190);
            $table->decimal('position', 20, 10)->default(0);

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['board_id', 'archived_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_lists');
    }
};
