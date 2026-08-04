<?php

use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Butler\Actions;
use Modules\Project\Butler\Butler;
use Modules\Project\Butler\Conditions;
use Modules\Project\Butler\Interpolator;
use Modules\Project\Butler\Kind;
use Modules\Project\Butler\Triggers;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\ButlerRule;
use Modules\Project\Models\Label;
use Modules\Project\Services\ListOperations;

// No `use RuntimeException;` and no other root-namespace import: Livewire 4
// compiles this block into a namespaced class, where PHP's "no-effect import"
// warning is promoted to a fatal ErrorException.

/**
 * Butler — the rule list and the builder behind it.
 *
 * One page for all three synchronous command types, because they are one
 * object: a trigger, conditions that qualify it, and an ordered chain of
 * actions. The form below changes exactly one control between them — a rule
 * picks a trigger, a button does not, because pressing it *is* the trigger.
 *
 * Calendar commands and due-date commands are absent by design, not by
 * omission. Both mean "at some clock time, sweep the board", both need the cron
 * job spec 06 lists as separate infrastructure, and neither can be honestly
 * half-built here: a due-date command that only ran when somebody happened to
 * open a page would be worse than no due-date command.
 *
 * **No `@island` on this page.** Everything here is a full render — a form that
 * changes shape as you pick a trigger has no stable fragment to update, and one
 * island per file is the rule anyway.
 *
 * Nothing toasts on save or delete: the rule list is on screen and shows the
 * result. Running a board button *does* toast, because "touched 7 cards" is the
 * one thing this page cannot show you.
 */
new
#[Title('Butler — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url(as: 'board')]
    public string $activeBoard = '';

    public bool $boardPickerOpen = false;

    /** Null when the form is closed; 0 for a new command; otherwise the id being edited. */
    public ?int $editingId = null;

    public bool $formOpen = false;

    public string $name = '';

    public string $kind = Kind::RULE;

    public string $trigger = '';

    /** The trigger's own qualifier — a list id, a label id, a user id, or a phrase. */
    public string $triggerValue = '';

    public string $icon = '';

    public bool $isEnabled = true;

    /** @var list<array{condition: string, value: string}> */
    public array $conditionRows = [];

    /** @var list<array{action: string, value: string}> */
    public array $actionRows = [];

    private ?Board $resolvedBoard = null;

    private ?Collection $resolvedBoards = null;

    public function mount(): void
    {
        $this->activeBoard = $this->resolveBoard($this->activeBoard);
    }

    /* Board plumbing — the same shape as ⚡board-dashboard.blade.php ---------- */

    private function resolveBoard(string $slug): string
    {
        $slugs = $this->allBoards()->pluck('slug');

        return $slugs->contains($slug) ? $slug : (string) $slugs->first();
    }

    private function allBoards(): Collection
    {
        return $this->resolvedBoards ??= Board::query()->active()->orderBy('position')->orderBy('name')->get();
    }

    private function board(): ?Board
    {
        return $this->resolvedBoard ??= $this->allBoards()->firstWhere('slug', $this->activeBoard);
    }

    public function boardName(): string
    {
        return $this->board()?->name ?? 'No board';
    }

    public function toggleBoardPicker(): void
    {
        $this->boardPickerOpen = ! $this->boardPickerOpen;
    }

    public function dismissPanels(): void
    {
        $this->boardPickerOpen = false;
    }

    public function selectBoard(string $slug): void
    {
        $this->activeBoard = $this->resolveBoard($slug);
        $this->resolvedBoard = null;
        $this->boardPickerOpen = false;
        $this->closeForm();
    }

    /* Reading ---------------------------------------------------------------- */

    public function with(): array
    {
        $board = $this->board();

        $commands = $board === null
            ? collect()
            : ButlerRule::query()
                ->where('board_id', $board->id)
                ->orderBy('kind')
                ->orderBy('position')
                ->orderBy('id')
                ->get();

        return [
            'boards' => $this->allBoards(),
            'commands' => $commands->groupBy('kind'),
            'lists' => $this->lists(),
            'labels' => $this->labels(),
            'people' => $this->people(),
            'sorts' => ListOperations::SORTS,
            'triggerCatalogue' => Triggers::CATALOGUE,
            'conditionCatalogue' => Conditions::CATALOGUE,
            'actionCatalogue' => Actions::CATALOGUE,
            'variables' => Interpolator::VARIABLES,
        ];
    }

    /** @return Collection<int, BoardList> */
    private function lists(): Collection
    {
        $board = $this->board();

        return $board === null
            ? collect()
            : BoardList::query()->where('board_id', $board->id)->active()->orderBy('position')->get(['id', 'name']);
    }

    /** @return Collection<int, Label> */
    private function labels(): Collection
    {
        $board = $this->board();

        return $board === null
            ? collect()
            : Label::query()->where('board_id', $board->id)->orderBy('position')->orderBy('name')->get();
    }

    /** @return Collection<int, User> */
    private function people(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    /* The form --------------------------------------------------------------- */

    public function create(string $kind): void
    {
        if (! Kind::isValid($kind)) {
            return;
        }

        $this->resetForm();
        $this->kind = $kind;
        $this->trigger = $kind === Kind::RULE ? Triggers::CARD_CREATED : '';
        $this->editingId = 0;
        $this->formOpen = true;
        $this->boardPickerOpen = false;
    }

    public function edit(int $id): void
    {
        $rule = $this->ruleOnThisBoard($id);

        if ($rule === null) {
            return;
        }

        $this->resetForm();

        $config = $rule->triggerConfig();

        $this->editingId = $rule->id;
        $this->name = (string) $rule->name;
        $this->kind = (string) $rule->kind;
        $this->trigger = (string) ($rule->trigger ?? '');
        $this->triggerValue = (string) (reset($config) === false ? '' : reset($config));
        $this->icon = (string) ($rule->icon ?? '');
        $this->isEnabled = (bool) $rule->is_enabled;

        $this->conditionRows = array_map(
            fn (array $row): array => [
                'condition' => (string) ($row['condition'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
            ],
            $rule->conditionSet(),
        );

        $this->actionRows = array_map(
            fn (array $row): array => [
                'action' => (string) ($row['action'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
            ],
            $rule->actionChain(),
        );

        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->kind = Kind::RULE;
        $this->trigger = '';
        $this->triggerValue = '';
        $this->icon = '';
        $this->isEnabled = true;
        $this->conditionRows = [];
        $this->actionRows = [['action' => '', 'value' => '']];
        $this->resetValidation();
    }

    /** Changing the trigger drops a qualifier that belonged to the old one. */
    public function updatedTrigger(): void
    {
        $this->triggerValue = '';
    }

    public function addCondition(): void
    {
        $this->conditionRows[] = ['condition' => '', 'value' => ''];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditionRows[$index]);
        $this->conditionRows = array_values($this->conditionRows);
    }

    public function addAction(): void
    {
        $this->actionRows[] = ['action' => '', 'value' => ''];
    }

    public function removeAction(int $index): void
    {
        unset($this->actionRows[$index]);
        $this->actionRows = array_values($this->actionRows);
    }

    /**
     * A row whose select changed keeps a value that belonged to the previous
     * choice — a list id sitting in a `title_contains` box. Clearing it on
     * change is the difference between an empty control and one holding "14".
     *
     * 🔴 `$key` is **nullable**. Livewire passes the sub-key only when one leaf
     * of the array changed; setting the whole property — which is what
     * `edit()` does, and what a test doing `->set('actionRows', […])` does —
     * calls this hook with `null` and a non-nullable parameter turns that into
     * a `TypeError` from inside the update pipeline.
     */
    public function updatedConditionRows(mixed $value, ?string $key = null): void
    {
        if ($key !== null && str_ends_with($key, '.condition')) {
            $this->conditionRows[(int) explode('.', $key)[0]]['value'] = '';
        }
    }

    public function updatedActionRows(mixed $value, ?string $key = null): void
    {
        if ($key !== null && str_ends_with($key, '.action')) {
            $this->actionRows[(int) explode('.', $key)[0]]['value'] = '';
        }
    }

    public function save(): void
    {
        $board = $this->board();

        if ($board === null || $this->editingId === null) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'in:'.implode(',', Kind::all())],
            'icon' => ['nullable', 'string', 'max:60'],
        ]);

        if ($this->kind === Kind::RULE && ! Triggers::isValid($this->trigger)) {
            $this->addError('trigger', 'Pick something for this rule to listen for.');

            return;
        }

        $actions = $this->cleanActions();

        if ($actions === []) {
            $this->addError('actionRows', 'A command that does nothing is not a command. Add at least one action.');

            return;
        }

        $rule = $this->editingId === 0
            ? new ButlerRule(['board_id' => $board->id, 'created_by' => auth()->id()])
            : $this->ruleOnThisBoard($this->editingId);

        if ($rule === null) {
            return;
        }

        $rule->fill([
            'kind' => $this->kind,
            'name' => trim($this->name),
            'trigger' => $this->kind === Kind::RULE ? $this->trigger : null,
            'trigger_config' => $this->kind === Kind::RULE ? $this->cleanTriggerConfig() : [],
            'conditions' => $this->cleanConditions(),
            'actions' => $actions,
            'is_enabled' => $this->isEnabled,
            'icon' => trim($this->icon) === '' ? null : trim($this->icon),
        ]);

        $rule->save();

        $this->closeForm();
    }

    /**
     * The qualifier, stored under the key the engine matches on rather than
     * under a generic one — `Butler::triggerMatches()` compares
     * `trigger_config` keys against the fired context, so "which list" has to
     * be called `list_id` there and here alike.
     */
    private function cleanTriggerConfig(): array
    {
        $value = trim($this->triggerValue);

        if ($value === '') {
            return [];
        }

        return match (Triggers::argument($this->trigger)) {
            'list' => ['list_id' => (int) $value],
            'label' => ['label_id' => (int) $value],
            'member' => ['user_id' => (int) $value],
            'text' => ['text' => $value],
            default => [],
        };
    }

    private function cleanConditions(): array
    {
        $out = [];

        foreach ($this->conditionRows as $row) {
            $key = (string) ($row['condition'] ?? '');

            if ($key === '' || ! Conditions::isValid($key)) {
                continue;
            }

            $out[] = ['condition' => $key, 'value' => trim((string) ($row['value'] ?? ''))];
        }

        return $out;
    }

    private function cleanActions(): array
    {
        $out = [];

        foreach ($this->actionRows as $row) {
            $key = (string) ($row['action'] ?? '');

            if ($key === '' || ! Actions::isValid($key)) {
                continue;
            }

            $out[] = ['action' => $key, 'value' => trim((string) ($row['value'] ?? ''))];
        }

        return $out;
    }

    /* Row actions ------------------------------------------------------------ */

    public function toggleEnabled(int $id): void
    {
        $rule = $this->ruleOnThisBoard($id);

        $rule?->forceFill(['is_enabled' => ! $rule->is_enabled])->save();
    }

    public function deleteCommand(int $id): void
    {
        $this->ruleOnThisBoard($id)?->delete();

        if ($this->editingId === $id) {
            $this->closeForm();
        }
    }

    /**
     * Run a board button from here rather than only from the board, so a
     * newly written one can be tried the moment it is saved.
     */
    public function runBoardButton(int $id): void
    {
        $rule = $this->ruleOnThisBoard($id);
        $board = $this->board();

        if ($rule === null || $board === null || $rule->kind !== Kind::BOARD_BUTTON) {
            return;
        }

        $touched = app(Butler::class)->pressBoard($rule, $board);

        // Worth a toast: the cards it changed are not on this page.
        $this->toastSuccess(
            $rule->name,
            $touched === 0
                ? 'Nothing on this board matched its conditions.'
                : 'Ran over '.$touched.' '.str('card')->plural($touched).'.',
        );
    }

    private function ruleOnThisBoard(int $id): ?ButlerRule
    {
        $board = $this->board();

        return $board === null
            ? null
            : ButlerRule::query()->where('board_id', $board->id)->find($id);
    }

    /* Small helpers the template asks for ------------------------------------- */

    public function argumentFor(string $family, string $key): ?string
    {
        return match ($family) {
            'trigger' => Triggers::argument($key),
            'condition' => Conditions::argument($key),
            default => Actions::argument($key),
        };
    }

    public function argumentLabelFor(string $family, string $key): ?string
    {
        return match ($family) {
            'trigger' => Triggers::argumentLabel($key),
            'condition' => null,
            default => Actions::argumentLabel($key),
        };
    }

    /** A one-line summary of what a saved command does, for the list. */
    public function summarise(ButlerRule $rule): string
    {
        $parts = array_map(
            fn (array $a): string => Actions::label((string) ($a['action'] ?? '')),
            $rule->actionChain(),
        );

        return $parts === [] ? 'does nothing yet' : implode(', then ', $parts);
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($boardPickerOpen)
        <div class="fixed inset-0 z-10" wire:click="dismissPanels" aria-hidden="true"></div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            <div>
                <h1 class="text-xl font-semibold text-mono">{{ $this->boardName() }} — Butler</h1>
                <p class="text-sm text-secondary-foreground mt-1">
                    Rules run on their own. Buttons run when you press them. Everything here happens inside the request that caused it.
                </p>
            </div>

            <div class="relative">
                <button wire:click="toggleBoardPicker" class="kt-btn kt-btn-outline gap-2">
                    <i class="ki-filled ki-down text-xs"></i> Switch board
                </button>
                <div class="kt-dropdown absolute z-20 mt-1 w-[220px] {{ $boardPickerOpen ? 'open' : '' }}">
                    <div class="p-2 flex flex-col gap-1">
                        @forelse ($boards as $b)
                            <button wire:click="selectBoard('{{ $b->slug }}')" wire:key="butler-pick-{{ $b->id }}"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full {{ $b->slug === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full {{ $b->dotClass() }}"></span>
                                {{ $b->name }}
                            </button>
                        @empty
                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">No boards yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Nothing here can be saved without a board to hang it on: `save()`
             returns early when `board()` is null, and a form that swallows the
             save is worse than a button that says it cannot be pressed. --}}
        <div class="flex flex-wrap items-center gap-1">
            <button wire:click="create('{{ \Modules\Project\Butler\Kind::RULE }}')" class="kt-btn kt-btn-sm kt-btn-primary gap-1.5"
                    @disabled($boards->isEmpty())>
                <i class="ki-filled ki-flash-circle text-sm"></i> New rule
            </button>
            <button wire:click="create('{{ \Modules\Project\Butler\Kind::CARD_BUTTON }}')" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5"
                    @disabled($boards->isEmpty())>
                <i class="ki-filled ki-note text-sm"></i> New card button
            </button>
            <button wire:click="create('{{ \Modules\Project\Butler\Kind::BOARD_BUTTON }}')" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5"
                    @disabled($boards->isEmpty())>
                <i class="ki-filled ki-row-horizontal text-sm"></i> New board button
            </button>
        </div>
    </div>

    @if ($boards->isEmpty())
        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-10 text-center px-5">
                <i class="ki-filled ki-flash-circle text-2xl text-muted-foreground mb-2"></i>
                <p class="text-sm text-secondary-foreground">Butler works on one board at a time, and there is no board yet.</p>
                <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-primary gap-2 mt-4">
                    <i class="ki-filled ki-element-plus"></i> Make the first board
                </a>
            </div>
        </div>
    @endif

    @if ($formOpen)
        <div class="kt-card border-primary/30">
            <div class="kt-card-header">
                <h3 class="kt-card-title">
                    {{ $editingId === 0 ? 'New' : 'Edit' }} {{ mb_strtolower(\Modules\Project\Butler\Kind::LABELS[$kind] ?? 'command') }}
                </h3>
                <button wire:click="closeForm" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
            </div>

            <div class="kt-card-content p-4 flex flex-col gap-4">

                <p class="text-xs text-muted-foreground">
                    {{ \Modules\Project\Butler\Kind::DESCRIPTIONS[$kind] ?? '' }}
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <label class="kt-form-label text-xs" for="butler-name">Name</label>
                        <input id="butler-name" type="text" class="kt-input" wire:model="name"
                               placeholder="e.g. Anything landing in Done gets marked complete">
                        @error('name') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label text-xs" for="butler-icon">
                            {{ $kind === \Modules\Project\Butler\Kind::RULE ? 'Icon (unused for rules)' : 'Button icon' }}
                        </label>
                        <input id="butler-icon" type="text" class="kt-input" wire:model="icon" placeholder="ki-flash-circle">
                        @error('icon') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($kind === \Modules\Project\Butler\Kind::RULE)
                    <div class="flex flex-col gap-2 rounded-lg border border-border p-3">
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">When</span>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <select class="kt-select" wire:model.live="trigger" aria-label="Trigger">
                                <option value="">Pick a trigger…</option>
                                @foreach ($triggerCatalogue as $key => $meta)
                                    <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                @endforeach
                            </select>

                            @if ($trigger !== '' && $this->argumentFor('trigger', $trigger) !== null)
                                @include('project::partials.butler-value', [
                                    'family' => 'trigger',
                                    'arg' => $this->argumentFor('trigger', $trigger),
                                    'model' => 'triggerValue',
                                    'placeholder' => $this->argumentLabelFor('trigger', $trigger),
                                ])
                            @endif
                        </div>

                        @error('trigger') <span class="text-xs text-destructive">{{ $message }}</span> @enderror

                        @if ($trigger !== '' && ! \Modules\Project\Butler\Triggers::isAutomatic($trigger))
                            <p class="text-xs text-warning">
                                Labels and members live on pivot tables, which raise no model event. This trigger fires when
                                another Butler action changes them; the card back and the board canvas each need one line
                                adding before a hand-made change fires it too.
                            </p>
                        @endif
                    </div>
                @endif

                <div class="flex flex-col gap-2 rounded-lg border border-border p-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ $kind === \Modules\Project\Butler\Kind::BOARD_BUTTON ? 'Only cards where' : 'Only if' }}
                        </span>
                        <button wire:click="addCondition" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                            <i class="ki-filled ki-plus text-xs"></i> Add condition
                        </button>
                    </div>

                    @forelse ($conditionRows as $i => $row)
                        <div class="flex items-start gap-2" wire:key="butler-cond-{{ $i }}">
                            <select class="kt-select grow min-w-0" wire:model.live="conditionRows.{{ $i }}.condition"
                                    aria-label="Condition {{ $i + 1 }}">
                                <option value="">Pick a condition…</option>
                                @foreach ($conditionCatalogue as $key => $meta)
                                    <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                @endforeach
                            </select>

                            @if (($row['condition'] ?? '') !== '' && $this->argumentFor('condition', $row['condition']) !== null)
                                <div class="w-[220px] shrink-0">
                                    @include('project::partials.butler-value', [
                                        'family' => 'condition',
                                        'arg' => $this->argumentFor('condition', $row['condition']),
                                        'model' => 'conditionRows.'.$i.'.value',
                                        'placeholder' => null,
                                    ])
                                </div>
                            @endif

                            <button wire:click="removeCondition({{ $i }})" class="kt-btn kt-btn-sm kt-btn-ghost text-destructive shrink-0"
                                    aria-label="Remove condition {{ $i + 1 }}">
                                <i class="ki-filled ki-trash text-sm"></i>
                            </button>
                        </div>
                    @empty
                        <p class="text-xs text-muted-foreground">
                            No conditions — this runs on every card the trigger names.
                        </p>
                    @endforelse
                </div>

                <div class="flex flex-col gap-2 rounded-lg border border-border p-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Then, in order</span>
                        <button wire:click="addAction" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                            <i class="ki-filled ki-plus text-xs"></i> Add action
                        </button>
                    </div>

                    @foreach ($actionRows as $i => $row)
                        <div class="flex items-start gap-2" wire:key="butler-act-{{ $i }}">
                            <span class="kt-badge kt-badge-sm shrink-0 mt-1.5">{{ $i + 1 }}</span>

                            <select class="kt-select grow min-w-0" wire:model.live="actionRows.{{ $i }}.action"
                                    aria-label="Action {{ $i + 1 }}">
                                <option value="">Pick an action…</option>
                                @foreach ($actionCatalogue as $key => $meta)
                                    <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                @endforeach
                            </select>

                            @if (($row['action'] ?? '') !== '' && $this->argumentFor('action', $row['action']) !== null)
                                <div class="w-[260px] shrink-0">
                                    @include('project::partials.butler-value', [
                                        'family' => 'action',
                                        'arg' => $this->argumentFor('action', $row['action']),
                                        'model' => 'actionRows.'.$i.'.value',
                                        'placeholder' => $this->argumentLabelFor('action', $row['action']),
                                    ])
                                </div>
                            @endif

                            <button wire:click="removeAction({{ $i }})" class="kt-btn kt-btn-sm kt-btn-ghost text-destructive shrink-0"
                                    aria-label="Remove action {{ $i + 1 }}">
                                <i class="ki-filled ki-trash text-sm"></i>
                            </button>
                        </div>
                    @endforeach

                    @error('actionRows') <span class="text-xs text-destructive">{{ $message }}</span> @enderror

                    <details class="mt-1">
                        <summary class="text-xs text-muted-foreground cursor-pointer">Variables you can use in a comment</summary>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-2">
                            @foreach ($variables as $token => $meaning)
                                <p class="text-xs text-muted-foreground">
                                    <code class="text-secondary-foreground">{{ $token }}</code> — {{ $meaning }}
                                </p>
                            @endforeach
                        </div>
                    </details>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" class="kt-checkbox" wire:model="isEnabled">
                        <span class="text-sm text-secondary-foreground">Enabled</span>
                    </label>

                    <div class="flex items-center gap-2">
                        <button wire:click="closeForm" class="kt-btn kt-btn-ghost">Cancel</button>
                        <button wire:click="save" class="kt-btn kt-btn-primary gap-1.5" wire:loading.attr="disabled" wire:target="save">
                            <i class="ki-filled ki-check text-sm"></i> Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @foreach (\Modules\Project\Butler\Kind::all() as $group)
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">{{ \Modules\Project\Butler\Kind::LABELS[$group] }}s</h3>
                <span class="text-xs text-muted-foreground">{{ \Modules\Project\Butler\Kind::DESCRIPTIONS[$group] }}</span>
            </div>

            <div class="kt-card-content p-0">
                @forelse ($commands->get($group, collect()) as $rule)
                    {{-- `last:` has no compiled variant in kargah.css, so the last row is
                         asked for by name rather than by a class Tailwind never emitted. --}}
                    <div class="flex items-start gap-3 px-4 py-3 {{ $loop->last ? '' : 'border-b border-border' }}"
                         wire:key="butler-row-{{ $rule->id }}">

                        <span class="size-8 rounded-lg grid place-items-center shrink-0 {{ $rule->is_enabled ? 'bg-primary/15 text-primary' : 'bg-accent/60 text-muted-foreground' }}">
                            <i class="{{ $rule->iconClass() }} text-sm"></i>
                        </span>

                        <div class="grow min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-mono truncate">{{ $rule->name }}</p>
                                @unless ($rule->is_enabled)
                                    <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">Disabled</span>
                                @endunless
                            </div>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ $rule->triggerSentence() }} — {{ $this->summarise($rule) }}
                            </p>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                Run {{ $rule->run_count }} {{ str('time')->plural($rule->run_count) }}@if ($rule->last_run_at), last {{ $rule->last_run_at->diffForHumans() }}@endif.
                            </p>
                        </div>

                        <div class="flex items-center gap-1 shrink-0">
                            @if ($group === \Modules\Project\Butler\Kind::BOARD_BUTTON)
                                <button wire:click="runBoardButton({{ $rule->id }})" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5"
                                        wire:loading.attr="disabled" wire:target="runBoardButton({{ $rule->id }})">
                                    <i class="ki-filled ki-rocket text-sm"></i> Run now
                                </button>
                            @endif

                            <button wire:click="toggleEnabled({{ $rule->id }})" class="kt-btn kt-btn-sm kt-btn-ghost"
                                    wire:loading.attr="disabled" wire:target="toggleEnabled({{ $rule->id }})"
                                    aria-label="{{ $rule->is_enabled ? 'Disable' : 'Enable' }} {{ $rule->name }}">
                                {{ $rule->is_enabled ? 'Disable' : 'Enable' }}
                            </button>
                            <button wire:click="edit({{ $rule->id }})" class="kt-btn kt-btn-sm kt-btn-ghost"
                                    aria-label="Edit {{ $rule->name }}">
                                <i class="ki-filled ki-pencil text-sm"></i>
                            </button>
                            <button wire:click="deleteCommand({{ $rule->id }})"
                                    wire:confirm="Delete {{ $rule->name }}?"
                                    class="kt-btn kt-btn-sm kt-btn-ghost text-destructive" aria-label="Delete {{ $rule->name }}">
                                <i class="ki-filled ki-trash text-sm"></i>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center py-8 text-center">
                        <i class="ki-filled ki-flash-circle text-2xl text-muted-foreground mb-2"></i>
                        <p class="text-sm text-secondary-foreground">Nothing here yet.</p>
                    </div>
                @endforelse
            </div>
        </div>
    @endforeach

</div>
