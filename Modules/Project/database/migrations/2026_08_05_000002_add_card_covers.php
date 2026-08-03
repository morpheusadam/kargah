<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A card cover: a colour band or a picture taken from one of the card's own
 * attachments, either half or full height. A full cover hides the card's
 * badges on the board — that rule lives in `Card::coverPresentation()` and
 * `coverHidesBadges()`, not here; this migration only makes room for it.
 *
 * **`cover_attachment_id` carries no foreign key.** `attachments` belongs to
 * `Modules\Data`, and this module reaches it only through
 * `Modules\Data\Contracts\AttachmentService`, never through its table —
 * `email_attachments.attachment_id` in Mailbox already sets this precedent,
 * for the same reason. A card whose cover attachment was later deleted is
 * still a valid row: `Card::coverPresentation()` resolves the id through the
 * service on every read and treats a miss as "no cover" rather than 500ing,
 * so nothing here enforces referential integrity that column cannot express
 * safely anyway.
 *
 * `cover_type` is nullable and `null` means no cover — the common case — so a
 * plain `NOT NULL DEFAULT` would have to invent a fourth state. `cover_size`
 * does default to `'half'` because it is meaningless without a cover, but a
 * card with no cover carries a harmless default rather than `null` needing
 * its own branch everywhere the column is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('cover_type', 10)->nullable()->after('completed_at');
            $table->string('cover_colour', 30)->nullable()->after('cover_type');
            $table->unsignedBigInteger('cover_attachment_id')->nullable()->after('cover_colour');
            $table->string('cover_size', 10)->default('half')->after('cover_attachment_id');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['cover_type', 'cover_colour', 'cover_attachment_id', 'cover_size']);
        });
    }
};
