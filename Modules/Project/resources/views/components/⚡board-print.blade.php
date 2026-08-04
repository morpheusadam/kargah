<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;

/**
 * One board, on paper.
 *
 * The cheap half of the spec's print row: a page written for ink rather than a
 * server-side PDF. It renders into `project::layouts.print` — its own bare
 * layout — because the app shell is a sidebar, a header and a theme that pins
 * the document dark, and none of those belong on a sheet of paper. The
 * `@media print` block below is the second line of defence, for anything that
 * leaks through anyway.
 *
 * **One entry per placement, exactly as the board draws it.** A card mirrored
 * onto two lists of this board prints under both, because that is what a
 * person reading the board sees; `CardPlacement::onCanvas()` is what decides
 * which placements count, so an archived card leaves the list it lives in and
 * stays on its mirrors, marked. The same rule `⚡table.blade.php` and
 * `BoardExportController` follow.
 *
 * The board comes from `?board=<slug>` like every other view in this module,
 * rather than a path segment, so the toolbar link is the same shape as the
 * table, calendar and dashboard ones.
 *
 * No Tailwind. Everything here is styled by the sheet in the template, which
 * is the one thing about this page that has to survive a stylesheet rebuild
 * and a theme change untouched.
 */
new
#[Title('Print — Kargah')]
#[Layout('project::layouts.print')]
class extends Component
{
    #[Url(as: 'board')]
    public string $activeBoard = '';

    /** Per-request memos; see ⚡boards.blade.php for why these are private. */
    private ?Board $resolvedBoard = null;

    private ?Collection $resolvedBoards = null;

    private ?Collection $resolvedLists = null;

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

    /**
     * The board's active lists, each with the placements the canvas draws and
     * everything a printed card needs: its labels, its members, and the two
     * checklist tallies as counts rather than a load of every item on the
     * board. Three queries for the whole document, not one per card.
     *
     * @return Collection<int, BoardList>
     */
    private function lists(): Collection
    {
        if ($this->resolvedLists !== null) {
            return $this->resolvedLists;
        }

        $board = $this->board();

        if ($board === null) {
            return $this->resolvedLists = collect();
        }

        return $this->resolvedLists = BoardList::query()
            ->where('board_id', $board->id)
            ->active()
            ->orderBy('position')
            ->with(['placements' => fn ($placements) => $placements
                ->onCanvas()
                ->with(['card' => fn ($card) => $card
                    ->with(['labels', 'members'])
                    ->withCount([
                        'comments',
                        'checklistItems as checklist_total',
                        'checklistItems as checklist_done' => fn ($items) => $items->where('is_done', true),
                    ]),
                ]),
            ])
            ->get();
    }

    public function with(): array
    {
        $lists = $this->lists();

        return [
            'board' => $this->board(),
            'lists' => $lists,
            'placementCount' => $lists->sum(fn (BoardList $list): int => $list->placements->count()),
        ];
    }
};

?>

<div class="kargah-print">

    <style>
        .kargah-print {
            max-width: 920px;
            margin: 0 auto;
            padding: 24px 20px 48px;
            color: #000;
            background: #fff;
            font-size: 13px;
            line-height: 1.45;
            /* Paper has no horizontal scrollbar. A card title with a long
               unbroken token in it — a URL, a branch name, a file path — runs
               off the right margin and is simply not printed, so every box on
               this page is told to break inside a word rather than overflow. */
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        /* A description is user markdown: it can contain an image, a fenced
           code block or a table, none of which know how wide the paper is. */
        .kargah-print img {
            max-width: 100%;
            height: auto;
        }

        .kargah-print pre {
            white-space: pre-wrap;
            word-break: break-all;
        }

        .kargah-print table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .kargah-print h1,
        .kargah-print h2,
        .kargah-print h3 {
            margin: 0;
            font-weight: 600;
        }

        .kargah-print-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 18px;
        }

        .kargah-print-head h1 {
            font-size: 20px;
        }

        .kargah-print-meta {
            margin: 4px 0 0;
            font-size: 11px;
            color: #444;
        }

        .kargah-print-description {
            margin: 8px 0 0;
            font-size: 12px;
            color: #222;
            max-width: 60ch;
        }

        .kargah-print-button {
            flex: none;
            border: 1px solid #000;
            background: #fff;
            color: #000;
            border-radius: 4px;
            padding: 6px 14px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .kargah-print-list {
            border: 1px solid #000;
            border-radius: 4px;
            margin-bottom: 14px;
            /* A list is the unit a reader follows down a column, so it is the
               unit that should not be torn across two sheets. A list longer
               than a page still breaks — nothing can stop that — and the rule
               is simply ignored for it. */
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .kargah-print-list-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 10px;
            border-bottom: 1px solid #000;
        }

        .kargah-print-list-head h2 {
            font-size: 14px;
            /* A flex item will not shrink below its content unless told to, so
               without this a long list name pushes the count off the sheet. */
            min-width: 0;
        }

        .kargah-print-count {
            flex: none;
            white-space: nowrap;
            font-size: 11px;
            color: #444;
        }

        .kargah-print-card {
            padding: 8px 10px;
            border-top: 1px solid #bbb;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .kargah-print-card:first-child {
            border-top: 0;
        }

        .kargah-print-card-title {
            font-size: 13px;
            font-weight: 600;
        }

        .kargah-print-number {
            font-weight: 400;
            color: #555;
            margin-right: 6px;
        }

        .kargah-print-facts {
            margin: 4px 0 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 14px;
            font-size: 11px;
            color: #333;
        }

        .kargah-print-facts b {
            font-weight: 600;
            color: #000;
        }

        .kargah-print-tag {
            display: inline-block;
            border: 1px solid #666;
            border-radius: 3px;
            padding: 0 5px;
            margin-right: 4px;
            font-size: 10px;
            line-height: 1.6;
        }

        .kargah-print-card-body {
            margin: 6px 0 0;
            font-size: 12px;
            color: #222;
        }

        .kargah-print-card-body p {
            margin: 0 0 4px;
        }

        .kargah-print-card-body :last-child {
            margin-bottom: 0;
        }

        .kargah-print-empty {
            padding: 10px;
            font-size: 12px;
            color: #555;
        }

        @media print {
            /* The button is the one thing on this page that cannot be printed
               usefully, and the shell selectors are for a leak: if this page is
               ever rendered into the app layout by accident, the sidebar and
               header still stay off the paper. */
            .kargah-print-button,
            .kt-sidebar,
            .kt-header,
            [data-kt-drawer],
            .kt-toast-container {
                display: none !important;
            }

            .kargah-print {
                max-width: none;
                padding: 0;
            }

            a {
                color: #000;
                text-decoration: none;
            }
        }
    </style>

    <header class="kargah-print-head">
        <div>
            <h1>{{ $board?->name ?? 'No board' }}</h1>
            <p class="kargah-print-meta">
                {{ $placementCount }} {{ Str::plural('card', $placementCount) }}
                across {{ $lists->count() }} {{ Str::plural('list', $lists->count()) }} —
                printed {{ now()->format('j M Y') }}.
                A card mirrored onto two lists is printed under both.
            </p>
            @if (filled($board?->description))
                <p class="kargah-print-description">{{ $board->description }}</p>
            @endif
        </div>

        <button type="button" class="kargah-print-button" id="kargah-print-button">Print</button>
    </header>

    @forelse ($lists as $list)
        <section class="kargah-print-list" wire:key="print-list-{{ $list->id }}">
            <div class="kargah-print-list-head">
                <h2>{{ $list->name }}</h2>
                <span class="kargah-print-count">
                    {{ $list->placements->count() }} {{ Str::plural('card', $list->placements->count()) }}
                </span>
            </div>

            @forelse ($list->placements as $placement)
                @php($card = $placement->card)
                <article class="kargah-print-card" wire:key="print-card-{{ $placement->id }}">
                    <div class="kargah-print-card-title">
                        @if ($card->number !== null)
                            <span class="kargah-print-number">#{{ $card->number }}</span>
                        @endif
                        {{ $card->title }}
                        @if ($placement->isMirror())
                            <span class="kargah-print-tag">Mirror</span>
                        @endif
                        @if ($card->isArchived())
                            <span class="kargah-print-tag">Archived</span>
                        @endif
                        @if ($card->isComplete())
                            <span class="kargah-print-tag">Complete</span>
                        @endif
                    </div>

                    <ul class="kargah-print-facts">
                        @if ($card->start_on !== null)
                            <li><b>Starts</b> {{ $card->start_on->format('j M Y') }}</li>
                        @endif
                        @if ($card->due_on !== null)
                            <li><b>Due</b> {{ $card->due_on->format('j M Y') }}</li>
                        @endif
                        @if ($card->members->isNotEmpty())
                            <li><b>Members</b> {{ $card->members->pluck('name')->implode(', ') }}</li>
                        @endif
                        @if ($card->labels->isNotEmpty())
                            <li>
                                <b>Labels</b>
                                @foreach ($card->labels as $label)
                                    <span class="kargah-print-tag">{{ $label->name }}</span>
                                @endforeach
                            </li>
                        @endif
                        @if ((int) $card->checklist_total > 0)
                            <li><b>Checklist</b> {{ (int) $card->checklist_done }}/{{ (int) $card->checklist_total }}</li>
                        @endif
                        @if ((int) $card->comments_count > 0)
                            <li><b>Comments</b> {{ (int) $card->comments_count }}</li>
                        @endif
                    </ul>

                    @if (filled($card->description))
                        <div class="kargah-print-card-body">{!! \Modules\Project\Support\Markdown::toHtml($card->description) !!}</div>
                    @endif
                </article>
            @empty
                <p class="kargah-print-empty">Nothing in this list.</p>
            @endforelse
        </section>
    @empty
        <p class="kargah-print-empty">
            {{ $board === null ? 'No boards yet.' : 'This board has no lists yet.' }}
        </p>
    @endforelse

    @script
        <script>
            document.getElementById('kargah-print-button')?.addEventListener('click', function () {
                window.print();
            });
        </script>
    @endscript
</div>
