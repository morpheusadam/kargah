<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns, their contacts, and one row per person who will receive one.
 *
 * `campaign_recipients` is where the safety lives. A 500-recipient campaign is
 * dispatched in chunks from cron, and a worker can die at any point, so each
 * recipient row is claimed and marked individually. The unique key on
 * (campaign_id, email) plus a status that only moves forward is what makes
 * "no recipient sent twice" true even when the worker is killed mid-run —
 * rather than a counter that a crash would leave lying.
 *
 * `unsubscribe_token` is per recipient so `List-Unsubscribe` works with one
 * click and no login, and `reply_token` is what lets a reply thread back to the
 * campaign it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->string('email', 190);
            $table->string('name', 190)->nullable();
            $table->string('company_name', 190)->nullable();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->json('tags')->nullable();
            $table->json('meta')->nullable();

            $table->boolean('is_subscribed')->default(true);
            $table->string('source', 60)->nullable();       // import | manual | inbox

            $table->timestamps();
            $table->softDeletes();

            $table->unique('email');
            $table->index('customer_id');
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name', 190);
            $table->string('subject', 255);
            $table->string('preheader', 255)->nullable();

            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();

            $table->foreignId('delivery_provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();

            // draft | scheduled | sending | sent | paused | failed
            $table->string('status', 20)->default('draft');

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('email', 190);
            $table->string('name', 190)->nullable();

            // pending | claimed | sent | failed | suppressed | bounced | complained
            $table->string('status', 20)->default('pending');

            // Which provider actually carried it, so the report can show the
            // split when a quota pushed the remainder to the next one.
            $table->foreignId('delivery_provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();

            $table->string('message_id', 255)->nullable();
            $table->string('unsubscribe_token', 64)->nullable();
            $table->string('reply_token', 64)->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            // One row per person per campaign. This is the constraint that
            // makes "nobody is sent twice" a fact rather than a hope.
            $table->unique(['campaign_id', 'email']);
            $table->unique('unsubscribe_token');
            $table->index(['campaign_id', 'status']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('contacts');
    }
};
