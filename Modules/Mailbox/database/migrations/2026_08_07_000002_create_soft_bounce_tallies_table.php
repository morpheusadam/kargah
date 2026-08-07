<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times in a row an address has softly refused a message.
 *
 * A soft bounce is a full mailbox or a greylist, and suppressing on one would
 * destroy a good list — which is why `WebhookProcessor` has always recorded
 * them and never acted. But an address that softly bounces every campaign is
 * dead in every way that matters, and each attempt at it is another point of
 * bounce rate charged against the sending domain. Somewhere between "once" and
 * "for ever" is a threshold, and a threshold needs a count.
 *
 * Keyed on the address rather than on a contact, for the same reason
 * `suppressions` is: a bounce can arrive for a transactional message that never
 * had a contact row, and that is exactly the case a contact-driven design drops
 * on the floor.
 *
 * **The count is consecutive, not cumulative.** A delivery to the address
 * clears it. Three soft bounces two years apart with successful sends between
 * them is a mailbox that fills up at Christmas, not a dead address; three in a
 * row with nothing getting through is the thing worth acting on. Without the
 * reset this table would eventually suppress every address that has ever been
 * away from its desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soft_bounce_tallies', function (Blueprint $table) {
            $table->id();

            // Unique, so recording a bounce is an upsert and a doubled callback
            // cannot count twice as two rows.
            $table->string('email', 190)->unique();

            $table->unsignedInteger('count')->default(0);
            $table->timestamp('last_bounced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soft_bounce_tallies');
    }
};
