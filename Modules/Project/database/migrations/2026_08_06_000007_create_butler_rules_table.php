<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Butler: one table for all three synchronous command types.
 *
 * A rule, a card button and a board button are the same object — a trigger,
 * a set of conditions that qualify it, and an ordered chain of actions. Only
 * the trigger differs: a rule's trigger is something that happened, a button's
 * trigger is somebody pressing it. Splitting them into three tables would mean
 * three copies of the same `conditions`/`actions` columns and three copies of
 * every query, so `kind` carries the difference instead.
 *
 * `trigger` is null for both button kinds and that is the honest shape: there
 * is no event to name. It is *not* defaulted to a sentinel string, because a
 * query for "every rule listening for card.created" should not have to know
 * about a sentinel.
 *
 * `conditions` and `actions` are JSON rather than two more tables. They are
 * read as a whole and written as a whole — nothing ever queries "every rule
 * whose third action is archive" — and an ordered chain in a child table needs
 * a position column and a join to reproduce something the array already is.
 * The same reasoning the module already applies to `custom_fields.options`.
 *
 * Everything is scoped to a board. A rule that fired across boards would need
 * to answer "which board's list is 'Done'", and there is no answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('butler_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();

            // 'rule' | 'card_button' | 'board_button'. A string rather than a
            // CHECK constraint, for the same reason `cards.cover_type` is one:
            // the set is owned by PHP (Modules\Project\Butler\Kind) and adding
            // the two scheduled kinds later should not need a migration.
            $table->string('kind', 20)->default('rule');

            $table->string('name');

            // Null for buttons. The event key a rule listens for, e.g.
            // 'card.moved_into_list' — see Modules\Project\Butler\Triggers.
            $table->string('trigger', 60)->nullable();

            // The qualifier on the trigger itself: which list, which label,
            // which person. Empty means "any".
            $table->json('trigger_config')->nullable();

            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();

            $table->boolean('is_enabled')->default(true);

            // Buttons are drawn in this order; rules ignore it.
            $table->integer('position')->default(0);

            // A `ki-filled ki-*` icon name for a button's face.
            $table->string('icon', 60)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Cheap evidence that a rule is or is not doing anything, which is
            // the first question anybody asks of automation they wrote a month
            // ago. Incremented with an atomic `increment()`, never read-then-write.
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // The one hot read: "everything on this board listening for X".
            $table->index(['board_id', 'kind', 'is_enabled']);
            $table->index(['trigger', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('butler_rules');
    }
};
