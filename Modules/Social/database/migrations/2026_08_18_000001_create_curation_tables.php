<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily curator's settings, its outlets, and its posting windows.
 *
 * Three tables rather than a config file, because the owner asked for all of it
 * to be adjustable from the settings pages — and a feed list that can only be
 * changed by editing PHP and deploying is a feed list only whoever wrote it can
 * change. `Modules/Social/config/curation.php` survives as the seed these tables
 * are filled from on a fresh install, and stops being read after that.
 *
 * **No foreign key points out of this module except to Core.** The rule is
 * stated in project-guaid/DECISIONS.md and it holds here in a place it was
 * tempting to break: the curator needs an AI provider, `assistant_providers` is
 * `Modules\Platform`'s table, and a column constrained to it would be a sideways
 * dependency in the schema itself. It is not needed — Platform's assistant
 * already has a "Default provider" setting, so the curator asks for the default
 * and the choice stays in the one place it was already made. A second copy of
 * that decision here would be a second thing to keep in step.
 *
 * Times are stored as `HH:MM` strings rather than as `time` columns. They are
 * wall-clock in whatever `curation_settings.timezone` says, never instants, and
 * a `time` column invites Eloquent into casting them against the application's
 * UTC — which is exactly the confusion the string avoids. The conversion to a
 * real UTC timestamp happens once, in `Windows`, at the moment a post is
 * scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row, ever. Kargah publishes as one operator to one set of
         * accounts, so a per-user or per-company split here would be modelling a
         * product this is not — and `Settings::current()` would then have to
         * decide whose row a cron run belongs to, which has no good answer.
         */
        Schema::create('curation_settings', function (Blueprint $table) {
            $table->id();

            // Off until somebody turns it on. This table's whole subject is
            // unattended publishing to live accounts, and a migration must not
            // be what starts that.
            $table->boolean('is_enabled')->default(false);

            // The clock every window below is read against. Not the
            // application's timezone, which is UTC and stays UTC: a window of
            // "19:00 to 23:00" is meaningful only in the reader's own day.
            $table->string('timezone', 64)->default('Asia/Tehran');

            // When the day's story is chosen, in UTC, as HH:MM. Has to be
            // early enough to precede the earliest window of the day — the
            // settings page validates that rather than leaving it to be
            // discovered by a day with no LinkedIn post in it.
            $table->string('curate_at_utc', 5)->default('01:30');

            // Which weekdays use the weekend window, as Carbon's own numbering
            // (Monday 1 … Sunday 7). Thursday and Friday here, because that is
            // the Iranian weekend and LinkedIn is dead on it — but it is a
            // setting because this is exactly the sort of thing that differs by
            // country and should not need a deploy.
            $table->string('weekend_days', 20)->default('4,5');

            // How old a story may be and still be chosen. Individual feeds
            // override it; see `curation_feeds.max_age_hours`.
            $table->unsignedSmallInteger('max_age_hours')->default(72);

            // Below this many characters an RSS standfirst is a bare headline,
            // and a summary written from it can only be the headline again.
            $table->unsignedSmallInteger('min_summary_length')->default(80);

            // How many further candidates to try when the model refuses the
            // best one as off-topic or unpostable. Without this, one awkward
            // story costs the whole day.
            $table->unsignedTinyInteger('spare_candidates')->default(3);

            $table->boolean('hackernews_enabled')->default(true);
            $table->decimal('hackernews_authority', 3, 2)->default(0.75);
            $table->unsignedSmallInteger('hackernews_min_points')->default(50);

            $table->boolean('lobsters_enabled')->default(true);
            $table->decimal('lobsters_authority', 3, 2)->default(0.70);
            // Not optional in practice. Without a floor, a three-point story
            // from a small community outranks a two-hundred-point discussion —
            // see the docblock on the Lobsters source.
            $table->unsignedSmallInteger('lobsters_min_engagement')->default(25);

            $table->timestamps();
        });

        /*
         * The outlets. One row per feed, and `label` is unique for a reason that
         * is the whole point of the ranker: corroboration counts *independent*
         * outlets, so two rows sharing a label would let one publisher count as
         * two agreeing and hand the day to whichever story it happened to cover
         * twice.
         */
        Schema::create('curation_feeds', function (Blueprint $table) {
            $table->id();

            $table->string('label', 120);
            $table->string('url', 500);

            // 0..1, and it means one thing only: how far to trust this outlet
            // when there is no other signal. Not a judgement of its journalism.
            // A decimal on SQLite is stored as a float — see DECISIONS.md — and
            // that is fine here in a way it never is for money: nothing is
            // summed, and two hundredths of drift changes no outcome.
            $table->decimal('authority', 3, 2)->default(0.50);

            // This outlet's own window, for the ones that publish every few
            // days. A digital-rights report is still the freshest thing on its
            // subject a week later and under the general window never got a turn.
            $table->unsignedSmallInteger('max_age_hours')->nullable();

            $table->boolean('is_active')->default(true);

            // Display order on the settings page. Not a priority: the ranker
            // does not read it.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique('label');
            $table->index(['is_active', 'sort_order']);
        });

        /*
         * When each network is posted to, and how many hashtags it gets.
         *
         * One row per network rather than one shared window, because the whole
         * reason this feature schedules per network is that the good hour for
         * LinkedIn and the good hour for Instagram in Iran are at opposite ends
         * of the day. A network with no row here falls back to the general
         * evening window; that is a deliberate default rather than a gap, so
         * that connecting a seventeenth account does not require a migration.
         */
        Schema::create('curation_windows', function (Blueprint $table) {
            $table->id();

            // Matches `social_accounts.network` and the keys of
            // `Modules\Social\Support\Networks::all()`. Not constrained to
            // anything: `Networks` is code, not a table.
            $table->string('network', 30);

            $table->string('starts_at', 5);
            $table->string('ends_at', 5);

            // The weekend pair is nullable, and null means "use the weekday
            // window on weekends too". Distinguishable from an equal pair,
            // which says somebody deliberately chose the same hours.
            $table->string('weekend_starts_at', 5)->nullable();
            $table->string('weekend_ends_at', 5)->nullable();

            // The hashtag budget. A range rather than a number so the copy does
            // not read as though a machine counted to exactly five every day.
            //
            // 🔴 The ceiling is a real constraint, not a style preference. Ten
            // or more hashtags on LinkedIn risks a 30–50% reach penalty and its
            // 2026 algorithm does not classify by hashtag at all, so the budget
            // there is 3 and the keywords go in the opening line instead.
            // Instagram's platform limit is 30 and dense tagging is fine.
            $table->unsignedTinyInteger('hashtags_min')->default(2);
            $table->unsignedTinyInteger('hashtags_max')->default(3);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('network');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curation_windows');
        Schema::dropIfExists('curation_feeds');
        Schema::dropIfExists('curation_settings');
    }
};
