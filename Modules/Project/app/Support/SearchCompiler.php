<?php

namespace Modules\Project\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Carbon;
use Modules\Data\Contracts\AttachmentService;
use Modules\Project\Models\Board;
use Modules\Project\Models\Card;

/**
 * Turns a `ParsedSearch` into where-clauses on a `card_placements` query.
 *
 * ## SQL, not PHP — and where that stops scaling
 *
 * The board loads one query's worth of placements per column already
 * (`⚡boards.blade.php`'s `lists()`), so the alternative to this class would be
 * loading everything, as before, and testing each card in PHP with `matches()`.
 * That was right when the only filter was `stripos($card->title, $term)`, but
 * `checklist:` and `comment:` need item text and comment bodies that the board
 * has never had to load before — doing that in PHP would mean eager-loading
 * every checklist item and every comment on every card on every render, filtered
 * or not, to throw most of it away. Compiling to SQL instead means the database
 * only ever returns rows that already match, and the extra relations this
 * language needs (`checklists.items`, `comments`, `labels`) are queried through
 * `whereHas`, never loaded into memory to be inspected.
 *
 * This scales to however many cards MySQL can search with an index on
 * `card_placements.board_list_id` and the ordinary indexes on `cards.due_on`,
 * `cards.created_at` and `cards.updated_at` — comfortably past anything one
 * freelancer's board will ever hold. It stops scaling gracefully at free-text
 * search: `title LIKE '%term%'` cannot use an index, so a `LIKE` scan over
 * hundreds of thousands of cards would be the point to reach for Scout's
 * `database` engine (already the project's chosen full-text driver, see
 * `project-guaid/02-data-model.md`) rather than widen this class further.
 *
 * ## Dates are resolved here, against a clock this class is handed
 *
 * `SearchQuery` never calls `now()` on purpose — see its docblock — so a
 * relative window (`created:week`, `due:overdue`) is resolved the moment a
 * query actually runs, against whichever timezone the person searching is in.
 * `day`/`week`/`month` are treated as aliases for 1/7/30 days, both for the
 * backward-looking `created:`/`edited:` window and the forward-looking `due:`
 * window — `SearchQuery`'s own docblock describes both as "a window back from
 * now" and "a window ahead", which only reads as one consistent rule once the
 * same day counts are used on both sides.
 *
 * `due:overdue`, `due:complete` and `due:incomplete` are states, not windows,
 * and mirror `Card::dueState()`: overdue and incomplete both require
 * `completed_at IS NULL`, because a card marked done stops being late.
 *
 * ## What this class refuses to fake
 *
 * **`has:stickers`** is the only one left. There is no sticker table, so the
 * query is made to match nothing and the operator token is returned, letting
 * the caller say so rather than quietly showing results that only look
 * complete.
 *
 * The other three were stubbed for the same reason and are now real, each for
 * its own reason:
 *
 * - **`has:cover`** — `cards.cover_type` exists. Note it answers the *stored*
 *   cover, where `Card::coverPresentation()` answers the *drawable* one: an
 *   image cover whose attachment was later deleted is a row with a cover and a
 *   card front without one. Matching the column is the right side of that to be
 *   on — the card does have a cover, it has a broken one, and hiding it from
 *   search is how it never gets fixed.
 * - **`has:attachments`** — `AttachmentService::targetIdsWithAttachments()`
 *   answers it with one query, bounded by the number of attached cards rather
 *   than by the number of cards. Project may not read Data's table, so this
 *   goes through the contract and lands as a `whereIn` on ids.
 * - **`is:starred`** — starring is per person, on `board_user_states`, and this
 *   board shows one board at a time. So the operator resolves to a property of
 *   the open board, not of each card: on a starred board every card matches, on
 *   an unstarred one none does. That is Trello's meaning narrowed to a
 *   single-board canvas, and it is the honest reading rather than the useless
 *   one.
 *
 * ## Board scoping
 *
 * The board shows one board at a time, unlike Trello's cross-board search.
 * `board:` therefore narrows to nothing unless it names the board already
 * open — searching a client's name should not silently show a different
 * board's cards without the person choosing to switch there.
 */
final class SearchCompiler
{
    /** `day`/`week`/`month` as day counts, shared by `created:`, `edited:` and `due:`. */
    private const WINDOW_DAYS = ['day' => 1, 'week' => 7, 'month' => 30];

    /** `has:` values with no column behind them yet. */
    private const UNSUPPORTED_HAS = ['stickers'];

    /** `is:` values with no table behind them yet. */
    private const UNSUPPORTED_IS = [];

    public function __construct(
        private readonly Carbon $now,
        private readonly string $timezone,
        /**
         * Who is searching, when anybody is.
         *
         * Optional, and last, so the two-argument construction the unit tests
         * use still compiles. Only `is:starred` needs it — starring is per
         * person — and with no user that operator correctly matches nothing,
         * because nobody has starred anything.
         */
        private readonly ?User $user = null,
    ) {}

    /** The clock and timezone a request actually has: the signed-in user's, or the app default. */
    public static function forUser(?User $user, ?Carbon $now = null): self
    {
        return new self($now ?? Carbon::now(), $user?->timezone ?: config('app.timezone', 'UTC'), $user);
    }

    /**
     * Apply a parsed search and the board's filter-panel state to one query.
     *
     * The panel (labels, members, due) and the typed operators are two
     * independent ways to narrow the same list, so they combine with AND:
     * ticking a label in the panel while typing `member:nima` shows cards that
     * satisfy both, which is what "narrow further" means in every other part
     * of this application's filtering. Ordering is applied here too, because
     * `sort:` is part of the same parsed query and the caller should not have
     * to know when the default (position) applies versus an explicit field.
     *
     * @return list<string> operator tokens (e.g. "has:cover") that could not be
     *                       honoured. When this is non-empty the query is made
     *                       to match nothing — see the class docblock.
     */
    public function apply(
        EloquentBuilder $query,
        ParsedSearch $search,
        Board $board,
        array $panelLabelIds = [],
        array $panelAssigneeIds = [],
        string $panelDue = '',
    ): array {
        $unsupported = $this->unsupportedOperators($search);

        if ($unsupported !== []) {
            $query->whereRaw('1 = 0');

            return $unsupported;
        }

        foreach ($search->terms as $term) {
            $this->matchText($query, $term);
        }

        foreach ($search->excludedTerms as $term) {
            $this->excludeText($query, $term);
        }

        $keys = [...SearchQuery::TEXT_KEYS, 'created', 'edited', 'due', 'has', 'is'];

        foreach ($keys as $key) {
            $this->applyKey($query, $board, $key, $search->values($key), false);
            $this->applyKey($query, $board, $key, $search->excluded($key), true);
        }

        $this->applyPanelFilters($query, $panelLabelIds, $panelAssigneeIds, $panelDue);

        $this->applySort($query, $search);

        return [];
    }

    /**
     * @return list<string>
     */
    private function unsupportedOperators(ParsedSearch $search): array
    {
        $tokens = [];

        foreach (self::UNSUPPORTED_HAS as $value) {
            if (in_array($value, $search->values('has'), true)) {
                $tokens[] = 'has:'.$value;
            }
            if (in_array($value, $search->excluded('has'), true)) {
                $tokens[] = '-has:'.$value;
            }
        }

        foreach (self::UNSUPPORTED_IS as $value) {
            if (in_array($value, $search->values('is'), true)) {
                $tokens[] = 'is:'.$value;
            }
            if (in_array($value, $search->excluded('is'), true)) {
                $tokens[] = '-is:'.$value;
            }
        }

        return $tokens;
    }

    private function applyKey(EloquentBuilder $query, Board $board, string $key, array $values, bool $negate): void
    {
        if ($values === []) {
            return;
        }

        match ($key) {
            'member' => $this->applyRelationTextFilter($query, 'members', 'name', $values, $negate),
            'board' => $this->applyBoardFilter($query, $values, $board, $negate),
            'list' => $this->applyListFilter($query, $values, $negate),
            'label' => $this->applyLabelFilter($query, $values, $negate),
            'name' => $this->applyCardTextFilter($query, 'title', $values, $negate),
            'description' => $this->applyCardTextFilter($query, 'description', $values, $negate),
            'checklist' => $this->applyRelationTextFilter($query, 'checklistItems', 'text', $values, $negate),
            'comment' => $this->applyRelationTextFilter($query, 'comments', 'body', $values, $negate),
            'created' => $this->applyElapsedWindow($query, 'created_at', $values, $negate),
            'edited' => $this->applyElapsedWindow($query, 'updated_at', $values, $negate),
            'due' => $this->applyDueFilter($query, $values, $negate),
            'has' => $this->applyHasFilter($query, $values, $negate),
            'is' => $this->applyIsFilter($query, $board, $values, $negate),
            default => null,
        };
    }

    /* Free text -------------------------------------------------------------- */

    /** Title or description contains the term. This is the fix for the bug 06 names: title-only search. */
    private function matchText(EloquentBuilder $query, string $term): void
    {
        $query->whereHas('card', function ($card) use ($term): void {
            $card->where(function ($group) use ($term): void {
                $group->where('title', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%');
            });
        });
    }

    private function excludeText(EloquentBuilder $query, string $term): void
    {
        $query->whereDoesntHave('card', function ($card) use ($term): void {
            $card->where(function ($group) use ($term): void {
                $group->where('title', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%');
            });
        });
    }

    /* Relation text (member/checklist/comment) — one OR group per key -------- */

    private function applyRelationTextFilter(EloquentBuilder $query, string $cardRelation, string $column, array $values, bool $negate): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';

        $query->$method('card', function ($card) use ($cardRelation, $column, $values): void {
            $card->whereHas($cardRelation, function ($related) use ($column, $values): void {
                $related->where(function ($group) use ($column, $values): void {
                    foreach ($values as $value) {
                        $group->orWhere($column, 'like', '%'.$value.'%');
                    }
                });
            });
        });
    }

    /** `list:` lives on the placement itself, not through the card. */
    private function applyListFilter(EloquentBuilder $query, array $values, bool $negate): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';

        $query->$method('list', function ($list) use ($values): void {
            $list->where(function ($group) use ($values): void {
                foreach ($values as $value) {
                    $group->orWhere('name', 'like', '%'.$value.'%');
                }
            });
        });
    }

    /** Matches the label's name, or its palette key, so `label:red` still finds a colour-only label. */
    private function applyLabelFilter(EloquentBuilder $query, array $values, bool $negate): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';

        $query->$method('card', function ($card) use ($values): void {
            $card->whereHas('labels', function ($labels) use ($values): void {
                $labels->where(function ($group) use ($values): void {
                    foreach ($values as $value) {
                        $group->orWhere(function ($q) use ($value): void {
                            $q->where('name', 'like', '%'.$value.'%')->orWhere('colour', 'like', '%'.$value.'%');
                        });
                    }
                });
            });
        });
    }

    private function applyCardTextFilter(EloquentBuilder $query, string $column, array $values, bool $negate): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';

        $query->$method('card', function ($card) use ($column, $values): void {
            $card->where(function ($group) use ($column, $values): void {
                foreach ($values as $value) {
                    $group->orWhere($column, 'like', '%'.$value.'%');
                }
            });
        });
    }

    /**
     * `board:` compares against the board already open, in PHP — no query
     * needed, and no query possible: the placements query is already scoped
     * to this one board's lists, so there is nothing else for it to match.
     */
    private function applyBoardFilter(EloquentBuilder $query, array $values, Board $board, bool $negate): void
    {
        $matchesThisBoard = false;

        foreach ($values as $value) {
            if (stripos($board->name, $value) !== false) {
                $matchesThisBoard = true;

                break;
            }
        }

        if ($matchesThisBoard === $negate) {
            $query->whereRaw('1 = 0');
        }
    }

    /* Windows ------------------------------------------------------------------ */

    private function applyElapsedWindow(EloquentBuilder $query, string $column, array $values, bool $negate): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';
        $boundary = $this->now;

        $query->$method('card', function ($card) use ($column, $values, $boundary): void {
            $card->where(function ($group) use ($column, $values, $boundary): void {
                foreach ($values as $value) {
                    $days = self::WINDOW_DAYS[$value] ?? max(1, (int) $value);
                    $group->orWhere($column, '>=', $boundary->copy()->subDays($days));
                }
            });
        });
    }

    private function applyDueFilter(EloquentBuilder $query, array $values, bool $negate): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';
        $today = $this->today();

        $query->$method('card', function ($card) use ($values, $today): void {
            $card->where(function ($group) use ($values, $today): void {
                foreach ($values as $value) {
                    $group->orWhere(function ($q) use ($value, $today): void {
                        match (true) {
                            $value === 'overdue' => $q->where('due_on', '<', $today)->whereNull('completed_at'),
                            $value === 'complete' => $q->whereNotNull('due_on')->whereNotNull('completed_at'),
                            $value === 'incomplete' => $q->whereNotNull('due_on')->whereNull('completed_at'),
                            default => $q->where('due_on', '>=', $today)->where(
                                'due_on', '<=', Carbon::parse($today)->addDays(self::WINDOW_DAYS[$value] ?? max(1, (int) $value))->toDateString()
                            ),
                        };
                    });
                }
            });
        });
    }

    private function applyHasFilter(EloquentBuilder $query, array $values, bool $negate): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';

        $query->$method('card', function ($card) use ($values): void {
            $card->where(function ($group) use ($values): void {
                foreach ($values as $value) {
                    match ($value) {
                        'members' => $group->orWhereHas('members'),
                        'description' => $group->orWhere(function ($q): void {
                            $q->whereNotNull('description')->where('description', '!=', '');
                        }),
                        // The stored cover, not the drawable one — see the
                        // class docblock for why that is the right side.
                        'cover' => $group->orWhereNotNull('cover_type'),
                        // Ids through the contract, never a join onto Data's
                        // own table: Project is not allowed to know it exists.
                        'attachments' => $group->orWhereIn(
                            'cards.id',
                            app(AttachmentService::class)->targetIdsWithAttachments((new Card)->getMorphClass()),
                        ),
                        default => null, // stickers: caught earlier, never reached.
                    };
                }
            });
        });
    }

    private function applyIsFilter(EloquentBuilder $query, Board $board, array $values, bool $negate): void
    {
        /*
         * `starred` is handled apart from the others because it is not a
         * property of a card at all — it is a property of the board the canvas
         * has open, and of the person looking at it. Folding it into the
         * `whereHas('card')` group below would ask the database whether a card
         * is starred, which is not a question the schema can answer.
         *
         * So it resolves to all-or-nothing here, before the card conditions,
         * and is then dropped from the values the group iterates.
         */
        if (in_array('starred', $values, true)) {
            $starred = $this->user !== null && $board->isStarredBy($this->user);

            if ($starred === $negate) {
                $query->whereRaw('1 = 0');
            }

            $values = array_values(array_diff($values, ['starred']));

            if ($values === []) {
                return;
            }
        }

        $method = $negate ? 'whereDoesntHave' : 'whereHas';

        $query->$method('card', function ($card) use ($values): void {
            $card->where(function ($group) use ($values): void {
                foreach ($values as $value) {
                    match ($value) {
                        'open' => $group->orWhereNull('archived_at'),
                        'archived' => $group->orWhereNotNull('archived_at'),
                        default => null,
                    };
                }
            });
        });
    }

    /**
     * The filter panel's labels, members and due state — kept as a second,
     * ANDed layer rather than folded into the typed language, because the
     * panel is its own affordance with its own ids, not a string a person
     * typed. `due` mirrors `Card::dueState()` exactly, including the fix
     * that a card due *today* belongs under "soon": `dueState()` reports
     * 'overdue' | 'due' | 'soon' | 'later' | 'done', and the panel's "next
     * week" bucket is everything up to and including tomorrow that is not
     * done — `due_on <= tomorrow AND completed_at IS NULL` — which covers
     * all three of 'overdue', 'due' and 'soon' in one comparison.
     */
    private function applyPanelFilters(EloquentBuilder $query, array $labelIds, array $assigneeIds, string $due): void
    {
        if ($labelIds !== []) {
            $query->whereHas('card', fn ($card) => $card->whereHas('labels', fn ($labels) => $labels->whereIn('labels.id', $labelIds)));
        }

        if ($assigneeIds !== []) {
            $query->whereHas('card', fn ($card) => $card->whereHas('members', fn ($members) => $members->whereIn('users.id', $assigneeIds)));
        }

        if ($due === '') {
            return;
        }

        $today = $this->today();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        $query->whereHas('card', function ($card) use ($due, $today, $tomorrow): void {
            match ($due) {
                'overdue' => $card->where('due_on', '<', $today)->whereNull('completed_at'),
                'soon' => $card->whereNull('completed_at')->where('due_on', '<=', $tomorrow),
                'none' => $card->whereNull('due_on'),
                default => null,
            };
        });
    }

    /* Sort ----------------------------------------------------------------------- */

    /**
     * `position` is the board's own order and the default when nothing is
     * asked for. Every other field lives on `cards`, not `card_placements`,
     * so it orders by a correlated subquery rather than a join — a join would
     * multiply placement rows for a card with more than one matching row in
     * the subquery's own filters, which a correlated `SELECT` cannot do.
     */
    private function applySort(EloquentBuilder $query, ParsedSearch $search): void
    {
        $direction = $search->sortDescending ? 'desc' : 'asc';

        if ($search->sortField === null || $search->sortField === 'position') {
            $query->orderBy('card_placements.position', $direction);

            return;
        }

        $column = match ($search->sortField) {
            'due' => 'due_on',
            'created' => 'created_at',
            'edited' => 'updated_at',
            'name' => 'title',
            default => 'title',
        };

        $query->orderBy(
            Card::query()->select($column)->whereColumn('cards.id', 'card_placements.card_id'),
            $direction,
        );
    }

    /** "Today", as a date string, in the timezone this compiler was built for. */
    private function today(): string
    {
        return $this->now->copy()->setTimezone($this->timezone)->toDateString();
    }
}
