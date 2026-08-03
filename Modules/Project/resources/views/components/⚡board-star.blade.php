<?php

use Livewire\Component;
use Modules\Project\Models\Board;

/**
 * The star toggle beside a board's name.
 *
 * Nested wherever a board is listed: `<livewire:project::board-star :board-id="$board->id" :key="'board-star-'.$board->id" />`.
 * Takes a board id only, the same contract `⚡card-watch.blade.php` uses — one
 * line to mount, and it resolves the board itself.
 *
 * It is a component rather than a button on the page for one reason: clicking
 * it must not re-render the page around it. A board index carries the whole
 * canvas, and starring is a preference, not a change to anything on screen —
 * so the click re-renders this button and nothing else. That also means the
 * page holding it never has to know a star exists, and does not need a
 * listener, a property or a refresh when one is toggled.
 *
 * A `:key` is required when mounting inside a loop. Without one Livewire
 * reuses the first instance's state for every row and every star on the page
 * toggles the same board.
 *
 * No toast. The icon fills in place — a toast would report the thing the user
 * is looking at, which `docs/frontend-conventions.md` says not to do.
 */
new class extends Component
{
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
        $board = $this->board();

        return [
            'starred' => $board !== null && $board->isStarredBy(auth()->user()),
        ];
    }

    public function toggle(): void
    {
        $board = $this->board();
        $user = auth()->user();

        // A board id that no longer resolves, or a view with nobody logged in,
        // is handled by doing nothing — there is no failure here to report.
        if ($board === null || $user === null) {
            return;
        }

        $board->toggleStarFor($user);
    }
};

?>

<div class="inline-flex">
    <button type="button" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
            class="kt-btn kt-btn-sm kt-btn-ghost kt-btn-icon"
            aria-pressed="{{ $starred ? 'true' : 'false' }}"
            title="{{ $starred ? 'Remove this board from your starred boards' : 'Star this board so it stays at the top' }}">
        <i class="{{ $starred ? 'ki-filled' : 'ki-outline' }} ki-star text-sm {{ $starred ? 'text-warning' : 'text-muted-foreground/60' }}"></i>
        <span class="sr-only">{{ $starred ? 'Starred' : 'Not starred' }}</span>
    </button>
</div>
