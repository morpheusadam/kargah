<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Project\Database\Factories\LabelFactory;
use Modules\Project\Support\Palette;

class Label extends Model
{
    use HasFactory;

    protected $fillable = ['board_id', 'name', 'colour', 'position'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_label');
    }

    public function chipClass(): string
    {
        return Palette::chip($this->colour);
    }

    public function dotClass(): string
    {
        return Palette::dot($this->colour);
    }

    protected static function newFactory(): LabelFactory
    {
        return LabelFactory::new();
    }
}
