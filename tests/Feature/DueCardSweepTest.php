<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Notification;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Services\CardService;
use Modules\Project\Services\Watching;
use Tests\TestCase;

/**
 * The due-date sweep: `project:notify-due-cards`, dispatched from cron, and
 * the reason `dedupe_key` exists on `Notifier` in the first place. A sweep
 * that runs every fifteen minutes must not tell someone the same card is due
 * five hundred times before lunch — every test here that runs the command
 * twice is checking exactly that.
 */
class DueCardSweepTest extends TestCase
{
    use RefreshDatabase;

    private Board $board;

    private BoardList $backlog;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->me);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog']);
    }

    private function service(): CardService
    {
        return app(CardService::class);
    }

    /* Idempotence — the standing rule --------------------------------------------- */

    public function test_running_the_sweep_twice_writes_one_notification(): void
    {
        $card = $this->service()->append($this->backlog, 'Send the Northwind retainer proposal', [
            'due_on' => now()->toDateString(),
        ]);
        $card->members()->attach($this->me->id);

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertSame(1, Notification::query()->where('user_id', $this->me->id)->count());

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertSame(1, Notification::query()->where('user_id', $this->me->id)->count());
    }

    public function test_a_completed_card_produces_no_due_reminder(): void
    {
        $card = $this->service()->append($this->backlog, 'Fix invoice PDF margins', [
            'due_on' => now()->toDateString(),
            'completed_at' => now(),
        ]);
        $card->members()->attach($this->me->id);

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_an_archived_card_produces_no_due_reminder(): void
    {
        $card = $this->service()->append($this->backlog, 'Renew the wildcard certificate', [
            'due_on' => now()->toDateString(),
        ]);
        $card->members()->attach($this->me->id);
        $this->service()->archive($card);

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    /**
     * The same card produces both notifications once each, because they are
     * different events with different dedupe keys — not a repeat of the same
     * one.
     */
    public function test_a_card_that_goes_overdue_after_a_due_soon_notice_still_gets_the_overdue_one(): void
    {
        $card = $this->service()->append($this->backlog, 'Draft the Q3 expense summary', [
            'due_on' => now()->toDateString(),
        ]);
        $card->members()->attach($this->me->id);

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->me->id,
            'event' => 'card.due_soon',
        ]);
        $this->assertSame(1, Notification::query()->count());

        $this->travel(2)->days();

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->me->id,
            'event' => 'card.overdue',
        ]);
        $this->assertSame(2, Notification::query()->count());

        // And running it a third time, still overdue, changes nothing further.
        $this->artisan('project:notify-due-cards')->assertExitCode(0);
        $this->assertSame(2, Notification::query()->count());
    }

    public function test_a_card_due_tomorrow_gets_a_due_soon_notice_and_a_card_due_next_week_gets_none(): void
    {
        $soon = $this->service()->append($this->backlog, 'Scope the Bluepeak booking widget', [
            'due_on' => now()->addDay()->toDateString(),
        ]);
        $soon->members()->attach($this->me->id);

        $later = $this->service()->append($this->backlog, 'Write the hand-over notes for Orbit Studio', [
            'due_on' => now()->addWeek()->toDateString(),
        ]);
        $later->members()->attach($this->me->id);

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertSame(1, Notification::query()->count());
        $this->assertDatabaseHas('user_notifications', ['dedupe_key' => 'card:'.$soon->id.':due_soon']);
        $this->assertDatabaseMissing('user_notifications', ['dedupe_key' => 'card:'.$later->id.':due_soon']);
    }

    /* Recipients: members and watchers, unioned and deduplicated ------------------ */

    public function test_recipients_are_card_members_and_watchers_deduplicated(): void
    {
        $card = $this->service()->append($this->backlog, 'Chase the Harbour & Finch deposit', [
            'due_on' => now()->toDateString(),
        ]);

        $member = User::factory()->create();
        $watcher = User::factory()->create();
        $both = User::factory()->create();
        $stranger = User::factory()->create();

        $card->members()->attach([$member->id, $both->id]);
        app(Watching::class)->watch($card, $watcher->id);
        app(Watching::class)->watch($card, $both->id);

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertSame(3, Notification::query()->count());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $member->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $watcher->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $both->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $stranger->id]);
    }

    public function test_a_due_card_with_no_members_or_watchers_notifies_nobody(): void
    {
        $this->service()->append($this->backlog, 'Migrate Acme Studio off shared hosting', [
            'due_on' => now()->toDateString(),
        ]);

        $this->artisan('project:notify-due-cards')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    /* The scheduler ----------------------------------------------------------------- */

    public function test_the_sweep_is_registered_on_the_scheduler_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'project:notify-due-cards'));

        $this->assertCount(1, $events, 'project:notify-due-cards is not on the scheduler.');
        $this->assertNotNull(
            $events->first()->withoutOverlapping,
            'The sweep must be withoutOverlapping().',
        );
    }
}
