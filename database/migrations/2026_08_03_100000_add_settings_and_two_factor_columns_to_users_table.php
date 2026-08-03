<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the profile and security settings pages need that `users` did not carry.
 *
 * Two groups of columns, for two different reasons:
 *
 * **Profile — `timezone`, `locale`, `date_format`, `bio`.** Plain preferences,
 * defaulted to what the settings page already showed as a fixture, so an
 * existing row reads exactly as it did before this migration ran.
 *
 * **Two-factor — `two_factor_secret_encrypted`, `two_factor_recovery_codes_encrypted`,
 * `two_factor_confirmed_at`.** The secret is written the moment enrolment
 * starts but is not trusted until a real code has been checked against it —
 * that is what `two_factor_confirmed_at` records, and it is what every read of
 * "is two-factor on" actually tests, never merely "is a secret present". Both
 * `_encrypted` columns are named so `tests/Feature/NoSecretsInHtmlTest.php`
 * picks them up automatically; see `App\Models\User` for why the setter
 * encrypts rather than a cast, and `project-guaid/DECISIONS.md` for the mutator
 * idiom that fails silently if that rule is not followed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Europe/London');
            $table->string('locale', 5)->default('en');
            $table->string('date_format', 10)->default('Y-m-d');
            $table->text('bio')->nullable();

            $table->text('two_factor_secret_encrypted')->nullable();
            $table->text('two_factor_recovery_codes_encrypted')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'timezone',
                'locale',
                'date_format',
                'bio',
                'two_factor_secret_encrypted',
                'two_factor_recovery_codes_encrypted',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
