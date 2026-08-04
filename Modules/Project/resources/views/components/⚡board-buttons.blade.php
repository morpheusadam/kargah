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
 * Butler's board buttons, in the board's toolbar.
 *
 * Mounted from the board: `<livewire:project::board-buttons :board-id="$board->id" />`,
 * inside the `flex items-center gap-2` toolbar that also holds the view
 * switcher, the search box, the filter panel and the settings link — so it
 * renders **one horizontal row of buttons**, sized like its neighbours, rather
 * than a titled column. A `flex flex-col` block with a section heading in it
 * made that single-line toolbar three lines tall.
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

{{--
    `contents` on the root: Livewire always renders the root element, and on a
    board nobody has automated an empty block-level div would still take a slot
    in the toolbar's flex row and widen the gap between the filter panel and
    the settings button. With `contents` this component occupies nothing.

    The group carries the name "Butler" for a screen reader, since the heading
    that used to say so out loud does not fit on a toolbar row.
--}}
<div class="contents">
    @if ($buttons->isNotEmpty())
        <div class="flex items-center gap-1" role="group" aria-label="Butler board buttons">
            @foreach ($buttons as $button)
                {{--
                    `min-w-0 max-w-xs` plus a `truncate` span: `kt-btn` is
                    `white-space: nowrap` and never clips, so a button named
                    after the sentence it runs would stretch the toolbar until
                    the buttons after it left the screen. The full name stays
                    reachable in `title`.

                    The spinner is not decoration. A board button walks every
                    card on the board, and until the toast arrives it is the
                    only sign the press was heard.
                --}}
                <button wire:click="press({{ $button->id }})" wire:key="butler-board-button-{{ $button->id }}"
                        wire:loading.attr="disabled" wire:target="press({{ $button->id }})"
                        title="{{ $button->name }}"
                        class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5 min-w-0 max-w-xs">
                    <i class="{{ $button->iconClass() }} text-sm shrink-0"
                       wire:loading.remove wire:target="press({{ $button->id }})"></i>
                    <i class="ki-filled ki-loading animate-spin text-sm shrink-0"
                       wire:loading wire:target="press({{ $button->id }})"></i>
                    <span class="truncate">{{ $button->name }}</span>
                </button>
            @endforeach
        </div>
    @endif
</div>
