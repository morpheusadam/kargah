<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Butler\Kind;
use Modules\Project\Butler\Triggers;
use Modules\Project\Database\Factories\ButlerRuleFactory;

/**
 * One Butler command: a rule, a card button or a board button.
 *
 * All three are `trigger → conditions → actions`; see the migration's docblock
 * for why they share a table. A button has no trigger, because pressing it is
 * the trigger.
 *
 * Nothing here executes anything. `Modules\Project\Butler\Butler` is the only
 * thing that runs a chain, and it is the only thing holding the loop guard —
 * putting a `run()` on the model would be handing every caller a way round
 * that guard.
 */
class ButlerRule extends Model
{
    use HasFactory;

    protected $table = 'butler_rules';

    protected $fillable = [
        'board_id',
        'kind',
        'name',
        'trigger',
        'trigger_config',
        'conditions',
        'actions',
        'is_enabled',
        'position',
        'icon',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'is_enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function scopeRules(Builder $query): Builder
    {
        return $query->where('kind', Kind::RULE);
    }

    public function scopeButtons(Builder $query): Builder
    {
        return $query->whereIn('kind', [Kind::CARD_BUTTON, Kind::BOARD_BUTTON]);
    }

    public function isButton(): bool
    {
        return Kind::isButton($this->kind);
    }

    /** The action chain, always an array even when the column is null. */
    public function actionChain(): array
    {
        return array_values(array_filter((array) ($this->actions ?? []), 'is_array'));
    }

    /** The condition set, always an array even when the column is null. */
    public function conditionSet(): array
    {
        return array_values(array_filter((array) ($this->conditions ?? []), 'is_array'));
    }

    public function triggerConfig(): array
    {
        return (array) ($this->trigger_config ?? []);
    }

    /**
     * The button's icon, falling back to something rather than to nothing —
     * a button with no glyph at all reads as a broken one, and every other
     * button in this project carries a `ki-filled ki-*`.
     */
    public function iconClass(): string
    {
        $icon = trim((string) ($this->icon ?? ''));

        // Whole class strings only. Tailwind never sees this one (it is a Keen
        // icon font class, not a utility) but the same habit applies: the
        // stored value is the icon name, and the prefix is written out here.
        return $icon === '' ? 'ki-filled ki-flash' : 'ki-filled '.$icon;
    }

    /** A human sentence for the rule list, e.g. "when a card is created". */
    public function triggerSentence(): string
    {
        if ($this->isButton()) {
            return Kind::DESCRIPTIONS[$this->kind] ?? '';
        }

        return $this->trigger === null ? 'no trigger set' : 'when '.Triggers::label($this->trigger);
    }

    /** Note that this rule just ran, without a read-then-write race. */
    public function noteRun(): void
    {
        static::query()->whereKey($this->getKey())->update([
            'run_count' => $this->getConnection()->raw('run_count + 1'),
            'last_run_at' => now(),
        ]);
    }

    protected static function newFactory(): ButlerRuleFactory
    {
        return ButlerRuleFactory::new();
    }
}
