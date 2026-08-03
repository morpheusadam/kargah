<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Database\Factories\ChecklistItemFactory;

class ChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = ['checklist_id', 'text', 'is_done', 'position', 'completed_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'position' => 'decimal:10',
            'completed_at' => 'datetime',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    protected static function newFactory(): ChecklistItemFactory
    {
        return ChecklistItemFactory::new();
    }
}
