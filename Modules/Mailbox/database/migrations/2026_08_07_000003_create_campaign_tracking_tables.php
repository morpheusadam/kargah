<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened a campaign, and which link they followed.
 *
 * `DeliveryEvent` says opens and clicks are deliberately absent from the events
 * a provider reports, and the reason it gives is volume: one row per open would
 * be the largest table in the database within a month. That reason has not
 * changed, so this schema counts rather than logs.
 *
 * **Nothing here is one row per event.** An open is a counter and two timestamps
 * on the recipient row that already exists; a click is one row per
 * (link, recipient) pair with a counter on it. A 500-recipient campaign with
 * four links is therefore at most 2,000 rows however many times it is read, and
 * a person who opens the same message fifty times over a year adds nothing to
 * the row count at all. What is lost is the sequence — Kargah can say "Ada
 * clicked the pricing link nine times, first on Tuesday, last on Friday" but not
 * what she did in between, and no report in this module has ever asked.
 *
 * `campaign_links` is the half that is not about statistics. **The redirect only
 * ever sends somebody to a URL that is already a row in this table**, put there
 * when the message was built. A redirect that took its destination from the
 * request instead would be an open redirect on the sending domain — a
 * vulnerability, and a spam signal that costs the domain the reputation the rest
 * of this module exists to protect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            /*
             * First and last, plus a count, rather than a log.
             *
             * The first is what a unique-open rate counts, the last is what a
             * person reading one recipient's row wants to know, and the count is
             * the only thing that separates a message read once from one read
             * every morning for a fortnight. Three columns answer every question
             * the report asks; a table of opens would answer one more and cost
             * a row per pixel load for ever.
             */
            $table->timestamp('opened_at')->nullable()->after('sent_at');
            $table->timestamp('last_opened_at')->nullable()->after('opened_at');
            $table->unsignedInteger('open_count')->default(0)->after('last_opened_at');

            $table->timestamp('clicked_at')->nullable()->after('open_count');
            $table->timestamp('last_clicked_at')->nullable()->after('clicked_at');
            $table->unsignedInteger('click_count')->default(0)->after('last_clicked_at');
        });

        Schema::create('campaign_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();

            // The destination exactly as it appeared in the body, entity-decoded
            // once so that the redirect sends a person to `a=1&b=2` rather than
            // to `a=1&amp;b=2`. `text` rather than a string: a tracked campaign
            // link is routinely a few hundred characters of UTM parameters, and
            // truncating one silently would send somebody to the wrong page.
            $table->text('url');

            // What the unique index is actually on. A URL is too long to index
            // in full on every engine, and a hash of it is fixed width, so this
            // is the column that makes "register this link, or find the one
            // already registered" a single indexed lookup rather than a scan.
            $table->char('url_hash', 64);

            $table->timestamps();

            // One row per distinct URL per campaign. Registration is therefore
            // idempotent: a chunk that runs twice, or five hundred recipients
            // each carrying the same four links, produce four rows.
            $table->unique(['campaign_id', 'url_hash']);
        });

        Schema::create('campaign_link_clicks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_link_id')->constrained('campaign_links')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->constrained('campaign_recipients')->cascadeOnDelete();

            $table->unsignedInteger('clicks')->default(1);

            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();

            $table->timestamps();

            // The pair is the row, which is what bounds this table to
            // links × recipients and makes the tally an UPDATE rather than an
            // INSERT. It is also what lets the report say how many *people*
            // followed a link, as distinct from how many times it was followed.
            $table->unique(['campaign_link_id', 'campaign_recipient_id']);

            // Read the other way round for one recipient's history.
            $table->index('campaign_recipient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_link_clicks');
        Schema::dropIfExists('campaign_links');

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn([
                'opened_at',
                'last_opened_at',
                'open_count',
                'clicked_at',
                'last_clicked_at',
                'click_count',
            ]);
        });
    }
};
