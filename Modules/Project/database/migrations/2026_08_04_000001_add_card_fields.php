<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The card's own fields that were still missing: a start date, and a
 * per-board sequential number with a short slug.
 *
 * **The number is guaranteed unique per board by a counter column, not by
 * `MAX(number) + 1`.** `boards.next_card_number` is read with `lockForUpdate()`
 * and written back inside its own transaction — see `Card::booted()`, which is
 * where every future card is numbered. `MAX(number) + 1` was rejected because
 * two transactions can read the same maximum before either commits, which is
 * exactly the race this row exists to close.
 *
 * `cards.number` and `cards.slug` are deliberately left nullable at the schema
 * level rather than backfilled into a `NOT NULL` column. Every card reachable
 * through a board has an origin placement and gets numbered by this migration,
 * but a card is not required to have one — see `BoardList::booted()`'s own
 * comment on a card losing its only placement — and a `NOT NULL` column would
 * make that edge case a migration failure instead of an absent number, which
 * is the more honest state for a row nothing can currently reach.
 *
 * There is no database-level uniqueness constraint on `(board, number)`: a
 * card carries no board column at all — see the card-placements decision in
 * DECISIONS.md — so a composite key would need one added back purely for this,
 * which is the retrofit that migration explicitly avoided. Uniqueness rests on
 * the counter being the only writer, which `CardFieldsTest` exercises directly
 * rather than trusting the schema to catch a collision it cannot see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->unsignedInteger('next_card_number')->default(1)->after('position');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->date('start_on')->nullable()->after('description');
            $table->unsignedInteger('number')->nullable()->after('due_on');
            $table->string('slug', 80)->nullable()->after('number');

            $table->index('number');
        });

        $this->backfillNumbers();
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['number']);
            $table->dropColumn(['start_on', 'number', 'slug']);
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('next_card_number');
        });
    }

    /**
     * One pass per board, oldest card first — the order a person would expect
     * `#1` to mean "the first card made here", not an artefact of whatever
     * order the rows happened to come back in.
     */
    private function backfillNumbers(): void
    {
        $boardIds = DB::table('boards')->orderBy('id')->pluck('id');

        foreach ($boardIds as $boardId) {
            $cards = DB::table('cards')
                ->join('card_placements', 'card_placements.card_id', '=', 'cards.id')
                ->join('board_lists', 'board_lists.id', '=', 'card_placements.board_list_id')
                ->where('board_lists.board_id', $boardId)
                ->where('card_placements.is_origin', true)
                ->orderBy('cards.created_at')
                ->orderBy('cards.id')
                ->select('cards.id', 'cards.title')
                ->get();

            $number = 1;

            foreach ($cards as $card) {
                DB::table('cards')->where('id', $card->id)->update([
                    'number' => $number,
                    'slug' => Str::slug((string) $card->title) ?: 'card-'.$card->id,
                ]);

                $number++;
            }

            if ($number > 1) {
                DB::table('boards')->where('id', $boardId)->update(['next_card_number' => $number]);
            }
        }
    }
};
