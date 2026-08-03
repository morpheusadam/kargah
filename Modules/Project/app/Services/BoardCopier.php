<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Data\Contracts\AttachmentService;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Support\Position;

/**
 * Duplicate a list, or a whole board.
 *
 * ## What travels, and what does not
 *
 * Straight from `project-guaid/spec/06-trello-parity.md`, and deliberately **not
 * symmetric** — matching Trello, so that neither operation surprises somebody
 * who already knows the other tool:
 *
 * | | copy list | copy board |
 * | --- | --- | --- |
 * | card title, description, dates, cover colour | yes | yes |
 * | labels on a card | yes | yes, remapped to the copied labels |
 * | custom field values | yes | yes, remapped to the copied definitions |
 * | checklists and their items | yes | **no** |
 * | attachments | yes | **no** |
 * | comments | yes | **no** |
 * | card members | yes | **no** |
 * | archived cards | yes | **no** |
 * | activity trail | **no** | **no** |
 *
 * The spec's sentence for a board is "cards and descriptions only — no
 * comments, no activity, no card members, no archived cards". The **exclusion**
 * half is read as authoritative and the inclusion half as shorthand: a card's
 * own columns (its dates, its customer, its cover colour) are part of the card
 * in the same way its description is, and a board copy that dropped every due
 * date would be a worse copy than the one Trello makes, for no stated reason.
 * Anything a person *wrote against* a card and anything that is a record of
 * *who did what* — comments, members, the activity feed — is what the exclusion
 * list is about, and all of that is dropped.
 *
 * A copy writes exactly one activity row of its own, on the new list or board,
 * naming what it was copied from. That is the new thing's first event, not a
 * replay of the original's history.
 *
 * ## Decision — mirrors
 *
 * A list can show a card that lives somewhere else: a mirror placement, with
 * `is_origin = false`, pointing at a card whose origin is on another board. The
 * spec says nothing about what copying such a list should produce, and there
 * are only three possible answers:
 *
 * 1. a mirror in the copy, pointing at the **original** card;
 * 2. no placement at all;
 * 3. a real, independent card in the copy.
 *
 * **This class does (3).** (1) is wrong because a copy is meant to be a
 * separate thing you can edit without touching the original, and a mirror is
 * the opposite of that — renaming a card in the copy would rename it on the
 * board it came from. (2) is worse than wrong: a card with no placement is
 * invisible and unreachable, off every board and absent from the archive, which
 * reads a card through its origin list (see `BoardList::booted()` for the same
 * hazard from the other direction).
 *
 * So **every card in a copy gets exactly one origin placement**, whether it was
 * an origin or a mirror in the source, and the original card is not touched at
 * all — it keeps its own origin and its own mirrors.
 *
 * The one place mirroring survives a copy is *inside* a board copy: a card
 * placed on two lists of the board being copied stays one card in the copy,
 * with its origin on the copy of the list it lived in and a mirror on the copy
 * of the list that showed it. That preserves the board's own shape without ever
 * pointing back at the original. Where such a card's origin lies **outside** the
 * copied board, the first placement copied becomes the origin — one origin,
 * never zero.
 *
 * ## Decision — custom fields
 *
 * A definition belongs to a board; a value belongs to a card. So:
 *
 * - **Copying a board copies the definitions**, options and all, and remaps
 *   every copied value onto the new definition. A board copy that lost its
 *   custom fields would not be a copy of that board.
 * - **Copying a list inside one board copies no definitions**, because they are
 *   already there — the new list is on the same board and its cards point at
 *   the same `custom_fields` rows.
 * - **Values travel in both cases**, following the same rule as descriptions: a
 *   value is something written on the card.
 *
 * Board labels work the same way, for the same reason.
 *
 * ## Query cost
 *
 * A board of 500 cards is a *handful* of queries, not one per card: every child
 * table is read with one `whereIn` and written with one bulk insert (chunked at
 * `INSERT_CHUNK` rows so no driver's placeholder limit is reached). Nothing here
 * loops a query.
 *
 * That is also why every write goes through `DB::table()` rather than Eloquent:
 * the models carry `LogsActivity`, and a copy that produced 500 activity rows
 * would violate the "no activity" rule while it was violating the query budget.
 * `Card::booted()`'s numbering listener is bypassed for the same reason — card
 * numbers are allocated here instead, in one read and one write against the
 * destination board's counter.
 *
 * The one thing that cannot be bulk-written is an attachment. Data owns the
 * only writer to disk, so each file goes through `AttachmentService`, and only
 * cards that actually carry one are visited — `targetIdsWithAttachments()`
 * answers "which of these" in a single query so the rest are never touched.
 */
final class BoardCopier
{
    /**
     * Rows per `INSERT`. Well inside SQLite's 32 766 bound variables at the
     * widest table here (cards, seventeen columns) and inside MySQL's
     * `max_allowed_packet` at any realistic row size.
     */
    private const INSERT_CHUNK = 100;

    /**
     * Duplicate one list, with everything in it, onto the same board.
     *
     * The copy lands immediately to the right of the original, which is where
     * somebody who just asked for a copy expects to find it.
     *
     * @return array{list: BoardList, cards: int}
     */
    public function copyList(BoardList $source, ?string $name = null): array
    {
        return DB::transaction(function () use ($source, $name): array {
            $list = BoardList::query()->create([
                'board_id' => $source->board_id,
                'name' => $this->copyName($name, $source->name),
                'position' => $this->positionAfterList($source),
                'colour' => $source->colour,
                'wip_limit' => $source->wip_limit,
                // A copy is a live list even when the original has been
                // archived: you copy something to work on it.
                'archived_at' => null,
                'created_by' => auth()->id(),
            ]);

            $cards = $this->copyCards(
                listMap: [$source->id => $list->id],
                destinationBoardId: (int) $source->board_id,
                includeArchived: true,
                deep: true,
                labelMap: null,
                fieldMap: null,
            );

            activity('list')
                ->performedOn($list)
                ->causedBy(auth()->user())
                ->event('list.copied')
                ->withProperties(['from' => $source->name, 'cards' => $cards])
                ->log('copied from '.$source->name);

            return ['list' => $list, 'cards' => $cards];
        });
    }

    /**
     * Duplicate a whole board: its lists, its labels, its custom field
     * definitions, and the cards on it.
     *
     * Archived lists are left behind along with archived cards — an archived
     * list is not part of the board you are looking at.
     *
     * @return array{board: Board, lists: int, cards: int}
     */
    public function copyBoard(Board $source, ?string $name = null): array
    {
        return DB::transaction(function () use ($source, $name): array {
            $boardName = $this->copyName($name, $source->name);

            $board = Board::query()->create([
                'slug' => $this->uniqueSlug($boardName),
                'name' => $boardName,
                'colour' => $source->colour,
                'description' => $source->description,
                'company_id' => $source->company_id,
                'position' => (int) Board::query()->max('position') + 1,
                // `feed_token` is deliberately absent — it is not fillable and
                // is not copied. It is the unguessable half of a public
                // calendar address, and two boards answering to one token is a
                // leak, not a feature. The copy mints its own when asked.
                //
                // The background *is* copied, photo included, and it is the one
                // attachment reference this class shares rather than duplicates.
                // A background is one image describing how the board looks, not
                // a file kept against a card, and a copy that came back plain
                // grey would not read as a copy of that board.
                'background_type' => $source->background_type,
                'background_key' => $source->background_key,
                'background_attachment_id' => $source->background_attachment_id,
                'background_text_tone' => $source->background_text_tone,
                'archived_at' => null,
                'created_by' => auth()->id(),
            ]);

            $labelMap = $this->copyLabels($source, $board);
            $fieldMap = $this->copyCustomFields($source, $board);
            $listMap = $this->copyLists($source, $board);

            $cards = $listMap === [] ? 0 : $this->copyCards(
                listMap: $listMap,
                destinationBoardId: (int) $board->id,
                includeArchived: false,
                deep: false,
                labelMap: $labelMap,
                fieldMap: $fieldMap,
            );

            activity('board')
                ->performedOn($board)
                ->causedBy(auth()->user())
                ->event('board.copied')
                ->withProperties(['from' => $source->name, 'lists' => count($listMap), 'cards' => $cards])
                ->log('copied from '.$source->name);

            return ['board' => $board, 'lists' => count($listMap), 'cards' => $cards];
        });
    }

    /* The board's own children ------------------------------------------------ */

    /** @return array<int, int> source label id => new label id */
    private function copyLabels(Board $source, Board $board): array
    {
        $labels = DB::table('labels')->where('board_id', $source->id)->orderBy('id')->get();

        return $this->insertAndMap('labels', $labels->map(fn (object $label): array => [
            'board_id' => $board->id,
            'name' => $label->name,
            'colour' => $label->colour,
            'position' => $label->position,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all(), $labels->pluck('id')->all());
    }

    /** @return array<int, int> source field id => new field id */
    private function copyCustomFields(Board $source, Board $board): array
    {
        $fields = DB::table('custom_fields')->where('board_id', $source->id)->orderBy('id')->get();

        return $this->insertAndMap('custom_fields', $fields->map(fn (object $field): array => [
            'board_id' => $board->id,
            'name' => $field->name,
            'type' => $field->type,
            // The raw JSON string, option ids intact, so a copied value that
            // names option 3 still means the same option in the copy.
            'options' => $field->options,
            'position' => $field->position,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all(), $fields->pluck('id')->all());
    }

    /** @return array<int, int> source list id => new list id */
    private function copyLists(Board $source, Board $board): array
    {
        $lists = DB::table('board_lists')
            ->where('board_id', $source->id)
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $this->insertAndMap('board_lists', $lists->map(fn (object $list): array => [
            'board_id' => $board->id,
            'name' => $list->name,
            'position' => $list->position,
            'colour' => $list->colour,
            'wip_limit' => $list->wip_limit,
            'archived_at' => null,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all(), $lists->pluck('id')->all());
    }

    /* Cards -------------------------------------------------------------------- */

    /**
     * Copy every card placed in the source lists into their counterparts.
     *
     * @param  array<int, int>  $listMap  source list id => new list id
     * @param  array<int, int>|null  $labelMap  null means "the same board, so the same labels"
     * @param  array<int, int>|null  $fieldMap  null means "the same board, so the same definitions"
     * @return int cards created
     */
    private function copyCards(
        array $listMap,
        int $destinationBoardId,
        bool $includeArchived,
        bool $deep,
        ?array $labelMap,
        ?array $fieldMap,
    ): int {
        $placements = DB::table('card_placements')
            ->join('cards', 'cards.id', '=', 'card_placements.card_id')
            ->whereIn('card_placements.board_list_id', array_keys($listMap))
            ->whereNull('cards.deleted_at')
            ->when(! $includeArchived, fn ($query) => $query->whereNull('cards.archived_at'))
            ->orderBy('card_placements.board_list_id')
            ->orderBy('card_placements.position')
            ->select([
                'card_placements.id as placement_id',
                'card_placements.card_id',
                'card_placements.board_list_id',
                'card_placements.position',
                'card_placements.is_origin',
            ])
            ->get();

        if ($placements->isEmpty()) {
            return 0;
        }

        // First-encounter order, so the copy numbers its cards the way the
        // board reads: left to right, top to bottom.
        $sourceCardIds = $placements->pluck('card_id')->map(intval(...))->unique()->values()->all();

        $cards = DB::table('cards')->whereIn('id', $sourceCardIds)->get()->keyBy('id');

        $firstNumber = $this->allocateNumbers($destinationBoardId, count($sourceCardIds));

        $rows = [];

        foreach ($sourceCardIds as $index => $sourceId) {
            $card = $cards[$sourceId];
            $number = $firstNumber + $index;

            $rows[] = [
                'title' => $card->title,
                'description' => $card->description,
                'customer_id' => $card->customer_id,
                'company_id' => $card->company_id,
                'start_on' => $card->start_on,
                'due_on' => $card->due_on,
                'completed_at' => $card->completed_at,
                'archived_at' => $includeArchived ? $card->archived_at : null,
                'created_by' => auth()->id(),
                // A colour cover is a property of the card and travels. An
                // image cover names an attachment, so it can only travel where
                // the attachment does — it is remapped below for a list copy,
                // and dropped for a board copy, which carries no files. Sharing
                // the original's attachment id would leave the copy one delete
                // away from a card pointing at nothing.
                'cover_type' => $card->cover_type === 'colour' ? 'colour' : null,
                'cover_colour' => $card->cover_type === 'colour' ? $card->cover_colour : null,
                'cover_attachment_id' => null,
                'cover_size' => $card->cover_size,
                'number' => $number,
                'slug' => Str::slug((string) $card->title) ?: 'card-'.$number,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $cardMap = $this->insertAndMap('cards', $rows, $sourceCardIds);

        $this->copyPlacements($placements, $listMap, $cardMap);
        $this->copyLabelLinks($sourceCardIds, $cardMap, $labelMap);
        $this->copyFieldValues($sourceCardIds, $cardMap, $fieldMap);

        if ($deep) {
            $this->copyMembers($sourceCardIds, $cardMap);
            $this->copyComments($sourceCardIds, $cardMap);
            $this->copyChecklists($sourceCardIds, $cardMap);
            $this->copyAttachments($cards, $cardMap);
        }

        return count($cardMap);
    }

    /**
     * One placement row per source placement — and exactly one of them per card
     * marked as the origin.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $placements
     * @param  array<int, int>  $listMap
     * @param  array<int, int>  $cardMap
     */
    private function copyPlacements($placements, array $listMap, array $cardMap): void
    {
        // Which placement gets `is_origin`. The source's own origin when it was
        // copied too; otherwise the first placement seen, so a card mirrored in
        // from another board still lands with an origin rather than with none.
        $origins = [];

        foreach ($placements as $placement) {
            $cardId = (int) $placement->card_id;

            if (! array_key_exists($cardId, $origins) || $placement->is_origin) {
                $origins[$cardId] = (int) $placement->placement_id;
            }
        }

        $rows = [];

        foreach ($placements as $placement) {
            $cardId = (int) $placement->card_id;

            $rows[] = [
                'card_id' => $cardMap[$cardId],
                'board_list_id' => $listMap[(int) $placement->board_list_id],
                'position' => $placement->position,
                'is_origin' => $origins[$cardId] === (int) $placement->placement_id,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->bulkInsert('card_placements', $rows);
    }

    /**
     * @param  list<int>  $sourceCardIds
     * @param  array<int, int>  $cardMap
     * @param  array<int, int>|null  $labelMap
     */
    private function copyLabelLinks(array $sourceCardIds, array $cardMap, ?array $labelMap): void
    {
        $rows = [];

        foreach (DB::table('card_label')->whereIn('card_id', $sourceCardIds)->get() as $link) {
            $labelId = $labelMap === null ? (int) $link->label_id : ($labelMap[(int) $link->label_id] ?? null);

            if ($labelId === null) {
                continue;
            }

            $rows[] = ['card_id' => $cardMap[(int) $link->card_id], 'label_id' => $labelId];
        }

        $this->bulkInsert('card_label', $rows);
    }

    /**
     * @param  list<int>  $sourceCardIds
     * @param  array<int, int>  $cardMap
     * @param  array<int, int>|null  $fieldMap
     */
    private function copyFieldValues(array $sourceCardIds, array $cardMap, ?array $fieldMap): void
    {
        $query = DB::table('custom_field_values')->whereIn('card_id', $sourceCardIds);

        if ($fieldMap !== null) {
            $query->whereIn('custom_field_id', array_keys($fieldMap));
        }

        $rows = [];

        foreach ($query->get() as $value) {
            $rows[] = [
                'custom_field_id' => $fieldMap === null
                    ? (int) $value->custom_field_id
                    : $fieldMap[(int) $value->custom_field_id],
                'card_id' => $cardMap[(int) $value->card_id],
                'value_text' => $value->value_text,
                'value_number' => $value->value_number,
                'value_date' => $value->value_date,
                'value_boolean' => $value->value_boolean,
                'value_option_id' => $value->value_option_id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->bulkInsert('custom_field_values', $rows);
    }

    /**
     * @param  list<int>  $sourceCardIds
     * @param  array<int, int>  $cardMap
     */
    private function copyMembers(array $sourceCardIds, array $cardMap): void
    {
        $rows = DB::table('card_members')
            ->whereIn('card_id', $sourceCardIds)
            ->get()
            ->map(fn (object $member): array => [
                'card_id' => $cardMap[(int) $member->card_id],
                'user_id' => $member->user_id,
                'created_at' => now(),
            ])
            ->all();

        $this->bulkInsert('card_members', $rows);
    }

    /**
     * Comments keep their author and their original timestamp: a copied thread
     * that claimed everybody said everything just now would be a worse record
     * than one that says when it was actually said.
     *
     * @param  list<int>  $sourceCardIds
     * @param  array<int, int>  $cardMap
     */
    private function copyComments(array $sourceCardIds, array $cardMap): void
    {
        $rows = DB::table('card_comments')
            ->whereIn('card_id', $sourceCardIds)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $comment): array => [
                'card_id' => $cardMap[(int) $comment->card_id],
                'created_by' => $comment->created_by,
                'body' => $comment->body,
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
            ])
            ->all();

        // Reactions are not copied. An emoji is one person's response to one
        // comment at one moment — the same kind of thing as the activity trail
        // this operation deliberately leaves behind.
        $this->bulkInsert('card_comments', $rows);
    }

    /**
     * Two levels, two bulk inserts: the checklists, then every item across all
     * of them at once.
     *
     * @param  list<int>  $sourceCardIds
     * @param  array<int, int>  $cardMap
     */
    private function copyChecklists(array $sourceCardIds, array $cardMap): void
    {
        $checklists = DB::table('checklists')
            ->whereIn('card_id', $sourceCardIds)
            ->orderBy('id')
            ->get();

        if ($checklists->isEmpty()) {
            return;
        }

        $checklistMap = $this->insertAndMap('checklists', $checklists->map(fn (object $checklist): array => [
            'card_id' => $cardMap[(int) $checklist->card_id],
            'name' => $checklist->name,
            'position' => $checklist->position,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all(), $checklists->pluck('id')->all());

        $items = DB::table('checklist_items')
            ->whereIn('checklist_id', array_keys($checklistMap))
            ->orderBy('id')
            ->get()
            ->map(fn (object $item): array => [
                'checklist_id' => $checklistMap[(int) $item->checklist_id],
                'text' => $item->text,
                'is_done' => $item->is_done,
                'position' => $item->position,
                'completed_at' => $item->completed_at,
                'created_by' => $item->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        $this->bulkInsert('checklist_items', $items);
    }

    /**
     * Copy the files, through the contract that owns them.
     *
     * The only per-row work in this class, because Data is the application's
     * one writer to disk and it takes a model and bytes, not a row. It is kept
     * bounded two ways: `targetIdsWithAttachments()` names the cards that carry
     * a file in a single query, so cards with none are never visited, and the
     * models on both sides are loaded in one query each.
     *
     * A card whose cover was one of those files gets its cover back, pointed at
     * the new attachment. A file that has gone missing from the disk is skipped
     * rather than failing the copy — the row is a record of an upload, and a
     * copy is not the place to discover the bytes are gone.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $sourceCards  keyed by source card id
     * @param  array<int, int>  $cardMap
     */
    private function copyAttachments($sourceCards, array $cardMap): void
    {
        $files = app(AttachmentService::class);
        $alias = (new Card)->getMorphClass();

        $carrying = array_intersect(array_keys($cardMap), $files->targetIdsWithAttachments($alias));

        if ($carrying === []) {
            return;
        }

        $originals = Card::query()->whereIn('id', $carrying)->get()->keyBy('id');
        $copies = Card::query()->whereIn('id', array_map(fn (int $id): int => $cardMap[$id], $carrying))->get()->keyBy('id');
        $covers = [];

        foreach ($carrying as $sourceId) {
            $original = $originals[$sourceId] ?? null;
            $copy = $copies[$cardMap[$sourceId]] ?? null;

            if ($original === null || $copy === null) {
                continue;
            }

            $coverId = (int) ($sourceCards[$sourceId]->cover_attachment_id ?? 0);

            foreach ($files->forTarget($original) as $attachment) {
                $disk = Storage::disk($attachment['disk']);

                if (! $disk->exists($attachment['path'])) {
                    continue;
                }

                $stored = $files->attachContents(
                    $copy,
                    (string) $disk->get($attachment['path']),
                    $attachment['name'],
                    $attachment['mime'],
                    auth()->id(),
                );

                if ($coverId !== 0 && $coverId === (int) $attachment['id']) {
                    $covers[$copy->id] = (int) $stored['id'];
                }
            }
        }

        foreach ($covers as $cardId => $attachmentId) {
            DB::table('cards')->where('id', $cardId)->update([
                'cover_type' => 'image',
                'cover_attachment_id' => $attachmentId,
            ]);
        }
    }

    /* Plumbing ----------------------------------------------------------------- */

    /**
     * Reserve a run of card numbers on the destination board.
     *
     * The same counter, and the same reasoning, as `Card::booted()`: read under
     * `lockForUpdate()` and write the whole run back at once, so a copy of 500
     * cards costs two statements and cannot collide with a card created in
     * another request while it runs. `MAX(number) + 1` would have neither
     * property.
     *
     * @return int the first number of the run
     */
    private function allocateNumbers(int $boardId, int $count): int
    {
        $current = (int) (DB::table('boards')->where('id', $boardId)->lockForUpdate()->value('next_card_number') ?? 1);

        DB::table('boards')->where('id', $boardId)->update(['next_card_number' => $current + $count]);

        return $current;
    }

    /**
     * Insert rows and hand back "source id => new id".
     *
     * The map comes from reading back everything above the table's previous
     * maximum id, in id order, and pairing it positionally with the ids the
     * rows were built from. That is sound because every caller is inside this
     * class's single `DB::transaction()`: nothing else can interleave an insert
     * into the same table, and both SQLite and InnoDB hand out ids in insert
     * order. It is what keeps a 500-row copy to one write and one read instead
     * of 500 `insertGetId()` round trips.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $sourceIds  parallel to `$rows`
     * @return array<int, int>
     */
    private function insertAndMap(string $table, array $rows, array $sourceIds): array
    {
        if ($rows === []) {
            return [];
        }

        $before = (int) DB::table($table)->max('id');

        $this->bulkInsert($table, $rows);

        $newIds = DB::table($table)
            ->where('id', '>', $before)
            ->orderBy('id')
            ->pluck('id')
            ->map(intval(...))
            ->all();

        if (count($newIds) !== count($sourceIds)) {
            throw new \RuntimeException(
                'Copying '.$table.' inserted '.count($newIds).' rows for '.count($sourceIds).' originals.',
            );
        }

        return array_combine($sourceIds, $newIds);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulkInsert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * The position a copied list takes: immediately after its original, halving
     * the gap to whatever comes next rather than renumbering the board.
     */
    private function positionAfterList(BoardList $source): string
    {
        $current = Position::format((string) $source->position);

        $next = DB::table('board_lists')
            ->where('board_id', $source->board_id)
            ->whereNull('deleted_at')
            ->where('position', '>', $source->position)
            ->orderBy('position')
            ->value('position');

        return Position::between($current, $next === null ? null : Position::format((string) $next));
    }

    /** What the copy is called when nobody said. */
    private function copyName(?string $given, string $original): string
    {
        $given = trim((string) $given);

        return $given === '' ? Str::limit($original, 32, '').' (copy)' : $given;
    }

    /**
     * A slug nothing else has taken — `withTrashed()`, because a soft-deleted
     * board still holds its slug in the unique index.
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
}
