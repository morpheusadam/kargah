<?php

namespace Modules\Data\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Data\Database\Factories\BackupFactory;

/**
 * One backup run.
 *
 * The row is written *before* the dump starts, in `running`, and updated when it
 * finishes. A crash therefore leaves evidence rather than silence: a row stuck
 * in `running` is how you learn the nightly job died, and a table with no row at
 * all looks exactly like a job that was never scheduled.
 *
 * `checksum` is a sha256 of the artefact as written. It is what turns "there is
 * a file on the disk" into "there is a restorable backup on the disk", and the
 * restore verifies it before touching a live database.
 */
class Backup extends Model
{
    use HasFactory;

    public const TARGET_DATABASE = 'database';

    public const TARGET_FILES = 'files';

    public const TARGET_BOTH = 'both';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'target',
        'disk',
        'path',
        'size_bytes',
        'checksum',
        'status',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETE);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** The archive name, which is all a person needs to identify a run. */
    public function filename(): string
    {
        return $this->path === null ? '—' : basename($this->path);
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return match (true) {
            $bytes <= 0 => '—',
            $bytes < 1024 => $bytes.' B',
            $bytes < 1048576 => round($bytes / 1024).' KB',
            $bytes < 1073741824 => round($bytes / 1048576, 1).' MB',
            default => round($bytes / 1073741824, 2).' GB',
        };
    }

    /** How long the run took, or null while it is still going. */
    public function duration(): ?string
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        $seconds = $this->started_at->diffInSeconds($this->completed_at);

        return $seconds < 60
            ? $seconds.' s'
            : intdiv($seconds, 60).' min '.($seconds % 60).' s';
    }

    protected static function newFactory(): BackupFactory
    {
        return BackupFactory::new();
    }
}
