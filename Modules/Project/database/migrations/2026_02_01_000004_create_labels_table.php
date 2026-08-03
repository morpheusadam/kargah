<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labels belong to a board, and cards wear them.
 *
 * `colour` stores a palette key — 'copy', 'bug', 'finance' — never a CSS class.
 * Tailwind's scanner cannot see a class name built by concatenation, so the
 * key is resolved through a PHP map holding whole class strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();

            $table->string('name', 60);
            $table->string('colour', 30);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['board_id', 'position']);
        });

        Schema::create('card_label', function (Blueprint $table) {
            $table->id();

            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('labels')->cascadeOnDelete();

            $table->unique(['card_id', 'label_id']);
        });

        Schema::create('card_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->unique(['card_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_members');
        Schema::dropIfExists('card_label');
        Schema::dropIfExists('labels');
    }
};
