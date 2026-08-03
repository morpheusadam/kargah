<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use Modules\Core\Contracts\Notifier;
use Modules\Core\Models\Customer;
use Modules\Core\Models\Notification;
use Tests\TestCase;

/**
 * The notification spine.
 *
 * Core owns the table because a notification's subject may be a card, an
 * invoice, an email or a post, and Core is the only module all four may depend
 * on. These tests are what decide whether that holds: that the table stores a
 * morph alias rather than a class name, that a feed survives its subject being
 * deleted, that an id is not a capability, and that everything a cron entry can
 * call twice does the same thing twice.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function notifier(): Notifier
    {
        return app(Notifier::class);
    }

    // ------------------------------------------------------------------ notify

    public function test_notify_writes_a_row_with_the_user_event_title_subject_alias_and_actor(): void
    {
        $user = User::factory()->create();
        $actor = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Sam Okafor']);

        $written = $this->notifier()->notify($user->id, 'card.commented', 'Sam commented on “Pricing page”', [
            'subject' => $customer,
            'body' => 'Can we push the launch a week?',
            'url' => '/projects?card=41',
            'actor_id' => $actor->id,
        ]);

        $row = Notification::query()->sole();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('card.commented', $row->event);
        $this->assertSame('Sam commented on “Pricing page”', $row->title);
        $this->assertSame('Can we push the launch a week?', $row->body);
        $this->assertSame('/projects?card=41', $row->url);
        $this->assertSame($actor->id, $row->actor_id);
        $this->assertNull($row->read_at);

        // The alias, not the class name. A rename must not orphan the row.
        $this->assertSame('customer', $row->subject_type);
        $this->assertStringNotContainsString('\\', (string) $row->subject_type);
        $this->assertSame($customer->id, (int) $row->subject_id);

        // Arrays out, never models.
        $this->assertIsArray($written);
        $this->assertSame($row->id, $written['id']);
        $this->assertSame('customer', $written['subject_type']);
        $this->assertFalse($written['is_read']);
    }

    public function test_a_notification_may_be_about_nothing_in_particular(): void
    {
        $user = User::factory()->create();

        $this->notifier()->notify($user->id, 'backup.completed', 'Last night’s backup finished');

        $row = Notification::query()->sole();

        $this->assertNull($row->subject_type);
        $this->assertNull($row->subject_id);
        $this->assertNull($row->actor_id);
        $this->assertNull($row->body);
        $this->assertNull($row->url);
    }

    public function test_an_unknown_option_key_is_an_error_rather_than_silently_dropped(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        // A guessed key name is a notification that quietly points nowhere.
        $this->notifier()->notify($user->id, 'card.due_soon', 'Due tomorrow', ['subject_id' => 41]);
    }

    public function test_an_empty_title_or_event_is_refused(): void
    {
        $user = User::factory()->create();

        try {
            $this->notifier()->notify($user->id, 'card.due_soon', '   ');
            $this->fail('An empty title should be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        try {
            $this->notifier()->notify($user->id, '  ', 'Something happened');
            $this->fail('An empty event should be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Notification::count());
    }

    // --------------------------------------------------------------- idempotence

    /**
     * The "runs twice, changes nothing" test.
     *
     * A due-date sweep runs every minute. Without this it would tell you the
     * same card is due five hundred times before lunch.
     */
    public function test_notify_with_a_dedupe_key_twice_writes_one_row_and_does_not_move_created_at(): void
    {
        $user = User::factory()->create();

        $first = $this->notifier()->notify($user->id, 'card.due_soon', '“Send the retainer” is due tomorrow', [
            'dedupe_key' => 'card:41:due_soon',
        ]);

        $createdAt = Notification::query()->sole()->created_at;

        $this->travel(5)->minutes();

        $second = $this->notifier()->notify($user->id, 'card.due_soon', 'A completely different title', [
            'dedupe_key' => 'card:41:due_soon',
            'body' => 'and a body it did not have',
        ]);

        $this->assertSame(1, Notification::count());
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['title'], $second['title']);
        $this->assertNull($second['body'], 'The second run must not rewrite the row it found.');

        $this->assertTrue(
            $createdAt->equalTo(Notification::query()->sole()->created_at),
            'created_at moved on the second run.',
        );
    }

    public function test_notify_without_a_dedupe_key_twice_writes_two_rows(): void
    {
        $user = User::factory()->create();

        $this->notifier()->notify($user->id, 'card.commented', 'Sam commented');
        $this->notifier()->notify($user->id, 'card.commented', 'Sam commented');

        // Two comments on the same card really are two notifications. The
        // dedupe is opt-in for exactly this reason.
        $this->assertSame(2, Notification::count());
    }

    /**
     * The unique index is on (user_id, dedupe_key) with the column nullable.
     *
     * NULLs are distinct in a unique index on both SQLite and MySQL, so rows
     * that never opt in never collide. Measured rather than trusted: if this
     * ever stopped holding, every un-deduped notification after the first would
     * fail to write and the failure would look like nothing happening.
     */
    public function test_the_nullable_dedupe_index_lets_unkeyed_rows_coexist_but_refuses_a_repeated_key(): void
    {
        $user = User::factory()->create();

        Notification::factory()->count(3)->create(['user_id' => $user->id, 'dedupe_key' => null]);

        $this->assertSame(3, Notification::count());

        Notification::factory()->create(['user_id' => $user->id, 'dedupe_key' => 'card:41:due_soon']);

        // Straight past the service, at the database, so what is being proved
        // is the index and not the SELECT in front of it.
        try {
            DB::table('user_notifications')->insert([
                'user_id' => $user->id,
                'event' => 'card.due_soon',
                'title' => 'Duplicate',
                'dedupe_key' => 'card:41:due_soon',
                'created_at' => now(),
            ]);

            $this->fail('The unique index on (user_id, dedupe_key) did not fire.');
        } catch (QueryException) {
            // expected
        }

        // The same key for a different person is a different notification.
        $other = User::factory()->create();
        Notification::factory()->create(['user_id' => $other->id, 'dedupe_key' => 'card:41:due_soon']);

        $this->assertSame(5, Notification::count());
    }

    public function test_notify_many_returns_what_it_wrote_and_skips_duplicates_by_dedupe_key(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();

        $first = $this->notifier()->notifyMany([$a->id, $b->id, $c->id, $a->id], 'card.due_soon', 'Due tomorrow', [
            'dedupe_key' => 'card:41:due_soon',
        ]);

        // Four ids, three people.
        $this->assertSame(3, $first);
        $this->assertSame(3, Notification::count());

        $second = $this->notifier()->notifyMany([$a->id, $b->id, $c->id], 'card.due_soon', 'Due tomorrow', [
            'dedupe_key' => 'card:41:due_soon',
        ]);

        $this->assertSame(0, $second);
        $this->assertSame(3, Notification::count());
    }

    // ------------------------------------------------------------------ reading

    public function test_unread_count_and_recent_never_leak_another_users_rows(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        Notification::factory()->count(2)->create(['user_id' => $mine->id]);
        Notification::factory()->read()->create(['user_id' => $mine->id]);
        Notification::factory()->count(4)->create(['user_id' => $theirs->id]);

        $this->assertSame(2, $this->notifier()->unreadCount($mine->id));
        $this->assertSame(4, $this->notifier()->unreadCount($theirs->id));

        $recent = $this->notifier()->recent($mine->id);

        $this->assertCount(3, $recent);
        $this->assertSame([$mine->id], $recent->pluck('user_id')->unique()->all());

        // Arrays out, never models.
        $this->assertIsArray($recent->first());

        $this->assertCount(2, $this->notifier()->recent($mine->id, 20, true));
    }

    public function test_recent_is_newest_first_and_respects_the_limit(): void
    {
        $user = User::factory()->create();

        $old = Notification::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(3)]);
        $new = Notification::factory()->create(['user_id' => $user->id, 'created_at' => now()->subMinute()]);

        $recent = $this->notifier()->recent($user->id);

        $this->assertSame([$new->id, $old->id], $recent->pluck('id')->all());
        $this->assertCount(1, $this->notifier()->recent($user->id, 1));
    }

    // ------------------------------------------------------------- marking read

    public function test_mark_read_twice_does_not_move_read_at_and_returns_the_same_value(): void
    {
        $user = User::factory()->create();
        $row = Notification::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($this->notifier()->markRead($row->id, $user->id));

        $readAt = $row->fresh()->read_at;
        $this->assertNotNull($readAt);

        $this->travel(10)->minutes();

        $this->assertTrue($this->notifier()->markRead($row->id, $user->id));

        $this->assertTrue(
            $readAt->equalTo($row->fresh()->read_at),
            'read_at moved on the second call, so "first read at" would come to mean "last looked at".',
        );
    }

    public function test_mark_read_refuses_another_users_notification(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $row = Notification::factory()->create(['user_id' => $theirs->id]);

        // A notification id is not a capability.
        $this->assertFalse($this->notifier()->markRead($row->id, $mine->id));
        $this->assertNull($row->fresh()->read_at);

        $this->assertFalse($this->notifier()->markRead(9999, $mine->id));
    }

    public function test_mark_all_read_returns_the_number_it_changed_and_is_a_no_op_the_second_time(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        Notification::factory()->count(3)->create(['user_id' => $mine->id]);
        Notification::factory()->read()->create(['user_id' => $mine->id]);
        Notification::factory()->count(2)->create(['user_id' => $theirs->id]);

        $this->assertSame(3, $this->notifier()->markAllRead($mine->id));
        $this->assertSame(0, $this->notifier()->markAllRead($mine->id));

        $this->assertSame(0, $this->notifier()->unreadCount($mine->id));
        $this->assertSame(2, $this->notifier()->unreadCount($theirs->id));
    }

    public function test_mark_unread_puts_a_row_back_and_is_itself_idempotent(): void
    {
        $user = User::factory()->create();
        $row = Notification::factory()->read()->create(['user_id' => $user->id]);

        $this->assertFalse($row->markUnread());
        $this->assertNull($row->fresh()->read_at);

        $this->assertFalse($row->markUnread());
        $this->assertNull($row->fresh()->read_at);
    }

    public function test_the_model_scopes_split_the_pile(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Notification::factory()->count(2)->create(['user_id' => $user->id]);
        Notification::factory()->read()->create(['user_id' => $user->id]);
        Notification::factory()->create(['user_id' => $other->id]);

        $this->assertSame(3, Notification::query()->forUser($user->id)->count());
        $this->assertSame(2, Notification::query()->forUser($user->id)->unread()->count());
        $this->assertSame(1, Notification::query()->forUser($user->id)->read()->count());
    }

    // -------------------------------------------------------------------- prune

    public function test_prune_deletes_only_rows_older_than_the_cutoff_and_nothing_the_second_time(): void
    {
        $user = User::factory()->create();

        Notification::factory()->count(2)->olderThan(120)->create(['user_id' => $user->id]);
        Notification::factory()->count(3)->create(['user_id' => $user->id, 'created_at' => now()->subDays(10)]);

        $this->assertSame(2, $this->notifier()->prune(90));
        $this->assertSame(3, Notification::count());

        $this->assertSame(0, $this->notifier()->prune(90));
        $this->assertSame(3, Notification::count());
    }

    public function test_the_prune_command_runs_and_is_registered_on_the_scheduler(): void
    {
        $user = User::factory()->create();
        Notification::factory()->olderThan(200)->create(['user_id' => $user->id]);
        Notification::factory()->create(['user_id' => $user->id]);

        $this->artisan('core:prune-notifications', ['--days' => 90])
            ->expectsOutputToContain('Deleted 1')
            ->assertExitCode(0);

        $this->assertSame(1, Notification::count());

        $this->artisan('core:prune-notifications', ['--days' => 90])
            ->expectsOutputToContain('Nothing older than')
            ->assertExitCode(0);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'core:prune-notifications'));

        $this->assertCount(1, $events, 'core:prune-notifications is not on the scheduler.');
        $this->assertNotNull(
            $events->first()->withoutOverlapping,
            'The prune must be withoutOverlapping().',
        );
    }

    public function test_prune_refuses_a_window_of_zero_days(): void
    {
        $user = User::factory()->create();
        Notification::factory()->create(['user_id' => $user->id]);

        $this->artisan('core:prune-notifications', ['--days' => 0])->assertExitCode(1);

        $this->assertSame(1, Notification::count());
    }

    // --------------------------------------------------------------------- page

    public function test_the_page_renders_shows_an_unread_badge_and_marks_rows_read(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Notification::factory()->count(2)->create([
            'user_id' => $user->id,
            'title' => 'INV-0041 to Northwind Ltd is overdue',
            'event' => 'invoice.overdue',
        ]);

        $one = Notification::query()->first();

        $component = Livewire::test('core::notifications')
            ->assertOk()
            ->assertSee('INV-0041 to Northwind Ltd is overdue')
            ->assertViewHas('unread', 2);

        // Marking one visible row read is visible, so it says nothing.
        $component->call('markRead', $one->id)
            ->assertViewHas('unread', 1)
            ->assertNotDispatched('toast');

        $this->assertNotNull($one->fresh()->read_at);

        // A bulk change reaches rows that may be below the fold, so it reports.
        $component->call('markAllRead')
            ->assertViewHas('unread', 0)
            ->assertDispatched('toast');

        // Nothing happened, so nothing is announced.
        $component->call('markAllRead')->assertNotDispatched('toast');
    }

    public function test_the_page_filters_to_unread_only_without_announcing_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Notification::factory()->create(['user_id' => $user->id, 'title' => 'Still waiting on you']);
        Notification::factory()->read()->create(['user_id' => $user->id, 'title' => 'Dealt with already']);

        Livewire::test('core::notifications')
            ->assertSee('Dealt with already')
            ->call('toggleUnreadOnly')
            ->assertNotDispatched('toast')
            ->assertSee('Still waiting on you')
            ->assertDontSee('Dealt with already');
    }

    public function test_the_page_never_shows_another_users_notifications(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $this->actingAs($mine);

        Notification::factory()->create(['user_id' => $theirs->id, 'title' => 'Not for you at all']);

        Livewire::test('core::notifications')
            ->assertDontSee('Not for you at all')
            ->assertViewHas('unread', 0);
    }

    public function test_an_unreadable_cursor_is_the_first_page_rather_than_a_stack_trace(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Notification::factory()->create(['user_id' => $user->id, 'title' => 'The newest thing']);

        Livewire::test('core::notifications')
            ->set('cursor', 'not-a-cursor-at-all')
            ->assertOk()
            ->assertSee('The newest thing');
    }

    /**
     * This is the whole reason `title`, `body` and `url` are denormalised.
     *
     * Core resolving a polymorphic subject to a display string would mean Core
     * knowing about every module — and it would mean the feed 500ing the moment
     * a card was deleted.
     */
    public function test_a_notification_whose_subject_has_been_deleted_still_renders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create(['name' => 'Northwind Ltd']);

        $this->notifier()->notify($user->id, 'invoice.overdue', 'INV-0041 to Northwind Ltd is overdue', [
            'subject' => $customer,
            'url' => '/accounting/invoices/1',
        ]);

        $customer->forceDelete();

        $this->assertNull(Customer::withTrashed()->find($customer->id));

        Livewire::test('core::notifications')
            ->assertOk()
            ->assertSee('INV-0041 to Northwind Ltd is overdue');

        $this->get('/notifications')
            ->assertOk()
            ->assertSee('INV-0041 to Northwind Ltd is overdue');
    }

    public function test_the_header_bell_links_to_the_feed_and_carries_the_unread_count(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('core.notifications').'"', false)
            ->assertDontSee('data-kargah-bell-count', false);

        Notification::factory()->count(3)->create(['user_id' => $user->id]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-kargah-bell-count', false)
            ->assertSee('>3</span>', false);
    }

    public function test_the_sidebar_carries_the_feed_and_does_not_collide_with_socials(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/notifications')
            ->assertOk()
            ->assertSee('href="'.route('core.notifications').'"', false)
            ->assertSee('href="'.route('social.notifications').'"', false);

        $this->assertNotSame(route('core.notifications'), route('social.notifications'));
    }

    // ---------------------------------------------------------------- migration

    public function test_the_migration_drops_the_table_cleanly_and_puts_it_back(): void
    {
        $path = base_path('Modules/Core/database/migrations/2026_01_01_000005_create_user_notifications_table.php');

        $this->assertFileExists($path);

        $migration = require $path;

        $migration->down();
        $this->assertFalse(Schema::hasTable('user_notifications'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('user_notifications'));

        foreach (['id', 'user_id', 'subject_type', 'subject_id', 'event', 'title',
            'body', 'url', 'actor_id', 'read_at', 'dedupe_key', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('user_notifications', $column), $column.' is missing.');
        }

        // Rows are immutable except for read_at.
        $this->assertFalse(Schema::hasColumn('user_notifications', 'updated_at'));

        // Not soft deleted: a notification is not something a person created.
        $this->assertFalse(Schema::hasColumn('user_notifications', 'deleted_at'));
    }

    /**
     * The table is `user_notifications` so that Laravel's own stays free.
     *
     * `App\Models\User` uses `Notifiable`, and dropping the trait is not an
     * option — `CanResetPassword::sendPasswordResetNotification()` calls
     * `$this->notify()`. So the two must not share a name, and this is what
     * stops somebody renaming it back on the grounds that nothing currently
     * breaks.
     */
    public function test_the_table_does_not_squat_on_laravels_own_notifications_table(): void
    {
        $this->assertSame('user_notifications', (new Notification)->getTable());

        $this->assertFalse(
            Schema::hasTable('notifications'),
            'Core must leave `notifications` free for Illuminate\Notifications\DatabaseNotification.',
        );

        // The trait that makes this matter is still on User, and password reset
        // depends on it.
        $this->assertContains(
            Notifiable::class,
            class_uses_recursive(User::class),
        );
    }
}
