<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wants to hear about a card, a list, or a board.
 *
 * **One polymorphic table over all three levels**, rather than three separate
 * pivot tables, because the read that matters — "who should hear about this
 * card" — has to resolve card, list *and* board watchers together. Three
 * tables would mean three separate `whereIn` queries with no way to
 * deduplicate a person watching both a card and its board without pulling the
 * ids into PHP first; one table with a `(type, id)` pair lets that whole
 * resolution happen as a single `WHERE ... OR ... OR ...` and a single
 * `unique()` on the result. See `Modules\Project\Services\Watching`.
 *
 * `watchable_type` stores the morph **alias** (`card`, `board_list`, `board`),
 * never a class name — same rule as `activities.subject_type` and
 * `user_notifications.subject_type`, and the same enforced map: aliases
 * outlive a class being renamed or moved, a raw class name does not.
 *
 * **No soft delete, no `updated_at`.** A watcher row is a boolean toggle, not
 * content somebody wrote — the same kind of row as `card_members`, which is
 * not soft-deleted either. Unwatching removes the row outright; there is
 * nothing here worth restoring, and nothing to look back on once it is gone.
 *
 * The unique index is what makes `Watching::watch()` idempotent: watching
 * something twice writes one row, not two, without the service having to
 * read-then-write under a lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchers', function (Blueprint $table) {
            $table->id();

            $table->string('watchable_type', 60);
            $table->unsignedBigInteger('watchable_id');

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            // "Who watches this card/list/board" — the read every producer
            // makes, and it must find every watcher of one thing in one query.
            $table->unique(['watchable_type', 'watchable_id', 'user_id'], 'watchers_unique');

            // "What does this person watch" — for a future "your watched
            // items" page; not built yet, but the shape should not preclude it.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchers');
    }
};
