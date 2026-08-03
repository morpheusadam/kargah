<?php

use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Butler\Butler;
use Modules\Project\Butler\Kind;
use Modules\Project\Models\Board;
use Modules\Project\Models\ButlerRule;

// No root-namespace `use` imports here — see ⚡card-custom-fields.blade.php.

/**
 * Butler's board buttons, for the board sidebar.
 *
 * Mounted from the board: `<livewire:project::board-buttons :board-id="$board->id" />`.
 *
 * A board button runs its action chain over every active card the button's own
 * conditions qualify — the conditions do double duty as the filter, which is
 * why there is no separate "which cards" control anywhere. `Butler::pressBoard()`
 * owns that loop, including the rule that the archive is left alone unless a
 * condition explicitly asks for it.
 *
 * This one always toasts. A board button changes cards spread across every
 * list, most of them off screen, and "ran over 7 cards" is the only evidence
 * the person pressing it has that anything happened.
 */
new class extends Component
{
    use InteractsWithToasts;

    public int $boardId;

    private ?Board $resolvedBoard = null;

    public function mount(int $boardId): void
    {
        $this->boardId = $boardId;
    }

    private function board(): ?Board
    {
        return $this->resolvedBoard ??= Board::query()->find($this->boardId);
    }

    public function with(): array
    {
        return ['buttons' => $this->buttons()];
    }

    /** @return Collection<int, ButlerRule> */
    private function buttons(): Collection
    {
        return ButlerRule::query()
            ->where('board_id', $this->boardId)
            ->ofKind(Kind::BOARD_BUTTON)
            ->enabled()
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function press(int $ruleId): void
    {
        $board = $this->board();
        $rule = $this->buttons()->firstWhere('id', $ruleId);

        if ($board === null || $rule === null) {
            return;
        }

        $touched = app(Butler::class)->pressBoard($rule, $board);

        $this->dispatch('card-changed');

        if ($touched === 0) {
            $this->toastError($rule->name, 'Nothing on this board matched its conditions.');

            return;
        }

        $this->toastSuccess($rule->name, 'Ran over '.$touched.' '.str('card')->plural($touched).'.');
    }
};

?>

<div>
    @if ($buttons->isNotEmpty())
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <i class="ki-filled ki-flash text-sm text-muted-foreground"></i>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Butler</h3>
            </div>

            @foreach ($buttons as $button)
                <button wire:click="press({{ $button->id }})" wire:key="butler-board-button-{{ $button->id }}"
                        wire:loading.attr="disabled" wire:target="press({{ $button->id }})"
                        class="kt-btn kt-btn-sm kt-btn-ghost justify-start gap-2 w-full">
                    <i class="{{ $button->iconClass() }} text-sm"></i>
                    {{ $button->name }}
                </button>
            @endforeach
        </div>
    @endif
</div>
