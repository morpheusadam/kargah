<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * What one person has done to one list that nobody else should see.
 *
 * Today that is exactly one thing: whether the column is folded away. It has
 * its own table rather than a column on `board_lists` because a collapse is a
 * property of the viewer, not of the list — folding a column to read the one
 * beside it must not fold it for anyone else, and must not reach an export, a
 * print view or the API, all of which read the list itself.
 *
 * A missing row and a null `collapsed_at` mean the same thing: open. Only a
 * timestamp means folded. Storing the moment rather than a boolean costs
 * nothing and answers "since when" for free.
 */
class BoardListUserState extends Model
{
    protected $table = 'board_list_user_states';

    protected $fillable = [
        'user_id',
        'board_list_id',
        'collapsed_at',
    ];

    protected function casts(): array
    {
        return [
            'collapsed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(BoardList::class, 'board_list_id');
    }

    /**
     * The ids, among those given, that this person has folded away.
     *
     * One query for the whole board rather than one per column, and it takes
     * the ids it is asked about so a board with three lists does not scan every
     * collapse the person has ever made.
     *
     * @param  list<int>  $listIds
     * @return list<int>
     */
    public static function collapsedIdsFor(?User $user, array $listIds): array
    {
        if ($user === null || $listIds === []) {
            return [];
        }

        return static::query()
            ->where('user_id', $user->id)
            ->whereIn('board_list_id', $listIds)
            ->whereNotNull('collapsed_at')
            ->pluck('board_list_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Fold a list away for this person, or unfold it. Returns the new state.
     *
     * An upsert rather than a read-then-write: the unique index on
     * `(user_id, board_list_id)` is what makes that safe, and it means the
     * first collapse and the hundredth cost the same one statement.
     */
    public static function setCollapsed(User $user, int $listId, bool $collapsed): bool
    {
        $now = now();

        DB::table('board_list_user_states')->upsert(
            [[
                'user_id' => $user->id,
                'board_list_id' => $listId,
                'collapsed_at' => $collapsed ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'board_list_id'],
            ['collapsed_at', 'updated_at'],
        );

        return $collapsed;
    }
}
