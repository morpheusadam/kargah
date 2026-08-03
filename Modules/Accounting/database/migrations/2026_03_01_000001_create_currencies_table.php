<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The currencies this install deals in.
 *
 * `minor_unit` is what a figure is *displayed* at. It is not what it is stored
 * at: every money column is `decimal(20,6)` whatever the currency, so a lira
 * and a tether can be compared in raw SQL without knowing which is which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 60);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('minor_unit');
            $table->boolean('is_crypto')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('position')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
