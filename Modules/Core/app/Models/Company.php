<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Database\Factories\CompanyFactory;

/**
 * A legal entity you bill or are billed by.
 *
 * `is_domestic` is not decoration: it decides whether an invoice must carry a
 * lira equivalent at the central bank's buying rate. See spec 03.
 */
class Company extends Model
{
    use HasFactory;
    use Linkable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'tax_number',
        'tax_office',
        'country',
        'address',
        'website',
        'default_currency',
        'is_domestic',
        'notes',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_domestic' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
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

    /** What goes on an invoice, which is not always the trading name. */
    public function billingName(): string
    {
        return $this->legal_name ?: $this->name;
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->name, 0, 2));
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
