<?php

use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Butler\Butler;
use Modules\Project\Models\ButlerRule;
use Modules\Project\Models\Card;

// No root-namespace `use` imports here — see ⚡card-custom-fields.blade.php.

/**
 * Butler's card buttons, on the card back.
 *
 * Nested inside the card drawer: `<livewire:project::card-buttons :card-id="$card->id" />`.
 *
 * Takes a card id and nothing else, the same contract the drawer already uses
 * for custom fields — and for the same reason. The buttons a card shows belong
 * to the board the card *lives on*, its origin, never whichever board's canvas
 * happened to open it. A mirrored card therefore offers one set of buttons
 * wherever it is looked at.
 *
 * Pressing a button runs its chain through `Butler`, which is where the
 * conditions are checked and where the loop guard lives. This component never
 * touches a card itself.
 *
 * Renders **nothing at all** when the board has no card buttons, rather than an
 * empty heading — an automation section that is empty on every card of a board
 * nobody has automated is noise on the card back.
 */
new class extends Component
{
    use InteractsWithToasts;

    public int $cardId;

    private ?Card $resolvedCard = null;

    public function mount(int $cardId): void
    {
        $this->cardId = $cardId;
    }

    private function card(): ?Card
    {
        return $this->resolvedCard ??= Card::query()->with('list.board')->find($this->cardId);
    }

    public function with(): array
    {
        return ['buttons' => $this->buttons()];
    }

    /** @return Collection<int, ButlerRule> */
    private function buttons(): Collection
    {
        $boardId = $this->card()?->list?->board_id;

        return $boardId === null
            ? collect()
            : ButlerRule::query()
                ->where('board_id', $boardId)
                ->ofKind(\Modules\Project\Butler\Kind::CARD_BUTTON)
                ->enabled()
                ->orderBy('position')
                ->orderBy('id')
                ->get();
    }

    public function press(int $ruleId): void
    {
        $card = $this->card();
        $rule = $this->buttons()->firstWhere('id', $ruleId);

        if ($card === null || $rule === null) {
            return;
        }

        $ran = app(Butler::class)->press($rule, $card);

        // The card canvas already listens for this and redraws the card face.
        $this->dispatch('card-changed');

        // Only the *silent* outcome is worth a toast. A button that moved the
        // card, added a label or posted a comment has shown its own result on
        // the card back; a button whose conditions rejected the card has shown
        // nothing at all, and would otherwise look broken.
        if (! $ran) {
            $this->toastError($rule->name, 'This card does not match that button’s conditions, so nothing ran.');
        }

        $this->resolvedCard = null;
    }
};

?>

{{--
    `contents` on the root, so that on a board with no card buttons this
    component leaves no trace at all. Livewire always renders the root element;
    the card back stacks its sections with `flex flex-col gap-*`, and an empty
    block-level div is still a flex item, so without this the drawer showed a
    gap of blank space where the Butler section would have been.
--}}
<div class="contents">
    @if ($buttons->isNotEmpty())
        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <i class="ki-filled ki-flash-circle text-sm text-muted-foreground"></i>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Butler</h3>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($buttons as $button)
                    {{--
                        `min-w-0 max-w-full` plus a `truncate` span: `kt-btn` is
                        `white-space: nowrap` and never clips, so a button named
                        after the sentence it runs would push out of the drawer.
                        The full name stays reachable in `title`.
                    --}}
                    <button wire:click="press({{ $button->id }})" wire:key="butler-card-button-{{ $button->id }}"
                            wire:loading.attr="disabled" wire:target="press({{ $button->id }})"
                            title="{{ $button->name }}"
                            class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 min-w-0 max-w-full">
                        <i class="{{ $button->iconClass() }} text-sm shrink-0"
                           wire:loading.remove wire:target="press({{ $button->id }})"></i>
                        <i class="ki-filled ki-loading animate-spin text-sm shrink-0"
                           wire:loading wire:target="press({{ $button->id }})"></i>
                        <span class="truncate">{{ $button->name }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
