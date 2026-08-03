<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tallies that both answer "who, on what" — a vote on a card, and an emoji
 * on a comment.
 *
 * They share a migration because they share a shape: a join row with no content
 * of its own, unique on the pair (or triple) that defines it, and cascading
 * away with whichever side is deleted. Neither is soft-deleted and neither
 * carries `updated_at` — the same reasoning as `watchers` and `card_members`.
 * Un-voting removes the row; there is nothing on it worth restoring, and
 * nothing to look back on once it is gone.
 *
 * The unique indexes are what make the toggles idempotent. A double-click that
 * lands as two requests writes one row, not two, without either toggle having
 * to read-then-write under a lock.
 *
 * `comment_reactions.emoji` stores the emoji itself as UTF-8 text, not a
 * shortcode. A shortcode would need a lookup table to render and a migration
 * every time the set changed; the character is what gets drawn, is what SQLite
 * happily round-trips on this connection, and needs no map to read back. Which
 * emoji are *offered* is `Modules\Project\Support\Reactions` — the column takes
 * whatever it is given, and validating against the set is the caller's job, the
 * same way `cards.cover_colour` trusts `Palette` rather than a CHECK
 * constraint. Sixteen characters is room for the longest of them (a heart with
 * its variation selector is two code points) and then some.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_votes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            // "Has this person voted, and how many votes has this card" — the
            // two reads the card back and the card front make, both served
            // from here.
            $table->unique(['card_id', 'user_id']);
        });

        Schema::create('comment_reactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('card_comment_id')->constrained('card_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('emoji', 16);

            $table->timestamp('created_at')->nullable();

            // One person, one of each emoji, per comment. Reacting again with
            // the same emoji is a removal, not a second row.
            $table->unique(['card_comment_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reactions');
        Schema::dropIfExists('card_votes');
    }
};
