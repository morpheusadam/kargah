<?php

namespace Modules\Project\Contracts;

/**
 * What other modules — and the API — may know about boards, lists and cards.
 *
 * `CardReader` already covers "cards belonging to a customer"; this covers
 * everything shaped by a *board* rather than a *client* — the API layer and
 * the home dashboard both need to draw a board, a list or a due-date panel
 * without ever holding `Modules\Project\Models\Board`, `BoardList` or `Card`.
 * Reaching into one of those from outside this module is the thing that turns
 * a modular monolith back into a monolith.
 *
 * **Arrays out, never models.** Every method here returns a plain array or a
 * `list<array>` — never an Eloquent model, never a Collection of one — so a
 * rename inside Project cannot break a page outside it.
 *
 * **A card mirrored onto two lists counts once.**
 * `Modules\Project\Models\CardPlacement::scopeOnCanvas()` is the single
 * definition of what a board draws, and every method below that gathers cards
 * across more than one list goes through it rather than re-deriving the rule.
 * Counting a mirror twice does not just double one card on a chart — on a
 * dashboard it overstates how much work is actually outstanding, which is the
 * one thing that panel exists to get right.
 *
 * @phpstan-type BoardArray array{
 *     id: int, slug: string, name: string, colour: string,
 *     company_id: ?int, is_archived: bool
 * }
 * @phpstan-type BoardListArray array{
 *     id: int, name: string, position: string, is_archived: bool
 * }
 * @phpstan-type CardSummaryArray array{
 *     id: int, title: string, board: string, board_slug: ?string, list: string,
 *     due_on: ?string, due_state: ?string, is_archived: bool, url: string
 * }
 */
interface BoardReader
{
    /**
     * Every board, in board-picker order.
     *
     * @return list<BoardArray>
     */
    public function boards(bool $includeArchived = false): array;

    /**
     * One board by slug, or null when no board has it.
     *
     * @return null|array{
     *     id: int, slug: string, name: string, colour: string,
     *     company_id: ?int, is_archived: bool, description: ?string
     * }
     */
    public function findBoard(string $slug): ?array;

    /**
     * Every list on a board, in column order — archived ones included, which
     * is why `is_archived` is on the shape rather than being the thing that
     * decides whether a row appears at all. A caller that only wants the
     * columns a board actually draws filters on it; one building a settings
     * page needs the archived ones too.
     *
     * An unknown slug is an empty board, not an error.
     *
     * @return list<BoardListArray>
     */
    public function listsForBoard(string $boardSlug): array;

    /**
     * The cards on one list, in the order the canvas draws them — through
     * `CardPlacement::scopeOnCanvas()`, so an archived mirror is included
     * (greyed out, not draggable) exactly as the board itself shows it, and a
     * card whose origin was archived is not.
     *
     * @return list<array{
     *     id: int, title: string, description: ?string, due_on: ?string,
     *     due_state: ?string, is_complete: bool, is_archived: bool,
     *     is_origin: bool, position: string,
     *     customer: ?array{id: int, name: string},
     *     labels: list<array{id: int, name: string, colour: string}>,
     *     checklist: array{done: int, total: int}, comment_count: int,
     *     url: string
     * }>
     */
    public function cardsForList(int $listId): array;

    /**
     * One card, by id, or null when it does not exist or has been deleted.
     *
     * Reads through the card's *origin* placement for its board and list —
     * where the card lives, not everywhere it is mirrored. `mirrored_onto`
     * names the lists it is also shown in, for a caller that wants to say so
     * without a second round trip.
     *
     * @return null|array{
     *     id: int, title: string, description: ?string, due_on: ?string,
     *     due_state: ?string, is_complete: bool, is_archived: bool,
     *     board: ?string, board_slug: ?string, list: ?string,
     *     customer: ?array{id: int, name: string},
     *     labels: list<array{id: int, name: string, colour: string}>,
     *     members: list<array{id: int, name: string}>,
     *     mirrored_onto: list<string>,
     *     checklist: array{done: int, total: int}, comment_count: int,
     *     url: string
     * }
     */
    public function findCard(int $cardId): ?array;

    /**
     * Cards due within `$days`, across every board, soonest first.
     *
     * Not "overdue" — that is `cardsOverdue()`. A card due today is included
     * (`$days = 0` is a real, useful call: "what is due today"); a completed
     * or archived card is not, because it no longer needs anyone's attention.
     *
     * Bounded by `$limit`, which is what keeps a dashboard widget one query
     * instead of one row per board.
     *
     * @return list<CardSummaryArray>
     */
    public function cardsDueSoon(int $days = 30, int $limit = 20): array;

    /** How many cards are due within `$days` — a `count()`, never a hydrated list. */
    public function countDueSoon(int $days = 30): int;

    /**
     * Cards whose due date has passed and are still open, across every
     * board, most overdue first.
     *
     * @return list<CardSummaryArray>
     */
    public function cardsOverdue(int $limit = 20): array;

    /** How many cards are overdue — a `count()`, never a hydrated list. */
    public function countOverdue(): int;
}
