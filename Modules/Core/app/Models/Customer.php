<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Database\Factories\CustomerFactory;

/**
 * A person you deal with. May belong to a company, may not.
 */
class Customer extends Model
{
    use HasFactory;
    use Linkable;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'role',
        'avatar_path',
        'timezone',
        'notes',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * The join that turns an inbox into a CRM: an incoming message resolves to
     * a customer by its sender address.
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
        }

        return mb_strtoupper(mb_substr($this->name, 0, 2));
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
