<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mailbox Kargah reads.
 *
 * `sync_cursor` is what makes the IMAP job resumable. Shared hosting gives a
 * request perhaps thirty seconds, so a 2,000-message mailbox cannot be synced
 * in one go. The job takes a bounded chunk, records where it got to, and exits;
 * the next cron tick carries on. Killing it halfway loses at most the chunk in
 * flight, and re-running that chunk is harmless because `emails.message_id` is
 * unique.
 *
 * The password is encrypted with the application key. It is never rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('email', 190);

            $table->string('imap_host', 190);
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl');   // ssl | tls | none
            $table->boolean('imap_validate_cert')->default(true);
            $table->string('imap_username', 190);
            $table->text('imap_password_encrypted')->nullable();

            $table->string('default_folder', 120)->default('INBOX');

            // Where the sync got to. `uid_next` is IMAP's own high-water mark;
            // storing it means the next run asks only for what arrived since.
            $table->unsignedBigInteger('sync_cursor')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
