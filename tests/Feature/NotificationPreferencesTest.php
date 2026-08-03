<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use Modules\Core\Contracts\NotificationPreferences;
use Modules\Core\Contracts\Notifier;
use Modules\Core\Models\NotificationPreference;
use Modules\Core\Models\NotificationSetting;
use Modules\Core\Support\NotificationEvents;
use Tests\TestCase;

/**
 * Whether `/settings/notifications` actually persists, and whether
 * `Modules\Core\Services\Notifier` actually honours what it saved.
 *
 * The two questions this file exists to answer, precisely: what does "no row"
 * mean for a person who has never opened the page, and what does turning a
 * switch off actually change.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function preferences(): NotificationPreferences
    {
        return app(NotificationPreferences::class);
    }

    private function notifier(): Notifier
    {
        return app(Notifier::class);
    }

    // -------------------------------------------------------------- defaults

    public function test_a_user_with_no_rows_gets_the_documented_default_for_every_event_on_both_channels(): void
    {
        $user = User::factory()->create();

        $prefs = $this->preferences()->forUser($user->id);

        foreach (NotificationEvents::all() as $event => $meta) {
            $this->assertSame($meta['default'], $prefs[$event], $event.' did not read its documented default.');
            $this->assertSame($meta['default']['in_app'], $this->preferences()->allows($user->id, $event, 'in_app'));
            $this->assertSame($meta['default']['email'], $this->preferences()->allows($user->id, $event, 'email'));
        }

        $this->assertSame(0, NotificationPreference::count(), 'Nothing should be seeded for an untouched user.');
    }

    public function test_a_user_with_no_settings_row_gets_the_documented_digest_and_quiet_hours_defaults(): void
    {
        $user = User::factory()->create();

        $this->assertSame(NotificationEvents::DEFAULT_DIGEST, $this->preferences()->digest($user->id));

        $this->assertSame([
            'enabled' => false,
            'from' => NotificationEvents::DEFAULT_QUIET_FROM,
            'to' => NotificationEvents::DEFAULT_QUIET_TO,
        ], $this->preferences()->quietHours($user->id));

        $this->assertSame(0, NotificationSetting::count());
    }

    public function test_an_unknown_event_defaults_to_allowed_rather_than_being_silently_dropped(): void
    {
        $user = User::factory()->create();

        // The notifier may fire an event the settings page has not caught up
        // to yet; refusing to tell anyone about it would be worse than the
        // alternative, so an unrecognised event reads as allowed.
        $this->assertTrue($this->preferences()->allows($user->id, 'platform.something_new', 'in_app'));
        $this->assertTrue($this->preferences()->allows($user->id, 'platform.something_new', 'email'));

        $written = $this->notifier()->notify($user->id, 'platform.something_new', 'Something new happened');
        $this->assertNotNull($written['id']);
    }

    public function test_saving_an_unknown_event_key_is_refused(): void
    {
        $user = User::factory()->create();

        // The reverse of the above: the *page* must never be able to write a
        // preference row nobody can ever see or edit again because of a typo.
        $this->expectException(InvalidArgumentException::class);

        $this->preferences()->save(
            $user->id,
            ['not.a.real.event' => ['in_app' => true, 'email' => true]],
            'daily',
            false,
            '22:00',
            '08:00',
        );

        $this->assertSame(0, NotificationPreference::count());
    }

    public function test_saving_an_unknown_digest_or_a_badly_shaped_quiet_hours_time_is_refused(): void
    {
        $user = User::factory()->create();

        try {
            $this->preferences()->save($user->id, [], 'hourly', false, '22:00', '08:00');
            $this->fail('An unknown digest frequency should be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        try {
            $this->preferences()->save($user->id, [], 'daily', true, '10pm', '08:00');
            $this->fail('A quiet-hours time not shaped H:i should be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, NotificationSetting::count());
    }

    // ------------------------------------------------------------------ save

    public function test_saving_writes_rows_and_saving_again_with_the_same_values_changes_nothing(): void
    {
        $user = User::factory()->create();

        $events = [
            'invoice.overdue' => ['in_app' => true, 'email' => false],
            'card.due_soon' => ['in_app' => false, 'email' => false],
        ];

        $this->preferences()->save($user->id, $events, 'weekly', true, '23:00', '06:30');

        $this->assertSame(2, NotificationPreference::count());
        $this->assertSame(1, NotificationSetting::count());

        $preferenceTimestamps = NotificationPreference::query()->orderBy('event')->pluck('updated_at', 'event');
        $settingsTimestamp = NotificationSetting::query()->sole()->updated_at;

        $this->travel(10)->minutes();

        // Runs twice, changes nothing.
        $this->preferences()->save($user->id, $events, 'weekly', true, '23:00', '06:30');

        $this->assertSame(2, NotificationPreference::count());
        $this->assertSame(1, NotificationSetting::count());

        foreach (NotificationPreference::query()->orderBy('event')->get() as $row) {
            $this->assertTrue(
                $preferenceTimestamps[$row->event]->equalTo($row->updated_at),
                $row->event.'\'s updated_at moved on an identical second save.',
            );
        }

        $this->assertTrue($settingsTimestamp->equalTo(NotificationSetting::query()->sole()->updated_at));

        $this->assertSame(['in_app' => true, 'email' => false], $this->preferences()->forUser($user->id)['invoice.overdue']);
        $this->assertSame(['in_app' => false, 'email' => false], $this->preferences()->forUser($user->id)['card.due_soon']);
    }

    public function test_saving_different_values_does_update_the_row(): void
    {
        $user = User::factory()->create();

        $this->preferences()->save($user->id, ['invoice.overdue' => ['in_app' => true, 'email' => true]], 'daily', false, '22:00', '08:00');
        $this->preferences()->save($user->id, ['invoice.overdue' => ['in_app' => false, 'email' => true]], 'daily', false, '22:00', '08:00');

        $this->assertSame(1, NotificationPreference::count());
        $this->assertSame(['in_app' => false, 'email' => true], $this->preferences()->forUser($user->id)['invoice.overdue']);
    }

    // ------------------------------------------------------------------ page

    public function test_turning_something_off_and_reloading_the_page_shows_it_off(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.notifications')
            ->assertSet('prefs.invoice_overdue.in_app', true)
            ->set('prefs.invoice_overdue.in_app', false)
            ->call('save')
            ->assertDispatched('toast');

        $this->assertSame(
            ['in_app' => false, 'email' => true],
            $this->preferences()->forUser($user->id)['invoice.overdue'],
        );

        // A fresh mount — the actual bug being fixed: the old page recomputed
        // its fixture on every mount and never looked at what was saved.
        Livewire::test('pages::settings.notifications')
            ->assertSet('prefs.invoice_overdue.in_app', false)
            ->assertSet('prefs.invoice_overdue.email', true);
    }

    public function test_flipping_a_switch_without_saving_does_not_toast_and_does_not_persist(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.notifications')
            ->set('prefs.invoice_overdue.in_app', false)
            ->assertNotDispatched('toast');

        $this->assertSame(0, NotificationPreference::count());
    }

    public function test_saving_persists_the_digest_and_quiet_hours(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.notifications')
            ->set('digest', 'weekly')
            ->set('quietHours', true)
            ->set('quietFrom', '23:00')
            ->set('quietTo', '06:00')
            ->call('save')
            ->assertDispatched('toast');

        $this->assertSame('weekly', $this->preferences()->digest($user->id));
        $this->assertSame([
            'enabled' => true,
            'from' => '23:00',
            'to' => '06:00',
        ], $this->preferences()->quietHours($user->id));
    }

    // -------------------------------------------------------------- notifier

    public function test_the_notifier_honours_a_disabled_event_and_still_honours_an_enabled_one(): void
    {
        $user = User::factory()->create();

        $this->preferences()->save(
            $user->id,
            ['card.due_soon' => ['in_app' => false, 'email' => false]],
            'daily',
            false,
            '22:00',
            '08:00',
        );

        $skipped = $this->notifier()->notify($user->id, 'card.due_soon', '“Send the retainer” is due tomorrow');

        $this->assertNull($skipped['id']);
        $this->assertSame(0, \Modules\Core\Models\Notification::count());

        // A different, still-enabled event for the same user writes normally.
        $written = $this->notifier()->notify($user->id, 'invoice.overdue', 'INV-0041 is overdue');

        $this->assertNotNull($written['id']);
        $this->assertSame(1, \Modules\Core\Models\Notification::count());
    }

    public function test_a_preference_check_does_not_break_dedupe_idempotence(): void
    {
        $user = User::factory()->create();

        $first = $this->notifier()->notify($user->id, 'invoice.overdue', 'INV-0041 is overdue', [
            'dedupe_key' => 'invoice:41:overdue',
        ]);

        $second = $this->notifier()->notify($user->id, 'invoice.overdue', 'A different title', [
            'dedupe_key' => 'invoice:41:overdue',
        ]);

        $this->assertSame(1, \Modules\Core\Models\Notification::count());
        $this->assertSame($first['id'], $second['id']);
    }

    public function test_disabling_an_event_makes_repeated_calls_with_a_dedupe_key_write_nothing_at_all(): void
    {
        $user = User::factory()->create();

        $this->preferences()->save(
            $user->id,
            ['invoice.overdue' => ['in_app' => false, 'email' => false]],
            'daily',
            false,
            '22:00',
            '08:00',
        );

        $first = $this->notifier()->notify($user->id, 'invoice.overdue', 'INV-0041 is overdue', [
            'dedupe_key' => 'invoice:41:overdue',
        ]);
        $second = $this->notifier()->notify($user->id, 'invoice.overdue', 'INV-0041 is overdue', [
            'dedupe_key' => 'invoice:41:overdue',
        ]);

        $this->assertNull($first['id']);
        $this->assertNull($second['id']);
        $this->assertSame(0, \Modules\Core\Models\Notification::count());
    }

    public function test_notify_many_reads_preferences_in_one_query_regardless_of_how_many_users(): void
    {
        $users = User::factory()->count(50)->create();

        // One of the fifty has explicitly opted out; the rest have no rows at
        // all, which is the ordinary case this needs to stay cheap for.
        $this->preferences()->save(
            $users->first()->id,
            ['post.failed' => ['in_app' => false, 'email' => false]],
            'daily',
            false,
            '22:00',
            '08:00',
        );

        DB::enableQueryLog();

        $written = $this->notifier()->notifyMany($users->pluck('id')->all(), 'post.failed', 'A scheduled post failed');

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $preferenceReads = $queries->filter(fn (array $q): bool => str_contains($q['query'], 'notification_preferences'));

        $this->assertCount(1, $preferenceReads, 'channelsForMany() must read preferences in one query, not one per user.');
        $this->assertSame(49, $written);
        $this->assertSame(49, \Modules\Core\Models\Notification::count());
    }

    // ------------------------------------------------------------ quiet hours

    public function test_quiet_hours_off_never_suppresses_email(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->assertFalse($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(23, 30)));
    }

    public function test_quiet_hours_inside_and_outside_a_non_wrapping_window(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->preferences()->save($user->id, [], 'daily', true, '13:00', '14:00');

        $this->assertTrue($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(13, 30)));
        $this->assertFalse($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(12, 59)));
        $this->assertFalse($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(14, 30)));
    }

    public function test_quiet_hours_wraps_midnight_and_is_correct_at_both_ends(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->preferences()->save($user->id, [], 'daily', true, '22:00', '08:00');

        // Well inside, on both sides of midnight.
        $this->assertTrue($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(23, 0)));
        $this->assertTrue($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(2, 0)));

        // Well outside.
        $this->assertFalse($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(12, 0)));

        // The exact boundary minutes: start is inclusive, end is exclusive.
        $this->assertTrue($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(22, 0)));
        $this->assertFalse($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(21, 59)));
        $this->assertTrue($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(7, 59)));
        $this->assertFalse($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(8, 0)));
    }

    public function test_quiet_hours_are_evaluated_in_the_users_own_timezone(): void
    {
        // 22:00–08:00 local. Tokyo is UTC+9, so 22:00 JST is 13:00 UTC.
        $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

        $this->preferences()->save($user->id, [], 'daily', true, '22:00', '08:00');

        $this->assertTrue($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(13, 30)));
        $this->assertFalse($this->preferences()->inQuietHours($user->id, now('UTC')->setTime(12, 0)));
    }

    public function test_quiet_hours_suppress_email_but_never_the_in_app_write(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->preferences()->save(
            $user->id,
            ['invoice.overdue' => ['in_app' => true, 'email' => true]],
            'daily',
            true,
            '22:00',
            '08:00',
        );

        $quietMoment = now('UTC')->setTime(23, 0);

        $this->assertFalse($this->preferences()->allows($user->id, 'invoice.overdue', 'email', $quietMoment));
        $this->assertTrue($this->preferences()->allows($user->id, 'invoice.overdue', 'in_app', $quietMoment));

        // The notifier never even asks about the email channel — it only
        // ever writes the in-app feed — so the row is written regardless.
        $written = $this->notifier()->notify($user->id, 'invoice.overdue', 'INV-0041 is overdue');
        $this->assertNotNull($written['id']);
    }

    // -------------------------------------------------------------- migration

    public function test_the_notification_preferences_migration_drops_cleanly_and_puts_it_back(): void
    {
        $this->assertMigrationRoundTrips(
            'Modules/Core/database/migrations/2026_01_01_000006_create_notification_preferences_table.php',
            'notification_preferences',
            ['id', 'user_id', 'event', 'in_app', 'email', 'created_at', 'updated_at'],
        );
    }

    public function test_the_notification_settings_migration_drops_cleanly_and_puts_it_back(): void
    {
        $this->assertMigrationRoundTrips(
            'Modules/Core/database/migrations/2026_01_01_000007_create_notification_settings_table.php',
            'notification_settings',
            ['id', 'user_id', 'digest', 'quiet_hours_enabled', 'quiet_hours_from', 'quiet_hours_to', 'created_at', 'updated_at'],
        );
    }

    /** @param  list<string>  $columns */
    private function assertMigrationRoundTrips(string $relativePath, string $table, array $columns): void
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path);

        /** @var Migration $migration */
        $migration = require $path;

        $migration->down();
        $this->assertFalse(Schema::hasTable($table));

        $migration->up();
        $this->assertTrue(Schema::hasTable($table));

        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn($table, $column), $table.'.'.$column.' is missing.');
        }
    }
}
