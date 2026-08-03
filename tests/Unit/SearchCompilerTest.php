<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\Checklist;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Models\Label;
use Modules\Project\Support\Position;
use Modules\Project\Support\SearchCompiler;
use Modules\Project\Support\SearchQuery;
use Tests\TestCase;

/**
 * `SearchCompiler` turns a `ParsedSearch` into where-clauses on a
 * `card_placements` query. These tests exercise the compiler directly,
 * against a real (SQLite) database, rather than through the board component —
 * `BoardSearchTest` covers the end-to-end wiring, including the filter panel
 * and the mirrored-card count.
 */
class SearchCompilerTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Board $board;

    private BoardList $listA;

    private BoardList $listB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->board = Board::factory()->create(['name' => 'Client Work']);
        $this->listA = BoardList::factory()->for($this->board)->create(['name' => 'To Do', 'position' => Position::format('1024')]);
        $this->listB = BoardList::factory()->for($this->board)->create(['name' => 'Doing', 'position' => Position::format('2048')]);
    }

    /** A query scoped exactly the way the board scopes it: this board's active lists, on canvas. */
    private function placementsQuery(): Builder
    {
        $listIds = BoardList::query()->where('board_id', $this->board->id)->pluck('id');

        return CardPlacement::query()->whereIn('board_list_id', $listIds)->onCanvas();
    }

    private function compiler(?Carbon $now = null, string $timezone = 'UTC'): SearchCompiler
    {
        return new SearchCompiler($now ?? Carbon::parse('2026-08-03 12:00:00', 'UTC'), $timezone);
    }

    /** @return list<string> */
    private function titles(Builder $query): array
    {
        return $query->with('card')->get()->pluck('card.title')->all();
    }

    /* Free text — the bug 06-trello-parity.md names ------------------------- */

    public function test_free_text_matches_title_and_description_not_title_alone(): void
    {
        Card::factory()->inList($this->listA)->create(['title' => 'Widget', 'description' => null]);
        Card::factory()->inList($this->listA)->create(['title' => 'Something else', 'description' => 'Mentions widget in passing']);
        Card::factory()->inList($this->listA)->create(['title' => 'Unrelated', 'description' => null]);

        $query = $this->placementsQuery();
        $unsupported = $this->compiler()->apply($query, SearchQuery::parse('widget'), $this->board);

        $this->assertSame([], $unsupported);
        $this->assertEqualsCanonicalizing(['Widget', 'Something else'], $this->titles($query));
    }

    public function test_an_excluded_term_removes_a_match_from_either_field(): void
    {
        Card::factory()->inList($this->listA)->create(['title' => 'Draft invoice', 'description' => null]);
        Card::factory()->inList($this->listA)->create(['title' => 'Send proposal', 'description' => 'invoice attached']);
        Card::factory()->inList($this->listA)->create(['title' => 'Unrelated card', 'description' => null]);

        $query = $this->placementsQuery();
        $this->compiler()->apply($query, SearchQuery::parse('-invoice'), $this->board);

        $this->assertSame(['Unrelated card'], $this->titles($query));
    }

    /* OR within a key, AND across keys — the reason the language is worth having */

    public function test_values_under_one_key_or_and_different_keys_and(): void
    {
        [$nima, $sam] = [User::factory()->create(['name' => 'Nima Fazlipour']), User::factory()->create(['name' => 'Sam'])];

        $red = Label::factory()->for($this->board)->create(['name' => 'Red', 'colour' => 'destructive']);
        $blue = Label::factory()->for($this->board)->create(['name' => 'Blue', 'colour' => 'info']);
        $green = Label::factory()->for($this->board)->create(['name' => 'Green', 'colour' => 'success']);

        $card1 = Card::factory()->inList($this->listA)->create(['title' => 'Card one']);
        $card1->labels()->attach($red);

        $card2 = Card::factory()->inList($this->listA)->create(['title' => 'Card two']);
        $card2->labels()->attach($blue);

        $card3 = Card::factory()->inList($this->listA)->create(['title' => 'Card three']);
        $card3->labels()->attach($green);

        $card4 = Card::factory()->inList($this->listA)->create(['title' => 'Card four']);
        $card4->labels()->attach($red);
        $card4->members()->attach($nima);

        // OR within `label:`: red or blue, never green.
        $orQuery = $this->placementsQuery();
        $this->compiler()->apply($orQuery, SearchQuery::parse('label:red label:blue'), $this->board);
        $this->assertEqualsCanonicalizing(['Card one', 'Card two', 'Card four'], $this->titles($orQuery));

        // AND across `label:` and `member:`: only the card with both.
        $andQuery = $this->placementsQuery();
        $this->compiler()->apply($andQuery, SearchQuery::parse('label:red member:nima'), $this->board);
        $this->assertSame(['Card four'], $this->titles($andQuery));
    }

    public function test_a_negated_key_excludes_every_value_and_ed(): void
    {
        $red = Label::factory()->for($this->board)->create(['name' => 'Red', 'colour' => 'destructive']);
        $blue = Label::factory()->for($this->board)->create(['name' => 'Blue', 'colour' => 'info']);

        $card1 = Card::factory()->inList($this->listA)->create(['title' => 'Has red']);
        $card1->labels()->attach($red);

        $card2 = Card::factory()->inList($this->listA)->create(['title' => 'Has blue']);
        $card2->labels()->attach($blue);

        $card3 = Card::factory()->inList($this->listA)->create(['title' => 'Has neither']);

        $query = $this->placementsQuery();
        $this->compiler()->apply($query, SearchQuery::parse('-label:red -label:blue'), $this->board);

        $this->assertSame(['Has neither'], $this->titles($query));
    }

    /* board: / list: ------------------------------------------------------------- */

    /**
     * The board shows one board at a time, unlike Trello's cross-board search,
     * so `board:` can only ever narrow to nothing or leave everything as it
     * was — there is no other board's cards in this query to reach for.
     */
    public function test_board_matches_the_open_board_by_name_or_excludes_everything(): void
    {
        Card::factory()->inList($this->listA)->create(['title' => 'On this board']);

        $matching = $this->placementsQuery();
        $this->compiler()->apply($matching, SearchQuery::parse('board:"Client Work"'), $this->board);
        $this->assertSame(['On this board'], $this->titles($matching));

        $notMatching = $this->placementsQuery();
        $this->compiler()->apply($notMatching, SearchQuery::parse('board:"Some Other Board"'), $this->board);
        $this->assertSame([], $this->titles($notMatching));
    }

    public function test_list_matches_the_column_a_card_is_placed_in(): void
    {
        Card::factory()->inList($this->listA)->create(['title' => 'In To Do']);
        Card::factory()->inList($this->listB)->create(['title' => 'In Doing']);

        $query = $this->placementsQuery();
        $this->compiler()->apply($query, SearchQuery::parse('list:doing'), $this->board);

        $this->assertSame(['In Doing'], $this->titles($query));
    }

    /* is: / has: ------------------------------------------------------------- */

    public function test_is_open_and_is_archived(): void
    {
        $open = Card::factory()->inList($this->listA)->create(['title' => 'Open card']);

        $archivedCard = Card::factory()->inList($this->listA)->create(['title' => 'Archived card']);
        $archivedCard->forceFill(['archived_at' => now()])->save();
        // Archived origin cards leave the canvas entirely (scopeOnCanvas); mirror
        // it so there is an archived placement actually on the board to find.
        $archivedCard->placements()->create([
            'board_list_id' => $this->listB->id,
            'position' => Position::format('1024'),
            'is_origin' => false,
        ]);

        $openQuery = $this->placementsQuery();
        $this->compiler()->apply($openQuery, SearchQuery::parse('is:open'), $this->board);
        $this->assertSame(['Open card'], $this->titles($openQuery));

        $archivedQuery = $this->placementsQuery();
        $this->compiler()->apply($archivedQuery, SearchQuery::parse('is:archived'), $this->board);
        $this->assertSame(['Archived card'], $this->titles($archivedQuery));
    }

    public function test_has_members_and_has_description(): void
    {
        $withMember = Card::factory()->inList($this->listA)->create(['title' => 'Has a member', 'description' => null]);
        $withMember->members()->attach(User::factory()->create());

        Card::factory()->inList($this->listA)->create(['title' => 'No member', 'description' => null]);

        $withDescription = Card::factory()->inList($this->listA)->create(['title' => 'Has description', 'description' => 'Some detail']);
        Card::factory()->inList($this->listA)->create(['title' => 'No description', 'description' => null]);

        $membersQuery = $this->placementsQuery();
        $this->compiler()->apply($membersQuery, SearchQuery::parse('has:members'), $this->board);
        $this->assertSame(['Has a member'], $this->titles($membersQuery));

        $descriptionQuery = $this->placementsQuery();
        $this->compiler()->apply($descriptionQuery, SearchQuery::parse('has:description'), $this->board);
        $this->assertSame(['Has description'], $this->titles($descriptionQuery));
    }

    public function test_checklist_and_comment_match_their_own_text(): void
    {
        $withItem = Card::factory()->inList($this->listA)->create(['title' => 'Has the checklist item']);
        $checklist = Checklist::factory()->for($withItem, 'card')->create();
        ChecklistItem::factory()->for($checklist)->create(['text' => 'Deploy to production']);

        Card::factory()->inList($this->listA)->create(['title' => 'No matching checklist item']);

        $withComment = Card::factory()->inList($this->listA)->create(['title' => 'Has the comment']);
        CardComment::factory()->for($withComment, 'card')->create(['body' => 'Blocked on the client']);

        Card::factory()->inList($this->listA)->create(['title' => 'No matching comment']);

        $checklistQuery = $this->placementsQuery();
        $this->compiler()->apply($checklistQuery, SearchQuery::parse('checklist:deploy'), $this->board);
        $this->assertSame(['Has the checklist item'], $this->titles($checklistQuery));

        $commentQuery = $this->placementsQuery();
        $this->compiler()->apply($commentQuery, SearchQuery::parse('comment:blocked'), $this->board);
        $this->assertSame(['Has the comment'], $this->titles($commentQuery));
    }

    /**
     * Two operators that name real data (`has:attachments`) and two that name
     * nothing at all (`has:cover`, `has:stickers`, `is:starred`) are all
     * treated the same way: the query is made to match nothing, and the
     * caller is told which operator it could not honour, rather than the
     * search silently ignoring what was typed.
     */
    public function test_unhonourable_operators_are_reported_and_match_nothing(): void
    {
        Card::factory()->inList($this->listA)->create(['title' => 'Would otherwise match everything']);

        foreach (['has:cover', 'has:stickers', 'has:attachments', 'is:starred'] as $token) {
            $query = $this->placementsQuery();
            $unsupported = $this->compiler()->apply($query, SearchQuery::parse($token), $this->board);

            $this->assertSame([$token], $unsupported, $token.' should be reported as unsupported');
            $this->assertSame([], $this->titles($query), $token.' should match nothing rather than everything');
        }
    }

    /* due: --------------------------------------------------------------------- */

    /**
     * The boundary is midnight **in the user's timezone**, not the server's.
     * `$now` is 2026-08-03 21:30 UTC, which is already 2026-08-04 00:30 in
     * Istanbul (UTC+3) — so a card due on the 3rd is overdue in Istanbul even
     * though it is still the 3rd in UTC, and a card due on the 4th is due
     * today rather than overdue.
     */
    public function test_due_overdue_boundary_is_the_users_midnight(): void
    {
        $now = Carbon::parse('2026-08-03 21:30:00', 'UTC');

        $overdueInIstanbul = Card::factory()->inList($this->listA)->create(['title' => 'Overdue in Istanbul', 'due_on' => '2026-08-03']);
        $dueTodayInIstanbul = Card::factory()->inList($this->listA)->create(['title' => 'Due today in Istanbul', 'due_on' => '2026-08-04']);

        $istanbulQuery = $this->placementsQuery();
        $this->compiler($now, 'Europe/Istanbul')->apply($istanbulQuery, SearchQuery::parse('due:overdue'), $this->board);
        $this->assertSame(['Overdue in Istanbul'], $this->titles($istanbulQuery));

        // The same instant, read in UTC, has not reached the 4th yet: nothing
        // due on the 3rd is overdue there.
        $utcQuery = $this->placementsQuery();
        $this->compiler($now, 'UTC')->apply($utcQuery, SearchQuery::parse('due:overdue'), $this->board);
        $this->assertSame([], $this->titles($utcQuery));

        // Sanity: the "due today" card is real and simply not overdue.
        $this->assertNotNull($dueTodayInIstanbul->due_on);
    }

    public function test_due_overdue_excludes_a_card_marked_complete(): void
    {
        Card::factory()->inList($this->listA)->create([
            'title' => 'Overdue but done',
            'due_on' => '2026-08-01',
            'completed_at' => now(),
        ]);
        Card::factory()->inList($this->listA)->create([
            'title' => 'Overdue and open',
            'due_on' => '2026-08-01',
            'completed_at' => null,
        ]);

        $query = $this->placementsQuery();
        $this->compiler(Carbon::parse('2026-08-03 12:00:00', 'UTC'))->apply($query, SearchQuery::parse('due:overdue'), $this->board);

        $this->assertSame(['Overdue and open'], $this->titles($query));
    }

    /* created: / edited: --------------------------------------------------------- */

    public function test_created_seven_is_a_boundary_at_exactly_seven_days(): void
    {
        $now = Carbon::parse('2026-08-10 12:00:00', 'UTC');

        $six = Card::factory()->inList($this->listA)->create(['title' => 'Six days old']);
        $seven = Card::factory()->inList($this->listA)->create(['title' => 'Seven days old']);
        $eight = Card::factory()->inList($this->listA)->create(['title' => 'Eight days old']);

        DB::table('cards')->where('id', $six->id)->update(['created_at' => $now->copy()->subDays(6)]);
        DB::table('cards')->where('id', $seven->id)->update(['created_at' => $now->copy()->subDays(7)]);
        DB::table('cards')->where('id', $eight->id)->update(['created_at' => $now->copy()->subDays(8)]);

        $query = $this->placementsQuery();
        $this->compiler($now)->apply($query, SearchQuery::parse('created:7'), $this->board);

        $this->assertEqualsCanonicalizing(['Six days old', 'Seven days old'], $this->titles($query));
    }

    /* Sorting ---------------------------------------------------------------- */

    public function test_sort_due_orders_ascending_and_descending(): void
    {
        $a = Card::factory()->inList($this->listA)->create(['title' => 'Due last', 'due_on' => '2026-08-20']);
        $b = Card::factory()->inList($this->listA)->create(['title' => 'Due first', 'due_on' => '2026-08-01']);
        $c = Card::factory()->inList($this->listA)->create(['title' => 'Due middle', 'due_on' => '2026-08-10']);

        $ascending = $this->placementsQuery();
        $this->compiler()->apply($ascending, SearchQuery::parse('sort:due'), $this->board);
        $this->assertSame(['Due first', 'Due middle', 'Due last'], $this->titles($ascending));

        $descending = $this->placementsQuery();
        $this->compiler()->apply($descending, SearchQuery::parse('sort:-due'), $this->board);
        $this->assertSame(['Due last', 'Due middle', 'Due first'], $this->titles($descending));
    }

    public function test_sort_position_orders_by_the_placement_not_the_card(): void
    {
        $card = Card::factory()->inList($this->listA)->create(['title' => 'One card']);
        $placement = $card->originPlacement()->first();

        // Mirror the same card onto the other list at a different position, so
        // there are two placements whose *own* positions differ even though
        // they point at one card.
        $mirror = CardPlacement::factory()->for($card)->for($this->listB, 'list')->mirror()->create([
            'position' => Position::format('99999'),
        ]);

        $query = CardPlacement::query()->whereIn('id', [$placement->id, $mirror->id])->onCanvas();
        $this->compiler()->apply($query, SearchQuery::parse('sort:-position'), $this->board);

        $ids = $query->pluck('id')->all();
        $this->assertSame([$mirror->id, $placement->id], $ids);
    }

    /* Mirrors ------------------------------------------------------------------ */

    /** A card mirrored onto a second list of the same board is drawn twice, deliberately — see CardPlacement::scopeOnCanvas(). */
    public function test_a_mirrored_card_is_counted_once_per_placement(): void
    {
        $card = Card::factory()->inList($this->listA)->create(['title' => 'Mirrored card']);
        CardPlacement::factory()->for($card)->for($this->listB, 'list')->mirror()->create();

        $query = $this->placementsQuery();
        $this->compiler()->apply($query, SearchQuery::parse(''), $this->board);

        $this->assertSame(['Mirrored card', 'Mirrored card'], $this->titles($query));
    }

    /* Scale -------------------------------------------------------------------- */

    /** A board with fifty cards issues the same number of queries as one with five: the count does not track the card count. */
    public function test_a_rich_query_issues_a_bounded_number_of_queries(): void
    {
        $label = Label::factory()->for($this->board)->create(['name' => 'Bug']);
        $member = User::factory()->create(['name' => 'Nima']);

        for ($i = 0; $i < 50; $i++) {
            $card = Card::factory()->inList($this->listA)->create(['title' => "Card {$i}", 'description' => 'invoice']);
            if ($i % 5 === 0) {
                $card->labels()->attach($label);
                $card->members()->attach($member);
            }
        }

        DB::enableQueryLog();

        $query = $this->placementsQuery();
        $this->compiler()->apply(
            $query,
            SearchQuery::parse('invoice label:bug member:nima sort:-due'),
            $this->board,
        );
        $query->with('card')->get();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One query for the placements (with every whereHas compiled as an
        // EXISTS subquery inside it), plus the eager loads `lists()` also
        // asks for. Comfortably bounded, and nowhere near fifty.
        $this->assertLessThan(10, $count, 'the query count should not grow with the number of cards');
    }
}
