<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "anything to anything" pivot.
 *
 * A feature module may never hold a foreign key into another feature module;
 * those relationships live here. `source_type` and `target_type` hold short
 * morph aliases, never class names — see Core's enforced morph map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();

            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');

            $table->string('target_type', 60);
            $table->unsignedBigInteger('target_id');

            // 'converted_to' | 'billed_as' | 'references' | 'planned_from' | 'attached_to'
            $table->string('relation', 40);

            $table->json('meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['source_type', 'source_id'], 'links_source_index');
            $table->index(['target_type', 'target_id'], 'links_target_index');
            $table->index('relation');

            $table->unique(
                ['source_type', 'source_id', 'target_type', 'target_id', 'relation'],
                'links_unique_pair'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
