<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board backgrounds, list colours, and the label palette's move from semantic
 * keys to Trello's own ten.
 *
 * **Backgrounds.** Three kinds, one row: `background_type` is 'colour' |
 * 'gradient' | 'photo'. `background_key` holds a `Palette` key for the first
 * two and is `null` for a photo. `background_attachment_id` carries no
 * foreign key, for the same reason `cards.cover_attachment_id` does not (see
 * `2026_08_05_000002_add_card_covers.php`): `attachments` belongs to
 * `Modules\Data`, and this module reaches it only through
 * `Modules\Data\Contracts\AttachmentService`, never through its table.
 * `background_text_tone` is the light/dark toggle a board's own cards read to
 * stay legible — stored rather than guessed from the image, because guessing
 * wrongly makes a board unreadable and there is no cheap way to detect it from
 * a byte stream on a shared host.
 *
 * A fresh board is `background_type = 'colour'` with `background_key = null`,
 * which every reader treats as "nothing chosen yet" — the canvas keeps
 * whatever its default has always been, rather than a fourth type existing
 * purely to mean "none".
 *
 * **List colour.** One nullable column, `board_lists.colour`. Null means no
 * header colour, which is every list that exists before this migration runs
 * and the sensible default after it.
 *
 * **Labels.** `Palette` gains Trello's ten colours alongside the semantic keys
 * boards, lists and due-date badges still use — see the class docblock. Every
 * *existing* label row is remapped from whichever of the seven semantic keys
 * it held to the nearest of the ten, because a label called "Bug" reading
 * `destructive` is a colour and a system meaning wearing the same string.
 * `LABEL_COLOUR_MAP` is bijective over the only keys that could exist in this
 * column before today — `Palette` had exactly seven — so `down()` inverts it
 * verbatim and no label's original colour needs recording anywhere else.
 */
return new class extends Migration
{
    /**
     * Old semantic key => new Trello key. 'pink' maps to itself: it already
     * existed as a selectable label colour and Trello's own pink is the value
     * it already held.
     *
     * @var array<string, string>
     */
    private const LABEL_COLOUR_MAP = [
        'primary' => 'blue',
        'success' => 'green',
        'info' => 'sky',
        'warning' => 'yellow',
        'destructive' => 'red',
        'neutral' => 'black',
        'pink' => 'pink',
    ];

    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->string('background_type', 20)->default('colour')->after('colour');
            $table->string('background_key', 40)->nullable()->after('background_type');
            $table->unsignedBigInteger('background_attachment_id')->nullable()->after('background_key');
            $table->string('background_text_tone', 10)->default('light')->after('background_attachment_id');
        });

        Schema::table('board_lists', function (Blueprint $table) {
            $table->string('colour', 30)->nullable()->after('position');
        });

        foreach (self::LABEL_COLOUR_MAP as $from => $to) {
            if ($from === $to) {
                continue;
            }

            DB::table('labels')->where('colour', $from)->update(['colour' => $to]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::LABEL_COLOUR_MAP, true) as $from => $to) {
            if ($from === $to) {
                continue;
            }

            DB::table('labels')->where('colour', $to)->update(['colour' => $from]);
        }

        Schema::table('board_lists', function (Blueprint $table) {
            $table->dropColumn('colour');
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn([
                'background_type',
                'background_key',
                'background_attachment_id',
                'background_text_tone',
            ]);
        });
    }
};
