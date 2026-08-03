<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named, scoped, revocable credentials for programs.
 *
 * The shape is WordPress's, because WordPress got it right: a person generates
 * a named credential, sees the secret once, and uses it with HTTP Basic auth.
 * Revoking one does not touch the owner's own password, and every credential
 * records when it was last used and from where.
 *
 * **There is no column here that can be turned back into the secret.**
 * `token_hash` is a one-way hash from the same driver that hashes the user's
 * password; `prefix` is the first six characters, stored so a row on the
 * settings page is identifiable without revealing anything useful. If the
 * secret could be read back out of this table it would not be a credential, it
 * would be a password lying in a table.
 *
 * `expires_at` is nullable on purpose. A credential that never expires is a
 * decision somebody made, and it should look like one on the page rather than
 * being the only option.
 *
 * The timestamp puts this after every module migration (Core `2026_01_01_*`
 * through Social `2026_07_01_*`), which is what actually orders migrations —
 * `php artisan migrate` ignores module priority and falls back to filename
 * order. Its only foreign key is to `users`, which is created first of all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_passwords', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What the owner called it: "Laptop CLI", "the assistant". The name
            // is the whole reason revoking one does not break the others.
            $table->string('name', 120);

            $table->string('token_hash', 255);
            $table->string('prefix', 12);

            // ['project:read', 'accounting:read', …] — see Support\Scopes.
            $table->json('scopes');

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();   // 45 = an IPv6 address with an IPv4 tail

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // The authenticator's only lookup: owner plus prefix, then a hash
            // check over the handful of rows that come back. Never a query on
            // `token_hash` — a hash is not a lookup key, and a database that
            // can find a row by its hash is a database an attacker can ask.
            $table->index(['user_id', 'prefix']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_passwords');
    }
};
