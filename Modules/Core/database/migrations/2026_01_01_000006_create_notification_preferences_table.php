<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user per event: whether they want it in the feed and by email.
 *
 * **Absent means default, and the default lives in code, not in this table.**
 * A user who has never opened `/settings/notifications` has no rows here at
 * all — this migration seeds nothing — and
 * `Modules\Core\Support\NotificationEvents` is what a reader falls back to.
 * The alternative, seeding a row per canonical event for every user, would
 * mean a brand new event added to the product is invisible to everyone who
 * signed up before it existed unless a backfill remembers to run; leaving the
 * table empty and defaulting in code means a new event is on for everybody
 * the moment it ships, exactly like every other row nobody has touched yet.
 *
 * **This table does not hold the digest frequency or the quiet-hours window.**
 * Those are `Modules\Core\Models\NotificationSetting`, one row per user, not
 * one row per user per event — a different lifetime entirely: an event
 * preference is added, edited and removed independently for each of ten (and
 * growing) events, while the digest and quiet hours are two or three scalars
 * that exist exactly once per person. Folding both into this table would mean
 * either repeating the digest and quiet-hours columns on every one of a
 * user's event rows — the same fact stored ten times, out of sync the moment
 * one row is saved and the others are not — or inventing a sentinel row with
 * a null `event` to carry them, which makes every query here need to remember
 * to exclude it. A second table is one query more at read time and avoids
 * both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'card.due_soon', 'invoice.overdue' — the same event strings
            // `Notifier::notify()` accepts, and the same length limit as
            // `user_notifications.event`.
            $table->string('event', 60);

            $table->boolean('in_app');
            $table->boolean('email');

            $table->timestamps();

            // One opinion per person per event; `save()` upserts against it.
            $table->unique(['user_id', 'event'], 'notification_preferences_user_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
