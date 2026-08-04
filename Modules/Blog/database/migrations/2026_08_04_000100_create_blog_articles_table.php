<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What belongs to an article as a whole, rather than to one destination.
 *
 * A published article is a `post_targets` row and a WordPress site is a
 * `social_accounts` row — see `Modules\Social\Support\Networks::WORDPRESS` for
 * why, and for what that decision buys. This table is the small remainder: the
 * things that are true of the article no matter where it goes.
 *
 * **The line between this table and `post_targets.options` is not arbitrary.**
 * A title is the article's; a `draft`-or-`publish` decision is a destination's,
 * because the same article is perfectly reasonably a draft on the client's site
 * and published on your own. Categories and tags are the same: the taxonomies
 * are the site's, so the names the person picked are per-target and the term ids
 * they resolve to are meaningless anywhere else. Everything per-destination
 * therefore lives on the target and nothing about it is here.
 *
 * `post_id` is unique and cascades. One row per post, and deleting the post
 * takes the article with it — there is nothing an article means without the post
 * that carries its body and its destinations.
 *
 * `featured_attachment_id` is **not** a foreign key, and that is deliberate.
 * Attachments belong to `Modules\Data`, which soft-deletes them; a real
 * constraint here would either forbid Data from tidying a file or, with a
 * cascade, let deleting one picture delete somebody's article. A dangling id is
 * a missing cover image, which is a thing a page can render around.
 *
 * Adding this table is safe on SQLite. What is not safe is ever dropping a
 * column from `posts` while this foreign key exists: the table rebuild fires
 * every `ON DELETE CASCADE` pointing at it, and `PRAGMA foreign_keys` is a no-op
 * inside the transaction a migration runs in. That is the same trap that keeps
 * `posts.media` in the schema — see the docblock on `Modules\Social\Models\Post`
 * — and this table has now made the cost of springing it slightly higher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_articles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->unique()->constrained('posts')->cascadeOnDelete();

            // Long enough for a headline nobody would defend and short enough
            // to index. WordPress's own column is unbounded text, so this is
            // Kargah's limit rather than the network's; the composer says so.
            $table->string('title', 200);

            // Null means "let WordPress make one from the title", which is what
            // it does well and what most people want.
            $table->string('slug', 200)->nullable();

            $table->text('excerpt')->nullable();

            // Where the article was first published, when that is somewhere
            // else. Kargah has no public page for an article, so this always
            // points away from Kargah — see
            // `Modules\Blog\Services\WordPressPublisher` for how it reaches the
            // post and for the two neater implementations that do not work
            // without a plugin.
            $table->string('canonical_url', 500)->nullable();

            // An id from Data's attachments, not a foreign key. See the class
            // docblock.
            $table->unsignedBigInteger('featured_attachment_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_articles');
    }
};
