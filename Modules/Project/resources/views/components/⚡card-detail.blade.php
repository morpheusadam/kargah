<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\Checklist;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;

/**
 * Card detail drawer, reading from the database.
 *
 * Nested inside the board. The board dispatches `open-card` with a card id and
 * this component loads that row with everything hanging off it: labels,
 * members, checklists, comments — then slides in from the right.
 *
 * Three things are worth knowing before changing anything.
 *
 * **Every write here is a real write.** There is no local copy of the card that
 * the drawer edits and the board later reconciles; the component holds the card
 * *id* and reads the row on each render. So the only state that has to survive
 * a round-trip is what the user has typed but not yet saved.
 *
 * **Anything that changes the front of the card dispatches `card-changed`.**
 * The board canvas is an island, and an island nobody redraws keeps whatever
 * the DOM already had. The description is the one field deliberately left out:
 * it is not drawn on the card face, so redrawing the canvas for it would send
 * every card back for nothing.
 *
 * **Labels come from the card's own board.** A label belongs to one board, so
 * the picker is `$card->list->board->labels` and never a global list — putting
 * another board's label on a card would attach a row the board can never show.
 */
new
class extends Component
{
    use InteractsWithToasts;

    public bool $open = false;

    public ?int $cardId = null;

    #[Validate('required|min:3|max:120')]
    public string $title = '';

    public string $description = '';

    public string $dueDate = '';

    /** The single assignee's user id, as a string, or '' for nobody. */
    public string $assignee = '';

    /** The list `moveCard()` will move to, as a string id. */
    public string $moveToList = '';

    public string $newChecklistItem = '';

    public string $newComment = '';

    public bool $editingTitle = false;

    public bool $editingDescription = false;

    public bool $labelPopoverOpen = false;

    public bool $duePopoverOpen = false;

    public bool $movePopoverOpen = false;

    /** Per-request memo. Private, so Livewire neither ships nor rehydrates it. */
    private ?Card $resolvedCard = null;

    /* Reading the card ---------------------------------------------------- */

    /**
     * The open card, or null when the drawer has never been opened — or when
     * the card was archived away underneath it.
     */
    private function card(): ?Card
    {
        if ($this->cardId === null) {
            return null;
        }

        return $this->resolvedCard ??= Card::query()
            ->with([
                'list.board.labels',
                'labels',
                'members',
                'checklists.items',
                'comments.author',
            ])
            ->find($this->cardId);
    }

    /** Drop the memo so the next read sees what was just written. */
    private function forgetCard(): void
    {
        $this->resolvedCard = null;
    }

    /**
     * Redraw the board canvas.
     *
     * Only for changes the card face actually shows — title, labels, due date,
     * members, checklist counts, comment counts, and whether the card is on the
     * board at all.
     */
    private function cardChanged(): void
    {
        $this->forgetCard();

        $this->dispatch('card-changed');
    }

    private function reportMissingCard(): void
    {
        $this->open = false;
        $this->cardId = null;

        $this->toastError('That card is gone', 'It was deleted while the drawer was open.');
    }

    /** Every checklist item on the card, in order, across every checklist. */
    private function items(): Collection
    {
        $card = $this->card();

        return $card === null ? collect() : $card->checklists->flatMap->items;
    }

    /** One item, but only if it belongs to the open card. */
    private function itemOnThisCard(int $itemId): ?ChecklistItem
    {
        return $this->items()->firstWhere('id', $itemId);
    }

    /** The lists the card can be moved to: its own board's, still on the board. */
    private function listsOnThisBoard(): Collection
    {
        $card = $this->card();

        if ($card?->list === null) {
            return collect();
        }

        return BoardList::query()
            ->where('board_id', $card->list->board_id)
            ->active()
            ->orderBy('position')
            ->get();
    }

    public function with(): array
    {
        $card = $this->card();
        $items = $this->items();
        $done = $items->where('is_done', true)->count();
        $total = $items->count();

        return [
            'card' => $card,
            'labels' => $card?->list?->board?->labels ?? collect(),
            'cardLabelIds' => $card?->labels->pluck('id')->all() ?? [],
            'members' => User::query()->orderBy('name')->get(),
            'lists' => $this->listsOnThisBoard(),
            'checklist' => $items,
            'comments' => $card?->comments ?? collect(),
            'checklistDone' => $done,
            'checklistTotal' => $total,
            'checklistPercent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'formatting' => [
                ['icon' => 'ki-text-bold', 'wrap' => '**', 'title' => 'Bold'],
                ['icon' => 'ki-text-italic', 'wrap' => '_', 'title' => 'Italic'],
                ['icon' => 'ki-text-strikethrough', 'wrap' => '~~', 'title' => 'Strikethrough'],
                ['icon' => 'ki-code', 'wrap' => '`', 'title' => 'Code'],
                ['icon' => 'ki-textalign-left', 'prefix' => '- ', 'title' => 'Bullet list'],
                ['icon' => 'ki-check-squared', 'prefix' => '- [ ] ', 'title' => 'Task list'],
                ['icon' => 'ki-share', 'wrap' => '[]', 'title' => 'Link'],
            ],
        ];
    }

    /* Opening and closing ------------------------------------------------ */

    /** Fill the editable fields from the row. */
    private function hydrateFrom(Card $card): void
    {
        $this->cardId = $card->id;
        $this->title = $card->title;
        $this->description = (string) $card->description;
        $this->dueDate = $card->due_on?->toDateString() ?? '';
        $this->assignee = (string) ($card->members->first()?->id ?? '');
        $this->moveToList = (string) $card->board_list_id;

        $this->editingTitle = false;
        $this->editingDescription = false;
        $this->labelPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->newComment = '';
        $this->newChecklistItem = '';

        $this->resetValidation();
    }

    #[On('open-card')]
    public function openCard(int $cardId): void
    {
        $this->cardId = $cardId;
        $this->forgetCard();

        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $this->hydrateFrom($card);

        $this->open = true;

        $this->toastSuccess('Card open', $card->title.' is in the drawer on the right.');
    }

    public function close(): void
    {
        $wasOpen = $this->open;

        $this->open = false;
        $this->labelPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;

        if ($wasOpen) {
            $this->toastSuccess('Card closed', 'Anything still open in an editor was not saved.');
        }
    }

    /* Title and description ---------------------------------------------- */

    public function editTitle(): void
    {
        $this->editingTitle = true;

        $this->toastSuccess('Title editor open', 'Esc puts the old title back.');
    }

    public function cancelTitle(): void
    {
        $wasEditing = $this->editingTitle;

        $this->editingTitle = false;
        $this->title = $this->card()?->title ?? '';
        $this->resetValidation('title');

        if ($wasEditing) {
            $this->toastSuccess('Rename abandoned', 'The card kept its old title.');
        }
    }

    /** Rename the card. */
    public function saveTitle(): void
    {
        $this->validateOnly('title');

        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $card->update(['title' => trim($this->title)]);

        $this->title = $card->title;
        $this->editingTitle = false;

        $this->cardChanged();

        $this->toastSuccess('Card renamed', 'It reads '.$card->title.' everywhere now.');
    }

    public function editDescription(): void
    {
        $this->editingDescription = true;

        $this->toastSuccess('Description editor open', 'Markdown is kept as you write it.');
    }

    public function cancelDescription(): void
    {
        $wasEditing = $this->editingDescription;

        $this->editingDescription = false;
        $this->description = (string) ($this->card()?->description ?? '');

        if ($wasEditing) {
            $this->toastSuccess('Edit abandoned', 'The card kept its old description.');
        }
    }

    /**
     * Store the description.
     *
     * No `card-changed`: the description is not drawn on the card face, so
     * redrawing the canvas for it would send every card back for nothing.
     */
    public function saveDescription(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $card->update(['description' => $this->description === '' ? null : $this->description]);

        $this->description = (string) $card->description;
        $this->editingDescription = false;

        $this->forgetCard();

        $this->toastSuccess(
            trim($this->description) === '' ? 'Description cleared' : 'Description saved',
            'Markdown was kept exactly as you wrote it.',
        );
    }

    /* Labels, due date, assignee ----------------------------------------- */

    public function toggleLabelPopover(): void
    {
        $this->labelPopoverOpen = ! $this->labelPopoverOpen;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;

        $this->labelPopoverOpen
            ? $this->toastSuccess('Label picker open', 'Tick the labels this card should carry.')
            : $this->toastSuccess('Label picker closed');
    }

    /** Add or remove one of the board's labels on this card. */
    public function toggleLabel(int $labelId): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $label = $card->list?->board?->labels->firstWhere('id', $labelId);

        if ($label === null) {
            $this->toastError('That label is not on this board', 'Reload the page and try again.');

            return;
        }

        $wasOn = $card->labels->contains('id', $label->id);

        $wasOn
            ? $card->labels()->detach($label->id)
            : $card->labels()->attach($label->id);

        // A pivot change is invisible to the model's own attribute log, so it
        // is written by hand. Every board action reaches the feed or none of
        // them can be trusted to.
        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event($wasOn ? 'card.label_removed' : 'card.label_added')
            ->withProperties(['label' => $label->name])
            ->log($wasOn ? 'lost the label '.$label->name : 'gained the label '.$label->name);

        $this->cardChanged();

        $this->toastSuccess(
            $wasOn ? $label->name.' taken off the card' : $label->name.' put on the card',
            $wasOn
                ? 'It no longer shows on the front of the card.'
                : 'It shows on the front of the card and in the board filter.',
        );
    }

    public function toggleDuePopover(): void
    {
        $this->duePopoverOpen = ! $this->duePopoverOpen;
        $this->labelPopoverOpen = false;
        $this->movePopoverOpen = false;

        $this->duePopoverOpen
            ? $this->toastSuccess('Due date picker open', 'Pick a date, or remove the one already set.')
            : $this->toastSuccess('Due date picker closed');
    }

    /**
     * Set the due date.
     *
     * `cards.due_on` is a date, not an instant: a card due on 31 July is due on
     * 31 July wherever it is read, so only the day is stored.
     */
    public function saveDueDate(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $typed = trim($this->dueDate);

        if ($typed === '') {
            $this->toastError('No date was picked', 'Choose a day, or use remove to clear the one already set.');

            return;
        }

        try {
            $due = Carbon::parse($typed)->startOfDay();
        } catch (\Throwable) {
            $this->toastError('That date could not be read', 'Use the picker rather than typing the day in.');

            return;
        }

        $card->update(['due_on' => $due->toDateString()]);

        $this->dueDate = $due->toDateString();
        $this->duePopoverOpen = false;

        $this->cardChanged();

        $this->toastSuccess('Due date set', $card->title.' is due on '.$due->format('j M Y').'.');
    }

    public function clearDueDate(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $card->update(['due_on' => null]);

        $this->dueDate = '';
        $this->duePopoverOpen = false;

        $this->cardChanged();

        $this->toastSuccess('Due date removed', $card->title.' is no longer counted as due.');
    }

    /**
     * Fired when the assignee select changes; empty means unassigned.
     *
     * The schema models members as a pivot, but the drawer offers one assignee,
     * so setting it replaces whoever was on the card rather than adding to them.
     */
    public function updatedAssignee(string $value): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        if (trim($value) === '') {
            $card->members()->sync([]);

            $this->cardChanged();

            $this->toastSuccess('Card unassigned', 'Nobody is carrying '.$card->title.'.');

            return;
        }

        $user = User::query()->find((int) $value);

        if ($user === null) {
            $this->assignee = (string) ($card->members->first()?->id ?? '');

            $this->toastError('That person could not be found', 'Reload the page and try again.');

            return;
        }

        $card->members()->sync([$user->id]);

        $this->assignee = (string) $user->id;

        $this->cardChanged();

        $this->toastSuccess('Card assigned', $user->name.' is carrying '.$card->title.'.');
    }

    /* Checklist ----------------------------------------------------------- */

    public function toggleChecklistItem(int $itemId): void
    {
        $item = $this->itemOnThisCard($itemId);

        if ($item === null) {
            $this->toastError('That item is gone', 'It was deleted while the drawer was open.');
            $this->forgetCard();

            return;
        }

        $done = ! $item->is_done;

        $item->update([
            'is_done' => $done,
            'completed_at' => $done ? now() : null,
        ]);

        $this->cardChanged();

        $this->toastSuccess(
            $done ? 'Item ticked' : 'Item unticked',
            $item->text,
        );
    }

    /**
     * Append an item to the checklist.
     *
     * A card that has never had one gets a checklist created here rather than
     * on every card up front, and the item takes the position after the last —
     * never a raw float.
     */
    public function addChecklistItem(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $text = trim($this->newChecklistItem);

        if ($text === '') {
            $this->toastError('The item needs some text', 'Type what has to be done, then add it.');

            return;
        }

        $checklist = $card->checklists->first() ?? Checklist::query()->create([
            'card_id' => $card->id,
            'name' => 'Checklist',
            'position' => Position::after(null),
        ]);

        $last = ChecklistItem::query()
            ->where('checklist_id', $checklist->id)
            ->orderByDesc('position')
            ->value('position');

        ChecklistItem::query()->create([
            'checklist_id' => $checklist->id,
            'text' => $text,
            'is_done' => false,
            'position' => Position::after($last === null ? null : Position::format((string) $last)),
            'created_by' => auth()->id(),
        ]);

        // Adding one item usually means adding three, so the box empties and
        // keeps the focus rather than closing anything.
        $this->newChecklistItem = '';

        $this->cardChanged();

        $this->toastSuccess('Item added', $text.' is at the bottom of the checklist.');
    }

    public function deleteChecklistItem(int $itemId): void
    {
        $item = $this->itemOnThisCard($itemId);

        if ($item === null) {
            $this->toastError('That item is gone', 'It was deleted while the drawer was open.');
            $this->forgetCard();

            return;
        }

        $text = $item->text;

        $item->delete();

        $this->cardChanged();

        $this->toastSuccess('Item deleted', $text.' is off the checklist.');
    }

    /* Attachments and comments -------------------------------------------- */

    /**
     * There is no attachments table yet.
     *
     * Files land with the Data module, which owns the disk, the size accounting
     * and the download route. Until then the section renders its empty state
     * and this says so rather than pretending to delete a row.
     */
    public function removeAttachment(string $name): void
    {
        $this->toastInfo('Not connected yet', 'File attachments arrive with the Data module.');
    }

    /** Post a comment on the card. */
    public function addComment(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $body = trim($this->newComment);

        if ($body === '') {
            $this->toastError('The comment is empty', 'Write something before posting it.');

            return;
        }

        CardComment::query()->create([
            'card_id' => $card->id,
            'created_by' => auth()->id(),
            'body' => $body,
        ]);

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.commented')
            ->log('was commented on');

        $this->newComment = '';

        $this->cardChanged();

        $this->toastSuccess('Comment posted', 'It is at the bottom of the thread on '.$card->title.'.');
    }

    /* Right rail actions --------------------------------------------------- */

    public function toggleMovePopover(): void
    {
        $this->movePopoverOpen = ! $this->movePopoverOpen;
        $this->labelPopoverOpen = false;
        $this->duePopoverOpen = false;

        if ($this->movePopoverOpen) {
            $this->moveToList = (string) ($this->card()?->board_list_id ?? '');

            $this->toastSuccess('Move picker open', 'Pick the list this card belongs in.');

            return;
        }

        $this->toastSuccess('Move picker closed');
    }

    /** Move the card to another list on the same board. */
    public function moveCard(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $target = $this->listsOnThisBoard()->firstWhere('id', (int) $this->moveToList);

        if ($target === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try the move again.');

            return;
        }

        if ($target->id === $card->board_list_id) {
            $this->movePopoverOpen = false;

            $this->toastSuccess('Nothing to move', $card->title.' is already in '.$target->name.'.');

            return;
        }

        // The bottom of the target list, counted rather than guessed: the
        // service brackets the index by real positions on either side.
        $below = Card::query()
            ->where('board_list_id', $target->id)
            ->where('id', '!=', $card->id)
            ->active()
            ->count();

        app(CardService::class)->move($card, $target, $below);

        $this->movePopoverOpen = false;

        $this->cardChanged();

        $this->toastSuccess('Card moved', $card->title.' is at the bottom of '.$target->name.'.');
    }

    /** Duplicate the card, its labels and its checklist into the same list. */
    public function copyCard(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $list = $card->list;

        if ($list === null) {
            $this->toastError('That card has no list', 'Reload the page and try again.');

            return;
        }

        // `cards.title` is 255, so the suffix has to be made room for rather
        // than appended to whatever is already there.
        $copy = app(CardService::class)->append($list, mb_substr($card->title, 0, 240).' (copy)', [
            'description' => $card->description,
            'due_on' => $card->due_on?->toDateString(),
        ]);

        $copy->labels()->sync($card->labels->pluck('id')->all());

        foreach ($card->checklists as $checklist) {
            $copied = Checklist::query()->create([
                'card_id' => $copy->id,
                'name' => $checklist->name,
                'position' => Position::format((string) $checklist->position),
            ]);

            foreach ($checklist->items as $item) {
                ChecklistItem::query()->create([
                    'checklist_id' => $copied->id,
                    'text' => $item->text,
                    'is_done' => $item->is_done,
                    'position' => Position::format((string) $item->position),
                    'completed_at' => $item->completed_at,
                    'created_by' => auth()->id(),
                ]);
            }
        }

        // The drawer follows the copy: it is the card the user now wants to
        // edit, and the original is one click away on the board behind it.
        $this->cardId = $copy->id;
        $this->forgetCard();

        $fresh = $this->card();

        if ($fresh !== null) {
            $this->hydrateFrom($fresh);
        }

        $this->cardChanged();

        $this->toastSuccess('Card copied', 'The copy is at the bottom of '.$list->name.', and the drawer is on it.');
    }

    /** Archive the card. Nothing is deleted — the archive can put it back. */
    public function archiveCard(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        app(CardService::class)->archive($card);

        $title = $card->title;

        $this->open = false;
        $this->movePopoverOpen = false;

        $this->cardChanged();

        $this->toastSuccess($title.' archived', 'It left the board and can be restored from the archive.');
    }

    /** Soft-delete the card, so the row survives for anything pointing at it. */
    public function deleteCard(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $title = $card->title;

        $card->delete();

        $this->open = false;
        $this->movePopoverOpen = false;
        $this->cardId = null;

        $this->cardChanged();

        $this->toastSuccess($title.' deleted', 'It is off the board, and nothing else moved.');
    }
};

?>

<div class="fixed inset-0 z-50 overflow-hidden {{ $open ? '' : 'pointer-events-none' }}"
     aria-hidden="{{ $open ? 'false' : 'true' }}">

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/40 transition-opacity duration-200 {{ $open ? 'opacity-100' : 'opacity-0' }}"
         wire:click="close"></div>

    {{-- Slide-over --}}
    <aside class="absolute inset-y-0 end-0 w-full max-w-[760px] bg-background border-s border-border shadow-lg
                  flex flex-col transition-transform duration-200 ease-out {{ $open ? 'translate-x-0' : 'translate-x-full' }}"
           role="dialog" aria-modal="true" aria-label="Card detail" tabindex="-1"
           wire:keydown.escape="close">

        @if ($card)
            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-border">
                <div class="min-w-0 grow">
                    @if ($editingTitle)
                        <div class="flex flex-col gap-2">
                            <input type="text" class="kt-input @error('title') border-destructive @enderror"
                                   aria-label="Card title" wire:model="title"
                                   wire:keydown.escape="cancelTitle" wire:keydown.enter.prevent="saveTitle" autofocus>
                            @error('title')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            <div class="flex items-center gap-2">
                                <button wire:click="saveTitle" wire:loading.attr="disabled" wire:target="saveTitle"
                                        class="kt-btn kt-btn-sm kt-btn-primary">
                                    <span wire:loading.remove wire:target="saveTitle">Save</span>
                                    <span wire:loading wire:target="saveTitle"><i class="ki-filled ki-loading animate-spin"></i> Saving…</span>
                                </button>
                                <button wire:click="cancelTitle" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                            </div>
                        </div>
                    @else
                        <button wire:click="editTitle"
                                class="text-start w-full rounded-md px-1 -mx-1 hover:bg-accent/60"
                                title="Rename this card">
                            <h2 class="text-lg font-semibold text-mono leading-snug">{{ $card->title }}</h2>
                        </button>
                        <p class="text-xs text-muted-foreground mt-1">
                            In list <span class="text-secondary-foreground">{{ $card->list?->name ?? '—' }}</span>
                            on <span class="text-secondary-foreground">{{ $card->list?->board?->name ?? '—' }}</span>
                        </p>
                    @endif
                </div>

                <button wire:click="close" class="kt-btn kt-btn-icon kt-btn-ghost size-8 shrink-0"
                        title="Close card" aria-label="Close card">
                    <i class="ki-filled ki-cross text-sm"></i>
                </button>
            </div>

            {{-- Body --}}
            <div class="grow overflow-y-auto kt-scrollable-y">
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_200px] gap-6 px-5 py-5">

                    {{-- Main column --}}
                    <div class="flex flex-col gap-6 min-w-0">

                        {{-- Labels, due date, assignee --}}
                        <div class="flex flex-wrap items-start gap-6">

                            <div class="flex flex-col gap-2">
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Labels</span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @forelse ($card->labels as $label)
                                        <span class="text-xs font-medium px-2 py-1 rounded {{ $label->chipClass() }}">{{ $label->name }}</span>
                                    @empty
                                        <span class="text-sm text-muted-foreground">None yet</span>
                                    @endforelse

                                    <div class="relative">
                                        <button wire:click="toggleLabelPopover" class="kt-btn kt-btn-icon kt-btn-outline size-7"
                                                title="Edit labels" aria-label="Edit labels"
                                                aria-expanded="{{ $labelPopoverOpen ? 'true' : 'false' }}">
                                            <i class="ki-filled ki-plus text-xs"></i>
                                        </button>

                                        <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[260px] {{ $labelPopoverOpen ? 'open' : '' }}">
                                            <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-border">
                                                <h4 class="text-sm font-semibold text-mono">Labels</h4>
                                                <button wire:click="toggleLabelPopover" class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                        title="Close labels" aria-label="Close labels">
                                                    <i class="ki-filled ki-cross text-xs"></i>
                                                </button>
                                            </div>
                                            <div class="p-2 flex flex-col gap-1">
                                                @forelse ($labels as $label)
                                                    <button wire:click="toggleLabel({{ $label->id }})" wire:key="label-{{ $label->id }}"
                                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60">
                                                        <span class="size-3 rounded-sm {{ $label->dotClass() }}"></span>
                                                        <span class="grow text-secondary-foreground">{{ $label->name }}</span>
                                                        @if (in_array($label->id, $cardLabelIds, true))
                                                            <i class="ki-filled ki-check text-sm text-primary"></i>
                                                        @endif
                                                    </button>
                                                @empty
                                                    <p class="text-xs text-muted-foreground px-2 py-3 text-center">This board has no labels yet.</p>
                                                @endforelse
                                            </div>
                                            @if ($card->list?->board)
                                                <div class="border-t border-border p-2">
                                                    <a href="{{ route('projects.board-settings', ['board' => $card->list->board->slug]) }}" wire:navigate
                                                       class="kt-btn kt-btn-ghost kt-btn-sm justify-start gap-2 w-full">
                                                        <i class="ki-filled ki-setting-2 text-sm"></i> Manage board labels
                                                    </a>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2">
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Due date</span>
                                <div class="relative">
                                    <button wire:click="toggleDuePopover" class="kt-btn kt-btn-outline kt-btn-sm gap-2"
                                            aria-expanded="{{ $duePopoverOpen ? 'true' : 'false' }}">
                                        <i class="ki-filled ki-calendar text-sm"></i>
                                        {{ $card->due_on ? $card->due_on->format('j M Y') : 'No due date' }}
                                    </button>

                                    <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[240px] p-4 flex flex-col gap-3 {{ $duePopoverOpen ? 'open' : '' }}">
                                        <label class="kt-form-label text-xs" for="card-due">Due on</label>
                                        <input id="card-due" type="date" class="kt-input" wire:model="dueDate">
                                        <div class="flex items-center gap-2">
                                            <button wire:click="saveDueDate" wire:loading.attr="disabled" wire:target="saveDueDate"
                                                    class="kt-btn kt-btn-sm kt-btn-primary">
                                                <span wire:loading.remove wire:target="saveDueDate">Save</span>
                                                <span wire:loading wire:target="saveDueDate"><i class="ki-filled ki-loading animate-spin"></i></span>
                                            </button>
                                            <button wire:click="clearDueDate" class="kt-btn kt-btn-sm kt-btn-ghost">Remove</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2">
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Assignee</span>
                                <div class="flex items-center gap-2">
                                    @php($holder = $card->members->first())
                                    @if ($holder)
                                        <span class="size-7 rounded-full grid place-items-center text-[11px] font-semibold bg-primary/15 text-primary"
                                              title="{{ $holder->name }}">
                                            {{ $holder->initials() }}
                                        </span>
                                    @else
                                        <span class="size-7 rounded-full grid place-items-center bg-muted text-muted-foreground">
                                            <i class="ki-filled ki-user text-xs"></i>
                                        </span>
                                    @endif
                                    <select class="kt-select max-w-[190px]" aria-label="Assignee" wire:model.live="assignee">
                                        <option value="">Unassigned</option>
                                        @foreach ($members as $member)
                                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        {{-- Description --}}
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <i class="ki-filled ki-notepad-edit text-sm text-muted-foreground"></i>
                                <h3 class="text-sm font-semibold text-mono">Description</h3>
                            </div>

                            @if ($editingDescription)
                                <div class="rounded-lg border border-border overflow-hidden" data-md-editor="card-description">
                                    <div class="flex flex-wrap items-center gap-1 bg-muted/50 border-b border-border px-2 py-1.5">
                                        @foreach ($formatting as $tool)
                                            <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                    title="{{ $tool['title'] }}" aria-label="{{ $tool['title'] }}"
                                                    @isset($tool['wrap']) data-md-wrap="{{ $tool['wrap'] }}" @endisset
                                                    @isset($tool['prefix']) data-md-prefix="{{ $tool['prefix'] }}" @endisset>
                                                <i class="ki-filled {{ $tool['icon'] }} text-sm"></i>
                                            </button>
                                        @endforeach
                                        <span class="text-[11px] text-muted-foreground ms-auto pe-1">Markdown</span>
                                    </div>
                                    <textarea id="card-description" rows="7" class="kt-textarea border-0 rounded-none w-full"
                                              aria-label="Card description" wire:model="description"
                                              wire:keydown.escape="cancelDescription"
                                              placeholder="What has to be true before this card can move?"></textarea>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button wire:click="saveDescription" wire:loading.attr="disabled" wire:target="saveDescription"
                                            class="kt-btn kt-btn-sm kt-btn-primary">
                                        <span wire:loading.remove wire:target="saveDescription">Save</span>
                                        <span wire:loading wire:target="saveDescription"><i class="ki-filled ki-loading animate-spin"></i> Saving…</span>
                                    </button>
                                    <button wire:click="cancelDescription" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                </div>
                            @else
                                <button wire:click="editDescription"
                                        class="text-start rounded-lg border border-border bg-muted/30 px-4 py-3 hover:border-primary/40 transition-colors">
                                    @if (trim((string) $card->description) !== '')
                                        <div class="text-sm text-secondary-foreground whitespace-pre-line leading-relaxed">{{ $card->description }}</div>
                                    @else
                                        <span class="text-sm text-muted-foreground">Add a more detailed description…</span>
                                    @endif
                                </button>
                            @endif
                        </div>

                        {{-- Checklist --}}
                        <div class="flex flex-col gap-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <i class="ki-filled ki-check-squared text-sm text-muted-foreground"></i>
                                    <h3 class="text-sm font-semibold text-mono">Checklist</h3>
                                </div>
                                <span class="text-xs text-muted-foreground">{{ $checklistDone }}/{{ $checklistTotal }} done</span>
                            </div>

                            <div class="flex items-center gap-3">
                                <div class="kt-progress grow">
                                    <div class="h-full rounded-full {{ $checklistPercent === 100 ? 'bg-success' : 'bg-primary' }}"
                                         style="width: {{ $checklistPercent }}%"
                                         role="progressbar" aria-valuenow="{{ $checklistPercent }}" aria-valuemin="0" aria-valuemax="100"
                                         aria-label="Checklist progress"></div>
                                </div>
                                <span class="text-xs text-muted-foreground w-9 text-end">{{ $checklistPercent }}%</span>
                            </div>

                            <div class="flex flex-col gap-1">
                                @forelse ($checklist as $item)
                                    <div class="group flex items-start gap-2.5 rounded-md px-2 py-1.5 hover:bg-accent/60" wire:key="check-{{ $item->id }}">
                                        <input type="checkbox" class="kt-checkbox mt-0.5"
                                               id="check-{{ $item->id }}"
                                               wire:click="toggleChecklistItem({{ $item->id }})"
                                               @checked($item->is_done)>
                                        <label for="check-{{ $item->id }}"
                                               class="grow text-sm cursor-pointer {{ $item->is_done ? 'text-muted-foreground line-through' : 'text-secondary-foreground' }}">
                                            {{ $item->text }}
                                        </label>
                                        <button wire:click="deleteChecklistItem({{ $item->id }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0"
                                                title="Delete item" aria-label="Delete checklist item">
                                            <i class="ki-filled ki-trash text-xs"></i>
                                        </button>
                                    </div>
                                @empty
                                    <p class="text-sm text-muted-foreground px-2 py-1.5">No checklist on this card yet.</p>
                                @endforelse
                            </div>

                            <div class="flex items-center gap-2">
                                <input type="text" class="kt-input grow" placeholder="Add an item"
                                       aria-label="New checklist item"
                                       wire:model="newChecklistItem"
                                       wire:keydown.enter.prevent="addChecklistItem">
                                <button wire:click="addChecklistItem" wire:loading.attr="disabled" wire:target="addChecklistItem"
                                        class="kt-btn kt-btn-sm kt-btn-outline">
                                    <span wire:loading.remove wire:target="addChecklistItem">Add</span>
                                    <span wire:loading wire:target="addChecklistItem"><i class="ki-filled ki-loading animate-spin"></i></span>
                                </button>
                            </div>
                        </div>

                        {{--
                            Attachments. There is no attachments table yet: files
                            land with the Data module, which owns the disk and the
                            download route. The empty state is the honest render.
                        --}}
                        <div class="flex flex-col gap-3">
                            <div class="flex items-center gap-2">
                                <i class="ki-filled ki-paper-clip text-sm text-muted-foreground"></i>
                                <h3 class="text-sm font-semibold text-mono">Attachments</h3>
                            </div>

                            <div class="rounded-lg border border-dashed border-border px-4 py-6 text-center">
                                <i class="ki-filled ki-cloud-add text-2xl text-muted-foreground"></i>
                                <p class="text-sm text-muted-foreground mt-2">Nothing can be attached yet.</p>
                                <p class="text-xs text-muted-foreground mt-1">File attachments arrive with the Data module.</p>
                            </div>
                        </div>

                        {{-- Comments --}}
                        <div class="flex flex-col gap-3">
                            <div class="flex items-center gap-2">
                                <i class="ki-filled ki-message-text-2 text-sm text-muted-foreground"></i>
                                <h3 class="text-sm font-semibold text-mono">Activity</h3>
                            </div>

                            @forelse ($comments as $comment)
                                <div class="flex items-start gap-3" wire:key="comment-{{ $comment->id }}">
                                    <span class="size-8 rounded-full grid place-items-center text-[11px] font-semibold shrink-0 bg-primary/15 text-primary">
                                        {{ $comment->author?->initials() ?? '—' }}
                                    </span>
                                    <div class="min-w-0 grow">
                                        <div class="flex items-baseline gap-2">
                                            <span class="text-sm font-medium text-mono">{{ $comment->author?->name ?? 'Someone no longer here' }}</span>
                                            <span class="text-xs text-muted-foreground">{{ $comment->created_at?->format('j M, H:i') }}</span>
                                        </div>
                                        <p class="text-sm text-secondary-foreground mt-1 rounded-lg bg-muted/40 border border-border px-3 py-2 whitespace-pre-line">
                                            {{ $comment->body }}
                                        </p>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-muted-foreground">No comments yet. The first one usually explains why the card exists.</p>
                            @endforelse
                        </div>
                    </div>

                    {{-- Right rail --}}
                    <aside class="flex flex-col gap-2" aria-label="Card actions">
                        <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Actions</span>

                        <div class="relative">
                            <button wire:click="toggleMovePopover" class="kt-btn kt-btn-outline justify-start gap-2 w-full"
                                    aria-expanded="{{ $movePopoverOpen ? 'true' : 'false' }}">
                                <i class="ki-filled ki-arrow-right text-sm"></i> Move
                            </button>

                            <div class="kt-dropdown absolute z-20 mt-1 end-0 w-[240px] p-4 flex flex-col gap-3 {{ $movePopoverOpen ? 'open' : '' }}">
                                <label class="kt-form-label text-xs" for="card-move-list">Move to list</label>
                                <select id="card-move-list" class="kt-select" wire:model="moveToList">
                                    @foreach ($lists as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-[11px] text-muted-foreground">The card goes to the bottom of that list.</p>
                                <div class="flex items-center gap-2">
                                    <button wire:click="moveCard" wire:loading.attr="disabled" wire:target="moveCard"
                                            class="kt-btn kt-btn-sm kt-btn-primary">
                                        <span wire:loading.remove wire:target="moveCard">Move</span>
                                        <span wire:loading wire:target="moveCard"><i class="ki-filled ki-loading animate-spin"></i> Moving…</span>
                                    </button>
                                    <button wire:click="toggleMovePopover" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                </div>
                            </div>
                        </div>

                        <button wire:click="copyCard" wire:loading.attr="disabled" wire:target="copyCard"
                                class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                            <span wire:loading.remove wire:target="copyCard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-copy text-sm"></i> Copy
                            </span>
                            <span wire:loading wire:target="copyCard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Copying…
                            </span>
                        </button>
                        <button wire:click="archiveCard" wire:loading.attr="disabled" wire:target="archiveCard"
                                class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                            <span wire:loading.remove wire:target="archiveCard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-archive text-sm"></i> Archive
                            </span>
                            <span wire:loading wire:target="archiveCard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Archiving…
                            </span>
                        </button>
                        <button wire:click="deleteCard" wire:loading.attr="disabled" wire:target="deleteCard"
                                class="kt-btn kt-btn-outline justify-start gap-2 w-full text-destructive border-destructive/30">
                            <span wire:loading.remove wire:target="deleteCard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-trash text-sm"></i> Delete
                            </span>
                            <span wire:loading wire:target="deleteCard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Deleting…
                            </span>
                        </button>

                        <p class="text-[11px] text-muted-foreground mt-2 leading-relaxed">
                            Archiving keeps the card readable from the archive. Deleting takes it off the board for good.
                        </p>
                    </aside>
                </div>
            </div>

            {{-- Comment composer --}}
            <div class="border-t border-border px-5 py-4">
                <div class="flex items-start gap-3">
                    <span class="size-8 rounded-full grid place-items-center text-[11px] font-semibold shrink-0 bg-primary/15 text-primary">
                        {{ auth()->user()?->initials() ?? '—' }}
                    </span>
                    <div class="grow flex flex-col gap-2">
                        <textarea rows="2" class="kt-textarea" placeholder="Write a comment…"
                                  aria-label="New comment" wire:model="newComment"></textarea>
                        <div class="flex items-center gap-2">
                            <button wire:click="addComment" wire:loading.attr="disabled" wire:target="addComment"
                                    class="kt-btn kt-btn-sm kt-btn-primary gap-1">
                                <span wire:loading.remove wire:target="addComment">Comment</span>
                                <span wire:loading wire:target="addComment"><i class="ki-filled ki-loading animate-spin"></i> Posting…</span>
                            </button>
                            <span class="text-[11px] text-muted-foreground">It is posted as you, and stays on the card.</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </aside>
{{--
    Kept inside the component's root element on purpose. Livewire renders one
    root node and discards everything after it, so a @push below the closing tag
    never reaches the page.
--}}
@script
<script>
    (function initMarkdownToolbar() {
        if (window.kargahMarkdownToolbar) return;
        window.kargahMarkdownToolbar = true;

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-md-wrap], [data-md-prefix]');
            if (!button) return;

            const editor = button.closest('[data-md-editor]');
            const textarea = editor && editor.querySelector('textarea');
            if (!textarea) return;

            event.preventDefault();

            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const value = textarea.value;
            const selected = value.slice(start, end);

            let replacement;
            let caret;

            if (button.dataset.mdPrefix) {
                const prefix = button.dataset.mdPrefix;
                const lines = (selected || '').split('\n');
                replacement = lines.map(function (line) { return prefix + line; }).join('\n');
                caret = start + replacement.length;
            } else {
                const wrap = button.dataset.mdWrap;
                if (wrap === '[]') {
                    replacement = '[' + (selected || 'link text') + '](https://)';
                } else {
                    replacement = wrap + (selected || '') + wrap;
                }
                caret = selected ? start + replacement.length : start + replacement.length - wrap.length;
            }

            textarea.value = value.slice(0, start) + replacement + value.slice(end);
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.focus();
            textarea.setSelectionRange(caret, caret);
        });
    })();
</script>
@endscript
</div>
