<?php

use Livewire\Component;
use Modules\Project\Models\Card;
use Modules\Project\Services\Watching;

/**
 * The watch toggle on the card back.
 *
 * Nested inside the card drawer: `<livewire:project::card-watch :card-id="$card->id" />`.
 * Takes a card id only, the same contract `⚡card-custom-fields.blade.php` uses
 * — one line to mount, and it resolves the card itself.
 *
 * No toast either way. Watching flips a visible icon and label right there on
 * the button — a toast reporting the exact thing the button already shows
 * would be reporting what the user is looking at, which the toast rule
 * (`docs/frontend-conventions.md`) says not to do. A failed toggle is not
 * possible here — there is no validation to fail, only a card id that no
 * longer resolves, which is handled by doing nothing rather than by erroring.
 */
new class extends Component
{
    public int $cardId;

    private ?Card $resolvedCard = null;

    public function mount(int $cardId): void
    {
        $this->cardId = $cardId;
    }

    private function card(): ?Card
    {
        return $this->resolvedCard ??= Card::query()->find($this->cardId);
    }

    public function with(): array
    {
        $card = $this->card();
        $userId = auth()->id();

        return [
            'watching' => $card !== null && $userId !== null
                ? app(Watching::class)->isWatching($card, $userId)
                : false,
        ];
    }

    public function toggle(): void
    {
        $card = $this->card();
        $userId = auth()->id();

        if ($card === null || $userId === null) {
            return;
        }

        app(Watching::class)->toggle($card, $userId);
    }
};

?>

<div>
    <button type="button" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
            class="kt-btn kt-btn-sm {{ $watching ? 'kt-btn-primary' : 'kt-btn-outline' }} gap-1.5"
            aria-pressed="{{ $watching ? 'true' : 'false' }}"
            title="{{ $watching ? 'Stop watching this card' : 'Watch this card' }}">
        <i class="ki-filled {{ $watching ? 'ki-eye' : 'ki-eye-slash' }} text-sm"></i>
        {{ $watching ? 'Watching' : 'Watch' }}
    </button>
</div>
