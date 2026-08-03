<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Database\Factories\ChecklistItemFactory;

/**
 * One line on a checklist — and, since the advanced-checklist columns landed,
 * one that can carry a person and a day of its own.
 *
 * `due_on` is a **date**, cast the same way `cards.due_on` is: an item due on
 * 31 July is due on 31 July wherever it is read. The calendar page and the ICS
 * feed both draw it as a whole day, and converting the item to a card hands the
 * date straight over.
 */
class ChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = ['checklist_id', 'text', 'is_done', 'position', 'completed_at', 'created_by', 'assigned_to', 'due_on'];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'position' => 'decimal:10',
            'completed_at' => 'datetime',
            'due_on' => 'date',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /** Whoever is carrying this one line, which need not be anybody on the card. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The same vocabulary `Card::dueState()` speaks, so an item badge and a
     * card badge read alike wherever they sit next to each other. A ticked item
     * is `done` for the same reason a completed card is: it is no longer owed.
     */
    public function dueState(): ?string
    {
        if ($this->due_on === null) {
            return null;
        }

        if ($this->is_done) {
            return 'done';
        }

        $today = now()->startOfDay();

        if ($this->due_on->lt($today)) {
            return 'overdue';
        }

        if ($this->due_on->eq($today)) {
            return 'due';
        }

        return $this->due_on->lte($today->copy()->addDay()) ? 'soon' : 'later';
    }

    /** The `Palette` key the item's date badge draws in — `Card`'s mapping, unchanged. */
    public function dueBadgeColour(): ?string
    {
        return match ($this->dueState()) {
            'done' => 'success',
            'due' => 'destructive',
            'overdue' => 'pink',
            'soon' => 'warning',
            'later' => 'neutral',
            default => null,
        };
    }

    protected static function newFactory(): ChecklistItemFactory
    {
        return ChecklistItemFactory::new();
    }
}
