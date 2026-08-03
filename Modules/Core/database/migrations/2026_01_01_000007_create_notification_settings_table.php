<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The handful of notification scalars that exist once per person, not once
 * per person per event — see `2026_01_01_000006_create_notification_preferences_table`
 * for why that split is a second table rather than extra columns there or a
 * JSON blob on `users`.
 *
 * `quiet_hours_from` and `quiet_hours_to` are plain `H:i` strings, not a SQL
 * `time` column: SQLite has no native time type and stores one as text
 * regardless, and a string sidesteps every driver's own rules about how a
 * bare time is parsed and compared. The wall-clock arithmetic that matters —
 * a window that wraps midnight, a user's own timezone — happens in
 * `Modules\Core\Services\NotificationPreferences::inQuietHours()`, in PHP,
 * never in SQL.
 *
 * **Absent means the defaults `NotificationEvents` documents** — `daily`,
 * quiet hours off — exactly like the per-event table. No row is seeded here
 * either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'instant' | 'daily' | 'weekly' | 'off' — validated against
            // `NotificationEvents::DIGESTS` before it ever reaches here.
            $table->string('digest', 10);

            $table->boolean('quiet_hours_enabled');

            // 'H:i', local to the user's own timezone. Nullable because a row
            // is only ever written once quiet hours have been touched; when
            // `quiet_hours_enabled` is false these may still hold the last
            // values the person set, so turning the switch back on remembers
            // them instead of resetting to the defaults.
            $table->string('quiet_hours_from', 5)->nullable();
            $table->string('quiet_hours_to', 5)->nullable();

            $table->timestamps();

            // One settings row per person; `save()` upserts against it.
            $table->unique('user_id', 'notification_settings_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
