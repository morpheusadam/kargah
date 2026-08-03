<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Project\Database\Factories\ChecklistFactory;

class Checklist extends Model
{
    use HasFactory;

    protected $fillable = ['card_id', 'name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'decimal:10'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position');
    }

    protected static function newFactory(): ChecklistFactory
    {
        return ChecklistFactory::new();
    }
}
