<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One assignee and one due date per checklist *item*, not just per card.
 *
 * Trello calls this an "advanced checklist item", and the two columns arrive
 * together because they are the pair that makes an item behave like a small
 * card: somebody is carrying it, and it is owed by a day. Converting an item
 * to a real card carries both across, which is only possible once they exist.
 *
 * `due_on` is a **date, not a timestamp**, exactly like `cards.due_on`: an item
 * due on 31 July is due on 31 July wherever it is read, and storing an instant
 * would make the day drift with the reader's timezone. The calendar and the ICS
 * feed both read it as a whole day for the same reason.
 *
 * `assigned_to` is `nullOnDelete`, matching `checklist_items.created_by` right
 * beside it: deleting a person must not delete the work they were carrying.
 *
 * Two added columns, no drop. A column drop on SQLite rebuilds the table and
 * fires every `ON DELETE CASCADE` pointing at it — see DECISIONS.md — so `down()`
 * is written for a real rollback outside a transaction and is not something the
 * test suite ever runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('is_done')->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable()->after('assigned_to');

            // "What is due, across every checklist on the board" — the calendar
            // and the ICS feed both ask exactly this, and neither cares about
            // the undated majority.
            $table->index('due_on');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropIndex(['due_on']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn('due_on');
        });
    }
};
