<?php

namespace Modules\Data\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Data\Database\Factories\CredentialCategoryFactory;

/**
 * How the vault is grouped: hosting, email, banking, and so on.
 *
 * A table rather than a string column on `credentials`, because a category is
 * renamed far more often than it is created and a rename should not have to
 * touch every row that used the old spelling.
 */
class CredentialCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'colour', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class, 'category_id');
    }

    protected static function newFactory(): CredentialCategoryFactory
    {
        return CredentialCategoryFactory::new();
    }
}
