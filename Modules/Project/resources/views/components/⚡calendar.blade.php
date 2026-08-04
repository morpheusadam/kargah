<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Models\Board;
use Modules\Project\Models\Card;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Services\BoardCalendar;
use Modules\Project\Support\Palette;

/**
 * One board's cards, on a calendar.
 *
 * **One event per card, distinct, never per placement.** `Board::cards()` is
 * already deduplicated for exactly this reason — a card mirrored onto two
 * lists has one due date, and drawing it twice would make dragging it
 * ambiguous: which placement's copy did the click move? The same
 * deduplication is why the `.ics` feed built from `BoardCalendar` agrees with
 * what this page shows.
 *
 * **`start_on` and `due_on` are both dates on the card now**, so a card with
 * both draws as a bar spanning the two; a card with only one draws as a
 * single day. Dragging a bar moves the whole range by the number of days it
 * was dropped; there is no edge-resize here, only the move the brief asks
 * for.
 *
 * **Advanced checklist items are drawn here too.** An item can carry a due
 * date of its own, and 06 says those belong on the calendar. They are a second
 * source of events rather than a second calendar: one month grid, with item
 * events carrying an `item-` prefixed id so a drag can tell which table it is
 * about to write to. The prefix is load-bearing — an item id and a card id
 * collide numerically, and `parseInt` on a bare id would reschedule the wrong
 * row. `BoardCalendar` puts the same items on the `.ics` feed, so the page and
 * a subscriber's client agree.
 *
 * **The drag has no local echo to protect.** Every request from this
 * component re-renders the whole page — there are no islands here — so
 * `mount()` destroys and rebuilds FullCalendar from the fresh `data-events`
 * on every `morphed` hook, success or failure alike. A failed reschedule
 * therefore corrects itself the same way a successful one confirms itself:
 * by redrawing from what the database actually holds, not by asking the
 * calendar to undo a guess.
 */
new
#[Title('Calendar — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url(as: 'board')]
    public string $activeBoard = '';

    public bool $boardPickerOpen = false;

    private ?Board $resolvedBoard = null;

    private ?Collection $resolvedBoards = null;

    private ?Collection $resolvedCards = null;

    private ?Collection $resolvedItems = null;

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

    /** Every active, dated card on the board — distinct by card, never by placement. */
    private function datedCards(): Collection
    {
        if ($this->resolvedCards !== null) {
            return $this->resolvedCards;
        }

        $board = $this->board();

        if ($board === null) {
            return $this->resolvedCards = collect();
        }

        return $this->resolvedCards = $board->cards()
            ->active()
            ->where(fn ($q) => $q->whereNotNull('due_on')->orWhereNotNull('start_on'))
            ->orderByRaw('COALESCE(start_on, due_on)')
            ->get(['id', 'title', 'start_on', 'due_on', 'completed_at']);
    }

    /**
     * Every dated, unticked checklist item on an active card on this board.
     *
     * A ticked item is left off for the same reason a completed card keeps its
     * green badge but an archived one disappears: the calendar is what is still
     * owed, and a done item is not.
     */
    private function datedItems(): Collection
    {
        if ($this->resolvedItems !== null) {
            return $this->resolvedItems;
        }

        $board = $this->board();

        if ($board === null) {
            return $this->resolvedItems = collect();
        }

        return $this->resolvedItems = ChecklistItem::query()
            ->whereNotNull('due_on')
            ->where('is_done', false)
            ->whereIn('checklist_id', fn ($q) => $q
                ->select('id')
                ->from('checklists')
                ->whereIn('card_id', $board->cards()->active()->select('cards.id')))
            ->with('checklist:id,card_id')
            ->orderBy('due_on')
            ->get();
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return [...$this->cardEvents(), ...$this->itemEvents()];
    }

    /**
     * An item event is a single day, never a bar: an item has one date, and
     * dragging it moves that one date.
     *
     * @return list<array<string, mixed>>
     */
    private function itemEvents(): array
    {
        return $this->datedItems()->map(fn (ChecklistItem $item): array => [
            // `item-` rather than a bare id. A card and an item can share the
            // number 7, and the drag handler has to know which one it holds.
            'id' => 'item-'.$item->id,
            'title' => '☑ '.$item->text,
            'start' => $item->due_on->toDateString(),
            'end' => null,
            'allDay' => true,
            'backgroundColor' => 'transparent',
            // `currentColor`, not `transparent`. FullCalendar writes
            // `border-color` as an *inline* style on the event anchor, which
            // beats any class — so the dashed border that is supposed to be
            // the whole difference between an item and a card was being drawn
            // in transparent, and the two kinds looked identical apart from
            // the tick in the title. `currentColor` resolves against the tone
            // class's own `text-*`, so the outline arrives in the same colour
            // the item's date already reads in.
            'borderColor' => 'currentColor',
            'textColor' => 'inherit',
            'classNames' => [
                ...explode(' ', Palette::tone($item->dueBadgeColour() ?? 'neutral')),
                'rounded', 'px-1', 'border', 'border-dashed',
            ],
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function cardEvents(): array
    {
        return $this->datedCards()->map(function (Card $card): array {
            $start = $card->start_on ?? $card->due_on;
            $end = $card->due_on !== null && $card->start_on !== null
                ? $card->due_on->copy()->addDay()
                : null;

            return [
                'id' => (string) $card->id,
                'title' => $card->title,
                'start' => $start->toDateString(),
                'end' => $end?->toDateString(),
                'allDay' => true,
                'backgroundColor' => 'transparent',
                'borderColor' => 'transparent',
                'textColor' => 'inherit',
                'classNames' => [
                    ...explode(' ', Palette::tone($card->dueBadgeColour() ?? 'neutral')),
                    'rounded', 'px-1',
                ],
            ];
        })->values()->all();
    }

    /**
     * Whether the subscription link is currently on screen.
     *
     * It starts hidden and this never survives a navigation, so the link is on
     * the page only for as long as somebody is looking at it.
     */
    public bool $feedRevealed = false;

    public function with(): array
    {
        $board = $this->board();

        return [
            'boards' => $this->allBoards(),
            'events' => $this->events(),
            'upcoming' => $this->datedCards()->take(8),
            // Computed only once asked for. Rendering it unconditionally put a
            // bearer capability into the page on every visit — see revealFeedLink().
            'icsUrl' => $board !== null && $this->feedRevealed
                ? app(BoardCalendar::class)->feedUrl($board)
                : null,
        ];
    }

    /**
     * Put the subscription link on screen, once, deliberately.
     *
     * The link is a **bearer capability**: anyone holding it can read this
     * board's card titles and due dates without signing in, which is the whole
     * point — a calendar client cannot log in. That also makes it a secret, and
     * `tests/Feature/NoSecretsInHtmlTest.php` caught it being rendered into
     * every visit to this page, which is exactly what that test is for.
     *
     * So it behaves like the vault: hidden until asked for, and the asking is
     * on the record. Google Calendar hides its own iCal address behind the same
     * gesture for the same reason.
     */
    public function revealFeedLink(): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('No board is open', 'Pick a board first.');

            return;
        }

        $this->feedRevealed = true;

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.feed_link_revealed')
            ->log('revealed the calendar subscription link');
    }

    public function hideFeedLink(): void
    {
        $this->feedRevealed = false;
    }

    public function boardName(): string
    {
        return $this->board()?->name ?? 'Board';
    }

    public function selectBoard(string $slug): void
    {
        $this->activeBoard = $this->resolveBoard($slug);
        $this->resolvedBoard = null;
        $this->resolvedCards = null;
        $this->resolvedItems = null;
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

    /**
     * Move a card's dates by the number of days it was dropped, whole range
     * together. No toast on success — the bar is already sitting in its new
     * spot on screen. A failure toasts, because that is not otherwise visible.
     */
    public function reschedule(int $cardId, int $deltaDays): void
    {
        if ($deltaDays === 0) {
            return;
        }

        $board = $this->board();
        $card = $board?->cards()->active()->whereKey($cardId)->first();

        if ($card === null) {
            $this->toastError('That card is gone', 'It was deleted, archived, or moved off this board while the page was open.');

            return;
        }

        $card->update([
            'start_on' => $card->start_on?->copy()->addDays($deltaDays),
            'due_on' => $card->due_on?->copy()->addDays($deltaDays),
        ]);

        $this->resolvedCards = null;
    }

    /**
     * Move one checklist item's own due date. Same shape as `reschedule()`
     * above, against `checklist_items` rather than `cards`, and scoped through
     * the board so an id typed into the wire cannot reach another board's work.
     */
    public function rescheduleItem(int $itemId, int $deltaDays): void
    {
        if ($deltaDays === 0) {
            return;
        }

        $item = $this->datedItems()->firstWhere('id', $itemId);

        if ($item === null) {
            $this->toastError('That item is gone', 'It was deleted, ticked, or moved off this board while the page was open.');

            return;
        }

        $item->update(['due_on' => $item->due_on->copy()->addDays($deltaDays)]);

        $this->resolvedItems = null;
    }

    /** Invalidate every ICS URL issued before now. A real write: it toasts. */
    public function regenerateFeedLink(): void
    {
        $board = $this->board();

        if ($board === null) {
            return;
        }

        app(BoardCalendar::class)->regenerateToken($board);

        $this->toastSuccess(
            'Calendar link regenerated',
            'Anyone using the old link stops receiving updates. Share the new one with whoever needs it.',
        );
    }

    public function openCard(int $cardId): void
    {
        $this->dispatch('open-card', cardId: $cardId);
    }

    /**
     * The copy itself happens in JS (see `window.kargahCopy`, `@script`
     * below) — this is only the confirmation. A clipboard copy has no other
     * visible result, so it is the one write-adjacent action on this page
     * that does toast.
     */
    public function confirmLinkCopied(): void
    {
        $this->toastSuccess('Calendar link copied', 'Paste it into whatever app is asking to subscribe.');
    }

    #[On('card-changed')]
    public function cardChanged(): void
    {
        $this->resolvedCards = null;
        $this->resolvedItems = null;
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
                <h1 class="text-xl font-semibold text-mono">{{ $this->boardName() }} — calendar</h1>
                <p class="text-sm text-secondary-foreground mt-1">Start and due dates, in one month. Drag a card to reschedule it.</p>
            </div>

            {{-- Escape on the wrapper, so the keystroke lands while the trigger still holds focus. --}}
            <div class="relative" wire:keydown.escape="dismissPanels">
                <button wire:click="toggleBoardPicker" class="kt-btn kt-btn-outline gap-2"
                        aria-haspopup="true" aria-expanded="{{ $boardPickerOpen ? 'true' : 'false' }}">
                    <i class="ki-filled ki-down text-xs"></i> Switch board
                </button>
                <div class="kt-dropdown absolute z-20 mt-1 w-[220px] {{ $boardPickerOpen ? 'open' : '' }}">
                    <div class="p-2 flex flex-col gap-1">
                        @forelse ($boards as $b)
                            {{-- `min-w-0` plus a `truncate` span: `kt-btn` is nowrap and does not clip. --}}
                            <button wire:click="selectBoard('{{ $b->slug }}')" wire:key="cal-pick-{{ $b->id }}"
                                    wire:loading.attr="disabled" wire:target="selectBoard"
                                    title="{{ $b->name }}"
                                    class="kt-btn kt-btn-ghost justify-start gap-2 w-full min-w-0 {{ $b->slug === $activeBoard ? 'bg-accent/60' : '' }}">
                                <span class="size-2.5 rounded-full shrink-0 {{ $b->dotClass() }}"></span>
                                <span class="truncate">{{ $b->name }}</span>
                            </button>
                        @empty
                            <p class="text-xs text-muted-foreground px-2 py-3 text-center">No boards yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Views. Five pills since Activity landed — `flex-wrap` so they fold rather than overflow a narrow window. --}}
        <div class="flex flex-wrap items-center justify-end gap-1">
            <a href="{{ route('projects.boards', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-row-horizontal text-sm"></i> Board
            </a>
            <a href="{{ route('projects.table', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-row-vertical text-sm"></i> Table
            </a>
            <span class="kt-btn kt-btn-sm kt-btn-primary gap-1.5">
                <i class="ki-filled ki-calendar text-sm"></i> Calendar
            </span>
            <a href="{{ route('projects.dashboard', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-chart-simple text-sm"></i> Dashboard
            </a>
            <a href="{{ route('projects.activity', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                <i class="ki-filled ki-time text-sm"></i> Activity
            </a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 xl:col-span-8">
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Month</h3>
                    <span class="text-xs text-muted-foreground">{{ count($events) }} dated</span>
                </div>
                <div class="kt-card-content p-4">
                    <div id="project-calendar" class="kt-scrollable-x-auto" data-project-calendar
                         data-events="{{ json_encode($events) }}"></div>

                    {{-- Shown until the calendar bundle takes over, and if it never loads --}}
                    <div data-project-calendar-fallback class="flex flex-col divide-y divide-border">
                        @forelse ($upcoming as $card)
                            <button wire:click="openCard({{ $card->id }})" wire:key="fallback-{{ $card->id }}"
                                    class="flex items-center gap-3 py-2.5 text-start hover:bg-accent/30 transition-colors">
                                <span class="size-2 rounded-full shrink-0 {{ \Modules\Project\Support\Palette::dot($card->dueBadgeColour() ?? 'neutral') }}"></span>
                                <span class="text-sm text-mono grow min-w-0 truncate">{{ $card->title }}</span>
                                <span class="text-xs text-muted-foreground shrink-0">
                                    {{ $card->start_on?->format('j M') }}{{ $card->start_on && $card->due_on ? ' – ' : '' }}{{ $card->due_on?->format('j M Y') }}
                                </span>
                            </button>
                        @empty
                            <div class="flex flex-col items-center py-14 text-center">
                                <i class="ki-filled ki-calendar text-4xl text-muted-foreground mb-3"></i>
                                <p class="text-sm text-secondary-foreground">Nothing dated on this board.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12 xl:col-span-4 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Subscribe</h3>
                </div>
                <div class="kt-card-content p-4 flex flex-col gap-3">
                    <p class="text-xs text-muted-foreground">
                        Paste this into Google Calendar, Apple Calendar or Thunderbird as a subscription. It carries
                        this board's name and, for every active card with a due date, the card's title and due date —
                        nothing else. No session, no cookie, no login: the link itself is what proves it is allowed.
                    </p>
                    {{--
                        The link is hidden until asked for. Anyone holding it can
                        read this board without signing in, so it is a secret
                        that happens to be a URL — and a secret rendered on every
                        visit ends up in browser history, caches and screenshots.
                    --}}
                    @if ($icsUrl)
                        <div class="kt-input">
                            <input type="text" class="grow" readonly value="{{ $icsUrl }}" aria-label="Calendar subscription link">
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" data-copy-text="{{ $icsUrl }}" wire:click="confirmLinkCopied"
                                    class="kt-btn kt-btn-sm kt-btn-outline gap-1.5">
                                <i class="ki-filled ki-copy text-sm"></i> Copy link
                            </button>
                            <button wire:click="hideFeedLink" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                                <i class="ki-filled ki-eye-slash text-sm"></i> Hide
                            </button>
                            <button wire:click="regenerateFeedLink" wire:confirm="Anyone using the current link stops getting updates. Regenerate it?"
                                    class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                                <i class="ki-filled ki-arrows-circle text-sm"></i> Regenerate
                            </button>
                        </div>
                    @elseif ($this->board() !== null)
                        <div class="kt-input">
                            <input type="text" class="grow" readonly value="••••••••••••••••••••••••••••"
                                   aria-label="Calendar subscription link, hidden">
                        </div>
                        <button wire:click="revealFeedLink" class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 self-start">
                            <i class="ki-filled ki-eye text-sm"></i> Show link
                        </button>
                    @else
                        <p class="text-xs text-muted-foreground">Pick a board to get its link.</p>
                    @endif
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Coming up</h3>
                    <a href="{{ route('projects.table', ['board' => $activeBoard]) }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Table</a>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($upcoming as $card)
                        <button wire:click="openCard({{ $card->id }})" wire:key="upcoming-{{ $card->id }}"
                                class="flex items-start gap-3 px-4 py-3 w-full text-start hover:bg-accent/30 transition-colors">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                <i class="ki-filled ki-calendar text-base text-muted-foreground"></i>
                            </span>
                            <div class="min-w-0 grow">
                                <div class="text-sm font-medium text-mono truncate">{{ $card->title }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ $card->start_on?->format('j M') }}{{ $card->start_on && $card->due_on ? ' – ' : '' }}{{ $card->due_on?->format('j M Y') }}
                                </div>
                            </div>
                        </button>
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-time text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Nothing dated yet.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>

    </div>

    <livewire:project::card-detail />

{{--
    Kept inside the component's root element on purpose. Livewire renders one
    root node and discards everything after it, so a @push below the closing
    tag never reaches the page.
--}}
@assets
<script src="/assets/vendors/fullcalendar/index.global.min.js"></script>
@endassets
@script
<script>
(function () {
    if (! window.kargahCopy) {
        window.kargahCopy = function (text) {
            if (! text) return;

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text);
                return;
            }

            var field = document.createElement('textarea');
            field.value = text;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            document.execCommand('copy');
            document.body.removeChild(field);
        };
    }

    function mount() {
        // A closure left behind by a wire:navigate must not touch the page
        // that replaced it.
        if (! $wire.$el || ! $wire.$el.isConnected) return;

        $wire.$el.querySelectorAll('[data-copy-text]').forEach(function (button) {
            if (button.onclick) return;

            button.onclick = function () {
                window.kargahCopy(button.getAttribute('data-copy-text'));
            };
        });

        var el = $wire.$el.querySelector('[data-project-calendar]');

        if (! el) return;

        // No bundle, no calendar — leave the plain list in place.
        if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar !== 'function') return;

        // Ask the library, never a data-* flag: the morph removes any
        // attribute the incoming HTML does not carry, so a flag would clear
        // itself on every render and leave a second instance on the node.
        //
        // The month being looked at is the one thing worth carrying across the
        // rebuild. Every request here re-renders the page, and a fresh
        // calendar starts on today — so dragging a card in September, or
        // simply opening a card from "Coming up", used to snap the grid back
        // to the current month and lose the reader's place. Ask the outgoing
        // instance where it was before destroying it.
        var startOn = null;
        var startView = null;

        if (el._projectCalendar) {
            startOn = el._projectCalendar.getDate();
            startView = el._projectCalendar.view.type;
            el._projectCalendar.destroy();
            el._projectCalendar = null;
        }

        var events = [];

        try {
            events = JSON.parse(el.dataset.events || '[]');
        } catch (e) {
            events = [];
        }

        var calendar = new FullCalendar.Calendar(el, {
            initialView: startView || 'dayGridMonth',
            initialDate: startOn || undefined,
            height: 640,
            firstDay: 1,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            buttonText: { today: 'Today', month: 'Month', list: 'List' },
            events: events,
            editable: true,
            eventStartEditable: true,
            eventDurationEditable: false,
            dayMaxEventRows: 3,
            eventDisplay: 'block',
            eventDrop: function (info) {
                var days = info.delta && typeof info.delta.days === 'number' ? info.delta.days : 0;

                if (! days) return;

                // A card and a checklist item can share an id, so the prefix
                // decides the table, not the number. Without it parseInt would
                // happily move card 7 when item 7 was dragged.
                var id = String(info.event.id || '');

                if (id.indexOf('item-') === 0) {
                    $wire.rescheduleItem(parseInt(id.slice(5), 10), days);

                    return;
                }

                $wire.reschedule(parseInt(id, 10), days);
            },
        });

        calendar.render();

        el._projectCalendar = calendar;

        var fallback = $wire.$el.querySelector('[data-project-calendar-fallback]');

        if (fallback) fallback.classList.add('hidden');
    }

    Livewire.hook('morphed', mount);
    mount();
})();
</script>
@endscript
</div>
