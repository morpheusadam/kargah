<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Support\Palette;

/**
 * One board, counted.
 *
 * **Per-list counts are placements; every other count is cards.** A card
 * mirrored onto two lists of this board genuinely sits in both, so "cards in
 * this list" counts the placement — the mirror inflates its *list's* bar by
 * one, honestly, because it really is sitting there too. But "how many cards
 * are overdue", "how many carry Bea", "how many are tagged Bug" are facts
 * about the *card*, and `Board::cards()` is already deduplicated for exactly
 * this reason — counting a mirrored card's due date twice would report more
 * overdue work than actually exists. `CardPlacement::onCanvas()` is what
 * "cards in this list" already means on the board canvas, so the per-list
 * bar uses it rather than reinventing the archived-mirror rule.
 *
 * **ApexCharts is loaded from `@script` on this page alone**, guarded with
 * `chart.destroy()` — never a `data-*` flag, which the morph clears on every
 * render. Loading it from the layout added 563 KB to every page in this
 * project; see docs/frontend-conventions.md.
 */
new
#[Title('Dashboard — Kargah')]
class extends Component
{
    #[Url(as: 'board')]
    public string $activeBoard = '';

    public bool $boardPickerOpen = false;

    /**
     * Literal hex values, not `Palette` keys: ApexCharts paints with real
     * colours, not Tailwind classes, and `Palette` deliberately holds only
     * the latter — see its own docblock on why a class is never built by
     * concatenation. Kept here, local to the one page that needs them,
     * rather than adding a second, chart-flavoured colour map to a file this
     * task does not own.
     */
    private const STATE_COLOURS = [
        'overdue' => '#ec4899',
        'due' => '#ef4444',
        'soon' => '#f59e0b',
        'later' => '#94a3b8',
        'done' => '#22c55e',
        'none' => '#64748b',
    ];

    private const STATE_LABELS = [
        'overdue' => 'Overdue',
        'due' => 'Due today',
        'soon' => 'Due tomorrow',
        'later' => 'Later',
        'done' => 'Complete',
        'none' => 'No due date',
    ];

    private ?Board $resolvedBoard = null;

    private ?Collection $resolvedBoards = null;

    public function mount(): void
    {
        $this->activeBoard = $this->resolveBoard($this->activeBoard);
    }

    private function resolveBoard(string $slug): string
    {
        $slugs = $this->allBoards()->pluck('slug');

        return $slugs->contains($slug) ? $slug : (string) $slugs->first();
    }

    private function allBoards(): Collection
    {
        return $this->resolvedBoards ??= Board::query()->active()->orderBy('position')->orderBy('name')->get();
    }

    private function board(): ?Board
    {
        return $this->resolvedBoard ??= $this->allBoards()->firstWhere('slug', $this->activeBoard);
    }

    /** @return Collection<int, BoardList> */
    private function lists(): Collection
    {
        $board = $this->board();

        return $board === null
            ? collect()
            : BoardList::query()->where('board_id', $board->id)->active()->orderBy('position')->get(['id', 'name']);
    }

    /**
     * Placements per list — one row per card genuinely sitting there,
     * mirrors included, exactly what the board canvas draws.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function perList(): array
    {
        $lists = $this->lists();

        if ($lists->isEmpty()) {
            return [];
        }

        $counts = CardPlacement::query()
            ->onCanvas()
            ->whereIn('board_list_id', $lists->pluck('id'))
            ->selectRaw('board_list_id, count(*) as total')
            ->groupBy('board_list_id')
            ->pluck('total', 'board_list_id');

        return $lists->map(fn (BoardList $list): array => [
            'label' => $list->name,
            'count' => (int) ($counts[$list->id] ?? 0),
        ])->all();
    }

    /** @return Collection<int, Card> */
    private function activeCards(): Collection
    {
        $board = $this->board();

        return $board === null
            ? collect()
            : $board->cards()->active()->get(['id', 'title', 'due_on', 'completed_at']);
    }

    /**
     * Every active card, once, bucketed by `dueState()` — the same method the
     * board's own due-date badge reads, so this page cannot disagree with
     * the board about what "overdue" means.
     *
     * @return array<string, int>
     */
    private function perDueState(): array
    {
        $buckets = array_fill_keys(array_keys(self::STATE_LABELS), 0);

        foreach ($this->activeCards() as $card) {
            $state = $card->dueState() ?? 'none';
            $buckets[$state] = ($buckets[$state] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Members on this board's active cards, each counted once per card they
     * carry — not once per placement, for the same reason the due-date
     * buckets are not.
     *
     * @return Collection<int, object{id: int, name: string, total: int}>
     */
    private function perMember(): Collection
    {
        $cardIds = $this->activeCards()->pluck('id');

        if ($cardIds->isEmpty()) {
            return collect();
        }

        return DB::table('card_members')
            ->join('users', 'users.id', '=', 'card_members.user_id')
            ->whereIn('card_members.card_id', $cardIds)
            ->select('users.id', 'users.name', DB::raw('count(*) as total'))
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->get();
    }

    /** @return Collection<int, object{id: int, name: string, colour: string, total: int}> */
    private function perLabel(): Collection
    {
        $cardIds = $this->activeCards()->pluck('id');

        if ($cardIds->isEmpty()) {
            return collect();
        }

        return DB::table('card_label')
            ->join('labels', 'labels.id', '=', 'card_label.label_id')
            ->whereIn('card_label.card_id', $cardIds)
            ->select('labels.id', 'labels.name', 'labels.colour', DB::raw('count(*) as total'))
            ->groupBy('labels.id', 'labels.name', 'labels.colour')
            ->orderByDesc('total')
            ->get();
    }

    public function with(): array
    {
        $perList = $this->perList();
        $perDueState = $this->perDueState();
        $perMember = $this->perMember();
        $perLabel = $this->perLabel();

        $maxMember = $perMember->max('total') ?: 1;
        $maxLabel = $perLabel->max('total') ?: 1;

        return [
            'boards' => $this->allBoards(),
            'totalCards' => $this->activeCards()->count(),
            'totalPlacements' => array_sum(array_column($perList, 'count')),
            'listChart' => [
                'categories' => array_column($perList, 'label'),
                'series' => array_column($perList, 'count'),
            ],
            'dueChart' => [
                'labels' => array_map(fn (string $state) => self::STATE_LABELS[$state], array_keys($perDueState)),
                'series' => array_values($perDueState),
                'colours' => array_map(fn (string $state) => self::STATE_COLOURS[$state], array_keys($perDueState)),
            ],
            'perMember' => $perMember,
            'maxMember' => $maxMember,
            'perLabel' => $perLabel,
            'maxLabel' => $maxLabel,
        ];
    }

    public function boardName(): string
    {
        return $this->board()?->name ?? 'Board';
    }

    public function selectBoard(string $slug): void
    {
        $this->activeBoard = $this->resolveBoard($slug);
        $this->resolvedBoard = null;
        $this->boardPickerOpen = false;
    }

    public function toggleBoardPicker(): void
    {
        $this->boardPickerOpen = ! $this->boardPickerOpen;
    }

    public function dismissPanels(): void
    {
        $this->boardPickerOpen = false;
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($boardPickerOpen)
        <div class="fixed inset-0 z-10" wire:click="dismissPanels" aria-hidden="true"></div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            <div>
                <h1 class="text-xl font-semibold text-mono">{{ $this->boardName() }} — dashboard</h1>
                <p class="text-sm text-secondary-foreground mt-1">
                    {{ $totalCards }} active {{ str('card')->plural($totalCards) }}, {{ $totalPlacements }} placed across its lists.
                </p>
            </div>

            <div class="relative">
                <button wire:click="toggleBoardPicker" class="kt-btn kt-btn-outline gap-2">
                    <i class="ki-filled ki-down text-xs"></i> Switch board
                </button>
                <div class="kt-dropdown absolute z-20 mt-1 w-[220px] {{ $boardPickerOpen ? 'open' : '' }}">
                    <div class="p-2 flex flex-col gap-1">
                        @forelse ($boards as $b)
                            <button wire:click="selectBoard('{{ $b->slug }}')" wire:key="dash-pick-{{ $b->id }}"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full {{ $b->slug === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full {{ $b->dotClass() }}"></span>
                                {{ $b->name }}
                            </button>
                        @empty
                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">No boards yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Views. ⚡boards.blade.php needs the matching switcher added by whoever owns it. --}}
        <div class="flex items-center gap-1">
            <a href="{{ route('projects.boards', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-row-horizontal text-sm"></i> Board
            </a>
            <a href="{{ route('projects.table', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-row-vertical text-sm"></i> Table
            </a>
            <a href="{{ route('projects.calendar', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-calendar text-sm"></i> Calendar
            </a>
            <span class="kt-btn kt-btn-sm kt-btn-primary gap-1.5">
                <i class="ki-filled ki-chart-simple text-sm"></i> Dashboard
            </span>
            <a href="{{ route('projects.activity', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-time text-sm"></i> Activity
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Cards per list</h3>
            </div>
            <div class="kt-card-content p-4">
                @if (array_sum($listChart['series']) > 0)
                    <div data-dashboard-list-chart
                         data-categories="{{ json_encode($listChart['categories']) }}"
                         data-series="{{ json_encode($listChart['series']) }}"></div>
                @else
                    <div class="flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-row-horizontal text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">No lists to count yet.</p>
                    </div>
                @endif
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Cards by due date</h3>
            </div>
            <div class="kt-card-content p-4">
                @if (array_sum($dueChart['series']) > 0)
                    <div data-dashboard-due-chart
                         data-labels="{{ json_encode($dueChart['labels']) }}"
                         data-series="{{ json_encode($dueChart['series']) }}"
                         data-colours="{{ json_encode($dueChart['colours']) }}"></div>
                @else
                    <div class="flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-calendar text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">No cards on this board yet.</p>
                    </div>
                @endif
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Cards per member</h3>
            </div>
            <div class="kt-card-content p-4 flex flex-col gap-3">
                @forelse ($perMember as $row)
                    <div class="flex items-center gap-3">
                        <span class="size-7 rounded-full grid place-items-center text-[10px] font-semibold bg-primary/15 text-primary shrink-0">
                            {{ collect(explode(' ', $row->name))->map(fn ($p) => mb_substr($p, 0, 1))->join('') }}
                        </span>
                        <span class="text-sm text-secondary-foreground w-[120px] truncate">{{ $row->name }}</span>
                        <div class="grow h-2 rounded-full bg-muted overflow-hidden">
                            <div class="h-full bg-primary rounded-full" style="width: {{ (int) round($row->total / $maxMember * 100) }}%"></div>
                        </div>
                        <span class="text-xs text-muted-foreground w-[24px] text-end">{{ $row->total }}</span>
                    </div>
                @empty
                    <div class="flex flex-col items-center py-8 text-center">
                        <i class="ki-filled ki-profile-circle text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">Nobody is carrying a card here yet.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Cards per label</h3>
            </div>
            <div class="kt-card-content p-4 flex flex-col gap-3">
                @forelse ($perLabel as $row)
                    <div class="flex items-center gap-3">
                        <span class="size-3 rounded-sm shrink-0 {{ \Modules\Project\Support\Palette::dot($row->colour) }}"></span>
                        <span class="text-sm text-secondary-foreground w-[120px] truncate">{{ $row->name }}</span>
                        <div class="grow h-2 rounded-full bg-muted overflow-hidden">
                            <div class="h-full rounded-full {{ \Modules\Project\Support\Palette::dot($row->colour) }}" style="width: {{ (int) round($row->total / $maxLabel * 100) }}%"></div>
                        </div>
                        <span class="text-xs text-muted-foreground w-[24px] text-end">{{ $row->total }}</span>
                    </div>
                @empty
                    <div class="flex flex-col items-center py-8 text-center">
                        <i class="ki-filled ki-tag text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">No labels on this board's cards yet.</p>
                    </div>
                @endforelse
            </div>
        </div>

    </div>

{{--
    Kept inside the component's root element on purpose — see the note on
    every other page in this module for why.
--}}
@script
<script src="/assets/vendors/apexcharts/apexcharts.min.js"></script>
<script>
(function () {
    function readJson(el, key, fallback) {
        try {
            return JSON.parse(el.dataset[key] || 'null') ?? fallback;
        } catch (e) {
            return fallback;
        }
    }

    function mount() {
        if (! $wire.$el || ! $wire.$el.isConnected) return;
        if (typeof ApexCharts === 'undefined') return;

        var listEl = $wire.$el.querySelector('[data-dashboard-list-chart]');

        if (listEl) {
            // Ask the instance, never a data-* flag: the morph clears any
            // attribute the incoming HTML does not carry.
            if (listEl._chart) {
                listEl._chart.destroy();
                listEl._chart = null;
            }

            var listChart = new ApexCharts(listEl, {
                chart: { type: 'bar', height: 280, toolbar: { show: false } },
                series: [{ name: 'Cards', data: readJson(listEl, 'series', []) }],
                xaxis: { categories: readJson(listEl, 'categories', []) },
                plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
                dataLabels: { enabled: false },
                colors: ['#3b82f6'],
            });

            listChart.render();
            listEl._chart = listChart;
        }

        var dueEl = $wire.$el.querySelector('[data-dashboard-due-chart]');

        if (dueEl) {
            if (dueEl._chart) {
                dueEl._chart.destroy();
                dueEl._chart = null;
            }

            var dueChart = new ApexCharts(dueEl, {
                chart: { type: 'donut', height: 280 },
                series: readJson(dueEl, 'series', []),
                labels: readJson(dueEl, 'labels', []),
                colors: readJson(dueEl, 'colours', []),
                legend: { position: 'bottom' },
                dataLabels: { enabled: false },
            });

            dueChart.render();
            dueEl._chart = dueChart;
        }
    }

    Livewire.hook('morphed', mount);
    mount();
})();
</script>
@endscript
</div>
