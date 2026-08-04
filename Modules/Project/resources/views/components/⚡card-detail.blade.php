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
use Modules\Project\Butler\Butler;
use Modules\Project\Butler\Triggers;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\CardVote;
use Modules\Project\Models\Checklist;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Models\CommentReaction;
use Modules\Project\Services\CardService;
use Modules\Project\Services\Watching;
use Modules\Project\Support\Mentions;
use Modules\Project\Support\Palette;
use Modules\Project\Support\Position;
use Modules\Project\Support\Reactions;

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

    /** The list of who voted, which hangs off the tally rather than sitting in the row. */
    public bool $votersPopoverOpen = false;

    /** The comment whose emoji picker is open, or null when none is. */
    public ?int $reactionPickerFor = null;

    /**
     * Whether the `@` autocomplete is showing under the comment box.
     *
     * Server-driven, and deliberately so: the people list is a handful of rows
     * on a self-hosted install, so a JSON endpoint plus a client-side filter
     * would be more moving parts than the feature is worth. The cost is one
     * debounced round trip per keystroke *while an `@` is open* — the textarea
     * is `.live.debounce`, and `updatedNewComment()` closes the list the moment
     * the token stops looking like a mention.
     */
    public bool $mentionOpen = false;

    /** The checklist item whose assignee/date row is expanded, or null. */
    public ?int $itemEditing = null;

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
                // `.assignee` and not just `.items`: every item row draws the
                // avatar of whoever is carrying it, so without this a
                // twenty-line checklist is twenty queries the drawer did not
                // have to make.
                'checklists.items.assignee',
                'comments.author',
                // The grouped chips need every row, and the tooltip on each
                // chip needs the name behind it — one load for both, rather
                // than a count query per comment per emoji.
                'comments.reactions.user',
                'votes.user',
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

    /**
     * The reaction chips under every comment on the card, keyed by comment id.
     *
     * Grouped here rather than in the template because it is three steps —
     * group by emoji, count each group, work out whether the reader is in it —
     * and a template doing that inline would redo the whole thing for every
     * chip rather than once for every comment.
     *
     * The order is `Reactions`' own, not the order the rows happen to come
     * back in, so adding a fourth reaction to a comment does not rearrange the
     * three already sitting there.
     *
     * @return array<int, list<array{emoji: string, name: string, count: int, mine: bool, who: string}>>
     */
    private function reactionChips(): array
    {
        $card = $this->card();

        if ($card === null) {
            return [];
        }

        $userId = auth()->id();

        return $card->comments
            ->mapWithKeys(fn (CardComment $comment): array => [
                $comment->id => $comment->reactions
                    ->groupBy('emoji')
                    ->map(fn (Collection $group, string $emoji): array => [
                        'emoji' => $emoji,
                        'name' => Reactions::name($emoji),
                        'count' => $group->count(),
                        'mine' => $userId !== null && $group->contains('user_id', $userId),
                        'who' => $group
                            ->map(fn (CommentReaction $reaction): string => $reaction->user?->name ?? 'Someone no longer here')
                            ->join(', ', ' and '),
                    ])
                    ->sortBy(fn (array $chip): int => Reactions::order($chip['emoji']))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * The `@…` currently being typed at the end of the comment box, or null
     * when there is not one.
     *
     * Anchored to the end of the string on purpose. Livewire knows what is in
     * the textarea but not where the caret is, so "the token being typed" can
     * only mean "the token the text ends with" — which is where somebody typing
     * a mention actually is. Editing an `@` back in the middle of a written
     * paragraph simply does not open the list; the mention still resolves when
     * the comment is posted, because resolution never depended on this.
     */
    private function mentionPartial(): ?string
    {
        return preg_match('/(?:^|\s)@([A-Za-z0-9._-]*)$/u', $this->newComment, $matches) === 1
            ? $matches[1]
            : null;
    }

    /** Typing closes or opens the list; nothing else has to be tracked. */
    public function updatedNewComment(): void
    {
        $this->mentionOpen = $this->mentionPartial() !== null;
    }

    /**
     * Finish the half-typed mention with the person who was clicked.
     *
     * Replaces the trailing token rather than appending, so `@ni` + a click on
     * Nima leaves `@nima ` and not `@ni@nima `.
     */
    public function insertMention(int $userId): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            $this->toastError('That person could not be found', 'Reload the page and try again.');

            return;
        }

        $handle = Mentions::handleFor($user);

        $this->newComment = preg_match('/@[A-Za-z0-9._-]*$/u', $this->newComment) === 1
            ? (string) preg_replace('/@[A-Za-z0-9._-]*$/u', '@'.$handle.' ', $this->newComment)
            : rtrim($this->newComment).' @'.$handle.' ';

        $this->mentionOpen = false;
    }

    public function with(): array
    {
        $card = $this->card();
        $items = $this->items();
        $done = $items->where('is_done', true)->count();
        $total = $items->count();
        $partial = $this->mentionPartial();

        return [
            'card' => $card,
            'mentionSuggestions' => $this->mentionOpen && $partial !== null
                ? Mentions::suggest($partial)
                : collect(),
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
            'voteCount' => $card?->votes->count() ?? 0,
            'hasVoted' => $card?->hasVoteFrom(auth()->id()) ?? false,
            // Names, not users: the popover lists who voted and nothing else,
            // and a vote whose user row is gone still counts.
            'voters' => $card?->votes->map(fn (CardVote $vote): array => [
                'id' => $vote->id,
                'name' => $vote->user?->name ?? 'Someone no longer here',
            ]) ?? collect(),
            'reactionSet' => Reactions::SET,
            'reactionChips' => $this->reactionChips(),
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
        $this->closePopovers();
        $this->mentionOpen = false;
        $this->itemEditing = null;
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

        // The drawer is `aria-modal`, so the focus has to follow it in. Without
        // this the focus stays on the board underneath, which means `Escape`
        // never reaches the panel and a keyboard user is still tabbing through
        // the page the drawer is covering. Handled by the script at the foot of
        // this file.
        $this->dispatch('card-drawer-opened');
    }

    /**
     * Something outside this drawer changed the card that is open in it.
     *
     * Today that is a Butler card button, which runs an action chain and then
     * announces it — the board canvas has listened for this event since
     * mirroring shipped, and the drawer showing the very card that just moved
     * or gained a label had no reason to be the last to know.
     *
     * Nothing happens when the drawer is shut, or when the drawer's own edits
     * are what raised it: `forgetCard()` only drops the memo, so the next read
     * goes back to the database.
     */
    #[On('card-changed')]
    public function cardChangedElsewhere(): void
    {
        if (! $this->open || $this->cardId === null) {
            return;
        }

        $this->forgetCard();

        $card = $this->card();

        if ($card !== null) {
            $this->hydrateFrom($card);
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->closePopovers();
        $this->mentionOpen = false;
        $this->itemEditing = null;

        // So the browser can put the focus back where it was before the drawer
        // took it, rather than dropping it on `<body>` — see `openCard()`.
        $this->dispatch('card-drawer-closed');
    }

    /**
     * Shut every popover in the drawer.
     *
     * There are nine and exactly one may be open at a time: they overlap each
     * other in the two rows they sit in, so a second one opening on top of the
     * first is two panels fighting over the same few hundred pixels. The emoji
     * picker used to sit outside this rule — it closed nothing and nothing
     * closed it — which is how a reaction picker ended up open behind the
     * cover picker with no way to tell which click belonged to which.
     */
    private function closePopovers(): void
    {
        $this->labelPopoverOpen = false;
        $this->memberPopoverOpen = false;
        $this->startPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->movePopoverOpen = false;
        $this->mirrorPopoverOpen = false;
        $this->coverPopoverOpen = false;
        $this->votersPopoverOpen = false;
        $this->reactionPickerFor = null;
    }

    private function aPopoverIsOpen(): bool
    {
        return $this->labelPopoverOpen
            || $this->memberPopoverOpen
            || $this->startPopoverOpen
            || $this->duePopoverOpen
            || $this->movePopoverOpen
            || $this->mirrorPopoverOpen
            || $this->coverPopoverOpen
            || $this->votersPopoverOpen
            || $this->reactionPickerFor !== null;
    }

    /**
     * Escape, one layer at a time.
     *
     * The panel used to close on any Escape at all, so dismissing a date picker
     * also threw away the card being read — and Escape typed into the title box
     * or the description cancelled the edit *and* shut the drawer, because both
     * handlers fire as the event bubbles. Escape now takes the top thing off:
     * the mention list, then whatever popover is open, then the checklist item
     * editor, and only when there is nothing left the drawer itself.
     */
    public function escape(): void
    {
        if ($this->mentionOpen) {
            $this->mentionOpen = false;

            return;
        }

        if ($this->aPopoverIsOpen()) {
            $this->closePopovers();

            return;
        }

        if ($this->itemEditing !== null) {
            $this->itemEditing = null;

            return;
        }

        $this->close();
    }

    /** Dismiss the `@` autocomplete, leaving what has been typed alone. */
    public function closeMentions(): void
    {
        $this->mentionOpen = false;
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
        $open = ! $this->labelPopoverOpen;
        $this->closePopovers();
        $this->labelPopoverOpen = $open;
    }

    /** Opening a picker is not worth announcing. */
    public function toggleMemberPopover(): void
    {
        $open = ! $this->memberPopoverOpen;
        $this->closePopovers();
        $this->memberPopoverOpen = $open;
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

        // Butler, for the same reason the notification above is by hand:
        // `card_members` is a pivot and `attach()`/`detach()` raise no Eloquent
        // events, so a rule watching for a member change would only ever see
        // the ones Butler itself made.
        app(Butler::class)->fire(
            $wasOn ? Triggers::CARD_MEMBER_REMOVED : Triggers::CARD_MEMBER_ADDED,
            $card,
            ['user_id' => $user->id],
        );

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

        // Same reasoning as the member toggle above: a pivot raises no model
        // events, so Butler has to be told by hand or it never hears this.
        app(Butler::class)->fire(
            $wasOn ? Triggers::CARD_LABEL_REMOVED : Triggers::CARD_LABEL_ADDED,
            $card,
            ['label_id' => $label->id],
        );

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
        $open = ! $this->startPopoverOpen;
        $this->closePopovers();
        $this->startPopoverOpen = $open;
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

    /**
     * `coverPopoverOpen` was once missing from this one toggle's list while
     * every other toggle cleared it, and the emoji picker was missing from all
     * nine. Both are why the closing is `closePopovers()` now rather than a
     * hand-written list per method that has to be kept in step by hand.
     */
    public function toggleDuePopover(): void
    {
        $open = ! $this->duePopoverOpen;
        $this->closePopovers();
        $this->duePopoverOpen = $open;
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

    /* Advanced checklist items ---------------------------------------------
     *
     * An assignee and a due date per *item*, not just per card. Both are
     * columns on `checklist_items`, both are optional, and both survive the
     * item being converted into a card of its own — which is the whole reason
     * Trello calls this "advanced" rather than "another checkbox".
     */

    /** Expand or fold the assignee/date row under one item. */
    public function toggleItemEditor(int $itemId): void
    {
        $this->itemEditing = $this->itemEditing === $itemId ? null : $itemId;
    }

    /**
     * Put a person on one line of the checklist, or take them off it with an
     * empty value.
     *
     * Deliberately not restricted to the card's own members: a checklist item
     * is often the one piece of a card somebody else is carrying, and making
     * them join the card first would be a step with no purpose.
     */
    public function assignItem(int $itemId, string $userId): void
    {
        $item = $this->itemOnThisCard($itemId);

        if ($item === null) {
            $this->toastError('That item is gone', 'It was deleted while the drawer was open.');
            $this->forgetCard();

            return;
        }

        $user = trim($userId) === '' ? null : User::query()->find((int) $userId);

        if (trim($userId) !== '' && $user === null) {
            $this->toastError('That person could not be found', 'Reload the page and try again.');

            return;
        }

        $item->update(['assigned_to' => $user?->id]);

        // The item row redraws with the new avatar in front of the person who
        // clicked, so there is nothing a toast would add. The card face does
        // not show item assignees, so no `card-changed` either.
        $this->forgetCard();
    }

    /**
     * Set one item's own due date.
     *
     * A date, never an instant — `checklist_items.due_on` is a `date` column
     * for the same reason `cards.due_on` is, so an item due on 31 July is due
     * on 31 July wherever it is read.
     */
    public function setItemDue(int $itemId, string $date): void
    {
        $item = $this->itemOnThisCard($itemId);

        if ($item === null) {
            $this->toastError('That item is gone', 'It was deleted while the drawer was open.');
            $this->forgetCard();

            return;
        }

        $typed = trim($date);

        if ($typed === '') {
            $this->clearItemDue($itemId);

            return;
        }

        try {
            $due = Carbon::parse($typed)->startOfDay();
        } catch (\Throwable) {
            $this->toastError('That date could not be read', 'Use the picker rather than typing the day in.');

            return;
        }

        $item->update(['due_on' => $due->toDateString()]);

        $this->forgetCard();

        // Worth saying: an item date shows on the calendar and on the ICS feed,
        // neither of which is on screen here.
        $this->toastSuccess(
            'Item due '.$due->format('j M Y'),
            $item->text.' now appears on the board calendar and its subscription feed.',
        );
    }

    public function clearItemDue(int $itemId): void
    {
        $item = $this->itemOnThisCard($itemId);

        if ($item === null) {
            $this->toastError('That item is gone', 'It was deleted while the drawer was open.');
            $this->forgetCard();

            return;
        }

        if ($item->due_on === null) {
            return;
        }

        $item->update(['due_on' => null]);

        $this->forgetCard();

        $this->toastSuccess('Item date removed', $item->text.' is off the calendar.');
    }

    /**
     * Turn a checklist item into a card of its own, in the same list.
     *
     * **The assignee and the due date come across.** That is the sentence in
     * 06 that makes this method worth having rather than "type it again as a
     * card": an item that somebody is carrying, due on a day, becomes a card
     * that the same somebody is carrying, due on the same day. Losing either
     * on the way over would make the convert button something people learn not
     * to trust.
     *
     * The item is removed once the card exists, the way Trello's own convert
     * behaves — leaving both would give the board two live copies of one piece
     * of work, and the checklist tally would go on counting something that has
     * become a card in its own right.
     */
    public function convertItemToCard(int $itemId): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $item = $this->itemOnThisCard($itemId);

        if ($item === null) {
            $this->toastError('That item is gone', 'It was deleted while the drawer was open.');
            $this->forgetCard();

            return;
        }

        $list = $card->list;

        if ($list === null) {
            $this->toastError('That card has no list', 'There is nowhere to put the new card. Reload the page and try again.');

            return;
        }

        // `cards.title` is 255 and `checklist_items.text` is 255, so the two
        // fit — but the trim is kept explicit rather than assumed from the
        // column widths agreeing today.
        $new = app(CardService::class)->append($list, mb_substr($item->text, 0, 255), [
            'due_on' => $item->due_on?->toDateString(),
        ]);

        if ($item->assigned_to !== null) {
            $new->members()->attach($item->assigned_to);

            // Being added to a card always notifies, watch state or not — the
            // same rule and the same call `toggleMember()` makes, because a
            // pivot attach fires no model event for an observer to hear.
            app(Watching::class)->notifyMemberAdded($new, $item->assigned_to, auth()->id());
        }

        $text = $item->text;

        $item->delete();

        $this->itemEditing = null;

        $this->cardChanged();

        $this->toastSuccess(
            'Converted to a card',
            $text.' is at the bottom of '.$list->name.', carrying its assignee and its due date. It is off the checklist.',
        );
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

        // Who was named, worked out before the box is emptied. The notifying
        // itself is `CardCommentObserver`'s job — this is only so the toast can
        // say it happened, which is otherwise invisible to the person posting.
        $named = Mentions::resolve($body)->reject(fn (User $u): bool => $u->id === auth()->id());

        $this->newComment = '';
        $this->mentionOpen = false;

        $this->cardChanged();

        $this->toastSuccess(
            'Comment posted',
            $named->isEmpty()
                ? 'It is at the bottom of the thread on '.$card->title.'.'
                : $named->pluck('name')->join(', ', ' and ').' '.($named->count() === 1 ? 'was' : 'were').' notified.',
        );
    }

    /* Votes and reactions -------------------------------------------------- */

    /** Opening a list of names is not worth announcing. */
    public function toggleVotersPopover(): void
    {
        $open = ! $this->votersPopoverOpen;
        $this->closePopovers();
        $this->votersPopoverOpen = $open;
    }

    /**
     * Cast your vote, or take it back.
     *
     * No toast: the button carries the tally and its own pressed state, so a
     * toast would report exactly what the person is looking at. No activity
     * entry and no notification either — a vote is the lightest signal on the
     * board, and one feed line per vote would bury the changes that matter.
     * `card_votes` carries `unique(card_id, user_id)`, so a double-click that
     * arrives as two requests is a vote and an un-vote, never two rows.
     *
     * `cardChanged()` rather than `forgetCard()`, because the vote chip is
     * drawn on the front of the card as well as here.
     */
    public function toggleVote(): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $userId = auth()->id();

        if ($userId === null) {
            return;
        }

        $vote = CardVote::query()
            ->where('card_id', $card->id)
            ->where('user_id', $userId)
            ->first();

        $vote !== null
            ? $vote->delete()
            : CardVote::query()->create(['card_id' => $card->id, 'user_id' => $userId]);

        $this->votersPopoverOpen = false;

        $this->cardChanged();
    }

    /**
     * The ninth popover, and for a while the only one outside the rule: it
     * closed nothing when it opened and nothing closed it, so an emoji picker
     * left open on a comment stayed open behind the cover or label picker the
     * next click opened.
     */
    public function toggleReactionPicker(int $commentId): void
    {
        $next = $this->reactionPickerFor === $commentId ? null : $commentId;
        $this->closePopovers();
        $this->reactionPickerFor = $next;
    }

    /**
     * Put one of the eight emoji on a comment, or take yours back off it.
     *
     * Both halves of the toggle land here: clicking a chip that already exists
     * and picking from the picker are the same call with the same arguments,
     * which is what makes "clicking a chip removes your own reaction from it"
     * true without a second method that could drift from this one.
     *
     * The comment is looked up on the open card rather than by id alone. The
     * id arrives from the browser, and nothing else in this method would stop
     * it naming a comment on somebody else's card.
     *
     * Like a vote: no toast, no activity entry, no notification. The chip
     * appears under the comment the moment it is written.
     */
    public function toggleReaction(int $commentId, string $emoji): void
    {
        $card = $this->card();

        if ($card === null) {
            $this->reportMissingCard();

            return;
        }

        $userId = auth()->id();

        if ($userId === null) {
            return;
        }

        $comment = $card->comments->firstWhere('id', $commentId);

        if ($comment === null) {
            $this->toastError('That comment is gone', 'It was deleted while the drawer was open.');
            $this->forgetCard();

            return;
        }

        if (! Reactions::has($emoji)) {
            $this->toastError('That is not one of the reactions', 'Pick one from the picker.');

            return;
        }

        $reaction = CommentReaction::query()
            ->where('card_comment_id', $comment->id)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        $reaction !== null
            ? $reaction->delete()
            : CommentReaction::query()->create([
                'card_comment_id' => $comment->id,
                'user_id' => $userId,
                'emoji' => $emoji,
            ]);

        // The picker sits where the new chip is about to appear, so it closes
        // rather than covering the thing it just added.
        $this->reactionPickerFor = null;

        // No `card-changed`: reactions are not drawn on the front of the card,
        // so redrawing the whole canvas for one would send every card back for
        // nothing. The comment *count* on the card face has not moved.
        $this->forgetCard();
    }

    /* Right rail actions --------------------------------------------------- */

    public function toggleMovePopover(): void
    {
        $open = ! $this->movePopoverOpen;
        $this->closePopovers();
        $this->movePopoverOpen = $open;

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
        $open = ! $this->mirrorPopoverOpen;
        $this->closePopovers();
        $this->mirrorPopoverOpen = $open;

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
        $open = ! $this->coverPopoverOpen;
        $this->closePopovers();
        $this->coverPopoverOpen = $open;
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
                    // The advanced pair travels with the item, the same as it
                    // does through a conversion: a copied checklist that lost
                    // who was carrying each line would be a worse copy than no
                    // copy at all.
                    'assigned_to' => $item->assigned_to,
                    'due_on' => $item->due_on?->toDateString(),
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

{{--
    `inert` while shut, not only `pointer-events-none`. The panel stays in the
    DOM when it closes — it is slid off screen, not removed — and without
    `inert` every button in it is still in the tab order, so a keyboard user
    tabs into a dialog the screen reader has just been told is not there.
    `inert` takes the whole subtree out of focus and hit testing at once.
--}}
<div class="fixed inset-0 z-50 overflow-hidden {{ $open ? '' : 'pointer-events-none' }}"
     @if (! $open) inert @endif
     aria-hidden="{{ $open ? 'false' : 'true' }}">

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/40 transition-opacity duration-200 {{ $open ? 'opacity-100' : 'opacity-0' }}"
         wire:click="close"></div>

    {{-- Slide-over --}}
    <aside class="absolute inset-y-0 end-0 w-full max-w-[760px] bg-background border-s border-border shadow-lg
                  flex flex-col transition-transform duration-200 ease-out {{ $open ? 'translate-x-0' : 'translate-x-full' }}"
           id="card-drawer-panel"
           role="dialog" aria-modal="true" aria-label="Card detail" tabindex="-1"
           wire:keydown.escape="escape">

        @if ($card)
            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-border">
                <div class="min-w-0 grow">
                    @if ($editingTitle)
                        <div class="flex flex-col gap-2">
                            <input type="text" class="kt-input @error('title') border-destructive @enderror"
                                   aria-label="Card title" wire:model="title"
                                   {{-- `.stop`, or the same Escape bubbles to the panel and shuts the drawer as well as cancelling the rename. --}}
                                   wire:keydown.escape.stop="cancelTitle" wire:keydown.enter.prevent="saveTitle" autofocus>
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
                                            {{-- Capped and scrolled: a board's label list has no ceiling, and a popover taller than the drawer cannot be reached at the bottom. --}}
                                            <div class="p-2 flex flex-col gap-1 max-h-[250px] overflow-y-auto">
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

                                    {{--
    🔴 `flex flex-col gap-3` belongs **inside** the conditional, and every panel
    on this page that uses `.kt-dropdown` has to do the same.

    The theme hides a closed panel with `.kt-dropdown:not(.open) { display: none }`,
    which lives in the components layer. Tailwind's `.flex` lives in the utilities
    layer, and **a cascade layer beats specificity outright** — so a panel carrying
    both was `display: flex` whether or not it was open, from the moment the card
    drawer first rendered. All five popovers on this card back were permanently
    visible, stacked on top of each other and on top of the description.

    Written out whole rather than built from parts because Tailwind's scanner reads
    source text; `'open ' . $layout` would generate nothing. See
    docs/frontend-conventions.md — "pick one mechanism per panel, never both" was
    already the rule, and this is the shape the violation takes when the second
    mechanism is a layer rather than a script.
--}}
<div class="kt-dropdown absolute z-20 mt-1 start-0 w-[240px] p-4 {{ $startPopoverOpen ? 'open flex flex-col gap-3' : '' }}">
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

                                        <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[240px] p-4 {{ $duePopoverOpen ? 'open flex flex-col gap-3' : '' }}">
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
                                            <div class="p-2 flex flex-col gap-1 max-h-[250px] overflow-y-auto">
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
                                A vote is the lightest thing anybody can do to a
                                card: no activity entry, no notification, no toast.
                                The tally rides on the button, because that is the
                                thing the click changes; who cast the votes is one
                                click further in, because a row of names here would
                                push the cover picker off the line.
                            --}}
                            <div class="flex flex-col gap-2">
                                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Votes</span>
                                <div class="flex items-center gap-2">
                                    <button wire:click="toggleVote" wire:loading.attr="disabled" wire:target="toggleVote"
                                            class="kt-btn kt-btn-sm gap-2 {{ $hasVoted ? 'kt-btn-primary' : 'kt-btn-outline' }}"
                                            aria-pressed="{{ $hasVoted ? 'true' : 'false' }}"
                                            title="{{ $hasVoted ? 'Take your vote back' : 'Vote for this card' }}">
                                        <i class="ki-filled ki-like text-sm"></i>
                                        {{ $voteCount }}
                                    </button>

                                    @if ($voteCount > 0)
                                        <div class="relative">
                                            <button wire:click="toggleVotersPopover" class="kt-btn kt-btn-icon kt-btn-outline size-7"
                                                    title="Who voted" aria-label="Who voted"
                                                    aria-expanded="{{ $votersPopoverOpen ? 'true' : 'false' }}">
                                                <i class="ki-filled ki-people text-xs"></i>
                                            </button>

                                            <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[220px] {{ $votersPopoverOpen ? 'open' : '' }}">
                                                <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-border">
                                                    <h4 class="text-sm font-semibold text-mono">
                                                        {{ $voteCount }} {{ str('vote')->plural($voteCount) }}
                                                    </h4>
                                                    <button wire:click="toggleVotersPopover" class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                            title="Close the voter list" aria-label="Close the voter list">
                                                        <i class="ki-filled ki-cross text-xs"></i>
                                                    </button>
                                                </div>
                                                {{-- Forty voters is forty rows: capped, or the list runs off the bottom of the drawer with no way to reach the end of it. --}}
                                                <div class="p-2 flex flex-col gap-1 max-h-[250px] overflow-y-auto">
                                                    @foreach ($voters as $voter)
                                                        <span class="px-2 py-1.5 text-sm text-secondary-foreground break-words" wire:key="voter-{{ $voter['id'] }}">
                                                            {{ $voter['name'] }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    @endif
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

                                    <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[280px] p-4 {{ $coverPopoverOpen ? 'open flex flex-col gap-3' : '' }}">
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
                                    {{-- `.stop` for the same reason the title box has it: Escape here cancelled the edit and closed the drawer behind it. --}}
                                    <textarea id="card-description" rows="7" class="kt-textarea border-0 rounded-none w-full"
                                              aria-label="Card description" wire:model="description"
                                              wire:keydown.escape.stop="cancelDescription" autofocus
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
                                            One of two places in this file that echo unescaped, and
                                            both go through the same sanitiser. `Support\Mentions`
                                            runs the text through `Support\Markdown` unchanged —
                                            raw HTML stripped, unsafe link schemes refused — and
                                            only then swaps its own placeholders for chip markup it
                                            built out of `e()`-escaped names. No user byte reaches
                                            the page without passing the converter.
                                        --}}
                                        {{--
                                            `break-words` and `overflow-x-auto` because the content
                                            is markdown somebody typed: a pasted URL is one unbroken
                                            word that would otherwise widen the whole drawer, and a
                                            fenced code block is a `<pre>` that never wraps. Prose
                                            still wraps normally, so the scrollbar only appears for
                                            the one thing that genuinely cannot.
                                        --}}
                                        <div class="text-sm text-secondary-foreground leading-relaxed break-words overflow-x-auto [&_p]:mb-2 last:[&_p]:mb-0">{!! \Modules\Project\Support\Mentions::toHtml($card->description, $members) !!}</div>
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

                        {{--
                            Butler's card buttons: an action chain somebody
                            defined once, run against this card. The component
                            dispatches `card-changed` when a chain alters the
                            card, which the board canvas and this drawer both
                            listen for.
                        --}}
                        <livewire:project::card-buttons :card-id="$card->id" />

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
                                    <div class="rounded-md px-2 py-1.5 hover:bg-accent/60" wire:key="check-{{ $item->id }}">
                                        <div class="group flex items-start gap-2.5">
                                            <input type="checkbox" class="kt-checkbox mt-0.5"
                                                   id="check-{{ $item->id }}"
                                                   wire:click="toggleChecklistItem({{ $item->id }})"
                                                   @checked($item->is_done)>
                                            <label for="check-{{ $item->id }}"
                                                   {{-- `min-w-0` so a long line shrinks instead of shoving the avatar, the due badge and both buttons off the row; `break-words` for the one that is a single unbroken string. --}}
                                                   class="grow min-w-0 break-words text-sm cursor-pointer {{ $item->is_done ? 'text-muted-foreground line-through' : 'text-secondary-foreground' }}">
                                                {{ $item->text }}
                                            </label>

                                            {{--
                                                The two advanced columns, drawn only when they carry
                                                something. An item with neither reads exactly as it
                                                did before the feature existed.
                                            --}}
                                            @if ($item->assignee)
                                                <span class="size-5 rounded-full grid place-items-center text-[9px] font-semibold shrink-0 bg-primary/15 text-primary"
                                                      title="{{ $item->assignee->name }}">{{ $item->assignee->initials() }}</span>
                                            @endif

                                            @if ($item->due_on)
                                                <span class="kt-badge kt-badge-sm shrink-0 {{ \Modules\Project\Support\Palette::tone($item->dueBadgeColour() ?? 'neutral') }}"
                                                      title="This item is due on {{ $item->due_on->format('j M Y') }}">
                                                    <i class="ki-filled ki-calendar text-[10px]"></i>
                                                    {{ $item->due_on->format('j M') }}
                                                </span>
                                            @endif

                                            <button wire:click="toggleItemEditor({{ $item->id }})"
                                                    class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0"
                                                    title="Assignee and due date" aria-label="Assignee and due date for this item"
                                                    aria-expanded="{{ $itemEditing === $item->id ? 'true' : 'false' }}">
                                                <i class="ki-filled ki-dots-horizontal text-xs"></i>
                                            </button>
                                            <button wire:click="deleteChecklistItem({{ $item->id }})"
                                                    class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0"
                                                    title="Delete item" aria-label="Delete checklist item">
                                                <i class="ki-filled ki-trash text-xs"></i>
                                            </button>
                                        </div>

                                        @if ($itemEditing === $item->id)
                                            <div class="mt-2 ms-7 flex flex-wrap items-end gap-2 rounded-lg border border-border bg-muted/30 p-2.5">
                                                <div class="flex flex-col gap-1 min-w-[150px]">
                                                    <label class="kt-form-label text-[11px]" for="item-assignee-{{ $item->id }}">Assignee</label>
                                                    <select id="item-assignee-{{ $item->id }}" class="kt-select kt-select-sm"
                                                            wire:change="assignItem({{ $item->id }}, $event.target.value)">
                                                        <option value="">Nobody</option>
                                                        @foreach ($members as $person)
                                                            <option value="{{ $person->id }}" @selected($item->assigned_to === $person->id)>{{ $person->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <div class="flex flex-col gap-1">
                                                    <label class="kt-form-label text-[11px]" for="item-due-{{ $item->id }}">Due</label>
                                                    <input type="date" id="item-due-{{ $item->id }}" class="kt-input kt-input-sm"
                                                           value="{{ $item->due_on?->toDateString() }}"
                                                           wire:change="setItemDue({{ $item->id }}, $event.target.value)">
                                                </div>

                                                @if ($item->due_on)
                                                    <button wire:click="clearItemDue({{ $item->id }})" class="kt-btn kt-btn-sm kt-btn-ghost">
                                                        Clear date
                                                    </button>
                                                @endif

                                                {{--
                                                    Convert takes the assignee and the date with it —
                                                    see `convertItemToCard()`. The item leaves the
                                                    checklist, so the confirm says so.
                                                --}}
                                                <button wire:click="convertItemToCard({{ $item->id }})"
                                                        wire:confirm="Make this a card in the same list? It comes off the checklist, keeping its assignee and due date."
                                                        class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 ms-auto">
                                                    <i class="ki-filled ki-exit-up text-sm"></i> Convert to card
                                                </button>
                                            </div>
                                        @endif
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
                                        <div class="text-sm text-secondary-foreground mt-1 rounded-lg bg-muted/40 border border-border px-3 py-2 break-words overflow-x-auto [&_p]:mb-2 last:[&_p]:mb-0">
                                            {!! \Modules\Project\Support\Mentions::toHtml($comment->body, $members) !!}
                                        </div>

                                        {{--
                                            Reactions. A chip is one emoji and everyone
                                            who used it; clicking it adds or removes
                                            *your* reaction, which is why a chip you are
                                            already in is drawn in the primary colour.
                                            The picker offers the same eight everywhere
                                            — `Modules\Project\Support\Reactions`.
                                        --}}
                                        <div class="flex flex-wrap items-center gap-1 mt-1.5">
                                            @foreach ($reactionChips[$comment->id] ?? [] as $chip)
                                                <button wire:click="toggleReaction({{ $comment->id }}, '{{ $chip['emoji'] }}')"
                                                        wire:key="reaction-{{ $comment->id }}-{{ $chip['emoji'] }}"
                                                        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs
                                                               {{ $chip['mine'] ? 'border-primary text-primary bg-primary/10' : 'border-border text-secondary-foreground hover:bg-accent/60' }}"
                                                        aria-pressed="{{ $chip['mine'] ? 'true' : 'false' }}"
                                                        title="{{ $chip['name'] }} — {{ $chip['who'] }}"
                                                        aria-label="{{ $chip['name'] }}, {{ $chip['count'] }}">
                                                    <span aria-hidden="true">{{ $chip['emoji'] }}</span>{{ $chip['count'] }}
                                                </button>
                                            @endforeach

                                            <div class="relative">
                                                <button wire:click="toggleReactionPicker({{ $comment->id }})"
                                                        class="kt-btn kt-btn-icon kt-btn-ghost size-6"
                                                        title="Add a reaction" aria-label="Add a reaction"
                                                        aria-expanded="{{ $reactionPickerFor === $comment->id ? 'true' : 'false' }}">
                                                    <i class="ki-filled ki-emoji-happy text-xs"></i>
                                                </button>

                                                <div class="kt-dropdown absolute z-20 mt-1 start-0 w-[200px] p-2 {{ $reactionPickerFor === $comment->id ? 'open' : '' }}">
                                                    <div class="flex flex-wrap gap-1">
                                                        @foreach ($reactionSet as $emoji)
                                                            <button wire:click="toggleReaction({{ $comment->id }}, '{{ $emoji }}')"
                                                                    wire:key="pick-{{ $comment->id }}-{{ $emoji }}"
                                                                    class="size-7 rounded-md grid place-items-center text-base hover:bg-accent/60"
                                                                    title="{{ \Modules\Project\Support\Reactions::name($emoji) }}"
                                                                    aria-label="{{ \Modules\Project\Support\Reactions::name($emoji) }}">
                                                                <span aria-hidden="true">{{ $emoji }}</span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
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

                            <div class="kt-dropdown absolute z-20 mt-1 end-0 w-[240px] p-4 {{ $movePopoverOpen ? 'open flex flex-col gap-3' : '' }}">
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

                            <div class="kt-dropdown absolute z-20 mt-1 end-0 w-[280px] p-4 {{ $mirrorPopoverOpen ? 'open flex flex-col gap-3' : '' }}">
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
                    <div class="grow flex flex-col gap-2 relative">
                        {{--
                            `.live.debounce` rather than a plain bind: the `@`
                            autocomplete is server-driven, so the server has to
                            see what is being typed. The debounce is what keeps
                            that to one round trip per pause rather than one per
                            keystroke, and the list closes itself the moment the
                            trailing token stops looking like a mention.
                        --}}
                        <textarea rows="2" class="kt-textarea" placeholder="Write a comment… type @ to mention somebody"
                                  aria-label="New comment" wire:model.live.debounce.400ms="newComment"
                                  {{-- `.stop` so dismissing the suggestion list does not also close the drawer and lose the half-written comment. --}}
                                  wire:keydown.escape.stop="closeMentions"></textarea>

                        @if ($mentionSuggestions->isNotEmpty())
                            {{--
                                `bottom: 100%` as an inline style, and `z-20` rather than `z-30`.
                                Both of the classes that used to do this — `bottom-full` and
                                `z-30` — are absent from the compiled sheet, so the list had no
                                offset and no stacking order at all: it rendered at its static
                                position, directly over the Comment button underneath it, and
                                clicking a name hit whatever painted last. `z-20` is the value
                                every other popover in this file uses and is in the sheet;
                                `bottom-full` has no compiled equivalent, so it is written out.
                            --}}
                            <div class="kt-dropdown open absolute z-20 mb-1 start-0 w-[260px] p-1"
                                 style="bottom: 100%;"
                                 role="listbox" aria-label="People you can mention">
                                @foreach ($mentionSuggestions as $person)
                                    <button wire:click="insertMention({{ $person->id }})" wire:key="mention-{{ $person->id }}"
                                            class="kt-btn kt-btn-ghost justify-start gap-2 w-full" role="option">
                                        <span class="size-6 rounded-full grid place-items-center text-[10px] font-semibold bg-primary/15 text-primary">
                                            {{ $person->initials() }}
                                        </span>
                                        <span class="text-sm text-mono">{{ $person->name }}</span>
                                        {{-- `'@'.` inside the expression, never a literal `@{{` — Blade reads that as an escaped brace pair and prints the braces. --}}
                                        <span class="text-[11px] text-muted-foreground ms-auto">{{ '@'.\Modules\Project\Support\Mentions::handleFor($person) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex items-center gap-2">
                            <button wire:click="addComment" wire:loading.attr="disabled" wire:target="addComment"
                                    class="kt-btn kt-btn-sm kt-btn-primary gap-1">
                                <span wire:loading.remove wire:target="addComment">Comment</span>
                                <span wire:loading wire:target="addComment"><i class="ki-filled ki-loading animate-spin"></i> Posting…</span>
                            </button>
                            <span class="text-[11px] text-muted-foreground">It is posted as you, and stays on the card. Anyone you name with an at-sign is told, whether or not they watch this card.</span>
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

    /*
     * Where the focus goes when the drawer opens and closes.
     *
     * The panel is `role="dialog" aria-modal="true"`, but nothing was moving
     * the focus into it, so `wire:keydown.escape` on the panel never fired for
     * anybody who had opened the card with the mouse — the key went to whatever
     * still had the focus on the board behind. Focusing the panel fixes Escape
     * and puts a keyboard user inside the dialog rather than behind it.
     *
     * The element to come back to is remembered as it is focused rather than
     * read when the drawer opens: by then the round trip has been and gone and
     * Livewire's morph may have replaced the node that was clicked.
     *
     * `setTimeout` and not `requestAnimationFrame`, because the panel is
     * `inert` while shut and `focus()` on an inert subtree is silently ignored;
     * a task boundary guarantees the morph that drops the attribute has run.
     */
    (function initCardDrawerFocus() {
        if (window.kargahCardDrawerFocus) return;
        window.kargahCardDrawerFocus = true;

        var returnTo = null;

        var panel = function () { return document.getElementById('card-drawer-panel'); };

        document.addEventListener('focusin', function (event) {
            var el = panel();
            if (el && el.contains(event.target)) return;
            returnTo = event.target;
        });

        window.addEventListener('card-drawer-opened', function () {
            setTimeout(function () {
                var el = panel();
                if (el) el.focus();
            }, 0);
        });

        window.addEventListener('card-drawer-closed', function () {
            setTimeout(function () {
                if (returnTo && returnTo.isConnected && typeof returnTo.focus === 'function') {
                    returnTo.focus();
                }
            }, 0);
        });
    })();
</script>
@endscript
</div>
