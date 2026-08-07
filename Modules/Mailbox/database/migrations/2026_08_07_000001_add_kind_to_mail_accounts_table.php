<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an account's mail comes from: a server we poll, or a push we are sent.
 *
 * Until now every row in `mail_accounts` described an IMAP server, and the
 * columns that describe one were `NOT NULL` because there was no other kind of
 * account. Cloudflare Email Routing hands a message to a Worker the moment it
 * arrives, and the Worker posts it here — so the account that receives
 * `info@lavzen.com` has no host to connect to, no username to log in as, and no
 * cursor to resume from. Those columns are not "unknown" for such a row; they
 * are *inapplicable*, which is what null is for.
 *
 * `kind` is what keeps the two apart where it matters. `MailAccount::dueForSync`
 * filters on it, so the scheduled IMAP command never picks up an inbound account
 * and never tries to open a socket to nowhere — the failure that would otherwise
 * be written to `last_error` every five minutes for ever.
 *
 * Existing rows are IMAP accounts, which is why that is the default rather than
 * a choice each one has to be updated into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            // 'imap' — polled by `mailbox:sync-imap`.
            // 'inbound' — written by the Email Worker's POST, never polled.
            $table->string('kind', 20)->default('imap')->after('email');
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->string('imap_host', 190)->nullable()->change();
            $table->string('imap_username', 190)->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * The nullable columns are not put back. Reversing them would have to
         * invent a host and a username for every inbound account to satisfy the
         * constraint, and a fabricated server address is worse than a wide
         * column — the sync would then be free to try connecting to it.
         */
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
