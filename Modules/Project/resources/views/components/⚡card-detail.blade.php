<?php

use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Card detail drawer.
 *
 * Nested inside the board. The board dispatches `open-card` with a card id and
 * this component slides in from the right with everything that hangs off a
 * card: description, labels, due date, assignee, checklist, attachments and
 * the comment thread.
 *
 * Frontend phase: the card comes from a fixture. Ticking a checklist item or
 * toggling a label changes this component's own state so the interaction can
 * be reviewed — nothing is written anywhere. Every action the backend will
 * own already exists with its final signature and an empty body.
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

    /** @var string[] */
    public array $cardLabels = [];

    public string $dueDate = '';

    public string $assignee = '';

    /** @var array<int, array{id:int, text:string, done:bool}> */
    public array $checklist = [];

    public string $newChecklistItem = '';

    public string $newComment = '';

    public bool $editingTitle = false;

    public bool $editingDescription = false;

    public bool $labelPopoverOpen = false;

    public bool $duePopoverOpen = false;

    /** Labels defined on the board. Kept in step with the board fixture. */
    private function labels(): array
    {
        return [
            'copy' => ['name' => 'Copywriting', 'chip' => 'bg-primary/15 text-primary', 'dot' => 'bg-primary'],
            'outreach' => ['name' => 'Outreach', 'chip' => 'bg-success/15 text-success', 'dot' => 'bg-success'],
            'dev' => ['name' => 'Development', 'chip' => 'bg-info/15 text-info', 'dot' => 'bg-info'],
            'bug' => ['name' => 'Bug', 'chip' => 'bg-destructive/15 text-destructive', 'dot' => 'bg-destructive'],
            'finance' => ['name' => 'Finance', 'chip' => 'bg-warning/15 text-warning', 'dot' => 'bg-warning'],
            'admin' => ['name' => 'Admin', 'chip' => 'bg-accent/60 text-secondary-foreground', 'dot' => 'bg-muted-foreground'],
        ];
    }

    private function members(): array
    {
        return [
            'nima' => ['name' => 'Nima Fazlipour', 'initials' => 'NF', 'tone' => 'bg-primary/15 text-primary'],
            'sara' => ['name' => 'Sara Rahimi', 'initials' => 'SR', 'tone' => 'bg-success/15 text-success'],
            'dan' => ['name' => 'Daniel Whitfield', 'initials' => 'DW', 'tone' => 'bg-info/15 text-info'],
            'mina' => ['name' => 'Mina Karimi', 'initials' => 'MK', 'tone' => 'bg-warning/15 text-warning'],
        ];
    }

    /** Everything the drawer knows about a card, keyed by card id. */
    private function cards(): array
    {
        return [
            1 => [
                'title' => 'Rewrite portfolio landing copy',
                'list' => 'Backlog',
                'board' => 'Client Work',
                'description' => "The current page reads like a CV. Rewrite it around the three services that actually sell:\n\n- retainer development\n- one-off audits\n- migration work\n\nKeep it under 400 words and end on the booking link.",
                'labels' => ['copy'],
                'due' => '',
                'assignee' => 'nima',
                'checklist' => [
                    ['id' => 1, 'text' => 'Pull the three highest-earning services from the 2026 invoices', 'done' => false],
                    ['id' => 2, 'text' => 'Draft the hero paragraph', 'done' => false],
                    ['id' => 3, 'text' => 'Rewrite the services section', 'done' => false],
                    ['id' => 4, 'text' => 'Proofread and publish', 'done' => false],
                ],
                'attachments' => [
                    ['name' => 'landing-copy-v2.md', 'size' => '11 KB', 'added' => '28 Jul 2026', 'icon' => 'ki-document'],
                ],
                'comments' => [
                    ['author' => 'sara', 'when' => '30 Jul, 09:12', 'body' => 'The old headline still mentions WordPress. We stopped taking that work in March.'],
                    ['author' => 'nima', 'when' => '30 Jul, 10:04', 'body' => 'Good catch. Dropping it in the rewrite.'],
                ],
            ],
            2 => [
                'title' => 'Collect testimonials from past clients',
                'list' => 'Backlog',
                'board' => 'Client Work',
                'description' => "Ask the five clients from the last two quarters for two sentences each. Offer to draft something they can edit — it doubles the reply rate.",
                'labels' => ['outreach', 'admin'],
                'due' => '2026-08-12',
                'assignee' => 'sara',
                'checklist' => [
                    ['id' => 1, 'text' => 'Northwind Ltd', 'done' => true],
                    ['id' => 2, 'text' => 'Acme Studio', 'done' => false],
                    ['id' => 3, 'text' => 'Bluepeak', 'done' => false],
                ],
                'attachments' => [],
                'comments' => [
                    ['author' => 'sara', 'when' => '29 Jul, 16:40', 'body' => 'Northwind replied the same day. Two lines, already usable.'],
                ],
            ],
            3 => [
                'title' => 'Send the Northwind retainer proposal',
                'list' => 'To Do',
                'board' => 'Client Work',
                'description' => "Twelve months, four days a month, invoiced on the first. Reuse the Acme Studio structure but drop the on-call clause.",
                'labels' => ['outreach'],
                'due' => '2026-08-05',
                'assignee' => 'nima',
                'checklist' => [
                    ['id' => 1, 'text' => 'Confirm the day rate for 2027', 'done' => true],
                    ['id' => 2, 'text' => 'Write the scope section', 'done' => true],
                    ['id' => 3, 'text' => 'Add the payment terms', 'done' => true],
                    ['id' => 4, 'text' => 'Internal read-through', 'done' => true],
                    ['id' => 5, 'text' => 'Export to PDF', 'done' => true],
                    ['id' => 6, 'text' => 'Send to Helen at Northwind', 'done' => false],
                ],
                'attachments' => [
                    ['name' => 'northwind-retainer-2026.pdf', 'size' => '284 KB', 'added' => '01 Aug 2026', 'icon' => 'ki-document'],
                ],
                'comments' => [
                    ['author' => 'dan', 'when' => '01 Aug, 11:20', 'body' => 'Rate looks low next to what we quoted Bluepeak for the same work.'],
                ],
            ],
            4 => [
                'title' => 'Fix invoice PDF margins',
                'list' => 'To Do',
                'board' => 'Client Work',
                'description' => "The footer overlaps the last table row when an invoice runs past fifteen line items. Only shows up on A4.",
                'labels' => ['bug'],
                'due' => '',
                'assignee' => 'dan',
                'checklist' => [],
                'attachments' => [],
                'comments' => [],
            ],
            5 => [
                'title' => 'Build the Acme Studio mail module',
                'list' => 'In Progress',
                'board' => 'Client Work',
                'description' => "Inbox, campaigns, contacts and provider settings. The provider layer has to stay swappable — Acme want to move off Postmark next year.",
                'labels' => ['dev'],
                'due' => '2026-08-20',
                'assignee' => 'nima',
                'checklist' => [
                    ['id' => 1, 'text' => 'Inbox list and reading pane', 'done' => true],
                    ['id' => 2, 'text' => 'Campaign composer', 'done' => true],
                    ['id' => 3, 'text' => 'Contact import', 'done' => true],
                    ['id' => 4, 'text' => 'Provider credentials screen', 'done' => false],
                    ['id' => 5, 'text' => 'Bounce handling', 'done' => false],
                    ['id' => 6, 'text' => 'Unsubscribe page', 'done' => false],
                    ['id' => 7, 'text' => 'Sending domain checks', 'done' => false],
                    ['id' => 8, 'text' => 'Rate limiting', 'done' => false],
                    ['id' => 9, 'text' => 'Hand-over notes', 'done' => false],
                ],
                'attachments' => [
                    ['name' => 'acme-mail-brief.pdf', 'size' => '1.2 MB', 'added' => '14 Jul 2026', 'icon' => 'ki-document'],
                    ['name' => 'inbox-wireframe.png', 'size' => '640 KB', 'added' => '18 Jul 2026', 'icon' => 'ki-picture'],
                    ['name' => 'provider-comparison.csv', 'size' => '8 KB', 'added' => '22 Jul 2026', 'icon' => 'ki-document'],
                ],
                'comments' => [
                    ['author' => 'mina', 'when' => '25 Jul, 14:02', 'body' => 'Acme asked whether campaign stats can be exported. Adding it here so it is not forgotten.'],
                    ['author' => 'nima', 'when' => '25 Jul, 14:31', 'body' => 'Out of scope for the first release. I will quote it separately.'],
                    ['author' => 'dan', 'when' => '28 Jul, 08:55', 'body' => 'Provider screen is blocked on the credentials store landing first.'],
                    ['author' => 'nima', 'when' => '31 Jul, 17:10', 'body' => 'Credentials store merged this morning, so that one is unblocked.'],
                ],
            ],
            6 => [
                'title' => 'Q3 expense reconciliation',
                'list' => 'Review',
                'board' => 'Client Work',
                'description' => "Match every card payment against a receipt before the quarter closes. Anything without a receipt goes on the personal side.",
                'labels' => ['finance'],
                'due' => '2026-08-01',
                'assignee' => 'mina',
                'checklist' => [
                    ['id' => 1, 'text' => 'Export the card statement', 'done' => true],
                    ['id' => 2, 'text' => 'Match hosting invoices', 'done' => true],
                    ['id' => 3, 'text' => 'Match software subscriptions', 'done' => true],
                    ['id' => 4, 'text' => 'Match travel', 'done' => true],
                    ['id' => 5, 'text' => 'Match equipment', 'done' => true],
                    ['id' => 6, 'text' => 'Flag anything unmatched', 'done' => true],
                    ['id' => 7, 'text' => 'File the receipts', 'done' => true],
                    ['id' => 8, 'text' => 'Hand to the accountant', 'done' => true],
                ],
                'attachments' => [
                    ['name' => 'q3-card-statement.csv', 'size' => '46 KB', 'added' => '31 Jul 2026', 'icon' => 'ki-document'],
                ],
                'comments' => [],
            ],
            7 => [
                'title' => 'Register the kargah.dev domain',
                'list' => 'Done',
                'board' => 'Client Work',
                'description' => "Registered for five years with privacy on. Renewal is in the calendar.",
                'labels' => ['admin'],
                'due' => '',
                'assignee' => 'nima',
                'checklist' => [],
                'attachments' => [],
                'comments' => [],
            ],
            8 => [
                'title' => 'Scope the Bluepeak booking widget',
                'list' => 'Backlog',
                'board' => 'Client Work',
                'description' => "Embeddable widget for their existing site. Needs to work without a build step, so plain JS and a single stylesheet.",
                'labels' => ['dev'],
                'due' => '',
                'assignee' => '',
                'checklist' => [],
                'attachments' => [
                    ['name' => 'bluepeak-requirements.docx', 'size' => '92 KB', 'added' => '26 Jul 2026', 'icon' => 'ki-document'],
                    ['name' => 'current-booking-flow.png', 'size' => '410 KB', 'added' => '26 Jul 2026', 'icon' => 'ki-picture'],
                ],
                'comments' => [],
            ],
        ];
    }

    /** The open card, or null when the drawer has never been opened. */
    private function card(): ?array
    {
        return $this->cards()[$this->cardId] ?? null;
    }

    public function with(): array
    {
        $card = $this->card();
        $done = count(array_filter($this->checklist, fn (array $item): bool => $item['done']));
        $total = count($this->checklist);

        return [
            'card' => $card,
            'labels' => $this->labels(),
            'members' => $this->members(),
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

    #[On('open-card')]
    public function openCard(int $cardId): void
    {
        $this->cardId = $cardId;
        $card = $this->card();

        $this->title = $card['title'] ?? '';
        $this->description = $card['description'] ?? '';
        $this->cardLabels = $card['labels'] ?? [];
        $this->dueDate = $card['due'] ?? '';
        $this->assignee = $card['assignee'] ?? '';
        $this->checklist = $card['checklist'] ?? [];

        $this->editingTitle = false;
        $this->editingDescription = false;
        $this->labelPopoverOpen = false;
        $this->duePopoverOpen = false;
        $this->newComment = '';
        $this->newChecklistItem = '';

        $this->open = true;

        $this->toastSuccess('Card open', $this->title.' is in the drawer on the right.');
    }

    public function close(): void
    {
        $wasOpen = $this->open;

        $this->open = false;
        $this->labelPopoverOpen = false;
        $this->duePopoverOpen = false;

        if ($wasOpen) {
            $this->toastSuccess('Card closed', 'Nothing you typed was saved.');
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
        $this->title = $this->card()['title'] ?? '';

        if ($wasEditing) {
            $this->toastSuccess('Rename abandoned', 'The card kept its old title.');
        }
    }

    /** Rename the card. */
    public function saveTitle(): void
    {
        $this->validateOnly('title');

        // Backend: persist the new title, then close the editor.
        $this->editingTitle = false;

        $this->toastInfo('Not connected yet', 'The new title goes back on the next refresh.');
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
        $this->description = $this->card()['description'] ?? '';

        if ($wasEditing) {
            $this->toastSuccess('Edit abandoned', 'The card kept its old description.');
        }
    }

    /** Store the description. */
    public function saveDescription(): void
    {
        // Backend: persist the description as written, markdown included.
        $this->editingDescription = false;

        $this->toastInfo('Not connected yet', 'The description goes back on the next refresh.');
    }

    /* Labels, due date, assignee ----------------------------------------- */

    public function toggleLabelPopover(): void
    {
        $this->labelPopoverOpen = ! $this->labelPopoverOpen;
        $this->duePopoverOpen = false;

        $this->labelPopoverOpen
            ? $this->toastSuccess('Label picker open', 'Tick the labels this card should carry.')
            : $this->toastSuccess('Label picker closed');
    }

    /** Add or remove a label on this card. */
    public function toggleLabel(string $key): void
    {
        $this->cardLabels = in_array($key, $this->cardLabels, true)
            ? array_values(array_diff($this->cardLabels, [$key]))
            : [...$this->cardLabels, $key];

        // Backend: persist the card's labels.
        $name = $this->labels()[$key]['name'] ?? $key;

        $this->toastSuccess(
            in_array($key, $this->cardLabels, true) ? $name.' put on the card' : $name.' taken off the card',
            'On screen only — labels are stored with the backend phase.',
        );
    }

    public function toggleDuePopover(): void
    {
        $this->duePopoverOpen = ! $this->duePopoverOpen;
        $this->labelPopoverOpen = false;

        $this->duePopoverOpen
            ? $this->toastSuccess('Due date picker open', 'Pick a date, or remove the one already set.')
            : $this->toastSuccess('Due date picker closed');
    }

    /** Set the due date. */
    public function saveDueDate(): void
    {
        // Backend: persist the due date and schedule the reminder.
        $this->duePopoverOpen = false;

        $this->toastInfo('Not connected yet', 'Due dates and their reminders land with the backend phase.');
    }

    public function clearDueDate(): void
    {
        $this->dueDate = '';

        // Backend: clear the due date and cancel the reminder.
        $this->duePopoverOpen = false;

        $this->toastSuccess('Due date removed', 'On screen only — it returns on the next refresh.');
    }

    /** Fired when the assignee select changes; empty means unassigned. */
    public function updatedAssignee(string $memberKey): void
    {
        // Backend: persist the assignment and notify the member.
        $this->toastInfo('Not connected yet', 'Nobody is notified until the backend phase lands.');
    }

    /* Checklist ----------------------------------------------------------- */

    public function toggleChecklistItem(int $itemId): void
    {
        $done = false;

        foreach ($this->checklist as $index => $item) {
            if ($item['id'] === $itemId) {
                $this->checklist[$index]['done'] = ! $item['done'];
                $done = $this->checklist[$index]['done'];
            }
        }

        // Backend: persist the tick.
        $this->toastSuccess(
            $done ? 'Item ticked' : 'Item unticked',
            'On screen only — ticks are stored with the backend phase.',
        );
    }

    /** Append an item to the checklist. */
    public function addChecklistItem(): void
    {
        // Backend: persist the item, then clear $newChecklistItem.
        $this->toastInfo('Not connected yet', 'Checklist items land with the backend phase.');
    }

    public function deleteChecklistItem(int $itemId): void
    {
        // Backend: delete the item.
        $this->toastInfo('Not connected yet', 'Checklist items land with the backend phase.');
    }

    /* Attachments and comments -------------------------------------------- */

    public function removeAttachment(string $name): void
    {
        // Backend: delete the file from the disk and the row from the card.
        $this->toastInfo('Not connected yet', $name.' stays on the card until the backend phase lands.');
    }

    /** Post a comment on the card. */
    public function addComment(): void
    {
        // Backend: persist the comment, then clear $newComment.
        $this->toastInfo('Not connected yet', 'Comments are posted once the backend phase lands.');
    }

    /* Right rail actions --------------------------------------------------- */

    public function moveCard(): void
    {
        // Backend: move the card to another list or board.
        $this->toastInfo('Not connected yet', 'Moving a card from here lands with the backend phase.');
    }

    public function copyCard(): void
    {
        // Backend: duplicate the card, its labels and its checklist.
        $this->toastInfo('Not connected yet', 'Copying a card lands with the backend phase.');
    }

    public function archiveCard(): void
    {
        // Backend: archive the card so it leaves the board but stays readable.
        $this->toastInfo('Not connected yet', 'Archiving a card lands with the backend phase.');
    }

    public function deleteCard(): void
    {
        // Backend: delete the card and everything attached to it.
        $this->toastInfo('Not connected yet', 'Deleting a card lands with the backend phase.');
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
                            <h2 class="text-lg font-semibold text-mono leading-snug">{{ $title }}</h2>
                        </button>
                        <p class="text-xs text-muted-foreground mt-1">
                            In list <span class="text-secondary-foreground">{{ $card['list'] }}</span>
                            on <span class="text-secondary-foreground">{{ $card['board'] }}</span>
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
                                    @forelse ($cardLabels as $key)
                                        <span class="text-xs font-medium px-2 py-1 rounded {{ $labels[$key]['chip'] }}">{{ $labels[$key]['name'] }}</span>
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
                                                @foreach ($labels as $key => $label)
                                                    <button wire:click="toggleLabel('{{ $key }}')"
                                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-start hover:bg-accent/60">
                                                        <span class="size-3 rounded-sm {{ $label['dot'] }}"></span>
                                                        <span class="grow text-secondary-foreground">{{ $label['name'] }}</span>
                                                        @if (in_array($key, $cardLabels, true))
                                                            <i class="ki-filled ki-check text-sm text-primary"></i>
                                                        @endif
                                                    </button>
                                                @endforeach
                                            </div>
                                            <div class="border-t border-border p-2">
                                                <a href="{{ route('projects.board-settings', ['board' => 'client-work']) }}" wire:navigate
                                                   class="kt-btn kt-btn-ghost kt-btn-sm justify-start gap-2 w-full">
                                                    <i class="ki-filled ki-setting-2 text-sm"></i> Manage board labels
                                                </a>
                                            </div>
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
                                        {{ $dueDate !== '' ? $dueDate : 'No due date' }}
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
                                    @if ($assignee !== '' && isset($members[$assignee]))
                                        <span class="size-7 rounded-full grid place-items-center text-[11px] font-semibold {{ $members[$assignee]['tone'] }}">
                                            {{ $members[$assignee]['initials'] }}
                                        </span>
                                    @else
                                        <span class="size-7 rounded-full grid place-items-center bg-muted text-muted-foreground">
                                            <i class="ki-filled ki-user text-xs"></i>
                                        </span>
                                    @endif
                                    <select class="kt-select max-w-[190px]" aria-label="Assignee" wire:model.live="assignee">
                                        <option value="">Unassigned</option>
                                        @foreach ($members as $key => $member)
                                            <option value="{{ $key }}">{{ $member['name'] }}</option>
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
                                    @if (trim($description) !== '')
                                        <div class="text-sm text-secondary-foreground whitespace-pre-line leading-relaxed">{{ $description }}</div>
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
                                    <div class="group flex items-start gap-2.5 rounded-md px-2 py-1.5 hover:bg-accent/60" wire:key="check-{{ $item['id'] }}">
                                        <input type="checkbox" class="kt-checkbox mt-0.5"
                                               id="check-{{ $item['id'] }}"
                                               wire:click="toggleChecklistItem({{ $item['id'] }})"
                                               @checked($item['done'])>
                                        <label for="check-{{ $item['id'] }}"
                                               class="grow text-sm cursor-pointer {{ $item['done'] ? 'text-muted-foreground line-through' : 'text-secondary-foreground' }}">
                                            {{ $item['text'] }}
                                        </label>
                                        <button wire:click="deleteChecklistItem({{ $item['id'] }})"
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

                        {{-- Attachments --}}
                        <div class="flex flex-col gap-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <i class="ki-filled ki-paper-clip text-sm text-muted-foreground"></i>
                                    <h3 class="text-sm font-semibold text-mono">Attachments</h3>
                                </div>
                                <button class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                                    <i class="ki-filled ki-cloud-add text-sm"></i> Add
                                </button>
                            </div>

                            @forelse ($card['attachments'] as $file)
                                <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-2">
                                    <span class="size-9 rounded-md grid place-items-center bg-muted">
                                        <i class="ki-filled {{ $file['icon'] }} text-base text-muted-foreground"></i>
                                    </span>
                                    <div class="min-w-0 grow">
                                        <div class="text-sm text-mono truncate">{{ $file['name'] }}</div>
                                        <div class="text-xs text-muted-foreground">{{ $file['size'] }} · added {{ $file['added'] }}</div>
                                    </div>
                                    <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Download" aria-label="Download attachment">
                                        <i class="ki-filled ki-exit-down text-sm"></i>
                                    </button>
                                    <button wire:click="removeAttachment('{{ $file['name'] }}')"
                                            class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive"
                                            title="Remove" aria-label="Remove attachment">
                                        <i class="ki-filled ki-trash text-sm"></i>
                                    </button>
                                </div>
                            @empty
                                <div class="rounded-lg border border-dashed border-border px-4 py-6 text-center">
                                    <i class="ki-filled ki-cloud-add text-2xl text-muted-foreground"></i>
                                    <p class="text-sm text-muted-foreground mt-2">Nothing attached to this card yet.</p>
                                </div>
                            @endforelse
                        </div>

                        {{-- Comments --}}
                        <div class="flex flex-col gap-3">
                            <div class="flex items-center gap-2">
                                <i class="ki-filled ki-message-text-2 text-sm text-muted-foreground"></i>
                                <h3 class="text-sm font-semibold text-mono">Activity</h3>
                            </div>

                            @forelse ($card['comments'] as $comment)
                                <div class="flex items-start gap-3">
                                    <span class="size-8 rounded-full grid place-items-center text-[11px] font-semibold shrink-0 {{ $members[$comment['author']]['tone'] }}">
                                        {{ $members[$comment['author']]['initials'] }}
                                    </span>
                                    <div class="min-w-0 grow">
                                        <div class="flex items-baseline gap-2">
                                            <span class="text-sm font-medium text-mono">{{ $members[$comment['author']]['name'] }}</span>
                                            <span class="text-xs text-muted-foreground">{{ $comment['when'] }}</span>
                                        </div>
                                        <p class="text-sm text-secondary-foreground mt-1 rounded-lg bg-muted/40 border border-border px-3 py-2">
                                            {{ $comment['body'] }}
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

                        <button wire:click="moveCard" class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                            <i class="ki-filled ki-arrow-right text-sm"></i> Move
                        </button>
                        <button wire:click="copyCard" class="kt-btn kt-btn-outline justify-start gap-2 w-full">
                            <i class="ki-filled ki-copy text-sm"></i> Copy
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
                            Archiving keeps the card readable from the archive. Deleting cannot be undone.
                        </p>
                    </aside>
                </div>
            </div>

            {{-- Comment composer --}}
            <div class="border-t border-border px-5 py-4">
                <div class="flex items-start gap-3">
                    <span class="size-8 rounded-full grid place-items-center text-[11px] font-semibold shrink-0 bg-primary/15 text-primary">NF</span>
                    <div class="grow flex flex-col gap-2">
                        <textarea rows="2" class="kt-textarea" placeholder="Write a comment…"
                                  aria-label="New comment" wire:model="newComment"></textarea>
                        <div class="flex items-center gap-2">
                            <button wire:click="addComment" wire:loading.attr="disabled" wire:target="addComment"
                                    class="kt-btn kt-btn-sm kt-btn-primary gap-1">
                                <span wire:loading.remove wire:target="addComment">Comment</span>
                                <span wire:loading wire:target="addComment"><i class="ki-filled ki-loading animate-spin"></i> Posting…</span>
                            </button>
                            <span class="text-[11px] text-muted-foreground">Everyone on the board is notified.</span>
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
