<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files, and the one table that knows where they are.
 *
 * Data owns storage. No other module writes to a disk — they attach through
 * `Modules\Data\Contracts\AttachmentService`, which is what keeps disk
 * configuration in one place and makes moving from local storage to S3 a
 * config change rather than a search across five modules.
 *
 * `attachable_type` is a morph alias, never a class name, so a card, an
 * invoice, an email or anything else can carry a file without this table
 * knowing what any of them are.
 *
 * `checksum` exists so a re-upload of the same bytes can be recognised, and so
 * a backup can be verified rather than merely assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            $table->string('attachable_type', 60);
            $table->unsignedBigInteger('attachable_id');

            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 190)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
