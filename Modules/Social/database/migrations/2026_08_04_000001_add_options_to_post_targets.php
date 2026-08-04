<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-target publishing options, for the networks that need more than a body.
 *
 * `post_targets` already carries `body_override`, because the same thought does
 * not fit two networks the same way. This column is the same idea taken one step
 * further: some destinations need *structure* rather than different prose. A
 * WordPress post has a title, categories, tags, a draft-or-publish decision and
 * a canonical link; an X thread has a chain; an Instagram post has a first
 * comment. None of those are copy, and none of them belong on `posts` — they are
 * per-destination, which is exactly what this table is for.
 *
 * **Why a JSON column rather than a table per concern.** Because the alternative
 * is a `wordpress_post_targets` table, and then a `thread_post_targets` table,
 * each with a nullable one-to-one against a row that already exists, and every
 * one of them joined on every publish run. The keys inside are read by exactly
 * one driver — the one that wrote the contract for them — and no query ever
 * filters on them, so there is nothing a column would buy. `Modules\Data`'s vault
 * makes the same call for the same reason.
 *
 * **Nullable, and every reader defaults to `[]`.** Five of the six drivers
 * shipping today ignore this column entirely, and a target written before this
 * migration ran must keep publishing exactly as it did. See
 * `Modules\Social\Services\Publishers\TakesTargetOptions` for how a driver opts
 * in to being handed it at all.
 *
 * Adding a column is safe on SQLite. Dropping one is not — it rebuilds the table
 * and fires every `ON DELETE CASCADE` pointing at it, inside a transaction where
 * `PRAGMA foreign_keys` is a no-op. See the docblock on
 * `Modules\Social\Models\Post` for the full account of that trap; it is why
 * `posts.media` is still there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->json('options')->nullable()->after('body_override');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped. See the class docblock: a column drop on
        // SQLite rebuilds `post_targets`, and `post_targets` is the table the
        // whole retry design rests on. A nullable column nobody reads costs
        // nothing; losing every delivery record costs the install.
    }
};
