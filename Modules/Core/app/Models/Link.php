<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row joining any two records in the application.
 *
 * Feature modules never hold a foreign key into one another; a card that bills
 * as an invoice line, or an email that became a task, is a row in here.
 */
class Link extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'relation',
        'meta',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }

    /** Rows where the given model sits on either end. */
    public function scopeTouching(Builder $query, Model $model): Builder
    {
        $type = $model->getMorphClass();

        return $query->where(function (Builder $q) use ($type, $model) {
            $q->where(fn (Builder $s) => $s->where('source_type', $type)->where('source_id', $model->getKey()))
                ->orWhere(fn (Builder $s) => $s->where('target_type', $type)->where('target_id', $model->getKey()));
        });
    }

    public function scopeRelation(Builder $query, string $relation): Builder
    {
        return $query->where('relation', $relation);
    }
}
