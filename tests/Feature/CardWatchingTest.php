<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Contracts\Notifier;
use Modules\Core\Models\Notification;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\Watcher;
use Modules\Project\Services\CardService;
use Modules\Project\Services\Watching;
use Tests\TestCase;

/**
 * The watch spine: who is told about what, and the first producer built end
 * to end — card commented → notify the watchers — plus the others 06's
 * Notifications section names: date changes, moves, and archiving.
 */
class CardWatchingTest extends TestCase
{
    use RefreshDatabase;

    private Board $board;

    private Board $other;

    private BoardList $backlog;

    private BoardList $review;

    private BoardList $leads;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->me);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->other = Board::factory()->create(['name' => 'Outreach', 'slug' => 'outreach']);

        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog']);
        $this->review = BoardList::factory()->for($this->board)->create(['name' => 'Review']);
        $this->leads = BoardList::factory()->for($this->other)->create(['name' => 'Leads']);
    }

    private function service(): CardService
    {
        return app(CardService::class);
    }

    private function watching(): Watching
    {
        return app(Watching::class);
    }

    /* Watching itself --------------------------------------------------------- */

    public function test_watching_something_twice_writes_one_row(): void
    {
        $card = $this->service()->append($this->backlog, 'Rewrite portfolio landing copy');
        $user = User::factory()->create();

        $this->watching()->watch($card, $user->id);
        $this->watching()->watch($card, $user->id);

        $this->assertSame(1, Watcher::query()->count());
        $this->assertTrue($this->watching()->isWatching($card, $user->id));
    }

    public function test_unwatching_removes_the_row_and_is_idempotent(): void
    {
        $card = $this->service()->append($this->backlog, 'Rewrite portfolio landing copy');
        $user = User::factory()->create();

        $this->watching()->watch($card, $user->id);

        $this->assertTrue($this->watching()->unwatch($card, $user->id));
        $this->assertFalse($this->watching()->unwatch($card, $user->id));
        $this->assertSame(0, Watcher::query()->count());
    }

    public function test_toggle_flips_the_state_both_ways(): void
    {
        $card = $this->service()->append($this->backlog, 'Rewrite portfolio landing copy');
        $user = User::factory()->create();

        $this->assertTrue($this->watching()->toggle($card, $user->id));
        $this->assertTrue($this->watching()->isWatching($card, $user->id));

        $this->assertFalse($this->watching()->toggle($card, $user->id));
        $this->assertFalse($this->watching()->isWatching($card, $user->id));
    }

    /* Recipient resolution ------------------------------------------------------ */

    public function test_watching_a_card_a_list_and_a_board_each_produce_the_right_recipient_set(): void
    {
        $card = $this->service()->append($this->backlog, 'Send the Northwind retainer proposal');

        $cardWatcher = User::factory()->create();
        $listWatcher = User::factory()->create();
        $boardWatcher = User::factory()->create();
        $stranger = User::factory()->create();

        $this->watching()->watch($card, $cardWatcher->id);
        $this->watching()->watch($this->backlog, $listWatcher->id);
        $this->watching()->watch($this->board, $boardWatcher->id);

        $recipients = $this->watching()->recipientsForCard($card);

        $this->assertEqualsCanonicalizing(
            [$cardWatcher->id, $listWatcher->id, $boardWatcher->id],
            $recipients,
        );
        $this->assertNotContains($stranger->id, $recipients);
    }

    public function test_someone_watching_two_levels_is_told_once(): void
    {
        $card = $this->service()->append($this->backlog, 'Fix invoice PDF margins');
        $person = User::factory()->create();

        $this->watching()->watch($card, $person->id);
        $this->watching()->watch($this->board, $person->id);

        $recipients = $this->watching()->recipientsForCard($card);

        $this->assertSame([$person->id], $recipients);
    }

    public function test_the_actor_is_never_notified_of_their_own_action(): void
    {
        $card = $this->service()->append($this->backlog, 'Chase the Harbour & Finch deposit');
        $author = User::factory()->create();

        $this->watching()->watch($card, $author->id);

        $recipients = $this->watching()->recipientsForCard($card, excludeUserId: $author->id);

        $this->assertSame([], $recipients);
    }

    public function test_recipient_resolution_is_two_queries_with_fifty_watchers(): void
    {
        $card = $this->service()->append($this->backlog, 'Register the kargah.dev domain');

        Watcher::factory()->watching($card)->count(20)->create();
        Watcher::factory()->watching($this->backlog)->count(15)->create();
        Watcher::factory()->watching($this->board)->count(15)->create();

        $this->assertSame(50, Watcher::query()->count());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $recipients = $this->watching()->recipientsForCard($card);

        $this->assertCount(50, $recipients);
        $this->assertLessThanOrEqual(2, $queries, 'Resolving recipients issued '.$queries.' queries; it must be bounded, not one per watcher.');
    }

    /* The first producer: card commented ---------------------------------------- */

    public function test_a_comment_notifies_a_card_watcher(): void
    {
        $card = $this->service()->append($this->backlog, 'Draft the Q3 expense summary');
        $watcher = User::factory()->create();
        $author = User::factory()->create();

        $this->watching()->watch($card, $watcher->id);

        CardComment::query()->create(['card_id' => $card->id, 'created_by' => $author->id, 'body' => 'Client asked for a call first.']);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $watcher->id,
            'event' => 'card.commented',
        ]);
        $this->assertSame(0, Notification::query()->where('user_id', $author->id)->count());
    }

    public function test_a_comment_on_an_unwatched_card_notifies_nobody(): void
    {
        $card = $this->service()->append($this->backlog, 'Renew the wildcard certificate');

        CardComment::query()->create(['card_id' => $card->id, 'created_by' => $this->me->id, 'body' => 'No watchers here.']);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_a_commenter_who_watches_their_own_card_is_not_told_about_their_own_comment(): void
    {
        $card = $this->service()->append($this->backlog, 'Migrate Acme Studio off shared hosting');
        $author = User::factory()->create();

        $this->watching()->watch($card, $author->id);

        CardComment::query()->create(['card_id' => $card->id, 'created_by' => $author->id, 'body' => 'Talking to myself.']);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_unwatching_stops_further_comment_notifications(): void
    {
        $card = $this->service()->append($this->backlog, 'Scope the Bluepeak booking widget');
        $watcher = User::factory()->create();

        $this->watching()->watch($card, $watcher->id);
        CardComment::query()->create(['card_id' => $card->id, 'created_by' => $this->me->id, 'body' => 'First one.']);

        $this->assertSame(1, Notification::query()->where('user_id', $watcher->id)->count());

        $this->watching()->unwatch($card, $watcher->id);
        CardComment::query()->create(['card_id' => $card->id, 'created_by' => $this->me->id, 'body' => 'Second one.']);

        $this->assertSame(1, Notification::query()->where('user_id', $watcher->id)->count());
    }

    /* New cards in a watched list or board ---------------------------------------- */

    public function test_watching_a_list_notifies_about_a_card_created_in_it(): void
    {
        $listWatcher = User::factory()->create();
        $this->watching()->watch($this->backlog, $listWatcher->id);

        $this->service()->append($this->backlog, 'Write the hand-over notes for Orbit Studio');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $listWatcher->id,
            'event' => 'card.new_in_list',
        ]);
    }

    public function test_watching_a_board_notifies_about_a_card_created_anywhere_on_it(): void
    {
        $boardWatcher = User::factory()->create();
        $this->watching()->watch($this->board, $boardWatcher->id);

        $this->service()->append($this->review, 'Reconcile the July card statement');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $boardWatcher->id,
            'event' => 'card.new_in_list',
        ]);
    }

    public function test_the_creator_is_not_notified_of_their_own_new_card(): void
    {
        $listWatcher = User::factory()->create();
        $this->watching()->watch($this->backlog, $listWatcher->id);

        $this->service()->append($this->backlog, 'Fix invoice PDF margins');

        // `$this->me` created the card and also is not the list watcher, so
        // this only proves the exclusion when the creator happens to be the
        // one watching too.
        $this->watching()->watch($this->backlog, $this->me->id);
        $this->service()->append($this->backlog, 'A second card');

        $this->assertSame(0, Notification::query()->where('user_id', $this->me->id)->count());
    }

    /* The mirror decision --------------------------------------------------------- */

    /**
     * Mirroring a card onto a watched board notifies that board's watchers —
     * the card genuinely appears there now, which is exactly what "watch this
     * board" promises. See `Watching`'s own docblock for the full reasoning.
     */
    public function test_mirroring_a_card_onto_a_watched_board_notifies_its_watcher(): void
    {
        $card = $this->service()->append($this->backlog, 'Q3 expense reconciliation');
        $boardWatcher = User::factory()->create();
        $this->watching()->watch($this->other, $boardWatcher->id);

        $this->service()->mirror($card, $this->leads);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $boardWatcher->id,
            'event' => 'card.new_in_list',
        ]);
    }

    /**
     * Once mirrored, ongoing activity on the card reaches watchers of every
     * board it is placed on — origin and mirror alike — not only the board it
     * lives on.
     */
    public function test_ongoing_activity_on_a_mirrored_card_reaches_the_mirror_boards_watcher_too(): void
    {
        $card = $this->service()->append($this->backlog, 'Collect testimonials from past clients');
        $this->service()->mirror($card, $this->leads);

        $mirrorBoardWatcher = User::factory()->create();
        $this->watching()->watch($this->other, $mirrorBoardWatcher->id);

        CardComment::query()->create(['card_id' => $card->id, 'created_by' => $this->me->id, 'body' => 'Update from the mirror board perspective.']);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $mirrorBoardWatcher->id,
            'event' => 'card.commented',
        ]);
    }

    /* Date changes, moves, archiving ---------------------------------------------- */

    public function test_changing_the_due_date_notifies_a_card_watcher(): void
    {
        $card = $this->service()->append($this->backlog, 'Draft the Q3 expense summary');
        $watcher = User::factory()->create();
        $this->watching()->watch($card, $watcher->id);

        $card->update(['due_on' => now()->addDays(3)->toDateString()]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $watcher->id,
            'event' => 'card.due_changed',
        ]);
    }

    public function test_moving_a_card_between_lists_notifies_a_card_watcher(): void
    {
        $card = $this->service()->append($this->backlog, 'Fix invoice PDF margins');
        $watcher = User::factory()->create();
        $this->watching()->watch($card, $watcher->id);

        $this->service()->move($card->originPlacement, $this->review, 0);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $watcher->id,
            'event' => 'card.moved',
        ]);
    }

    public function test_reordering_within_the_same_list_does_not_notify_anyone(): void
    {
        $card = $this->service()->append($this->backlog, 'Renew the wildcard certificate');
        $this->service()->append($this->backlog, 'A second card in the same list');
        $watcher = User::factory()->create();
        $this->watching()->watch($card, $watcher->id);

        $this->service()->move($card->originPlacement, $this->backlog, 0);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_archiving_a_card_notifies_a_card_watcher(): void
    {
        $card = $this->service()->append($this->backlog, 'Migrate Acme Studio off shared hosting');
        $watcher = User::factory()->create();
        $this->watching()->watch($card, $watcher->id);

        $this->service()->archive($card);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $watcher->id,
            'event' => 'card.archived',
        ]);
    }

    public function test_restoring_a_card_does_not_notify_anyone(): void
    {
        $card = $this->service()->append($this->backlog, 'Send the Northwind retainer proposal');
        $watcher = User::factory()->create();
        $this->watching()->watch($card, $watcher->id);
        $this->service()->archive($card);

        Notification::query()->delete();

        $this->service()->restore($card);

        $this->assertSame(0, Notification::query()->count());
    }

    /* Member added — always notified, not wired to the drawer yet ----------------- */

    public function test_a_member_added_to_a_card_is_notified_regardless_of_watching(): void
    {
        $card = $this->service()->append($this->backlog, 'Draft the Q3 expense summary');
        $member = User::factory()->create();

        $result = $this->watching()->notifyMemberAdded($card, $member->id, $this->me->id);

        $this->assertNotNull($result['id']);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $member->id,
            'event' => 'card.assigned',
        ]);
    }

    public function test_adding_yourself_to_a_card_does_not_notify_yourself(): void
    {
        $card = $this->service()->append($this->backlog, 'Draft the Q3 expense summary');

        $result = $this->watching()->notifyMemberAdded($card, $this->me->id, $this->me->id);

        $this->assertNull($result['id']);
        $this->assertSame(0, Notification::query()->count());
    }

    /* The nested component --------------------------------------------------------- */

    public function test_the_watch_toggle_component_mounts_from_a_card_id_and_toggles(): void
    {
        $card = $this->service()->append($this->backlog, 'Chase the Harbour & Finch deposit');

        $component = Livewire::test('project::card-watch', ['cardId' => $card->id])
            ->assertOk()
            ->assertViewHas('watching', false);

        $component->call('toggle')->assertViewHas('watching', true);

        $this->assertTrue($this->watching()->isWatching($card, $this->me->id));

        $component->call('toggle')->assertViewHas('watching', false);

        $this->assertFalse($this->watching()->isWatching($card, $this->me->id));
    }

    public function test_toggling_the_watch_button_never_toasts(): void
    {
        $card = $this->service()->append($this->backlog, 'Reconcile the July card statement');

        Livewire::test('project::card-watch', ['cardId' => $card->id])
            ->call('toggle')
            ->assertNotDispatched('toast')
            ->call('toggle')
            ->assertNotDispatched('toast');
    }
}
