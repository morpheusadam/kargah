<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to announce a post once it has actually gone out.
 *
 * A post leaves Kargah at an hour chosen at random inside a window, with nobody
 * watching — that is the whole point of the curator, and it is also the reason
 * the operator has no idea whether anything happened until they open the panel.
 * These three columns are the answer: a bot, a chat, and a switch.
 *
 * **Why this lands on `curation_settings` rather than a table of its own.** It is
 * three fields, it is per-install rather than per-anything, and the singleton row
 * that already exists is where the Social module keeps exactly that kind of
 * setting. The honest consequence is that the table is now the module's own
 * settings rather than strictly the curator's, and the name has stopped being
 * perfectly accurate — which is a smaller cost than a second one-row table and a
 * second `current()` to keep in step. It applies to **every** published post, not
 * only curated ones: a post composed by hand announces itself too.
 *
 * 🔴 **The token is encrypted, and the mutator has to be written the working
 * way.** `Attribute::make(set: fn ($v) => ['..._encrypted' => $v])` is the form
 * Laravel's own documentation shows, and a mutator's return value merges straight
 * into the raw attribute array — so casts never run on it and the secret is
 * written to the column in clear text. That failure is silent and looks correct.
 * See the long note in project-guaid/DECISIONS.md under "Phase 4 — Mailbox", and
 * `Modules\Social\Models\SocialAccount::credentials()` for the form that works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curation_settings', function (Blueprint $table) {
            // Off until there is somewhere to send to. A bot token with no chat
            // is not a configuration, it is half of one.
            $table->boolean('notify_enabled')->default(false);

            // Encrypted with the application key, never rendered back to a page.
            $table->text('notify_bot_token_encrypted')->nullable();

            // A numeric id for a private chat, or `@name` for a public channel.
            // Not a secret: a channel username is public and a chat id is useless
            // without the token.
            $table->string('notify_chat_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('curation_settings', function (Blueprint $table) {
            // Dropping columns rebuilds the table on SQLite. `curation_settings`
            // has nothing referencing it — no foreign key points here — so there
            // is no cascade to fire, which is why this is safe where the same
            // operation on `posts` would not be. See the note on
            // `Modules\Social\Models\Post`.
            $table->dropColumn(['notify_enabled', 'notify_bot_token_encrypted', 'notify_chat_id']);
        });
    }
};
