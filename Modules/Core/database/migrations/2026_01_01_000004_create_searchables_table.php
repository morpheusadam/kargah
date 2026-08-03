<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One denormalised index so a single search box returns results from every
 * module. Fed by listeners; never written to directly by a module.
 *
 * Scout runs against this table with the `database` driver, which uses the
 * database's own full-text index and needs no daemon — the only option that
 * works on shared hosting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searchables', function (Blueprint $table) {
            $table->id();

            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');

            $table->string('title', 255);
            $table->text('body')->nullable();

            // "Invoice · Northwind Ltd" — shown beside the result
            $table->string('context', 120)->nullable();
            $table->string('url', 255);

            // Lets results sort by recency rather than by insertion
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->unique(['subject_type', 'subject_id'], 'searchables_subject_unique');
            $table->index('occurred_at');
        });

        // Full-text index where the driver supports it. SQLite does not, and
        // Scout's database engine falls back to LIKE there, which is fine at
        // development scale.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE searchables ADD FULLTEXT searchables_fulltext (title, body)');
        }

        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE INDEX searchables_fulltext ON searchables
                 USING GIN (to_tsvector('simple', coalesce(title,'') || ' ' || coalesce(body,'')))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('searchables');
    }
};
