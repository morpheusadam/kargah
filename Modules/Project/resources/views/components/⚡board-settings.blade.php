<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Board settings.
 *
 * Everything that belongs to the board rather than to a card: its name, its
 * background, the labels every card picks from, who can see it and the two
 * ways to get rid of it.
 *
 * Frontend phase: labels and members come from a fixture. Selecting a
 * background or opening a label editor changes this component's own state so
 * the screen can be reviewed; every write is left to the backend.
 */
new
#[Title('Board settings — Kargah')]
class extends Component
{
    /** Board slug from the route. */
    public string $board = '';

    #[Validate('required|min:2|max:40')]
    public string $name = '';

    public string $background = 'indigo';

    /** Label key currently being edited inline. */
    public ?string $editingLabel = null;

    public string $labelDraft = '';

    public string $labelColorDraft = 'primary';

    public string $newLabelName = '';

    public string $newLabelColor = 'primary';

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    /** Roles keyed by member, so each select can bind to its own value. */
    public array $roles = [];

    public bool $confirmingDelete = false;

    public string $deleteConfirmation = '';

    public function mount(string $board): void
    {
        $this->board = $board;
        $this->name = ucwords(str_replace('-', ' ', $board));
        $this->roles = array_map(fn (array $member): string => $member['role'], $this->members());
    }

    private function backgrounds(): array
    {
        return [
            'indigo' => ['name' => 'Indigo', 'swatch' => 'bg-primary'],
            'moss' => ['name' => 'Moss', 'swatch' => 'bg-success'],
            'amber' => ['name' => 'Amber', 'swatch' => 'bg-warning'],
            'clay' => ['name' => 'Clay', 'swatch' => 'bg-destructive'],
            'sky' => ['name' => 'Sky', 'swatch' => 'bg-info'],
            'slate' => ['name' => 'Slate', 'swatch' => 'bg-muted-foreground'],
        ];
    }

    private function colours(): array
    {
        return [
            'primary' => ['name' => 'Indigo', 'dot' => 'bg-primary', 'chip' => 'bg-primary/15 text-primary'],
            'success' => ['name' => 'Green', 'dot' => 'bg-success', 'chip' => 'bg-success/15 text-success'],
            'warning' => ['name' => 'Amber', 'dot' => 'bg-warning', 'chip' => 'bg-warning/15 text-warning'],
            'destructive' => ['name' => 'Red', 'dot' => 'bg-destructive', 'chip' => 'bg-destructive/15 text-destructive'],
            'info' => ['name' => 'Blue', 'dot' => 'bg-info', 'chip' => 'bg-info/15 text-info'],
            'muted' => ['name' => 'Grey', 'dot' => 'bg-muted-foreground', 'chip' => 'bg-accent/60 text-secondary-foreground'],
        ];
    }

    private function labels(): array
    {
        return [
            'copy' => ['name' => 'Copywriting', 'colour' => 'primary', 'cards' => 6],
            'outreach' => ['name' => 'Outreach', 'colour' => 'success', 'cards' => 9],
            'dev' => ['name' => 'Development', 'colour' => 'info', 'cards' => 14],
            'bug' => ['name' => 'Bug', 'colour' => 'destructive', 'cards' => 3],
            'finance' => ['name' => 'Finance', 'colour' => 'warning', 'cards' => 5],
            'admin' => ['name' => 'Admin', 'colour' => 'muted', 'cards' => 2],
        ];
    }

    private function members(): array
    {
        return [
            'nima' => ['name' => 'Nima Fazlipour', 'email' => 'nima@kargah.dev', 'initials' => 'NF', 'tone' => 'bg-primary/15 text-primary', 'role' => 'owner', 'joined' => 'Jan 2026'],
            'sara' => ['name' => 'Sara Rahimi', 'email' => 'sara@kargah.dev', 'initials' => 'SR', 'tone' => 'bg-success/15 text-success', 'role' => 'admin', 'joined' => 'Mar 2026'],
            'dan' => ['name' => 'Daniel Whitfield', 'email' => 'dan@northwind.co.uk', 'initials' => 'DW', 'tone' => 'bg-info/15 text-info', 'role' => 'member', 'joined' => 'May 2026'],
            'mina' => ['name' => 'Mina Karimi', 'email' => 'mina@acmestudio.com', 'initials' => 'MK', 'tone' => 'bg-warning/15 text-warning', 'role' => 'observer', 'joined' => 'Jun 2026'],
        ];
    }

    public function with(): array
    {
        return [
            'backgrounds' => $this->backgrounds(),
            'colours' => $this->colours(),
            'labels' => $this->labels(),
            'members' => $this->members(),
            'roleOptions' => [
                'owner' => 'Owner',
                'admin' => 'Admin',
                'member' => 'Member',
                'observer' => 'Observer',
            ],
        ];
    }

    /* Name and appearance -------------------------------------------------- */

    /** Rename the board. */
    public function renameBoard(): void
    {
        $this->validateOnly('name');

        // Backend: persist the new name and update the board slug.
    }

    public function selectBackground(string $key): void
    {
        $this->background = $key;

        // Backend: persist the chosen background.
    }

    /* Labels ---------------------------------------------------------------- */

    public function startEditLabel(string $key): void
    {
        $labels = $this->labels();

        $this->editingLabel = $key;
        $this->labelDraft = $labels[$key]['name'] ?? '';
        $this->labelColorDraft = $labels[$key]['colour'] ?? 'primary';
    }

    public function cancelEditLabel(): void
    {
        $this->editingLabel = null;
        $this->labelDraft = '';
    }

    /** Rename a label and change its colour. */
    public function saveLabel(string $key): void
    {
        // Backend: persist the label's name and colour.
        $this->editingLabel = null;
    }

    /** Remove a label from the board and from every card carrying it. */
    public function deleteLabel(string $key): void
    {
        // Backend: delete the label and detach it from its cards.
    }

    /** Add a label to the board. */
    public function createLabel(): void
    {
        // Backend: persist the label, then clear $newLabelName.
    }

    /* Members ---------------------------------------------------------------- */

    /** Fired when a member's role select changes. */
    public function updatedRoles(string $value, string $memberKey): void
    {
        // Backend: persist the new role for this member.
    }

    public function removeMember(string $memberKey): void
    {
        // Backend: revoke access and unassign the member from their cards.
    }

    /** Invite somebody to the board by email. */
    public function invite(): void
    {
        // Backend: create the invitation and send the email.
    }

    /* Danger zone ------------------------------------------------------------ */

    public function archiveBoard(): void
    {
        // Backend: archive the board, its lists and its cards.
    }

    public function confirmDelete(): void
    {
        $this->confirmingDelete = true;
        $this->deleteConfirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->deleteConfirmation = '';
    }

    /** Delete the board for good. */
    public function deleteBoard(): void
    {
        // Backend: delete the board and everything under it.
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Heading --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <a href="{{ route('projects.boards') }}" wire:navigate class="hover:text-primary">Boards</a>
                <i class="ki-filled ki-right text-[10px]"></i>
                <span class="text-secondary-foreground">{{ $name }}</span>
            </div>
            <h1 class="text-xl font-semibold text-mono mt-1">Board settings</h1>
            <p class="text-sm text-secondary-foreground mt-1">Set how this board looks and who is allowed to work on it.</p>
        </div>
        <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-arrow-left"></i> Back to the board
        </a>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 items-start">

        {{-- Rename --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h2 class="kt-card-title">Board name</h2>
            </div>
            <div class="kt-card-content flex flex-col gap-3 p-5">
                <div class="flex flex-col gap-1.5">
                    <label class="kt-form-label" for="board-name">Name</label>
                    <input id="board-name" type="text" class="kt-input @error('name') border-destructive @enderror"
                           wire:model="name" wire:keydown.enter.prevent="renameBoard">
                    @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    <span class="text-xs text-muted-foreground">Shown in the board switcher and on every card link.</span>
                </div>
                <div>
                    <button wire:click="renameBoard" wire:loading.attr="disabled" wire:target="renameBoard"
                            class="kt-btn kt-btn-primary gap-2">
                        <span wire:loading.remove wire:target="renameBoard">Save name</span>
                        <span wire:loading wire:target="renameBoard"><i class="ki-filled ki-loading animate-spin"></i> Saving…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Background --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h2 class="kt-card-title">Background</h2>
            </div>
            <div class="kt-card-content flex flex-col gap-4 p-5">
                <p class="text-sm text-secondary-foreground">The colour behind the lists. It also tints the board switcher.</p>

                <div class="grid grid-cols-3 sm:grid-cols-6 gap-3">
                    @foreach ($backgrounds as $key => $option)
                        <button wire:click="selectBackground('{{ $key }}')" wire:key="bg-{{ $key }}"
                                class="flex flex-col items-center gap-1.5 group"
                                aria-pressed="{{ $background === $key ? 'true' : 'false' }}"
                                title="{{ $option['name'] }}">
                            <span class="w-full h-12 rounded-lg {{ $option['swatch'] }} border-2 transition-colors
                                         {{ $background === $key ? 'border-mono' : 'border-transparent group-hover:border-border' }}"></span>
                            <span class="text-xs {{ $background === $key ? 'text-mono' : 'text-muted-foreground' }}">{{ $option['name'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="flex items-center gap-2 rounded-lg border border-dashed border-border px-4 py-3">
                    <i class="ki-filled ki-picture text-base text-muted-foreground"></i>
                    <span class="text-sm text-muted-foreground grow">Photo backgrounds arrive with the backend.</span>
                    <span class="kt-badge kt-badge-sm kt-badge-outline">Later</span>
                </div>
            </div>
        </div>

        {{-- Labels --}}
        <div class="kt-card xl:col-span-2">
            <div class="kt-card-header">
                <h2 class="kt-card-title">Labels</h2>
                <span class="text-xs text-muted-foreground">{{ count($labels) }} labels on this board</span>
            </div>
            <div class="kt-card-content flex flex-col gap-3 p-5">

                @forelse ($labels as $key => $label)
                    <div class="rounded-lg border border-border px-3 py-2.5" wire:key="label-{{ $key }}">
                        @if ($editingLabel === $key)
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="text" class="kt-input max-w-[240px]" aria-label="Label name"
                                       wire:model="labelDraft" wire:keydown.escape="cancelEditLabel"
                                       wire:keydown.enter.prevent="saveLabel('{{ $key }}')">

                                <div class="flex items-center gap-1.5">
                                    @foreach ($colours as $colourKey => $colour)
                                        <button wire:click="$set('labelColorDraft', '{{ $colourKey }}')"
                                                class="size-6 rounded-md {{ $colour['dot'] }} border-2 {{ $labelColorDraft === $colourKey ? 'border-mono' : 'border-transparent' }}"
                                                title="{{ $colour['name'] }}" aria-label="Use {{ $colour['name'] }}"></button>
                                    @endforeach
                                </div>

                                <div class="flex items-center gap-2 ms-auto">
                                    <button wire:click="saveLabel('{{ $key }}')" wire:loading.attr="disabled" wire:target="saveLabel"
                                            class="kt-btn kt-btn-sm kt-btn-primary">
                                        <span wire:loading.remove wire:target="saveLabel">Save</span>
                                        <span wire:loading wire:target="saveLabel"><i class="ki-filled ki-loading animate-spin"></i></span>
                                    </button>
                                    <button wire:click="cancelEditLabel" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                </div>
                            </div>
                        @else
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="text-xs font-medium px-2 py-1 rounded {{ $colours[$label['colour']]['chip'] }}">{{ $label['name'] }}</span>
                                <span class="text-xs text-muted-foreground">on {{ $label['cards'] }} {{ $label['cards'] === 1 ? 'card' : 'cards' }}</span>
                                <div class="flex items-center gap-1 ms-auto">
                                    <button wire:click="startEditLabel('{{ $key }}')" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                                        <i class="ki-filled ki-pencil text-sm"></i> Edit
                                    </button>
                                    <button wire:click="deleteLabel('{{ $key }}')" wire:loading.attr="disabled" wire:target="deleteLabel"
                                            class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1">
                                        <i class="ki-filled ki-trash text-sm"></i> Delete
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-8">
                        <i class="ki-filled ki-tag text-2xl text-muted-foreground"></i>
                        <p class="text-sm text-muted-foreground mt-2">No labels yet. Add the first one below.</p>
                    </div>
                @endforelse

                <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-border">
                    <input type="text" class="kt-input max-w-[240px]" placeholder="New label name"
                           aria-label="New label name" wire:model="newLabelName"
                           wire:keydown.enter.prevent="createLabel">
                    <div class="flex items-center gap-1.5">
                        @foreach ($colours as $colourKey => $colour)
                            <button wire:click="$set('newLabelColor', '{{ $colourKey }}')"
                                    class="size-6 rounded-md {{ $colour['dot'] }} border-2 {{ $newLabelColor === $colourKey ? 'border-mono' : 'border-transparent' }}"
                                    title="{{ $colour['name'] }}" aria-label="Use {{ $colour['name'] }}"></button>
                        @endforeach
                    </div>
                    <button wire:click="createLabel" wire:loading.attr="disabled" wire:target="createLabel"
                            class="kt-btn kt-btn-outline gap-2">
                        <span wire:loading.remove wire:target="createLabel" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-plus"></i> Add label
                        </span>
                        <span wire:loading wire:target="createLabel" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Adding…
                        </span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Members --}}
        <div class="kt-card xl:col-span-2">
            <div class="kt-card-header">
                <h2 class="kt-card-title">Members</h2>
                <span class="text-xs text-muted-foreground">{{ count($members) }} people can open this board</span>
            </div>

            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="min-w-[240px]">Person</th>
                                <th class="w-[220px]">Email</th>
                                <th class="w-[160px]">Role</th>
                                <th class="w-[120px]">Joined</th>
                                <th class="w-[100px] text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($members as $key => $member)
                                <tr wire:key="member-{{ $key }}">
                                    <td>
                                        <div class="flex items-center gap-2.5">
                                            <span class="size-8 rounded-full grid place-items-center text-[11px] font-semibold {{ $member['tone'] }}">
                                                {{ $member['initials'] }}
                                            </span>
                                            <span class="text-mono font-medium">{{ $member['name'] }}</span>
                                        </div>
                                    </td>
                                    <td class="text-secondary-foreground">{{ $member['email'] }}</td>
                                    <td>
                                        @if ($member['role'] === 'owner')
                                            <span class="kt-badge kt-badge-sm kt-badge-primary gap-1">
                                                <i class="ki-filled ki-crown text-xs"></i> Owner
                                            </span>
                                        @else
                                            <select class="kt-select" aria-label="Role for {{ $member['name'] }}"
                                                    wire:model.live="roles.{{ $key }}">
                                                @foreach ($roleOptions as $roleKey => $roleName)
                                                    @continue($roleKey === 'owner')
                                                    <option value="{{ $roleKey }}">{{ $roleName }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                    <td class="text-secondary-foreground">{{ $member['joined'] }}</td>
                                    <td class="text-end">
                                        @if ($member['role'] === 'owner')
                                            <span class="text-xs text-muted-foreground">—</span>
                                        @else
                                            <button wire:click="removeMember('{{ $key }}')" wire:loading.attr="disabled" wire:target="removeMember"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1">
                                                <i class="ki-filled ki-cross-circle text-sm"></i> Remove
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-10 text-secondary-foreground">
                                        Only you can open this board. Invite somebody below.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="kt-card-footer flex flex-wrap items-center gap-2 p-5">
                <div class="kt-input max-w-[280px]">
                    <i class="ki-filled ki-sms text-muted-foreground"></i>
                    <input type="email" placeholder="name@studio.com" aria-label="Email to invite" wire:model="inviteEmail">
                </div>
                <select class="kt-select max-w-[160px]" aria-label="Role for the invitation" wire:model="inviteRole">
                    @foreach ($roleOptions as $roleKey => $roleName)
                        @continue($roleKey === 'owner')
                        <option value="{{ $roleKey }}">{{ $roleName }}</option>
                    @endforeach
                </select>
                <button wire:click="invite" wire:loading.attr="disabled" wire:target="invite" class="kt-btn kt-btn-outline gap-2">
                    <span wire:loading.remove wire:target="invite" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-user-tick"></i> Send invitation
                    </span>
                    <span wire:loading wire:target="invite" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Sending…
                    </span>
                </button>
            </div>
        </div>

        {{-- Danger zone --}}
        <div class="kt-card xl:col-span-2 border-destructive/30">
            <div class="kt-card-header">
                <h2 class="kt-card-title text-destructive">Danger zone</h2>
            </div>
            <div class="kt-card-content flex flex-col gap-4 p-5">

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium text-mono">Archive this board</div>
                        <p class="text-xs text-muted-foreground mt-1">
                            It leaves the switcher but every card stays readable from the archive.
                        </p>
                    </div>
                    <button wire:click="archiveBoard" wire:loading.attr="disabled" wire:target="archiveBoard"
                            class="kt-btn kt-btn-outline gap-2">
                        <span wire:loading.remove wire:target="archiveBoard" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-archive"></i> Archive board
                        </span>
                        <span wire:loading wire:target="archiveBoard" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Archiving…
                        </span>
                    </button>
                </div>

                <div class="flex flex-wrap items-start justify-between gap-3 pt-4 border-t border-border">
                    <div>
                        <div class="text-sm font-medium text-mono">Delete this board</div>
                        <p class="text-xs text-muted-foreground mt-1">
                            Lists, cards, comments and attachments go with it. This cannot be undone.
                        </p>
                    </div>

                    @if ($confirmingDelete)
                        <div class="flex flex-col gap-2 w-full sm:w-auto">
                            <label class="kt-form-label text-xs" for="delete-confirm">Type <span class="text-mono">{{ $name }}</span> to confirm</label>
                            <div class="flex items-center gap-2">
                                <input id="delete-confirm" type="text" class="kt-input max-w-[220px]"
                                       wire:model.live="deleteConfirmation" wire:keydown.escape="cancelDelete">
                                <button wire:click="deleteBoard" wire:loading.attr="disabled" wire:target="deleteBoard"
                                        class="kt-btn kt-btn-destructive gap-2" @disabled($deleteConfirmation !== $name)>
                                    <span wire:loading.remove wire:target="deleteBoard">Delete for good</span>
                                    <span wire:loading wire:target="deleteBoard"><i class="ki-filled ki-loading animate-spin"></i> Deleting…</span>
                                </button>
                                <button wire:click="cancelDelete" class="kt-btn kt-btn-ghost">Cancel</button>
                            </div>
                        </div>
                    @else
                        <button wire:click="confirmDelete" class="kt-btn kt-btn-destructive gap-2">
                            <i class="ki-filled ki-trash"></i> Delete board
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
