<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vault, the bookmarks, the repositories and the backups.
 *
 * Two rules govern `credentials`:
 *
 * **The secret is encrypted with the application key and never rendered.** It
 * is not sent to the browser with a `display: none`, not put in a data
 * attribute, and not included in `toArray()`. A reveal is a deliberate server
 * round trip for one item.
 *
 * **Every reveal is logged, with who and when.** A password manager whose
 * access is invisible is a password manager nobody can audit after an incident.
 *
 * `totp_encrypted` holds the TOTP seed, which is a second secret and is treated
 * exactly like the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('colour', 30)->default('neutral');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('credentials', function (Blueprint $table) {
            $table->id();

            $table->string('name', 190);
            $table->string('username', 190)->nullable();

            $table->text('secret_encrypted');
            $table->text('totp_encrypted')->nullable();

            $table->string('url', 500)->nullable();
            $table->text('notes_encrypted')->nullable();

            $table->foreignId('category_id')->nullable()->constrained('credential_categories')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->timestamp('last_revealed_at')->nullable();
            $table->timestamp('rotated_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index(['category_id', 'name']);
        });

        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();

            $table->string('title', 190);
            $table->string('url', 500);

            // 'telegram_bot' | 'deployed_project' | 'reference' | 'tool'
            $table->string('kind', 40)->default('reference');

            $table->text('notes')->nullable();
            $table->json('tags')->nullable();

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'title']);
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 30)->default('github');
            $table->string('full_name', 190);
            $table->string('description', 500)->nullable();
            $table->string('language', 60)->nullable();
            $table->string('default_branch', 120)->nullable();

            $table->unsignedInteger('stars')->default(0);
            $table->unsignedInteger('forks')->default(0);
            $table->unsignedInteger('open_issues')->default(0);
            $table->boolean('is_private')->default(false);
            $table->boolean('is_archived')->default(false);

            $table->string('html_url', 500)->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'full_name']);
            $table->index('pushed_at');
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();

            $table->string('target', 60);          // database | files | both
            $table->string('disk', 40)->default('local');
            $table->string('path', 500)->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();

            $table->string('status', 20)->default('pending');   // pending | running | complete | failed
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('bookmarks');
        Schema::dropIfExists('credentials');
        Schema::dropIfExists('credential_categories');
    }
};
