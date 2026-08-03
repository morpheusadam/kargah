<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one person, privately, thinks of one board.
 *
 * **One table for starring and for "recently viewed"**, rather than a
 * `board_stars` pivot beside a `board_views` table. Both are the same shape —
 * a fact about a (user, board) pair that nobody else can see — and the read
 * that matters wants them together: the boards index orders starred first and
 * then wants to show what you looked at last, which over two tables is two
 * joins and two indexes where one will do. A third column later ("collapsed
 * in the sidebar", "hidden from my index") lands here too without another
 * migration.
 *
 * Both timestamps are **nullable, and the null is the state**. A row with a
 * null `starred_at` is a board you have viewed but never starred; a row with a
 * null `last_viewed_at` is one you starred from a list without opening it.
 * That is why unstarring nulls the column instead of deleting the row — the
 * view history on the same row is not the star's to throw away. It also means
 * `starred_at` doubles as *when* you starred it, so a future "recently
 * starred" sort costs nothing.
 *
 * **No `updated_at`, no soft delete.** There is no history worth keeping in a
 * private preference: the previous value of `last_viewed_at` is precisely the
 * thing the next view is meant to replace. `created_at` stays because
 * "following this board since March" is a sentence somebody may want.
 *
 * The unique index is what makes `Board::markViewedBy()` a single `upsert`
 * rather than a read-then-write: without it the upsert has no conflict target
 * and every page view inserts another row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_user_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();

            $table->timestamp('starred_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamp('created_at')->nullable();

            // The upsert conflict target, and the lookup behind
            // `Board::isStarredBy()`. Both of the reads this table exists for
            // start from a user and narrow to a board, in that order.
            $table->unique(['user_id', 'board_id'], 'board_user_states_unique');

            // "The five boards I looked at last" — a user-scoped ordered read,
            // which the unique index above cannot serve because its second
            // column is the board, not the time.
            $table->index(['user_id', 'last_viewed_at'], 'board_user_states_recent_index');

            // The join in `scopeStarredFirstFor()` filters on the user and
            // matches on the board; the unique index leads with the user, so
            // this one leads with the board to keep a board-first plan
            // available when the optimiser prefers it.
            $table->index('board_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_user_states');
    }
};
