<?php

use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Board template picker.
 *
 * Nested inside the board page and opened by the "New board" button, which
 * dispatches `open-board-templates`. Each template shows the lists it would
 * create and the first cards it seeds them with, so the choice is made on
 * what the board will look like rather than on its name.
 */
new
class extends Component
{
    public bool $open = false;

    public string $template = 'client-project';

    #[Validate('required|min:2|max:40')]
    public string $name = '';

    private function templates(): array
    {
        return [
            'client-project' => [
                'name' => 'Client project',
                'icon' => 'ki-briefcase',
                'tone' => 'bg-primary/15 text-primary',
                'summary' => 'Take one client from the first brief through to a paid invoice.',
                'lists' => [
                    ['name' => 'Brief', 'cards' => ['Kick-off call notes', 'Success criteria']],
                    ['name' => 'Scope & quote', 'cards' => ['Estimate the build', 'Send the quote']],
                    ['name' => 'In progress', 'cards' => ['Set up the repository']],
                    ['name' => 'Client review', 'cards' => []],
                    ['name' => 'Invoiced', 'cards' => []],
                    ['name' => 'Done', 'cards' => []],
                ],
            ],
            'job-hunt' => [
                'name' => 'Job hunt',
                'icon' => 'ki-profile-circle',
                'tone' => 'bg-success/15 text-success',
                'summary' => 'Track every application so no follow-up is missed.',
                'lists' => [
                    ['name' => 'Leads', 'cards' => ['Studios hiring contract developers', 'Referrals to chase']],
                    ['name' => 'Applied', 'cards' => ['Tailor the CV per role']],
                    ['name' => 'Interviewing', 'cards' => []],
                    ['name' => 'Offer', 'cards' => []],
                    ['name' => 'Closed', 'cards' => []],
                ],
            ],
            'content-pipeline' => [
                'name' => 'Content pipeline',
                'icon' => 'ki-note-2',
                'tone' => 'bg-info/15 text-info',
                'summary' => 'Move posts from a rough idea to something published.',
                'lists' => [
                    ['name' => 'Ideas', 'cards' => ['What clients ask before they hire', 'Invoicing without a limited company']],
                    ['name' => 'Drafting', 'cards' => ['Case study: the Northwind migration']],
                    ['name' => 'Editing', 'cards' => []],
                    ['name' => 'Scheduled', 'cards' => []],
                    ['name' => 'Published', 'cards' => []],
                ],
            ],
            'blank' => [
                'name' => 'Blank board',
                'icon' => 'ki-element-plus',
                'tone' => 'bg-accent/60 text-secondary-foreground',
                'summary' => 'Start with nothing and add the lists as you go.',
                'lists' => [],
            ],
        ];
    }

    public function with(): array
    {
        $templates = $this->templates();

        return [
            'templates' => $templates,
            'selected' => $templates[$this->template] ?? $templates['blank'],
        ];
    }

    #[On('open-board-templates')]
    public function openPicker(): void
    {
        $this->open = true;
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function selectTemplate(string $key): void
    {
        $this->template = $key;
    }

    /** Create the board from the chosen template. */
    public function createBoard(): void
    {
        $this->validate();

        // Backend: create the board, its lists and the seed cards, then redirect to it.
    }
};

?>

<div class="kt-modal bg-black/50 z-50 {{ $open ? 'open' : '' }}"
     role="dialog" aria-modal="true" aria-labelledby="board-templates-title" tabindex="-1"
     wire:click.self="close" wire:keydown.escape="close">

    <div class="kt-modal-content max-w-[900px] w-full">

        <div class="kt-modal-header">
            <div>
                <h2 class="kt-modal-title" id="board-templates-title">New board</h2>
                <p class="text-xs text-muted-foreground mt-1">Pick a starting point. Every list can be renamed afterwards.</p>
            </div>
            <button wire:click="close" class="kt-btn kt-btn-icon kt-btn-ghost size-8"
                    title="Close" aria-label="Close the template picker">
                <i class="ki-filled ki-cross text-sm"></i>
            </button>
        </div>

        <div class="kt-modal-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                {{-- Templates --}}
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="new-board-name">Board name</label>
                        <input id="new-board-name" type="text" class="kt-input @error('name') border-destructive @enderror"
                               placeholder="e.g. Bluepeak booking widget" wire:model="name">
                        @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide mt-1">Template</span>

                    @foreach ($templates as $key => $item)
                        <button wire:click="selectTemplate('{{ $key }}')" wire:key="template-{{ $key }}"
                                class="flex items-start gap-3 rounded-lg border px-3 py-3 text-start transition-colors
                                       {{ $template === $key ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}"
                                aria-pressed="{{ $template === $key ? 'true' : 'false' }}">
                            <span class="size-9 rounded-md grid place-items-center shrink-0 {{ $item['tone'] }}">
                                <i class="ki-filled {{ $item['icon'] }} text-base"></i>
                            </span>
                            <span class="min-w-0 grow">
                                <span class="block text-sm font-medium text-mono">{{ $item['name'] }}</span>
                                <span class="block text-xs text-muted-foreground mt-0.5">{{ $item['summary'] }}</span>
                            </span>
                            @if ($template === $key)
                                <i class="ki-filled ki-check-circle text-base text-primary shrink-0"></i>
                            @endif
                        </button>
                    @endforeach
                </div>

                {{-- Preview --}}
                <div class="flex flex-col gap-2">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Preview</span>

                    <div class="rounded-lg border border-border bg-muted/30 p-3 h-full">
                        <div class="flex gap-2 overflow-x-auto kt-scrollable-x pb-2">
                            @forelse ($selected['lists'] as $list)
                                <div class="w-[150px] shrink-0 rounded-md bg-background border border-border p-2">
                                    <div class="text-xs font-semibold text-mono mb-2">{{ $list['name'] }}</div>
                                    <div class="flex flex-col gap-1.5">
                                        @forelse ($list['cards'] as $card)
                                            <div class="rounded border border-border bg-muted/40 px-2 py-1.5 text-[11px] text-secondary-foreground leading-snug">
                                                {{ $card }}
                                            </div>
                                        @empty
                                            <div class="rounded border border-dashed border-border px-2 py-3 text-[11px] text-muted-foreground text-center">
                                                Empty
                                            </div>
                                        @endforelse
                                    </div>
                                </div>
                            @empty
                                <div class="w-full py-10 text-center">
                                    <i class="ki-filled ki-element-plus text-2xl text-muted-foreground"></i>
                                    <p class="text-sm text-muted-foreground mt-2">No lists. You will add the first one on the board.</p>
                                </div>
                            @endforelse
                        </div>

                        <p class="text-xs text-muted-foreground mt-2">
                            {{ count($selected['lists']) }} {{ count($selected['lists']) === 1 ? 'list' : 'lists' }} will be created.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="kt-modal-footer">
            <span class="text-xs text-muted-foreground">You can change the background and labels in board settings.</span>
            <div class="flex items-center gap-2">
                <button wire:click="close" class="kt-btn kt-btn-outline">Cancel</button>
                <button wire:click="createBoard" wire:loading.attr="disabled" wire:target="createBoard"
                        class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="createBoard" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-plus"></i> Create board
                    </span>
                    <span wire:loading wire:target="createBoard" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Creating…
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
