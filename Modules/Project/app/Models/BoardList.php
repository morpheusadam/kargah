<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Project\Database\Factories\BoardListFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A column. Named `BoardList` because `List` is a reserved word in PHP.
 */
class BoardList extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'board_lists';

    protected $fillable = [
        'board_id',
        'name',
        'position',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'decimal:10',
            'archived_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->orderBy('position');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'board_id', 'archived_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('list');
    }

    protected static function newFactory(): BoardListFactory
    {
        return BoardListFactory::new();
    }
}
