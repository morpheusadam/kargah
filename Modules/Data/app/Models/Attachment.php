<?php

namespace Modules\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Data\Database\Factories\AttachmentFactory;

/**
 * One stored file, and the record of where it lives.
 *
 * Nothing outside `Modules\Data\Services\AttachmentService` may construct one of
 * these. The row and the bytes have to be written together or they drift apart,
 * and a half-written pair is the failure mode that costs an afternoon: a row
 * pointing at nothing, or bytes nobody can find. Going through the service is
 * what keeps the two in step.
 *
 * `attachable_type` holds a morph alias, so this table never learns what a card
 * or an invoice is.
 */
class Attachment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'checksum',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Everything attached to one target, newest first. */
    public function scopeForTarget(Builder $query, string $type, int $id): Builder
    {
        return $query->where('attachable_type', $type)
            ->where('attachable_id', $id)
            ->latest('id');
    }

    /** Files that already carry these exact bytes, whatever they are called. */
    public function scopeWithChecksum(Builder $query, string $checksum): Builder
    {
        return $query->where('checksum', $checksum);
    }

    public function extension(): string
    {
        return mb_strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    /**
     * Size in the units a person reads.
     *
     * Binary units against decimal labels, which is what every file manager on
     * this machine does; matching them beats being pedantic and disagreeing
     * with the operating system about how big a file is.
     */
    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return match (true) {
            $bytes < 1024 => $bytes.' B',
            $bytes < 1048576 => round($bytes / 1024).' KB',
            $bytes < 1073741824 => round($bytes / 1048576, 1).' MB',
            default => round($bytes / 1073741824, 2).' GB',
        };
    }

    protected static function newFactory(): AttachmentFactory
    {
        return AttachmentFactory::new();
    }
}
