<?php

namespace Modules\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Project\Models\Board;
use Modules\Project\Models\CardPlacement;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One board, dumped — as CSV for a spreadsheet, or as JSON for anything else.
 *
 * Behind `auth`, unlike `CalendarFeedController`: an export is the whole board
 * including every description and comment count, so it is only ever for
 * somebody already signed in. There is no token and no signed URL, because
 * there is nothing here for software that has never seen a session.
 *
 * **One row per placement, not per card.** A card mirrored onto two lists of
 * this board genuinely sits in both, and the "List" column has to be able to
 * say which one a given row is talking about — the same rule `⚡table.blade.php`
 * documents for itself. `CardPlacement::onCanvas()` decides which placements
 * count, so the export agrees with what the board actually draws: an archived
 * card leaves the list it lives in and stays on its mirrors. Those mirrors
 * carry an `Archived` column so a reader is not left guessing.
 *
 * **Archived lists are left out**, because the canvas does not draw them
 * either — `BoardList::active()` is what every other board view filters on.
 *
 * **Nothing is loaded twice and nothing is counted in PHP.** Labels, members
 * and the two checklist tallies ride the same eager load the board canvas
 * uses; the comment count is a subquery, not a load of every comment on the
 * board.
 *
 * The CSV streams. Rows are read in id chunks and written straight to
 * `php://output`, so a board with ten thousand placements never builds a
 * single string in memory. The JSON nests the same fields under their list and
 * is assembled in full before it is written — it has to be, since a nested
 * document is not a sequence of independent lines — which is the one place
 * this holds the export in memory, and a few megabytes for a board nobody
 * could read in a day.
 */
class BoardExportController extends Controller
{
    /** Rows re-read per query. Big enough that the round trips are few, small enough that memory is flat. */
    private const CHUNK = 250;

    /**
     * The columns, in order: the row key, then the heading a spreadsheet shows.
     * The JSON uses the same keys, minus the two the nesting already says.
     */
    private const COLUMNS = [
        'number' => 'Number',
        'title' => 'Title',
        'list' => 'List',
        'board' => 'Board',
        'members' => 'Members',
        'labels' => 'Labels',
        'due_on' => 'Due date',
        'start_on' => 'Start date',
        'completed' => 'Completed',
        'archived' => 'Archived',
        'description' => 'Description',
        'checklist_done' => 'Checklist done',
        'checklist_total' => 'Checklist total',
        'comments' => 'Comments',
        'created_at' => 'Created at',
    ];

    /** The route constrains `format` to `csv|json`; anything else never reaches here. */
    public function __invoke(Board $board, string $format = 'csv'): StreamedResponse
    {
        return $format === 'json' ? $this->json($board) : $this->csv($board);
    }

    private function csv(Board $board): StreamedResponse
    {
        return response()->streamDownload(function () use ($board): void {
            $handle = fopen('php://output', 'wb');

            // A UTF-8 byte-order mark. Excel reads a BOM-less UTF-8 CSV as the
            // system codepage, which turns every non-ASCII board name and card
            // title into mojibake on the one program most people open a CSV in.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_values(self::COLUMNS));

            $this->eachPlacement($board, function (CardPlacement $placement) use ($handle, $board): void {
                fputcsv($handle, $this->flatten($this->row($placement, $board)));
            });

            fclose($handle);
        }, $this->filename($board, 'csv'), [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    private function json(Board $board): StreamedResponse
    {
        $lists = [];

        $this->eachPlacement($board, function (CardPlacement $placement) use (&$lists, $board): void {
            $listId = (int) $placement->board_list_id;

            $lists[$listId] ??= [
                'id' => $listId,
                'name' => $placement->list?->name,
                'cards' => [],
            ];

            $card = $this->row($placement, $board);

            // The nesting already says which board and which list.
            unset($card['board'], $card['list']);

            $lists[$listId]['cards'][] = $card;
        });

        $document = [
            'board' => [
                'name' => $board->name,
                'slug' => $board->slug,
                'description' => $board->description,
            ],
            'exported_at' => now()->toIso8601String(),
            'lists' => array_values($lists),
        ];

        return response()->streamDownload(function () use ($document): void {
            echo json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }, $this->filename($board, 'json'), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /**
     * Every placement this board draws, in board order — list by position,
     * then card by its position in that list — handed over one at a time.
     *
     * The ids come first, on their own, because the order has to survive the
     * chunking and `chunkById()` would reorder by primary key to get it. Ints
     * are cheap to hold; the rows they name are not, so those are re-read a
     * chunk at a time and sorted back into the order the ids were in.
     */
    private function eachPlacement(Board $board, callable $callback): void
    {
        $ids = CardPlacement::query()
            ->onCanvas()
            ->join('board_lists', 'board_lists.id', '=', 'card_placements.board_list_id')
            ->where('board_lists.board_id', $board->id)
            ->whereNull('board_lists.deleted_at')
            ->whereNull('board_lists.archived_at')
            ->orderBy('board_lists.position')
            ->orderBy('card_placements.position')
            ->orderBy('card_placements.id')
            ->pluck('card_placements.id')
            ->all();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $order = array_flip($chunk);

            CardPlacement::query()
                ->whereIn('card_placements.id', $chunk)
                ->with([
                    'list',
                    'card' => fn ($card) => $card
                        ->with(['labels', 'members'])
                        ->withCount([
                            'comments',
                            'checklistItems as checklist_total',
                            'checklistItems as checklist_done' => fn ($items) => $items->where('is_done', true),
                        ]),
                ])
                ->get()
                ->sortBy(fn (CardPlacement $placement): int => $order[$placement->id])
                // A card soft-deleted between the two queries leaves a
                // placement with nothing to describe. Skipped rather than
                // exported as a row of empty columns.
                ->filter(fn (CardPlacement $placement): bool => $placement->card !== null)
                ->each($callback);
        }
    }

    /**
     * One placement, as the export sees it. Native types throughout — dates as
     * ISO strings, counts as ints, flags as booleans, members and labels as
     * lists of names — so the JSON needs no formatting decision and the CSV
     * makes its own in one place, `flatten()`.
     */
    private function row(CardPlacement $placement, Board $board): array
    {
        $card = $placement->card;

        return [
            'number' => $card->number === null ? null : (int) $card->number,
            'title' => $card->title,
            'list' => $placement->list?->name,
            'board' => $board->name,
            'members' => $card->members->pluck('name')->values()->all(),
            'labels' => $card->labels->pluck('name')->values()->all(),
            'due_on' => $card->due_on?->toDateString(),
            'start_on' => $card->start_on?->toDateString(),
            'completed' => $card->isComplete(),
            'archived' => $card->isArchived(),
            'description' => $card->description,
            'checklist_done' => (int) ($card->checklist_done ?? 0),
            'checklist_total' => (int) ($card->checklist_total ?? 0),
            'comments' => (int) ($card->comments_count ?? 0),
            'created_at' => $card->created_at?->toIso8601String(),
        ];
    }

    /** The same row, as a spreadsheet cell can hold it: one flat string each, in column order. */
    private function flatten(array $row): array
    {
        $cells = [];

        foreach (array_keys(self::COLUMNS) as $key) {
            $value = $row[$key] ?? null;

            $cells[] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'yes' : 'no',
                is_array($value) => implode('; ', $value),
                default => (string) $value,
            };
        }

        return $cells;
    }

    private function filename(Board $board, string $extension): string
    {
        return $board->slug.'-'.now()->format('Y-m-d').'.'.$extension;
    }
}
