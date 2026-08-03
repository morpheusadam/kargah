<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Contracts\AttachmentService;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\Checklist;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Services\CardService;
use Modules\Project\Services\Watching;
use Modules\Project\Support\Palette;
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
 * the DOM already had. The description and the start date are the two fields
 * deliberately left out: neither is drawn on the card face today, so
 * redrawing the canvas for either would send every card back for nothing.
 *
 * **Labels come from the card's own board.** A label belongs to one board, so
 * the picker is `$card->list->board->labels` and never a global list — putting
 * another board's label on a card would attach a row the board can never show.
 *
 * **A copy and a mirror are different things, and the rail says so.** A copy is
 * a new card that stops resembling this one the moment either is edited. A
 * mirror is *this* card, shown in another list; editing it anywhere edits it
 * everywhere. The card lives in exactly one of the lists it appears in — its
 * origin — and every action that changes where it lives goes through that
 * placement, never through a mirror.
 */
new
class extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    /** Extensions `uploadAttachment()` accepts. Mirrors what `⚡files.blade.php` already lists icons for. */
    private const ALLOWED_ATTACHMENT_TYPES = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'md', 'zip',
    ];

    /** Extensions a cover picker offers — the ones a browser can actually paint as an `<img>`. */
    private const COVER_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public bool $open = false;

    public ?int $cardId = null;

    #[Validate('required|min:3|max:120')]
    public string $title = '';

    public string $description = '';

    public string $startDate = '';

    public string $dueDate = '';

    /** The list `moveCard()` will move to, as a string id. */
    public string $moveToList = '';

    public string $newChecklistItem = '';

    public string $newComment = '';

    public bool $editingTitle = false;

    public bool $editingDescription = false;

    public bool $labelPopoverOpen = false;

    public bool $memberPopoverOpen = false;

    public bool $startPopoverOpen = false;

    public bool $duePopoverOpen = false;

    public bool $movePopoverOpen = false;

    public bool $mirrorPopoverOpen = false;

    public bool $coverPopoverOpen = false;

    /** The board the mirror picker is showing lists from, as a string id. */
    public string $mirrorBoard = '';

    /** The list the mirror will be added to, as a string id. */
    public string $mirrorList = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

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
                'placements.list.board',
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

    /**
     * Every list this card appears in, origin first.
     *
     * @return Collection<int, CardPlacement>
     */
    private function placements(): Collection
    {
        $card = $this->card();

        return $card === null
            ? collect()
            : $card->placements->sortByDesc('is_origin')->values();
    }

    /**
     * The lists a mirror could be added to.
     *
     * Everything on the chosen board that is still on it, less the lists this
     * card is already in — a card sits in a list once or not at all, and
     * offering an option that can only fail is not a picker.
     *
     * @return Collection<int, BoardList>
     */
    private function mirrorTargets(): Collection
    {
        if ($this->mirrorBoard === '') {
            return collect();
        }

        $taken = $this->placements()->pluck('board_list_id')->all();

        return BoardList::query()
            ->where('board_id', (int) $this->mirrorBoard)
            ->active()
            ->whereNotIn('id', $taken)
            ->orderBy('position')
            ->get();
    }

    /**
     * Everything attached to the open card, through the contract — never
     * `Modules\Data\Models\Attachment`. Icon and tone are added on top of the
     * plain array the service hands back, for the list this file draws.
     *
     * @return Collection<int, array>
     */
    private function attachments(): Collection
    {
        $card = $this->card();

        if ($card === null) {
            return collect();
        }

        return app(AttachmentService::class)->forTarget($card)->map(function (array $attachment): array {
            [$icon, $tone] = $this->attachmentIcon($attachment['extension']);

            return $attachment + ['icon' => $icon, 'tone' => $tone];
        });
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
            'cardMemberIds' => $card?->members->pluck('id')->all() ?? [],
            'lists' => $this->listsOnThisBoard(),
            'placements' => $this->placements(),
            'mirrorBoards' => Board::query()->active()->orderBy('name')->get(),
            'mirrorLists' => $this->mirrorTargets(),
            'checklist' => $items,
            'comments' => $card?->comments ?? collect(),
            'checklistDone' => $done,
            'checklistTotal' => $total,
            'checklistPercent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'attachments' => $this->attachments(),
            'attachmentImageTypes' => self::COVER_IMAGE_TYPES,
            'cover' => $card?->coverPresentation(),
            // The ten label colours, not every palette key. `keys()` also holds
            // the application's semantic tokens — success, destructive, info —
            // several of which share a display name with a label colour, so a
            // picker built from it offers sixteen swatches with visible
            // duplicates. A cover is a colour choice, not a semantic one.
            'coverColours' => Palette::labelColours(),
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
        $this->startDate = $card->start_on?->toDateString() ?? '';
        $this->dueDate = $card->due_on?->toDateString() ?? '';

        // Where the card *lives*, not wherever it happens to be shown. Moving
        // it moves the origin; a mirror is moved from the board it sits on.
        $this->moveToList = (string) ($card->originPlacement?->board_list_id ?? '');

        $this->editingTitle = false;
        $this->editingDescription = false;
        $this->labelPopoverOpen = false;
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
        $this->coverPopoverOpen = false;
        $this->mirrorBoard = (string) ($card->list?->board_id ?? '');
        $this->mirrorList = '';
        $this->newComment = '';
        $this->newChecklistItem = '';
        $this->uploads = [];

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
    }

    public function close(): void
    {
        $this->open = false;
        $this->labelPopoverOpen = false;
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
        $this->coverPopoverOpen = false;
    }

    /* Title and description ---------------------------------------------- */

    public function editTitle(): void
    {
        $this->editingTitle = true;
    }

    public function cancelTitle(): void
    {
        $this->editingTitle = false;
        $this->title = $this->card()?->title ?? '';
        $this->resetValidation('title');
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
    }

    public function cancelDescription(): void
    {
        $this->editingDescription = false;
        $this->description = (string) ($this->card()?->description ?? '');
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

    /* Labels, dates, members ----------------------------------------------- */

    public function toggleLabelPopover(): void
    {
        $this->labelPopoverOpen = ! $this->labelPopoverOpen;
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
        $this->coverPopoverOpen = false;
    }

    /** Opening a picker is not worth announcing. */
    public function toggleMemberPopover(): void
    {
        $this->memberPopoverOpen = ! $this->memberPopoverOpen;
        $this->labelPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
        $this->coverPopoverOpen = false;
    }

    /**
     * Add or remove one of this card's board's people.
     *
     * The pivot was always many-to-many — `card_members` carries
     * `unique(card_id, user_id)` — so toggling one person on or off the card
     * is the whole feature. The single-assignee `<select>` this replaced
     * called `sync([$id])` on every change, which is what made it look like
     * one assignee was all the schema allowed.
     */
    public function toggleMember(int $userId): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            $this->toastError('That person could not be found', 'Reload the page and try again.');

            return;
        }

        $wasOn = $card->members->contains('id', $user->id);

        $wasOn
            ? $card->members()->detach($user->id)
            : $card->members()->attach($user->id);

        if (! $wasOn) {
            // Being added to a card always notifies, regardless of watch state
            // — that is Trello's rule and it is the right one. It has to be
            // called by hand rather than by an observer, because
            // `BelongsToMany::attach()` fires no Eloquent model events, so
            // there is no hook on `card_members` the way there is on comments.
            app(Watching::class)->notifyMemberAdded($card, $user->id, auth()->id());
        }

        $this->cardChanged();

        $this->toastSuccess(
            $wasOn ? $user->name.' taken off the card' : $user->name.' added to the card',
            $wasOn
                ? 'They no longer carry '.$card->title.'.'
                : 'They are now carrying '.$card->title.', alongside anyone else already on it.',
        );
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

    public function toggleStartPopover(): void
    {
        $this->startPopoverOpen = ! $this->startPopoverOpen;
        $this->labelPopoverOpen = false;
        $this->memberPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
        $this->coverPopoverOpen = false;
    }

    /**
     * Set the start date.
     *
     * A start after the due date is not silently ignored: `saveDueDate()`
     * carries the same check the other way, so whichever end the card was
     * edited from, the person doing it is told in words rather than watching
     * the write appear to do nothing.
     */
    public function saveStartDate(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $typed = trim($this->startDate);

        if ($typed === '') {
            $this->toastError('No date was picked', 'Choose a day, or use remove to clear the one already set.');

            return;
        }

        try {
            $start = Carbon::parse($typed)->startOfDay();
        } catch (\Throwable) {
            $this->toastError('That date could not be read', 'Use the picker rather than typing the day in.');

            return;
        }

        if ($card->due_on !== null && $start->gt($card->due_on)) {
            $this->toastError(
                'The start date is after the due date',
                'Move the due date out first, or pick an earlier start.',
            );

            return;
        }

        $card->update(['start_on' => $start->toDateString()]);

        $this->startDate = $start->toDateString();
        $this->startPopoverOpen = false;

        $this->toastSuccess('Start date set', $card->title.' starts on '.$start->format('j M Y').'.');
    }

    public function clearStartDate(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $card->update(['start_on' => null]);

        $this->startDate = '';
        $this->startPopoverOpen = false;

        $this->toastSuccess('Start date removed', $card->title.' no longer has a start date.');
    }

    public function toggleDuePopover(): void
    {
        $this->duePopoverOpen = ! $this->duePopoverOpen;
        $this->labelPopoverOpen = false;
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
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

        if ($card->start_on !== null && $due->lt($card->start_on)) {
            $this->toastError(
                'The due date is before the start date',
                'Move the start date back first, or pick a later due date.',
            );

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
     * Tick the due date complete, or undo that.
     *
     * `Card::isComplete()` is the one thing anything else should ask —
     * Butler's due-date automation, once it exists, suppresses itself by
     * calling the same method rather than reading `completed_at` for itself.
     */
    public function toggleCardComplete(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $card->update(['completed_at' => $card->isComplete() ? null : now()]);

        $this->cardChanged();

        $this->toastSuccess(
            $card->isComplete() ? 'Card marked complete' : 'Card marked incomplete',
            $card->isComplete()
                ? 'The due date shows green everywhere the card appears.'
                : 'The due date is back to its usual colour.',
        );
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
     * Icon and tone per extension. Whole class strings in a map, never
     * `text-{$tone}` — the Tailwind scanner reads this file as text and
     * cannot see a class assembled at run time. Kept local rather than
     * imported from `⚡files.blade.php`: that map is private to a component
     * this one must not reach into, and it is eleven lines to repeat.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function attachmentIcon(string $extension): array
    {
        return match ($extension) {
            'pdf' => ['ki-document', 'text-destructive'],
            'doc', 'docx' => ['ki-document', 'text-primary'],
            'csv', 'xlsx', 'xls' => ['ki-file-sheet', 'text-success'],
            'md', 'txt' => ['ki-notepad', 'text-secondary-foreground'],
            'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif' => ['ki-picture', 'text-info'],
            'zip' => ['ki-archive', 'text-warning'],
            default => ['ki-document', 'text-muted-foreground'],
        };
    }

    /**
     * Store every queued upload against the open card.
     *
     * Goes through `AttachmentService` exactly like `⚡files.blade.php`
     * does — this component never opens a file handle. Size and type are
     * both checked before anything is stored: `max:25600` keeps a shared
     * host's disk from a careless video, and `mimes:` keeps the card
     * attachments list from becoming a place to park an executable.
     */
    public function uploadAttachment(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        if ($this->uploads === []) {
            $this->toastWarning('Nothing to upload', 'Choose a file first.');

            return;
        }

        $this->validate([
            'uploads.*' => ['file', 'max:25600', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_TYPES)],
        ]);

        $service = app(AttachmentService::class);

        foreach ($this->uploads as $upload) {
            $service->attach($card, $upload, auth()->id());
        }

        $stored = count($this->uploads);
        $names = collect($this->uploads)->map(fn ($u) => $u->getClientOriginalName())->join(', ', ' and ');
        $this->uploads = [];

        $this->toastSuccess(
            'Stored '.$stored.' '.str('file')->plural($stored),
            $names.' — attached to '.$card->title.'.',
        );
    }

    /**
     * Remove one of this card's attachments.
     *
     * The bytes stay on disk — `AttachmentService::delete()` soft deletes the
     * row, the same as everywhere else it is called from. When the removed
     * file was the card's own cover, the cover is cleared in the same
     * request rather than left pointing at a row that is now gone: nothing
     * *breaks* either way, because `Card::coverPresentation()` treats a
     * missing attachment as "no cover" on its own — but doing it here as
     * well means the card's `cover_type` does not sit stale in the database
     * until somebody happens to open this drawer again.
     */
    public function deleteAttachment(int $attachmentId): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $service = app(AttachmentService::class);
        $attachment = $service->find($attachmentId);

        if ($attachment === null || $attachment['target_type'] !== $card->getMorphClass() || $attachment['target_id'] !== $card->id) {
            $this->toastError('That file is gone', 'It was already removed, or never belonged to this card.');

            return;
        }

        $service->delete($attachmentId);

        $wasCover = $card->cover_type === 'image' && $card->cover_attachment_id === $attachmentId;

        if ($wasCover) {
            $card->update(['cover_type' => null, 'cover_colour' => null, 'cover_attachment_id' => null, 'cover_size' => 'half']);
        }

        $this->forgetCard();

        if ($wasCover) {
            $this->cardChanged();
        }

        $this->toastSuccess(
            $attachment['name'].' removed',
            $wasCover
                ? 'It was the card cover, so the cover was cleared as well. The bytes are still on disk.'
                : 'It is off the card. The bytes are still on disk and can be restored from Files.',
        );
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
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
        $this->coverPopoverOpen = false;

        if ($this->movePopoverOpen) {
            $this->moveToList = (string) ($this->card()?->originPlacement?->board_list_id ?? '');
        }
    }

    /** Move the card — where it lives, not where it is mirrored — to another list. */
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

        $placement = $card->originPlacement;

        if ($placement === null) {
            $this->toastError('That card is not on a board', 'Its list was deleted, so there is nowhere to move it from.');

            return;
        }

        if ($target->id === $placement->board_list_id) {
            $this->movePopoverOpen = false;

            $this->toastSuccess('Nothing to move', $card->title.' is already in '.$target->name.'.');

            return;
        }

        $service = app(CardService::class);

        // A card sits in a list once or not at all. Moving it into a list it is
        // already mirrored onto is refused rather than merged — two placements
        // of one card in one column is not a thing the board can draw.
        if ($service->placementIn($card->id, $target) !== null) {
            $this->toastError(
                $card->title.' is already in '.$target->name,
                'It is mirrored there. Remove that mirror first, or move it somewhere else.',
            );

            return;
        }

        // The bottom of the target list, counted rather than guessed: the
        // service brackets the index by real positions on either side.
        $below = CardPlacement::query()->where('board_list_id', $target->id)->count();

        $service->move($placement, $target, $below);

        $this->movePopoverOpen = false;

        $this->cardChanged();

        $this->toastSuccess('Card moved', $card->title.' is at the bottom of '.$target->name.'.');
    }

    /* Mirrors ---------------------------------------------------------------- */

    /** Opening a picker is not worth announcing. */
    public function toggleMirrorPopover(): void
    {
        $this->mirrorPopoverOpen = ! $this->mirrorPopoverOpen;
        $this->labelPopoverOpen = false;
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->coverPopoverOpen = false;

        if ($this->mirrorPopoverOpen) {
            $this->mirrorBoard = (string) ($this->card()?->list?->board_id ?? '');
            $this->mirrorList = '';
        }
    }

    /** A different board means a different set of lists. */
    public function updatedMirrorBoard(): void
    {
        $this->mirrorList = '';
    }

    /* Cover ----------------------------------------------------------------- */

    public function toggleCoverPopover(): void
    {
        $this->coverPopoverOpen = ! $this->coverPopoverOpen;
        $this->labelPopoverOpen = false;
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
    }

    /**
     * Set the cover to a plain colour band.
     *
     * Needs no attachment — Trello ships the colour half of a cover without
     * the file layer, and so does this. Setting the same colour and size the
     * card already carries writes nothing: this drawer always reads the card
     * fresh, so a no-op write here would cost a query for a change nobody
     * made. Picking a colour changes the drawer in front of the person doing
     * it, so — unlike an upload or a delete — it does not toast.
     */
    public function setCoverColour(string $colour): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        if (! Palette::has($colour)) {
            $this->toastError('That colour does not exist', 'Pick one from the list.');

            return;
        }

        if ($card->cover_type === 'colour' && $card->cover_colour === $colour) {
            return;
        }

        $card->update(['cover_type' => 'colour', 'cover_colour' => $colour, 'cover_attachment_id' => null]);

        $this->cardChanged();
    }

    /**
     * Set the cover to a picture, taken from one of this card's own
     * attachments — never someone else's, which is what the ownership check
     * below is for.
     */
    public function setCoverImage(int $attachmentId): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $attachment = app(AttachmentService::class)->find($attachmentId);

        if ($attachment === null || $attachment['target_type'] !== $card->getMorphClass() || $attachment['target_id'] !== $card->id) {
            $this->toastError('That file is not on this card', 'Attach it first, then pick it as the cover.');

            return;
        }

        if ($card->cover_type === 'image' && $card->cover_attachment_id === $attachmentId) {
            return;
        }

        $card->update(['cover_type' => 'image', 'cover_attachment_id' => $attachmentId, 'cover_colour' => null]);

        $this->cardChanged();
    }

    /** Half shows the badges alongside the cover; full replaces them with it. */
    public function toggleCoverSize(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        if ($card->cover_type === null) {
            return;
        }

        $card->update(['cover_size' => $card->cover_size === 'full' ? 'half' : 'full']);

        $this->cardChanged();
    }

    public function removeCover(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        if ($card->cover_type === null) {
            return;
        }

        $card->update(['cover_type' => null, 'cover_colour' => null, 'cover_attachment_id' => null, 'cover_size' => 'half']);

        $this->cardChanged();
    }

    /**
     * Show this card in another list as well.
     *
     * Not a copy: it is the same card, and editing it from either place edits
     * the one row. Mirroring into a list it is already in writes nothing and
     * says so rather than claiming a success it did not have.
     */
    public function mirrorCard(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        if (trim($this->mirrorList) === '') {
            $this->toastError('No list picked', 'Choose the list the card should also appear in.');

            return;
        }

        $target = BoardList::query()->active()->find((int) $this->mirrorList);

        if ($target === null) {
            $this->toastError('That list is gone', 'It was archived or deleted while the drawer was open.');

            return;
        }

        $service = app(CardService::class);

        if ($service->placementIn($card->id, $target) !== null) {
            $this->mirrorPopoverOpen = false;

            $this->toastInfo('Already there', $card->title.' is already in '.$target->name.'.');

            return;
        }

        $service->mirror($card, $target, auth()->id());

        $this->mirrorPopoverOpen = false;
        $this->mirrorList = '';

        $this->cardChanged();

        $this->toastSuccess(
            'Mirrored onto '.$target->name,
            'It is the same card in both places — editing it anywhere edits it everywhere.',
        );
    }

    /** Stop showing the card in one of the lists it was mirrored onto. */
    public function removeMirror(int $placementId): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $placement = $card->placements->firstWhere('id', $placementId);

        if ($placement === null) {
            $this->toastError('That mirror is gone', 'It was removed while the drawer was open.');
            $this->forgetCard();

            return;
        }

        if (! app(CardService::class)->unmirror($placement)) {
            // The origin is where the card lives. Taking it away would leave the
            // card on no board at all, which is what archiving and deleting are
            // for — both of which say what they are doing.
            $this->toastError(
                'That is where the card lives',
                'Archive or delete the card instead of removing it from its own list.',
            );

            return;
        }

        $this->cardChanged();

        $this->toastSuccess(
            'Mirror removed',
            $card->title.' no longer appears in '.($placement->list?->name ?? 'that list').'.',
        );
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
                            <h2 class="text-lg font-semibold text-mono leading-snug">
                                @if ($card->number)
                                    <span class="text-muted-foreground font-normal">#{{ $card->number }}</span>
                                @endif
                                {{ $card->title }}
                            </h2>
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

                        {{-- Labels, dates, members --}}
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
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Start date</span>
                                <div class="relative">
                                    <button wire:click="toggleStartPopover" class="kt-btn kt-btn-outline kt-btn-sm gap-2"
                                            aria-expanded="{{ $startPopoverOpen ? 'true' : 'false' }}">
                                        <i class="ki-filled ki-calendar text-sm"></i>
                                        {{ $card->start_on ? $card->start_on->format('j M Y') : 'No start date' }}
                                    </button>

                                    <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[240px] p-4 flex flex-col gap-3 {{ $startPopoverOpen ? 'open' : '' }}">
                                        <label class="kt-form-label text-xs" for="card-start">Starts on</label>
                                        <input id="card-start" type="date" class="kt-input" wire:model="startDate">
                                        <div class="flex items-center gap-2">
                                            <button wire:click="saveStartDate" wire:loading.attr="disabled" wire:target="saveStartDate"
                                                    class="kt-btn kt-btn-sm kt-btn-primary">
                                                <span wire:loading.remove wire:target="saveStartDate">Save</span>
                                                <span wire:loading wire:target="saveStartDate"><i class="ki-filled ki-loading animate-spin"></i></span>
                                            </button>
                                            <button wire:click="clearStartDate" class="kt-btn kt-btn-sm kt-btn-ghost">Remove</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2">
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Due date</span>
                                <div class="flex items-center gap-2">
                                    <div class="relative">
                                        @php($dueColour = $card->dueBadgeColour())
                                        <button wire:click="toggleDuePopover"
                                                class="kt-btn kt-btn-sm gap-2 {{ $dueColour ? \Modules\Project\Support\Palette::chip($dueColour) : 'kt-btn-outline' }}"
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

                                    @if ($card->due_on)
                                        <button wire:click="toggleCardComplete" wire:loading.attr="disabled" wire:target="toggleCardComplete"
                                                class="kt-btn kt-btn-icon kt-btn-outline size-7 {{ $card->isComplete() ? 'text-success border-success/40' : '' }}"
                                                title="{{ $card->isComplete() ? 'Mark incomplete' : 'Mark complete' }}"
                                                aria-label="{{ $card->isComplete() ? 'Mark incomplete' : 'Mark complete' }}"
                                                aria-pressed="{{ $card->isComplete() ? 'true' : 'false' }}">
                                            <i class="ki-filled ki-check text-sm"></i>
                                        </button>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-col gap-2">
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Members</span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @forelse ($card->members as $member)
                                        <span class="size-7 rounded-full grid place-items-center text-[11px] font-semibold bg-primary/15 text-primary"
                                              title="{{ $member->name }}">
                                            {{ $member->initials() }}
                                        </span>
                                    @empty
                                        <span class="text-sm text-muted-foreground">Unassigned</span>
                                    @endforelse

                                    <div class="relative">
                                        <button wire:click="toggleMemberPopover" class="kt-btn kt-btn-icon kt-btn-outline size-7"
                                                title="Edit members" aria-label="Edit members"
                                                aria-expanded="{{ $memberPopoverOpen ? 'true' : 'false' }}">
                                            <i class="ki-filled ki-plus text-xs"></i>
                                        </button>

                                        <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[240px] {{ $memberPopoverOpen ? 'open' : '' }}">
                                            <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-border">
                                                <h4 class="text-sm font-semibold text-mono">Members</h4>
                                                <button wire:click="toggleMemberPopover" class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                        title="Close members" aria-label="Close members">
                                                    <i class="ki-filled ki-cross text-xs"></i>
                                                </button>
                                            </div>
                                            <div class="p-2 flex flex-col gap-1">
                                                @foreach ($members as $member)
                                                    <button wire:click="toggleMember({{ $member->id }})" wire:key="member-{{ $member->id }}"
                                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60">
                                                        <span class="size-6 rounded-full grid place-items-center text-[10px] font-semibold bg-primary/15 text-primary">
                                                            {{ $member->initials() }}
                                                        </span>
                                                        <span class="grow text-secondary-foreground">{{ $member->name }}</span>
                                                        @if (in_array($member->id, $cardMemberIds, true))
                                                            <i class="ki-filled ki-check text-sm text-primary"></i>
                                                        @endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{--
                                A cover is a colour band or a picture taken from one
                                of this card's own attachments, half or full height.
                                A full cover replaces the badges on the card front
                                with the picture — that rule is drawn on the board,
                                not here, but the size toggle below is what sets it.
                            --}}
                            <div class="flex flex-col gap-2">
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Cover</span>
                                <div class="relative">
                                    <button wire:click="toggleCoverPopover" class="kt-btn kt-btn-outline kt-btn-sm gap-2"
                                            aria-expanded="{{ $coverPopoverOpen ? 'true' : 'false' }}">
                                        @if ($cover && $cover['type'] === 'colour')
                                            <span class="size-3 rounded-sm {{ \Modules\Project\Support\Palette::dot($cover['colour']) }}"></span> {{ \Modules\Project\Support\Palette::name($cover['colour']) }}
                                        @elseif ($cover && $cover['type'] === 'image')
                                            <i class="ki-filled ki-picture text-sm"></i> Photo
                                        @else
                                            <i class="ki-filled ki-brush text-sm"></i> No cover
                                        @endif
                                    </button>

                                    <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[280px] p-4 flex flex-col gap-3 {{ $coverPopoverOpen ? 'open' : '' }}">
                                        <div>
                                            <h4 class="text-sm font-semibold text-mono">Cover</h4>
                                            <p class="text-[11px] text-muted-foreground mt-1">A full cover replaces the badges on the card front with the picture.</p>
                                        </div>

                                        <div>
                                            <span class="text-xs text-muted-foreground">Colour</span>
                                            <div class="flex flex-wrap gap-1.5 mt-1.5">
                                                @foreach ($coverColours as $colourKey)
                                                    <button wire:click="setCoverColour('{{ $colourKey }}')" wire:key="cover-colour-{{ $colourKey }}"
                                                            class="size-7 rounded-md {{ \Modules\Project\Support\Palette::dot($colourKey) }} {{ $cover && $cover['type'] === 'colour' && $cover['colour'] === $colourKey ? 'ring-2 ring-offset-1 ring-primary' : '' }}"
                                                            title="{{ \Modules\Project\Support\Palette::name($colourKey) }}"
                                                            aria-label="Set the cover to {{ \Modules\Project\Support\Palette::name($colourKey) }}"></button>
                                                @endforeach
                                            </div>
                                        </div>

                                        @php($coverImageChoices = $attachments->whereIn('extension', $attachmentImageTypes))
                                        @if ($coverImageChoices->isNotEmpty())
                                            <div>
                                                <span class="text-xs text-muted-foreground">From an attachment</span>
                                                <div class="flex flex-wrap gap-1.5 mt-1.5">
                                                    @foreach ($coverImageChoices as $image)
                                                        <button wire:click="setCoverImage({{ $image['id'] }})" wire:key="cover-pick-{{ $image['id'] }}"
                                                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 {{ $cover && $cover['type'] === 'image' && $card->cover_attachment_id === $image['id'] ? 'border-primary text-primary' : '' }}">
                                                            <i class="ki-filled ki-picture text-sm"></i> {{ \Illuminate\Support\Str::limit($image['name'], 16) }}
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @else
                                            <p class="text-[11px] text-muted-foreground">Attach an image below to use it as a cover.</p>
                                        @endif

                                        @if ($cover)
                                            <label class="flex items-center gap-2 text-sm text-secondary-foreground">
                                                <input type="checkbox" class="kt-checkbox" wire:click="toggleCoverSize" @checked($cover['size'] === 'full')>
                                                Full cover — hides the badges on the card front
                                            </label>
                                            <button wire:click="removeCover" class="kt-btn kt-btn-sm kt-btn-ghost self-start">Remove cover</button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{--
                            Where this card appears. One of these is where it
                            lives; the rest are mirrors of it, and only those
                            can be removed from here. Removing a mirror is a
                            display change — the card itself is untouched.
                        --}}
                        @if ($placements->isNotEmpty())
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-2">
                                    <i class="ki-filled ki-devices-2 text-sm text-muted-foreground"></i>
                                    <h3 class="text-sm font-semibold text-mono">Appears in</h3>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    @foreach ($placements as $placement)
                                        <span class="inline-flex items-center gap-2 rounded-md border border-border bg-muted/30 ps-2.5 pe-1 py-1"
                                              wire:key="placement-{{ $placement->id }}">
                                            <span class="text-xs text-secondary-foreground">
                                                {{ $placement->list?->name ?? 'A deleted list' }}
                                                <span class="text-muted-foreground">· {{ $placement->list?->board?->name ?? '—' }}</span>
                                            </span>

                                            @if ($placement->isOrigin())
                                                <span class="kt-badge kt-badge-sm kt-badge-outline">Lives here</span>
                                            @else
                                                <button wire:click="removeMirror({{ $placement->id }})"
                                                        wire:loading.attr="disabled" wire:target="removeMirror"
                                                        class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                        title="Remove this mirror" aria-label="Remove the mirror in {{ $placement->list?->name ?? 'that list' }}">
                                                    <i class="ki-filled ki-cross text-xs"></i>
                                                </button>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

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
                                        {{--
                                            The one place in this file that echoes unescaped: markdown
                                            rendered through `Support\Markdown`, which strips raw HTML
                                            and refuses unsafe link schemes before this ever runs. A
                                            description is user input; nothing else in this file uses
                                            `{!! !!}` and it should stay that way.
                                        --}}
                                        <div class="text-sm text-secondary-foreground leading-relaxed [&_p]:mb-2 last:[&_p]:mb-0">{!! \Modules\Project\Support\Markdown::toHtml($card->description) !!}</div>
                                    @else
                                        <span class="text-sm text-muted-foreground">Add a more detailed description…</span>
                                    @endif
                                </button>
                            @endif
                        </div>

                        {{--
                            Custom fields belong to the card's origin board — the
                            same rule the labels above already follow — so a
                            mirrored card shows the fields of the board it lives
                            on, never the one it is merely mirrored onto. The
                            component resolves that from the card id alone.
                        --}}
                        <livewire:project::card-custom-fields :card-id="$card->id" />

                        {{--
                            Watching a card gets you its comments, date changes,
                            moves and archiving. Watching the list or board it
                            sits on gets you the same for everything in them —
                            that part lives on those pages, not here.
                        --}}
                        <livewire:project::card-watch :card-id="$card->id" />

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
                            Attachments, through `Modules\Data\Contracts\AttachmentService`.
                            An image extension gets a "use as cover" button; the cover
                            itself is picked from here, alongside a plain colour, from
                            the picker up in the labels/dates row.
                        --}}
                        <div class="flex flex-col gap-3">
                            <div class="flex items-center gap-2">
                                <i class="ki-filled ki-paper-clip text-sm text-muted-foreground"></i>
                                <h3 class="text-sm font-semibold text-mono">Attachments</h3>
                            </div>

                            <label class="rounded-lg border border-dashed border-border bg-accent/60 px-4 py-5 flex flex-col items-center gap-2 text-center cursor-pointer">
                                <i class="ki-filled ki-cloud-add text-2xl text-muted-foreground"></i>
                                <span class="text-sm text-secondary-foreground">Choose files, up to 25 MB each</span>
                                <input type="file" multiple class="hidden" wire:model="uploads">
                            </label>

                            <div wire:loading wire:target="uploads" class="text-xs text-secondary-foreground">
                                <i class="ki-filled ki-loading animate-spin"></i> Receiving…
                            </div>

                            @error('uploads.*')<span class="text-xs text-destructive">{{ $message }}</span>@enderror

                            @if (count($uploads) > 0)
                                <div class="flex items-center gap-2">
                                    <button wire:click="uploadAttachment" wire:loading.attr="disabled" wire:target="uploadAttachment"
                                            class="kt-btn kt-btn-sm kt-btn-primary gap-1">
                                        <span wire:loading.remove wire:target="uploadAttachment">
                                            Store {{ count($uploads) }} {{ str('file')->plural(count($uploads)) }}
                                        </span>
                                        <span wire:loading wire:target="uploadAttachment"><i class="ki-filled ki-loading animate-spin"></i> Storing…</span>
                                    </button>
                                </div>
                            @endif

                            <div class="flex flex-col gap-1.5">
                                @forelse ($attachments as $file)
                                    <div class="flex items-center gap-2.5 rounded-md border border-border px-3 py-2" wire:key="attachment-{{ $file['id'] }}">
                                        <i class="ki-filled {{ $file['icon'] }} {{ $file['tone'] }} text-lg shrink-0"></i>
                                        <div class="min-w-0 grow">
                                            <a href="{{ $file['download_url'] }}" class="text-sm font-medium text-mono truncate hover:underline block">{{ $file['name'] }}</a>
                                            <span class="text-xs text-muted-foreground">{{ $file['size'] }}</span>
                                        </div>

                                        @if (in_array($file['extension'], $attachmentImageTypes, true))
                                            <button wire:click="setCoverImage({{ $file['id'] }})"
                                                    class="kt-btn kt-btn-icon kt-btn-ghost size-7 {{ $cover && $cover['type'] === 'image' && $card->cover_attachment_id === $file['id'] ? 'text-primary' : '' }}"
                                                    title="Use as cover" aria-label="Use {{ $file['name'] }} as the card cover">
                                                <i class="ki-filled ki-picture text-sm"></i>
                                            </button>
                                        @endif

                                        <button wire:click="deleteAttachment({{ $file['id'] }})" wire:confirm="Remove {{ $file['name'] }}?"
                                                wire:loading.attr="disabled" wire:target="deleteAttachment({{ $file['id'] }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Remove attachment" aria-label="Remove {{ $file['name'] }}">
                                            <i class="ki-filled ki-trash text-xs"></i>
                                        </button>
                                    </div>
                                @empty
                                    <p class="text-sm text-muted-foreground px-1 py-1.5">No files attached yet.</p>
                                @endforelse
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
                                        {{-- The same sanitising renderer as the description, for the same reason: a comment is user input too. --}}
                                        <div class="text-sm text-secondary-foreground mt-1 rounded-lg bg-muted/40 border border-border px-3 py-2 [&_p]:mb-2 last:[&_p]:mb-0">
                                            {!! \Modules\Project\Support\Markdown::toHtml($comment->body) !!}
                                        </div>
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

                        {{--
                            Mirroring, which is not copying. A copy is a new
                            card that stops resembling this one the moment
                            either is edited; a mirror is this card, shown
                            somewhere else. The two sit next to each other, so
                            the wording has to do the work of telling them
                            apart.
                        --}}
                        <div class="relative">
                            <button wire:click="toggleMirrorPopover" class="kt-btn kt-btn-outline justify-start gap-2 w-full"
                                    aria-expanded="{{ $mirrorPopoverOpen ? 'true' : 'false' }}">
                                <i class="ki-filled ki-devices-2 text-sm"></i> Mirror
                            </button>

                            <div class="kt-dropdown absolute z-20 mt-1 end-0 w-[280px] p-4 flex flex-col gap-3 {{ $mirrorPopoverOpen ? 'open' : '' }}">
                                <div>
                                    <h4 class="text-sm font-semibold text-mono">Mirror to…</h4>
                                    <p class="text-[11px] text-muted-foreground mt-1">
                                        The same card, shown in another list. Editing it anywhere edits it everywhere.
                                    </p>
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="kt-form-label text-xs" for="card-mirror-board">Board</label>
                                    <select id="card-mirror-board" class="kt-select" wire:model.live="mirrorBoard">
                                        @foreach ($mirrorBoards as $option)
                                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="kt-form-label text-xs" for="card-mirror-list">List</label>
                                    <select id="card-mirror-list" class="kt-select" wire:model="mirrorList">
                                        <option value="">Pick a list</option>
                                        @foreach ($mirrorLists as $option)
                                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                    @if ($mirrorLists->isEmpty())
                                        <span class="text-[11px] text-muted-foreground">
                                            This card is already in every list on that board.
                                        </span>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2">
                                    <button wire:click="mirrorCard" wire:loading.attr="disabled" wire:target="mirrorCard"
                                            class="kt-btn kt-btn-sm kt-btn-primary">
                                        <span wire:loading.remove wire:target="mirrorCard">Mirror</span>
                                        <span wire:loading wire:target="mirrorCard"><i class="ki-filled ki-loading animate-spin"></i> Mirroring…</span>
                                    </button>
                                    <button wire:click="toggleMirrorPopover" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
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
