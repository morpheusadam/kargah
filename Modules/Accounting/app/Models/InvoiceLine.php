<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Database\Factories\InvoiceLineFactory;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\Linkable;

/**
 * One line on an invoice.
 *
 * `Linkable` is here rather than a `card_id` column on purpose. A card that
 * gets billed becomes a line through Core's `links` table, so Accounting holds
 * no foreign key into Project and the two modules can be built, moved or
 * dropped independently.
 *
 * Every money attribute is cast to `decimal:6` and hands back a *string*.
 * `position` is `decimal:10`, matching the column and the same fractional
 * ordering the boards use — a float there is how two lines end up sharing a
 * position and the invoice stops having an order.
 */
class InvoiceLine extends Model
{
    use HasFactory;
    use Linkable;

    protected $fillable = [
        'invoice_id', 'description', 'tasks', 'quantity', 'unit_price', 'amount', 'position',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'amount' => 'decimal:6',
            'position' => 'decimal:10',
            // The work this line covers, one item per entry, and never a price
            // among them — the figure is the line's own. See the migration.
            'tasks' => 'array',
        ];
    }

    /**
     * The work this line covers, cleaned for display.
     *
     * Blank entries are dropped here rather than at the point of writing,
     * because the builder binds a textarea and a person who leaves a trailing
     * newline has not asked for an empty bullet. Nothing is reordered: the order
     * is the order the person typed, and it is the order the client reads.
     *
     * @return list<string>
     */
    public function taskList(): array
    {
        return array_values(array_filter(
            array_map(trim(...), $this->tasks ?? []),
            static fn (string $task): bool => $task !== '',
        ));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * What quantity × unit price actually comes to, as a decimal string.
     *
     * Stored separately from the multiplication so a line can be overridden —
     * a rounded-down favour to a client is a decision, not a rounding error —
     * and this is how the two are compared when it matters.
     */
    public function computedAmount(?string $currency = null): string
    {
        return Money::toStorage(Money::lineTotal(
            (string) $this->quantity,
            (string) $this->unit_price,
            $currency ?? $this->invoice->currency,
        ));
    }

    /** How the line total reads, in the invoice's own currency. */
    public function formattedAmount(?string $currency = null): string
    {
        return Money::format((string) $this->amount, $currency ?? $this->invoice->currency);
    }

    protected static function newFactory(): InvoiceLineFactory
    {
        return InvoiceLineFactory::new();
    }
}
