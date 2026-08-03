<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Company;
use Modules\Project\Database\Factories\BoardFactory;
use Modules\Project\Support\Palette;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Board extends Model
{
    use HasFactory;
    use Linkable;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'colour',
        'description',
        'company_id',
        'position',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function lists(): HasMany
    {
        return $this->hasMany(BoardList::class)->orderBy('position');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('position');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cards(): HasManyThrough
    {
        return $this->hasManyThrough(Card::class, BoardList::class, 'board_id', 'board_list_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function dotClass(): string
    {
        return Palette::dot($this->colour);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'colour', 'company_id', 'archived_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('board');
    }

    protected static function newFactory(): BoardFactory
    {
        return BoardFactory::new();
    }
}
