<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which AI provider Kargah asks, with what model and what credentials.
 *
 * Shaped close to `delivery_providers` — a name, a driver string, an
 * encrypted secret and a set of flags — because the two problems are the
 * same one: several interchangeable outside services, one of which is active
 * (or default) at a time, none of whose credentials may ever be read back
 * out of the table.
 *
 * `base_url` is nullable and used by exactly one driver (`ollama`), which
 * needs no key at all. That single column is what lets the interface express
 * "a local endpoint, no key" without a special case — see
 * `Modules\Platform\Services\Assistant\OllamaDriver`'s docblock.
 *
 * `is_default` has no unique partial index: SQLite and MySQL do not agree on
 * how to express "unique where true", and the invariant — exactly one
 * default — is enforced by `AssistantProvider::makeDefault()`, which demotes
 * every other row inside the same call. That mirrors how this project already
 * handles "revoking is a conditional UPDATE, not an if" for application
 * passwords: idempotent application logic instead of a constraint the two
 * database drivers cannot express the same way.
 *
 * The timestamp sorts after every other module's migrations and after
 * `application_passwords` (`2026_08_01_*`), which is what actually orders
 * migrations under plain `php artisan migrate` — see
 * `project-guaid/DECISIONS.md`, Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_providers', function (Blueprint $table) {
            $table->id();

            // What the owner called it: "Gemini (default)", "Local Llama".
            $table->string('name', 120);

            // gemini | openrouter | anthropic | openai | ollama — see
            // Modules\Platform\Support\AssistantDrivers.
            $table->string('driver', 40);

            // Blank means "use the driver's own default" — see
            // AssistantProvider::effectiveModel().
            $table->string('model', 120)->nullable();

            // Encrypted with the application key, never rendered. Null for a
            // driver that needs none (ollama).
            $table->text('api_key_encrypted')->nullable();

            // Only ollama uses this today; kept general rather than named
            // after the one driver that needs it, since a second local-style
            // provider is exactly the kind of thing this table should not
            // need a migration to grow into.
            $table->string('base_url', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            // The settings page's "test this connection" button, so a row's
            // state survives a refresh instead of only living in a toast.
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_providers');
    }
};
