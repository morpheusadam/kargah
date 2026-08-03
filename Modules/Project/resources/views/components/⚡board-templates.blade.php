<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\Label;
use Modules\Project\Support\Palette;
use Modules\Project\Support\Position;

/**
 * Board template picker.
 *
 * Nested inside the board page and opened by the "New board" button, which
 * dispatches `open-board-templates`. Each template shows the lists it would
 * create and the first cards it seeds them with, so the choice is made on
 * what the board will look like rather than on its name.
 *
 * The four templates below are configuration, not a fixture: they are the
 * definition of what `createBoard()` writes, and the preview is a rendering of
 * that same definition rather than a second copy of it.
 *
 * Two things are worth knowing before changing anything.
 *
 * **The slug is unique in the schema, including for soft-deleted rows.** Two
 * boards called "Client work" are a perfectly reasonable thing to want, so the
 * slug is suffixed until it is free rather than the second create failing.
 *
 * **The toast is flashed, not dispatched.** This method redirects, and a
 * dispatched browser event dies with the page that would have shown it.
 */
new
class extends Component
{
    use InteractsWithToasts;

    public bool $open = false;

    public string $template = 'client-project';

    #[Validate('required|min:2|max:40')]
    public string $name = '';

    /**
     * `colour` is a palette key, never a class string — the board stores the
     * key and `Palette` resolves it, so the Tailwind scanner can see every
     * class name in one file.
     */
    private function templates(): array
    {
        return [
            'client-project' => [
                'name' => 'Client project',
                'icon' => 'ki-briefcase',
                'colour' => 'primary',
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
                'colour' => 'success',
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
                'colour' => 'info',
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
                'colour' => 'neutral',
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

    /**
     * A slug nothing else has taken.
     *
     * `withTrashed()` on purpose: a soft-deleted board still holds its slug in
     * a unique index, so ignoring it turns a delete-and-recreate into a
     * constraint violation the user cannot do anything about.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'board';
        $slug = $base;
        $suffix = 2;

        while (Board::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * The default label set on a new board.
     *
     * One label per palette colour, named after the colour, because a board
     * nobody has set up yet is more useful with six colours to reach for than
     * with six guesses at what the work is called.
     */
    private function seedLabels(Board $board): void
    {
        foreach (Palette::keys() as $position => $colour) {
            Label::query()->create([
                'board_id' => $board->id,
                'name' => Palette::name($colour),
                'colour' => $colour,
                'position' => $position,
            ]);
        }
    }

    /** Create the board, its lists and its seed cards, then go and look at it. */
    public function createBoard(): void
    {
        $this->validate();

        $templates = $this->templates();
        $key = array_key_exists($this->template, $templates) ? $this->template : 'blank';
        $template = $templates[$key];
        $name = trim($this->name);

        $board = DB::transaction(function () use ($template, $name): Board {
            $board = Board::query()->create([
                'slug' => $this->uniqueSlug($name),
                'name' => $name,
                'colour' => $template['colour'],
                'description' => $template['summary'],
                'company_id' => null,
                'position' => (int) Board::query()->max('position') + 1,
                'created_by' => auth()->id(),
            ]);

            $this->seedLabels($board);

            // Spread rather than counted up by hand: `position` is a decimal
            // column and every value that goes near it comes from `Position`.
            $listPositions = Position::spread(count($template['lists']));

            foreach ($template['lists'] as $index => $list) {
                $row = BoardList::query()->create([
                    'board_id' => $board->id,
                    'name' => $list['name'],
                    'position' => $listPositions[$index],
                    'created_by' => auth()->id(),
                ]);

                $cardPositions = Position::spread(count($list['cards']));

                foreach ($list['cards'] as $i => $title) {
                    $card = Card::query()->create([
                        'title' => $title,
                        'created_by' => auth()->id(),
                    ]);

                    // Where the card sits is a row of its own now, and every
                    // card has exactly one that says where it lives.
                    CardPlacement::query()->create([
                        'card_id' => $card->id,
                        'board_list_id' => $row->id,
                        'position' => $cardPositions[$i],
                        'is_origin' => true,
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            return $board;
        });

        $lists = count($template['lists']);
        $cards = array_sum(array_map(fn (array $list): int => count($list['cards']), $template['lists']));

        $this->open = false;
        $this->name = '';
        $this->template = 'client-project';

        // Flashed, not dispatched: a browser event does not survive the
        // redirect that is about to replace the page.
        $this->flashToast(
            'success',
            $board->name.' created',
            $lists === 0
                ? 'It has no lists yet, so start by adding the first one.'
                : $lists.' '.str('list')->plural($lists).' and '.$cards.' '.str('card')->plural($cards).' are waiting on it.',
        );

        $this->redirect(route('projects.boards', ['board' => $board->slug]), navigate: true);
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
                            <span class="size-9 rounded-md grid place-items-center shrink-0 {{ \Modules\Project\Support\Palette::tone($item['colour']) }}">
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
