<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour-blind pattern toggle for Trello-style labels — see
 * `Modules\Project\Support\Palette::pattern()`.
 *
 * A global reading preference, not a per-board setting: the same person
 * cannot reliably separate red from green on every board they open, not just
 * this one. That is what puts it on `users` next to `timezone`, `locale` and
 * `date_format` — see `2026_08_03_100000_add_settings_and_two_factor_columns_to_users_table.php`
 * for that precedent — rather than in a new table.
 *
 * Core's `notification_settings` table was the other precedent considered and
 * rejected. It exists because five columns (digest, quiet hours on/off, two
 * time strings) belong together and none of them belongs on `users` itself.
 * One boolean does not earn a table of its own the way that group did, and
 * `Modules\Core` is off limits to this change besides — it owns that table,
 * this migration does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('colour_blind_mode')->default(false)->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('colour_blind_mode');
        });
    }
};
