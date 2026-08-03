<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Project\Database\Factories\CardCommentFactory;

class CardComment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['card_id', 'created_by', 'body'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): CardCommentFactory
    {
        return CardCommentFactory::new();
    }
}
