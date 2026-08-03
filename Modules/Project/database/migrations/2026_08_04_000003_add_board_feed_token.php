<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The revocable half of the calendar feed's signed URL.
 *
 * A Laravel signed URL carries no server-side state: the signature is a MAC
 * over the query string and `APP_KEY`, so it stays valid for ever once issued
 * unless it also carries an `expires` value — and a subscription a calendar
 * client polls for months cannot be given one without silently breaking it.
 * `feed_token` is what makes the link revocable anyway: it travels as one of
 * the signed query parameters, so tampering with it still fails the signature
 * check, and `CalendarFeedController` additionally refuses any request whose
 * token does not match the column's *current* value. Regenerating the column
 * — a write on the calendar page — invalidates every URL issued before it,
 * signature or no signature.
 *
 * Nullable, and generated lazily by `BoardCalendar` the first time a board's
 * calendar page actually asks for a feed link, rather than backfilled here for
 * boards nobody has ever opened that page for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->string('feed_token', 64)->nullable()->unique()->after('colour');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('feed_token');
        });
    }
};
