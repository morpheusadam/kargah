<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Project\Database\Factories\WatcherFactory;

/**
 * One person watching one card, list or board.
 *
 * `watchable_type` is a morph alias off the map `ProjectServiceProvider`
 * already registers (`card`, `board_list`, `board`), not a class name — the
 * `morphTo()` below resolves through that same global map, so nothing here
 * needs to know it exists.
 *
 * A row is written once and either exists or does not; there is nothing on it
 * to edit, which is why there is no `updated_at`. See the migration's
 * docblock for why it is not soft-deleted either.
 */
class Watcher extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'watchable_type',
        'watchable_id',
        'user_id',
    ];

    public function watchable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): WatcherFactory
    {
        return WatcherFactory::new();
    }
}
