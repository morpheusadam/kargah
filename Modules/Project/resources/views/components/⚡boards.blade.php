<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Trello-style board.
 *
 * Frontend phase: lists and cards come from a static fixture so the interaction
 * model (drag between lists, reorder within a list) can be built and reviewed
 * before any persistence exists. The `moveCard` action is already the exact
 * signature the backend will implement — only its body changes later.
 */
new
#[Title('Boards — Kargah')]
class extends Component
{
    public string $activeBoard = 'client-work';

    public function with(): array
    {
        return [
            'boards' => [
                ['key' => 'client-work', 'name' => 'Client Work',  'color' => 'bg-primary'],
                ['key' => 'outreach',    'name' => 'Outreach',     'color' => 'bg-success'],
                ['key' => 'personal',    'name' => 'Personal',     'color' => 'bg-warning'],
            ],
            'lists' => [
                [
                    'id' => 'backlog', 'name' => 'Backlog',
                    'cards' => [
                        ['id' => 1, 'title' => 'Rewrite portfolio landing copy', 'labels' => [['name' => 'copy', 'class' => 'bg-primary/15 text-primary']], 'checklist' => [0, 4], 'due' => null, 'comments' => 2],
                        ['id' => 2, 'title' => 'Collect testimonials from past clients', 'labels' => [], 'checklist' => [1, 3], 'due' => 'Aug 12', 'comments' => 0],
                    ],
                ],
                [
                    'id' => 'todo', 'name' => 'To Do',
                    'cards' => [
                        ['id' => 3, 'title' => 'Send resume to 20 agencies', 'labels' => [['name' => 'outreach', 'class' => 'bg-success/15 text-success']], 'checklist' => [5, 20], 'due' => 'Aug 05', 'comments' => 1],
                        ['id' => 4, 'title' => 'Fix invoice PDF margins', 'labels' => [['name' => 'bug', 'class' => 'bg-destructive/15 text-destructive']], 'checklist' => [0, 0], 'due' => null, 'comments' => 0],
                    ],
                ],
                [
                    'id' => 'doing', 'name' => 'In Progress',
                    'cards' => [
                        ['id' => 5, 'title' => 'Build Kargah mail module', 'labels' => [['name' => 'dev', 'class' => 'bg-info/15 text-info']], 'checklist' => [3, 9], 'due' => 'Aug 20', 'comments' => 4],
                    ],
                ],
                [
                    'id' => 'review', 'name' => 'Review',
                    'cards' => [
                        ['id' => 6, 'title' => 'Q3 expense reconciliation', 'labels' => [['name' => 'finance', 'class' => 'bg-warning/15 text-warning']], 'checklist' => [8, 8], 'due' => 'Aug 01', 'comments' => 0],
                    ],
                ],
                [
                    'id' => 'done', 'name' => 'Done',
                    'cards' => [
                        ['id' => 7, 'title' => 'Register kargah.dev domain', 'labels' => [], 'checklist' => [0, 0], 'due' => null, 'comments' => 0],
                    ],
                ],
            ],
        ];
    }

    /**
     * Called by Sortable when a card is dropped.
     * Backend phase fills this in; the contract stays the same.
     */
    public function moveCard(int $cardId, string $toList, int $position): void
    {
        // no-op during the frontend phase
    }

    public function selectBoard(string $key): void
    {
        $this->activeBoard = $key;
    }
};

?>

<div class="flex flex-col gap-5 h-full" wire:ignore.self>

    {{-- Board toolbar --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold text-mono">Boards</h1>
            <div class="kt-dropdown" data-kt-dropdown="true" data-kt-dropdown-trigger="click" data-kt-dropdown-placement="bottom-start">
                <button class="kt-btn kt-btn-outline gap-2" data-kt-dropdown-toggle="true">
                    @foreach ($boards as $b)
                        @if ($b['key'] === $activeBoard)
                            <span class="size-2.5 rounded-full {{ $b['color'] }}"></span>
                            {{ $b['name'] }}
                        @endif
                    @endforeach
                    <i class="ki-filled ki-down text-xs"></i>
                </button>
                <div class="kt-dropdown-content w-[220px]" data-kt-dropdown-content="true">
                    <div class="p-2 flex flex-col gap-1">
                        @foreach ($boards as $b)
                            <button wire:click="selectBoard('{{ $b['key'] }}')"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 {{ $b['key'] === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full {{ $b['color'] }}"></span>
                                {{ $b['name'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <div class="kt-input max-w-[220px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search cards…">
            </div>
            <button class="kt-btn kt-btn-outline kt-btn-icon" title="Filter">
                <i class="ki-filled ki-filter"></i>
            </button>
            <button class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New board
            </button>
        </div>
    </div>

    {{-- The board --}}
    <div class="flex gap-4 overflow-x-auto pb-4 kt-scrollable-x items-start" id="kargah-board">

        @foreach ($lists as $list)
            <div class="kt-card w-[290px] shrink-0 bg-muted/40" data-list-id="{{ $list['id'] }}">

                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-mono">{{ $list['name'] }}</h3>
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ count($list['cards']) }}</span>
                    </div>
                    <button class="kt-btn kt-btn-icon kt-btn-ghost size-7">
                        <i class="ki-filled ki-dots-horizontal text-sm"></i>
                    </button>
                </div>

                <div class="kargah-list flex flex-col gap-2 px-3 pb-3 min-h-[60px]" data-list="{{ $list['id'] }}">
                    @foreach ($list['cards'] as $card)
                        <div class="kt-card bg-background border border-border rounded-lg p-3 cursor-grab hover:border-primary/40 transition-colors active:cursor-grabbing"
                             data-card-id="{{ $card['id'] }}">

                            @if (count($card['labels']))
                                <div class="flex flex-wrap gap-1 mb-2">
                                    @foreach ($card['labels'] as $label)
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded {{ $label['class'] }}">{{ $label['name'] }}</span>
                                    @endforeach
                                </div>
                            @endif

                            <p class="text-sm text-mono leading-snug">{{ $card['title'] }}</p>

                            @if ($card['checklist'][1] > 0 || $card['due'] || $card['comments'] > 0)
                                <div class="flex items-center gap-3 mt-2.5 text-xs text-muted-foreground">
                                    @if ($card['checklist'][1] > 0)
                                        <span class="inline-flex items-center gap-1 {{ $card['checklist'][0] === $card['checklist'][1] ? 'text-success' : '' }}">
                                            <i class="ki-filled ki-check-squared text-sm"></i>
                                            {{ $card['checklist'][0] }}/{{ $card['checklist'][1] }}
                                        </span>
                                    @endif
                                    @if ($card['due'])
                                        <span class="inline-flex items-center gap-1">
                                            <i class="ki-filled ki-calendar text-sm"></i>{{ $card['due'] }}
                                        </span>
                                    @endif
                                    @if ($card['comments'] > 0)
                                        <span class="inline-flex items-center gap-1">
                                            <i class="ki-filled ki-message-text-2 text-sm"></i>{{ $card['comments'] }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <button class="kt-btn kt-btn-ghost w-full justify-start gap-2 text-sm text-secondary-foreground px-4 py-2.5 rounded-b-lg">
                    <i class="ki-filled ki-plus text-sm"></i> Add a card
                </button>
            </div>
        @endforeach

        {{-- Add list --}}
        <button class="kt-card w-[290px] shrink-0 bg-muted/20 border border-dashed border-border hover:border-primary/50 transition-colors">
            <span class="flex items-center gap-2 px-4 py-4 text-sm text-secondary-foreground">
                <i class="ki-filled ki-plus"></i> Add another list
            </span>
        </button>
    </div>
</div>

@push('scripts')
<script>
    (function initBoard() {
        function mount() {
            if (typeof Sortable === 'undefined') return;

            document.querySelectorAll('.kargah-list').forEach(function (el) {
                if (el.dataset.sortableMounted) return;
                el.dataset.sortableMounted = '1';

                new Sortable(el, {
                    group: 'kargah-cards',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    dragClass: 'rotate-2',
                    onEnd: function (evt) {
                        const cardId = parseInt(evt.item.dataset.cardId, 10);
                        const toList = evt.to.dataset.list;
                        const position = evt.newIndex;

                        if (window.Livewire) {
                            Livewire.dispatch('card-moved', { cardId, toList, position });
                        }
                    },
                });
            });
        }

        document.addEventListener('DOMContentLoaded', mount);
        document.addEventListener('livewire:navigated', mount);
        if (window.Livewire) Livewire.hook('morph.updated', mount);
        mount();
    })();
</script>
@endpush
