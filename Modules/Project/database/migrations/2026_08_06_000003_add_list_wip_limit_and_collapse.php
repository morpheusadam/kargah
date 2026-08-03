<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cap on how many cards a list should hold, and whether a person has folded
 * that list away.
 *
 * The two are together because they are the same shape of change to the same
 * column, and because they split the same way every time: **a WIP limit is a
 * property of the list**, agreed by whoever works the board, and **a collapse
 * is a property of the person looking at it**. Folding a column away to see
 * the one next to it must not fold it away for anybody else, and on a
 * single-user install that distinction still matters — it is what stops a
 * collapse leaking into an export, a print view or the API.
 *
 * So `wip_limit` is a column on `board_lists`, and the collapse gets its own
 * per-user table, deliberately the same shape as `board_user_states`: a
 * nullable timestamp rather than a boolean, so the row records *when* somebody
 * folded the column, and an absent row and a null timestamp both read as open.
 *
 * `wip_limit` is nullable, and null means no limit. Zero would be a limit of
 * zero — a list nothing may enter — which is a legitimate thing to want and is
 * therefore not the same value as "unset".
 *
 * Adding a column and adding a table are both safe here. Dropping one would
 * not be: on SQLite a column drop rebuilds the table, which fires every
 * `ON DELETE CASCADE` pointing at it, and `PRAGMA foreign_keys` is a no-op
 * inside the transaction every test runs in. See DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_lists', function (Blueprint $table) {
            $table->unsignedInteger('wip_limit')->nullable()->after('colour');
        });

        Schema::create('board_list_user_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('board_list_id')->constrained('board_lists')->cascadeOnDelete();

            $table->timestamp('collapsed_at')->nullable();

            $table->timestamps();

            // "Which of this person's lists are folded" — one row per person
            // per list, which is what makes the toggle an upsert rather than a
            // read-then-write.
            $table->unique(['user_id', 'board_list_id'], 'board_list_user_states_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_list_user_states');

        Schema::table('board_lists', function (Blueprint $table) {
            $table->dropColumn('wip_limit');
        });
    }
};
