<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts, posts, and one row per place a post is going.
 *
 * The shape that matters is `post_targets`: **status lives per target, not per
 * post.** One post going to two networks succeeds on one and fails on the other
 * far more often than it succeeds or fails as a whole, and a single status
 * column would force a retry to resend the one that worked. Each target carries
 * its own status, its own remote id and its own error, so a retry touches only
 * what failed.
 *
 * `body_override` exists because the same thought does not fit two networks the
 * same way, and rewriting the post to suit one of them would change what the
 * others published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();

            $table->string('network', 30);              // mastodon | bluesky | linkedin | x | telegram
            $table->string('handle', 190);
            $table->string('display_name', 190)->nullable();
            $table->string('avatar_url', 500)->nullable();

            // Encrypted with the application key, never rendered.
            $table->text('credentials_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['network', 'handle']);
            $table->index(['is_active', 'network']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();

            $table->text('body');
            $table->json('media')->nullable();

            // draft | scheduled | publishing | published | partly_failed | failed
            $table->string('status', 20)->default('draft');

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The scheduler asks "what is due?" every minute; this is the index
            // that keeps that cheap.
            $table->index(['status', 'scheduled_for']);
            $table->index('published_at');
        });

        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();

            $table->text('body_override')->nullable();

            // pending | publishing | published | failed | skipped
            $table->string('status', 20)->default('pending');

            $table->string('remote_id', 190)->nullable();
            $table->string('remote_url', 500)->nullable();
            $table->text('error')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();

            $table->timestamps();

            // One post goes to one account once. This is what stops a retry
            // resending the target that already succeeded.
            $table->unique(['post_id', 'social_account_id']);
            $table->index(['status', 'last_attempt_at']);
        });

        Schema::create('social_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();

            $table->string('kind', 30);                 // mention | reply | follow | like | repost
            $table->string('remote_id', 190);
            $table->string('actor_handle', 190)->nullable();
            $table->text('excerpt')->nullable();
            $table->string('url', 500)->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            // Ingestion re-runs from cron; this is what makes that safe.
            $table->unique(['social_account_id', 'remote_id']);
            $table->index(['is_read', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_notifications');
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('social_accounts');
    }
};
