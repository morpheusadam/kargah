<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually sends the mail, and what it is allowed to send.
 *
 * Quotas are per provider and per window because that is how providers price
 * and throttle. The router picks by remaining quota and by health, so
 * exhausting one provider moves the remainder to the next rather than failing
 * the send — and the campaign report has to be able to say which went where.
 *
 * `health_score` is a moving figure, not a boolean: a provider that has started
 * bouncing is worse than one that has not, long before it is dead.
 *
 * `sending_domain` matters because SPF, DKIM and DMARC are properties of the
 * domain the mail claims to come from, and the pre-flight refuses to send
 * without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('driver', 40);              // smtp | brevo | postmark | ses | mailgun
            $table->string('sending_domain', 190)->nullable();
            $table->string('from_email', 190)->nullable();
            $table->string('from_name', 190)->nullable();

            // Encrypted with the application key, never rendered.
            $table->text('credentials_encrypted')->nullable();

            $table->unsignedInteger('daily_quota')->default(0);
            $table->unsignedInteger('hourly_quota')->default(0);
            $table->unsignedInteger('sent_today')->default(0);
            $table->unsignedInteger('sent_this_hour')->default(0);
            $table->timestamp('quota_window_started_at')->nullable();

            // 0–100. Bounces and complaints push it down; clean sends recover it.
            $table->unsignedTinyInteger('health_score')->default(100);
            $table->unsignedInteger('bounce_count')->default(0);
            $table->unsignedInteger('complaint_count')->default(0);

            $table->boolean('spf_verified')->default(false);
            $table->boolean('dkim_verified')->default(false);
            $table->timestamp('dns_checked_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('priority')->default(10);

            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'priority', 'health_score']);
        });

        /*
         * The shared suppression list.
         *
         * Shared on purpose: a hard bounce on one provider must block that
         * address on every provider. A per-provider list would let the same
         * dead address be retried through the next one, which is exactly how a
         * sending reputation is destroyed.
         */
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();

            $table->string('email', 190);

            // hard_bounce | complaint | unsubscribe | manual | invalid
            $table->string('reason', 30);
            $table->string('source', 60)->nullable();      // which provider reported it
            $table->text('detail')->nullable();

            $table->timestamp('suppressed_at');
            $table->timestamps();

            // One row per address. A second report updates the reason rather
            // than adding a duplicate, so a webhook can be delivered twice
            // without consequence.
            $table->unique('email');
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('delivery_providers');
    }
};
