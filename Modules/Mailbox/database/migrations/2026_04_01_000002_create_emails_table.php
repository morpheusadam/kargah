<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads, messages, and the metadata of what was attached to them.
 *
 * The load-bearing line in this file is the unique index on
 * `emails.message_id`. A re-sync must never duplicate a message, and that one
 * constraint is what makes the IMAP job safe to re-run — which is what makes it
 * safe to run from cron at all. Everything else about resumability is an
 * optimisation; this is the correctness.
 *
 * `customer_id` is resolved by matching `from_email` against Core's customers.
 * That join is what turns an inbox into a CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();

            $table->string('subject', 255)->nullable();
            $table->json('participants')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);

            $table->timestamps();

            $table->index('last_message_at');
        });

        Schema::create('emails', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('email_thread_id')->nullable()->constrained('email_threads')->nullOnDelete();

            // The whole idempotency guarantee, in one line.
            $table->string('message_id', 255)->unique();
            $table->string('in_reply_to', 255)->nullable();
            $table->unsignedBigInteger('uid')->nullable();

            $table->string('subject', 255)->nullable();

            $table->string('from_name', 190)->nullable();
            $table->string('from_email', 190)->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();

            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('has_attachments')->default(false);

            // Foreign keys point at Core, never sideways.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->string('folder', 120)->default('INBOX');

            $table->timestamp('received_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The inbox sorts by recency inside a folder and filters on read
            // and starred. This is the index that keeps it under 200 ms with
            // ten thousand messages stored.
            $table->index(['folder', 'received_at']);
            $table->index(['mail_account_id', 'folder', 'received_at']);
            $table->index(['is_read', 'received_at']);
            $table->index('customer_id');
            $table->index('from_email');
            $table->index('in_reply_to');
        });

        /*
         * Metadata only. Data owns storage and nothing else touches a disk, so
         * the file itself arrives in phase 6 through AttachmentService; until
         * then the inbox can still show a paperclip, a filename and a size
         * without lying about having the bytes.
         */
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_id')->constrained('emails')->cascadeOnDelete();

            $table->string('filename', 255);
            $table->string('mime', 190)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('content_id', 190)->nullable();
            $table->string('part_number', 20)->nullable();

            // Set in phase 6, when the bytes are actually stored.
            $table->unsignedBigInteger('attachment_id')->nullable();

            $table->timestamps();

            $table->index('email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('email_threads');
    }
};
