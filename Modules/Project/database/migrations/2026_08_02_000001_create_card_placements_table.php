<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a card sits — moved off the card and onto a row of its own.
 *
 * A card used to belong to exactly one list by foreign key. Mirror cards break
 * that: the same card is shown and edited from several lists, and each showing
 * has its own order in its own column. So `cards.board_list_id` becomes a join
 * table carrying `position` per placement, and `cards.position` goes away
 * entirely — a position is a property of a *placement*, never of a card.
 *
 * Exactly one placement per card carries `is_origin`. That row is where the
 * card lives: the archive restores to it, the drawer moves it, and deleting the
 * list it is in takes the card with it. Every other placement is a mirror, and
 * deleting a mirror never touches the card.
 *
 * **On SQLite this rebuilds `cards`, and the rebuild is why the backfill goes
 * through a staging table.** `board_list_id` carries a foreign key and sits in a
 * composite index, and SQLite's own DROP COLUMN refuses both; Laravel therefore
 * recreates the table and copies the rows. Recreating means dropping the old
 * `cards`, and *that* fires every ON DELETE CASCADE pointing at it. Laravel
 * turns foreign keys off around the rebuild, which is enough when migrations
 * run normally — but `PRAGMA foreign_keys` is a documented no-op inside an open
 * transaction, so the same code run from a wrapped test would silently take the
 * placements it had just written with it. Measured, not assumed.
 *
 * The staging table has no foreign keys, so nothing can cascade into it. Both
 * directions copy what they need there first, then touch `cards`, then write
 * the copy back. MySQL and PostgreSQL take the plain ALTER path and are never
 * in this position; the extra table costs them two statements.
 *
 * `down()` is not quite an exact mirror image, and the difference is stated
 * rather than hidden: `board_list_id` comes back **nullable**. A NOT NULL column
 * cannot be added to a populated table without a default, and a default here
 * would be a foreign key pointing at whichever list happened to be first. Every
 * row is backfilled immediately afterwards, so no card is left without a list.
 */
return new class extends Migration
{
    private const STAGING = 'card_placement_backfill';

    public function up(): void
    {
        $this->createStaging();

        DB::table(self::STAGING)->insertUsing(
            ['card_id', 'board_list_id', 'position', 'created_by'],
            DB::table('cards')->select('id', 'board_list_id', 'position', 'created_by'),
        );

        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['board_list_id', 'archived_at', 'position']);
            $table->dropForeign(['board_list_id']);
            $table->dropColumn(['board_list_id', 'position']);
        });

        Schema::create('card_placements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('board_list_id')->constrained('board_lists')->cascadeOnDelete();

            // The same decimal(20,10) the cards carried, for the same reason:
            // dropping a card between two others is one write, whatever the
            // list holds. See Modules\Project\Support\Position.
            $table->decimal('position', 20, 10)->default(0);

            $table->boolean('is_origin')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A card sits in a list once or not at all. Mirroring twice into the
            // same list is the same mirror, and the index says so.
            $table->unique(['card_id', 'board_list_id']);

            // The board canvas read: one list, in order.
            $table->index(['board_list_id', 'position']);
            $table->index(['card_id', 'is_origin']);
        });

        // One origin placement per card, carrying where the card already sat.
        // Soft-deleted cards included: the row still points at a list, and a
        // restore that landed a card nowhere would be worse than the delete.
        $now = now();

        DB::table(self::STAGING)->orderBy('card_id')->chunk(500, function ($rows) use ($now): void {
            DB::table('card_placements')->insert(
                collect($rows)->map(fn ($row): array => [
                    'card_id' => $row->card_id,
                    'board_list_id' => $row->board_list_id,
                    'position' => $row->position,
                    'is_origin' => true,
                    'created_by' => $row->created_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        });

        Schema::dropIfExists(self::STAGING);
    }

    public function down(): void
    {
        $this->createStaging();

        DB::table(self::STAGING)->insertUsing(
            ['card_id', 'board_list_id', 'position', 'created_by'],
            DB::table('card_placements')
                ->where('is_origin', true)
                ->select('card_id', 'board_list_id', 'position', 'created_by'),
        );

        Schema::dropIfExists('card_placements');

        Schema::table('cards', function (Blueprint $table) {
            $table->foreignId('board_list_id')->nullable()->constrained('board_lists')->cascadeOnDelete();
            $table->decimal('position', 20, 10)->default(0);
        });

        DB::table(self::STAGING)->orderBy('card_id')->chunk(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('cards')->where('id', $row->card_id)->update([
                    'board_list_id' => $row->board_list_id,
                    'position' => $row->position,
                ]);
            }
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->index(['board_list_id', 'archived_at', 'position']);
        });

        Schema::dropIfExists(self::STAGING);
    }

    /** Somewhere to hold the mapping that no cascade can reach. */
    private function createStaging(): void
    {
        Schema::dropIfExists(self::STAGING);

        Schema::create(self::STAGING, function (Blueprint $table) {
            $table->unsignedBigInteger('card_id')->primary();
            $table->unsignedBigInteger('board_list_id');
            $table->decimal('position', 20, 10)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
        });
    }
};
