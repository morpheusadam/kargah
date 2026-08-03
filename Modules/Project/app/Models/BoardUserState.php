<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Database\Factories\BoardUserStateFactory;

/**
 * One person's private state on one board: starred, and last looked at.
 *
 * Two features on one row rather than two tables — see the migration's
 * docblock for why. Nothing on this model is shared: another user's row for
 * the same board is a different row, and neither can see the other's.
 *
 * **There is almost nothing to call here.** Everything the application does
 * with this table it does through `Board` — `isStarredBy()`, `starFor()`,
 * `markViewedBy()`, `scopeStarredFirstFor()` — because a star is something you
 * ask a *board* about, not something you go and fetch a state row for. This
 * class exists so the row has a factory and a name; treat a direct
 * `BoardUserState::query()` outside a test as a sign the method you wanted
 * belongs on `Board` instead.
 *
 * `updated_at` is off, matching the migration. A view time that is overwritten
 * by the next view has no second timestamp worth keeping.
 */
class BoardUserState extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'board_id',
        'starred_at',
        'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'starred_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** A null `starred_at` is "viewed but not starred", not "no opinion" — see the migration. */
    public function isStarred(): bool
    {
        return $this->starred_at !== null;
    }

    protected static function newFactory(): BoardUserStateFactory
    {
        return BoardUserStateFactory::new();
    }
}
