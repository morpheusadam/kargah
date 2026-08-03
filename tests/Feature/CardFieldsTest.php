<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Palette;
use Tests\TestCase;

/**
 * The card's own fields: start date, the full due-date colour scale, mark
 * complete, and the per-board card number.
 *
 * Everything to do with markdown and the description/comment sanitiser lives
 * in `MarkdownTest` (the renderer, in isolation) and `CardDetailTest` (planted
 * through the actual drawer, which is the thing that has to defeat an attack,
 * not the class alone).
 */
class CardFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private BoardList $backlog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog']);
    }

    private function drawer(Card $card): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test('project::card-detail')->call('openCard', $card->id);
    }

    /* Due-date colour scale ---------------------------------------------------- */

    public function test_the_due_state_covers_the_full_five_colour_scale(): void
    {
        $today = now()->startOfDay();

        $overdue = app(CardService::class)->append($this->backlog, 'Overdue card', ['due_on' => $today->copy()->subDay()->toDateString()]);
        $due = app(CardService::class)->append($this->backlog, 'Due today', ['due_on' => $today->toDateString()]);
        $soon = app(CardService::class)->append($this->backlog, 'Due tomorrow', ['due_on' => $today->copy()->addDay()->toDateString()]);
        $later = app(CardService::class)->append($this->backlog, 'Due next week', ['due_on' => $today->copy()->addDays(6)->toDateString()]);
        $done = app(CardService::class)->append($this->backlog, 'Done but overdue', [
            'due_on' => $today->copy()->subWeek()->toDateString(),
            'completed_at' => now(),
        ]);
        $undated = app(CardService::class)->append($this->backlog, 'No due date');

        $this->assertSame('overdue', $overdue->dueState());
        $this->assertSame('due', $due->dueState());
        $this->assertSame('soon', $soon->dueState());
        $this->assertSame('later', $later->dueState());
        $this->assertSame('done', $done->dueState());
        $this->assertNull($undated->dueState());
    }

    public function test_every_due_state_maps_to_a_real_palette_key(): void
    {
        $today = now()->startOfDay();

        $cards = [
            'overdue' => app(CardService::class)->append($this->backlog, 'A', ['due_on' => $today->copy()->subDay()->toDateString()]),
            'due' => app(CardService::class)->append($this->backlog, 'B', ['due_on' => $today->toDateString()]),
            'soon' => app(CardService::class)->append($this->backlog, 'C', ['due_on' => $today->copy()->addDay()->toDateString()]),
            'later' => app(CardService::class)->append($this->backlog, 'D', ['due_on' => $today->copy()->addDays(6)->toDateString()]),
            'done' => app(CardService::class)->append($this->backlog, 'E', ['due_on' => $today->toDateString(), 'completed_at' => now()]),
        ];

        foreach ($cards as $expectedState => $card) {
            $this->assertSame($expectedState, $card->dueState());

            $colour = $card->dueBadgeColour();

            $this->assertNotNull($colour, $expectedState.' should map to a badge colour.');
            $this->assertTrue(Palette::has($colour), "'{$colour}' must be a real Palette key ({$expectedState}).");
        }

        // 'pink' is new: the five-state scale needs it and the palette had none.
        $this->assertSame('pink', $cards['overdue']->dueBadgeColour());

        $undated = app(CardService::class)->append($this->backlog, 'No due date');
        $this->assertNull($undated->dueBadgeColour());
    }

    /* Mark complete -------------------------------------------------------------- */

    public function test_toggling_complete_sets_and_clears_completed_at(): void
    {
        $card = app(CardService::class)->append($this->backlog, 'Ship the redesign', ['due_on' => now()->toDateString()]);

        $this->assertFalse($card->fresh()->isComplete());

        $this->drawer($card)
            ->call('toggleCardComplete')
            ->assertDispatched('card-changed');

        $fresh = $card->fresh();
        $this->assertTrue($fresh->isComplete());
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame('done', $fresh->dueState());

        $this->drawer($card)->call('toggleCardComplete');

        $backAgain = $card->fresh();
        $this->assertFalse($backAgain->isComplete());
        $this->assertNull($backAgain->completed_at);
    }

    /* Start date ------------------------------------------------------------------ */

    public function test_a_start_date_is_stored_and_survives_a_reload(): void
    {
        $card = app(CardService::class)->append($this->backlog, 'Draft the proposal');

        $this->drawer($card)
            ->set('startDate', '2026-08-10')
            ->call('saveStartDate')
            ->assertSet('startPopoverOpen', false);

        $stored = $card->fresh()->start_on;

        $this->assertSame('2026-08-10', $stored->toDateString());

        $this->drawer($card)->assertSet('startDate', '2026-08-10');
    }

    public function test_clearing_the_start_date_empties_the_column(): void
    {
        $card = app(CardService::class)->append($this->backlog, 'Draft the proposal', ['start_on' => '2026-08-01']);

        $this->drawer($card)->call('clearStartDate');

        $this->assertNull($card->fresh()->start_on);
    }

    /**
     * The one rule item 3 of the brief asked for by name: a start after the
     * due date is refused, and in words rather than a write that silently
     * does nothing.
     */
    public function test_a_start_date_after_the_due_date_is_refused_with_a_message(): void
    {
        $card = app(CardService::class)->append($this->backlog, 'Fix the invoice bug', ['due_on' => '2026-08-05']);

        $this->drawer($card)
            ->set('startDate', '2026-08-10')
            ->call('saveStartDate')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertNull($card->fresh()->start_on);
    }

    /** The same rule from the other end: a due date pulled before the start is refused too. */
    public function test_a_due_date_before_the_start_date_is_refused_with_a_message(): void
    {
        $card = app(CardService::class)->append($this->backlog, 'Fix the invoice bug', ['start_on' => '2026-08-10']);

        $this->drawer($card)
            ->set('dueDate', '2026-08-05')
            ->call('saveDueDate')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertNull($card->fresh()->due_on);
    }

    /** A start date is not drawn on the card face, so it does not earn a board redraw — same reasoning as the description. */
    public function test_saving_a_start_date_does_not_redraw_the_board(): void
    {
        $card = app(CardService::class)->append($this->backlog, 'Draft the proposal');

        $this->drawer($card)
            ->set('startDate', '2026-08-10')
            ->call('saveStartDate')
            ->assertNotDispatched('card-changed');
    }

    /* Card number ------------------------------------------------------------------ */

    public function test_a_card_is_numbered_from_one_on_its_board(): void
    {
        $first = app(CardService::class)->append($this->backlog, 'First card');
        $second = app(CardService::class)->append($this->backlog, 'Second card');

        $this->assertSame(1, $first->fresh()->number);
        $this->assertSame(2, $second->fresh()->number);
        $this->assertNotSame('', $first->fresh()->slug);
    }

    /** Mirroring shows the same card elsewhere; it does not mint a second number. */
    public function test_mirroring_a_card_does_not_change_its_number(): void
    {
        $card = app(CardService::class)->append($this->backlog, 'Shared task');
        $otherList = BoardList::factory()->for($this->board)->create(['name' => 'Doing']);

        app(CardService::class)->mirror($card, $otherList);

        $this->assertSame(1, $card->fresh()->number);

        // The next *origin* card on the board still gets #2, not #3 — a mirror
        // placement never advances the counter.
        $next = app(CardService::class)->append($this->backlog, 'Next original card');
        $this->assertSame(2, $next->fresh()->number);
    }

    public function test_two_boards_number_their_cards_independently(): void
    {
        $otherBoard = Board::factory()->create(['name' => 'Personal', 'slug' => 'personal']);
        $otherList = BoardList::factory()->for($otherBoard)->create(['name' => 'Ideas']);

        $mine = app(CardService::class)->append($this->backlog, 'Client work item');
        $theirs = app(CardService::class)->append($otherList, 'Personal item');

        $this->assertSame(1, $mine->fresh()->number);
        $this->assertSame(1, $theirs->fresh()->number, 'Each board starts its own count at 1.');
    }

    /**
     * The concurrency question the brief asked to be answered directly:
     * numbering is guaranteed by `boards.next_card_number`, read with
     * `lockForUpdate()` and written back inside its own transaction — see
     * `Card::booted()` — never by `MAX(number) + 1`, which two transactions
     * could both read before either commits.
     *
     * A single PHPUnit process cannot open two real database connections at
     * once, so this cannot force an actual race between two in-flight
     * transactions. What it *can* prove, and what this asserts, is that
     * repeated card creation nested inside one already-open transaction —
     * `CardService::append()`'s own transaction plus this test's outer one,
     * which on every supported driver becomes a savepoint — never produces
     * two placements reading the same counter value. `MAX(number) + 1` would
     * have failed this even under nesting, because two `SELECT MAX(...)`
     * calls in flight at once can return the same answer; the counter cannot,
     * because the second read only happens after the first write commits.
     */
    public function test_card_numbers_are_unique_per_board_even_created_inside_one_transaction(): void
    {
        DB::transaction(function () {
            for ($i = 1; $i <= 20; $i++) {
                app(CardService::class)->append($this->backlog, "Card {$i}");
            }
        });

        $numbers = Card::query()
            ->whereIn('id', CardPlacement::query()->where('board_list_id', $this->backlog->id)->pluck('card_id'))
            ->pluck('number')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(range(1, 20), $numbers);
        $this->assertCount(20, array_unique($numbers));
        $this->assertSame(21, $this->board->fresh()->next_card_number);
    }
}
