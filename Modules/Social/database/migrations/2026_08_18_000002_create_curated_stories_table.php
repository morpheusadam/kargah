<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the curator has already looked at, and what it made from it.
 *
 * This is the `seen` list from the pipeline this is ported from, which lived in a
 * committed `state.json`. As a table it does two further jobs that file could not.
 *
 * **It remembers refusals, not only publications.** When the model judges a story
 * off-topic, that verdict is written here — so tomorrow's run does not spend
 * another request from a daily free-tier quota re-asking about the same article,
 * which every one of the forty feeds will still be carrying. Without this the
 * quota is spent on the same rejections every morning.
 *
 * **It records which posts a story became.** One story a day becomes one `Post`
 * per network, at four different hours, because the good hour for LinkedIn and
 * the good hour for Instagram in Iran are at opposite ends of the day. Without the
 * link, `/social/posts` shows four unrelated rows and nothing says they were one
 * decision.
 *
 * 🔴 **No column is added to `posts`, and that is on purpose.** Adding one is
 * safe; it is the *dropping* that rebuilds the table on SQLite and fires
 * `post_targets`' ON DELETE CASCADE — see the docblock on
 * `Modules\Social\Models\Post`, where that is set out at length. A join table is
 * a shape nobody will ever need to undo in a hurry, which is the property worth
 * having on a table whose loss would take every delivery record with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curated_stories', function (Blueprint $table) {
            $table->id();

            // The source's own permanent id — `hn:41234567`, a feed's `<guid>`.
            // Indexed rather than unique: two outlets can legitimately produce
            // the same guid string, and a collision should not throw on a cron.
            $table->string('uid', 400);

            // The normalised article address: host without `www.`, no trailing
            // slash, no `/amp`, query dropped. This is the one that actually
            // catches a duplicate, because the same article syndicated to two
            // feeds arrives with different tracking parameters and identical
            // everything else.
            $table->string('url_key', 400);

            $table->string('title', 500);
            $table->string('url', 500);
            $table->string('source_label', 120);
            $table->string('publisher', 190)->nullable();

            // What the ranker gave it, and how many outlets carried it. Kept for
            // the settings page and for judging whether the ranking is behaving,
            // not read by anything that decides.
            $table->decimal('score', 12, 6)->default(0);
            $table->unsignedSmallInteger('sources_count')->default(1);

            // The curator's own day, in its own timezone — not a UTC timestamp.
            // A run at 01:30 UTC is already the next morning in Tehran, and "one
            // post a day" has to mean the reader's day.
            $table->date('chosen_on');

            // A story the model refused, with its reason. Kept so the refusal is
            // not re-purchased tomorrow.
            $table->boolean('was_skipped')->default(false);
            $table->text('skip_reason')->nullable();

            $table->timestamps();

            $table->unique('url_key');
            $table->index('uid');
            $table->index('chosen_on');
        });

        Schema::create('curated_story_posts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('curated_story_id')->constrained('curated_stories')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();

            // Denormalised from the post's targets so this table can be read
            // without joining three deep to answer "which network was this one".
            $table->string('network', 30);

            $table->timestamps();

            // One story produces one post per network. This is what makes a
            // re-run of the same day create nothing rather than a second set.
            $table->unique(['curated_story_id', 'network']);
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curated_story_posts');
        Schema::dropIfExists('curated_stories');
    }
};
