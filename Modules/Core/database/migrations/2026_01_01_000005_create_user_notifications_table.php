<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One in-app feed for everything that wants to tell somebody something.
 *
 * A sibling of `activities` and `searchables`, and it lives in Core for the same
 * reason they do: the subject of a notification may be a card, an invoice, an
 * email or a post, which is a morph, and Core is the only module every other one
 * may depend on. Putting this in Project would mean Accounting depending on
 * Project to say an invoice is overdue.
 *
 * **Core never learns what a card is.** The calling module renders `title`,
 * `body` and `url` and hands them over already finished. They are denormalised
 * on purpose, exactly like `searchables`: the alternative is Core resolving a
 * polymorphic subject to a display string, which means Core knowing about every
 * module. It also means a feed of things that already happened keeps the names
 * those things had at the time, and a row whose subject has since been deleted
 * still renders instead of 500ing.
 *
 * **Rows are immutable except for `read_at`.** There is no `updated_at`, because
 * a notification is not edited — it is written once, read once, and eventually
 * pruned. No soft deletes either: 02-data-model.md's rule is "soft deletes on
 * anything a person created", and nobody created this. `core:prune-notifications`
 * hard-deletes it.
 *
 * **`dedupe_key` is what makes a cron sweep safe.** A due-date sweep runs every
 * minute; without an opt-in key it would tell you the same card is due five
 * hundred times before lunch. The unique index is on (user_id, dedupe_key) and
 * the column is nullable, so rows that do not opt in never collide — NULLs are
 * distinct in a unique index on both SQLite and MySQL. `NotificationsTest`
 * proves it rather than assuming it.
 *
 * **`user_notifications`, not `notifications` — and that is why.** Laravel keeps
 * its own `notifications` table for `Illuminate\Notifications\DatabaseNotification`,
 * and `App\Models\User` uses the `Notifiable` trait, so `$user->notifications`
 * and `$user->notify()` both point at that name. The shapes are irreconcilable:
 * the framework's has a uuid primary key and `type`, `notifiable_type` and `data`
 * columns, this one has an auto-increment id and a `subject` morph. Taking the
 * name would have left a latent failure — the first person to send a
 * database-channel notification gets a confusing runtime error rather than a
 * clear one — and the trait cannot simply be dropped from `User`, because
 * `CanResetPassword::sendPasswordResetNotification()` calls `$this->notify()`.
 * Renaming this table is the only fix that is safe unconditionally. The name is
 * accurate on its own terms too: every row belongs to exactly one user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();

            // Who is being told.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // What it is about, if anything in particular. A morph alias, never
            // a class name — see Core's enforced morph map.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // 'card.commented', 'card.due_soon', 'invoice.overdue'
            $table->string('event', 60);

            // Already rendered by the calling module. Core renders a row.
            $table->string('title', 255);
            $table->string('body', 500)->nullable();
            $table->string('url', 255)->nullable();

            // Who caused it, when a person did. A due date arriving has no actor.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('read_at')->nullable();

            // Opt-in idempotence for anything that runs on a schedule.
            $table->string('dedupe_key', 120)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id'], 'user_notifications_subject_index');
            $table->index('read_at');
            $table->index('created_at');

            // The feed's own query: one user, newest first.
            $table->index(['user_id', 'created_at'], 'user_notifications_user_created_index');

            $table->unique(['user_id', 'dedupe_key'], 'user_notifications_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
