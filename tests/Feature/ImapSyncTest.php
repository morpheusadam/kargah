<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Jobs\SyncMailAccount;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailAttachment;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\Imap\FakeConnector;
use Modules\Mailbox\Services\Imap\RemoteAttachment;
use Modules\Mailbox\Services\Imap\RemoteMessage;
use Tests\TestCase;

/**
 * Reading mail on a host that will kill you.
 *
 * The three properties this file exists to prove are the three that decide
 * whether the sync can run from cron on shared hosting at all:
 *
 * 1. A run that is killed halfway and restarted stores every message exactly
 *    once. Proved by actually stopping a fetch mid-window, not by reasoning
 *    about it.
 * 2. A mailbox far larger than one execution slot syncs across several ticks,
 *    with no tick doing more than one chunk of work.
 * 3. Running the same chunk twice changes nothing the second time.
 *
 * The ticks are real. Each one runs the scheduled command and then drains the
 * queue with `--stop-when-empty`, which is exactly what `routes/console.php`
 * puts on cron — no daemon anywhere, and a job that dies takes only itself
 * down. Using the `sync` driver instead would have hidden the one thing worth
 * checking, which is that the command and the work are separate processes.
 *
 * Nothing here touches the network. The real webklex client is never
 * constructed: `mailbox.sync.connector` names the in-memory fake for the whole
 * of this file, and there are no IMAP credentials on a developer's machine to
 * construct it with.
 */
class ImapSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeConnector $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-01 09:00:00');

        $this->mailbox = new FakeConnector;

        $this->app->instance(FakeConnector::class, $this->mailbox);

        config([
            'mailbox.sync.connector' => FakeConnector::class,
            'mailbox.sync.chunk_size' => 100,
            'mailbox.sync.chunks_per_tick' => 1,
            'mailbox.sync.accounts_per_tick' => 5,

            // A tick is a cron minute: the scheduler dispatches, a worker
            // drains what is waiting and exits. Nothing stays alive between
            // ticks, which is the whole hosting constraint.
            'queue.default' => 'database',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* Fixtures ---------------------------------------------------------------- */

    private function account(array $attributes = []): MailAccount
    {
        return MailAccount::factory()->create($attributes);
    }

    /**
     * A run of unrelated messages, oldest first, UIDs contiguous from $firstUid.
     *
     * @return list<RemoteMessage>
     */
    private function messages(int $count, int $firstUid = 1, string $prefix = 'm'): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = new RemoteMessage(
                uid: $firstUid + $i,
                messageId: $prefix.'-'.$i.'@northwind.example',
                subject: 'Message '.$i,
                fromName: 'Sam Reeve',
                fromEmail: 'sam@northwind.example',
                to: ['studio@kargah.local'],
                textBody: 'Body of message '.$i,
                receivedAt: Carbon::parse('2026-04-01 08:00:00')->addMinutes($i),
            );
        }

        return $out;
    }

    /**
     * One cron minute: the scheduler dispatches, then a worker drains and exits.
     */
    private function tick(): void
    {
        $this->assertSame(0, Artisan::call('mailbox:sync-imap'), Artisan::output());

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--tries' => 1,
        ]);
    }

    /** How many stored messages share a message id with another. */
    private function duplicateMessageIds(): int
    {
        return DB::table('emails')
            ->select('message_id')
            ->groupBy('message_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();
    }

    /* The command dispatches; it never reads ---------------------------------- */

    public function test_the_scheduled_command_queues_work_rather_than_doing_it(): void
    {
        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(2_000));

        Artisan::call('mailbox:sync-imap');

        $this->assertSame(0, Email::count(), 'The command must not store a message itself.');
        $this->assertSame(0, $this->mailbox->delivered, 'The command must not fetch a message body.');
        $this->assertSame(1, DB::table('jobs')->count(), 'One chunk should be waiting for the worker.');
    }

    /* 1. A kill mid-run, then a restart ---------------------------------------- */

    public function test_killing_the_sync_mid_chunk_and_restarting_stores_every_message_once(): void
    {
        config(['mailbox.sync.chunk_size' => 10]);

        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(10));

        // The plug is pulled after five messages: rows already committed stay
        // committed, and nothing gets to write the cursor.
        $this->mailbox->killAfter(5);

        $this->tick();

        $this->assertSame(5, Email::count(), 'Half the mailbox should have survived the kill.');
        $this->assertNull($account->fresh()->sync_cursor, 'A killed chunk must not record progress.');
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'The killed chunk should be the only casualty.');

        // Restart.
        $this->mailbox->killAfter(null);

        $this->tick();

        $this->assertSame(10, Email::count(), 'The restart should finish the mailbox.');
        $this->assertSame(0, $this->duplicateMessageIds(), 'No message id may appear twice.');
        $this->assertSame(
            10,
            DB::table('emails')->distinct()->count('message_id'),
            'Ten stored rows should be ten distinct messages.',
        );
        $this->assertSame(10, $account->fresh()->sync_cursor);
    }

    /* 2. A mailbox bigger than one execution slot ------------------------------ */

    public function test_a_two_thousand_message_mailbox_syncs_across_several_ticks(): void
    {
        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(2_000));

        $ticks = 0;
        $stored = 0;

        while (Email::count() < 2_000 && $ticks < 40) {
            $this->tick();
            $ticks++;

            $now = Email::count();

            $this->assertLessThanOrEqual(
                100,
                $now - $stored,
                'Tick '.$ticks.' stored more than one chunk, which is a tick that can exceed max_execution_time.',
            );

            $stored = $now;
        }

        $this->assertSame(20, $ticks, 'Two thousand messages in chunks of a hundred is twenty ticks.');
        $this->assertGreaterThan(1, $ticks, 'A mailbox this size must not be synced in one tick.');

        $this->assertLessThanOrEqual(
            100,
            $this->mailbox->largestWindow(),
            'No single fetch may ask the server for more than a chunk.',
        );

        $this->assertSame(2_000, Email::count());
        $this->assertSame(2_000, DB::table('emails')->distinct()->count('message_id'));
        $this->assertSame(0, $this->duplicateMessageIds());
        $this->assertSame(2_000, $account->fresh()->sync_cursor);
    }

    /* 3. Every job is idempotent ----------------------------------------------- */

    public function test_running_the_same_chunk_twice_changes_nothing(): void
    {
        $account = $this->account();

        $messages = $this->messages(3);
        $messages[1] = new RemoteMessage(
            uid: $messages[1]->uid,
            messageId: $messages[1]->messageId,
            subject: $messages[1]->subject,
            fromName: $messages[1]->fromName,
            fromEmail: $messages[1]->fromEmail,
            receivedAt: $messages[1]->receivedAt,
            attachments: [new RemoteAttachment('quote.pdf', 'application/pdf', 8_192, null, '2')],
        );

        $this->mailbox->seed($account, $messages);

        $this->tick();

        $this->assertSame(3, Email::count());
        $this->assertSame(1, EmailAttachment::count());

        $before = [
            'emails' => Email::query()->orderBy('id')->get(['id', 'message_id', 'email_thread_id', 'updated_at'])->toArray(),
            'threads' => EmailThread::query()->orderBy('id')->get(['id', 'message_count', 'last_message_at'])->toArray(),
            'attachments' => EmailAttachment::query()->orderBy('id')->get(['id', 'email_id', 'filename', 'size_bytes'])->toArray(),
            'cursor' => $account->fresh()->sync_cursor,
        ];

        // The identical chunk, run again — the same thing the next tick would do
        // if the cursor write had been the part that was lost.
        Carbon::setTestNow('2026-04-01 09:30:00');

        SyncMailAccount::dispatchSync($account->fresh(), 'INBOX', 1, 3, 1);

        $this->assertSame(3, Email::count(), 'A second run must not add a row.');
        $this->assertSame(3, EmailThread::count(), 'A second run must not add a thread.');
        $this->assertSame(1, EmailAttachment::count(), 'A second run must not add an attachment.');
        $this->assertSame(0, $this->duplicateMessageIds());

        $this->assertEquals($before['emails'], Email::query()->orderBy('id')->get(['id', 'message_id', 'email_thread_id', 'updated_at'])->toArray());
        $this->assertEquals($before['threads'], EmailThread::query()->orderBy('id')->get(['id', 'message_count', 'last_message_at'])->toArray());
        $this->assertEquals($before['attachments'], EmailAttachment::query()->orderBy('id')->get(['id', 'email_id', 'filename', 'size_bytes'])->toArray());
        $this->assertSame($before['cursor'], $account->fresh()->sync_cursor);
    }

    /* Resumability that does not depend on bookkeeping ------------------------- */

    public function test_a_lost_cursor_costs_nothing_but_a_second_pass(): void
    {
        config(['mailbox.sync.chunk_size' => 5]);

        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(10));

        $this->tick();
        $this->tick();

        $this->assertSame(10, Email::count());

        // The cursor is the optimisation; the unique index is the correctness.
        // Throw the optimisation away and the sync still converges on the same
        // ten rows.
        $account->update(['sync_cursor' => null]);

        $this->tick();
        $this->tick();

        $this->assertSame(10, Email::count());
        $this->assertSame(0, $this->duplicateMessageIds());
    }

    public function test_a_changed_uid_validity_discards_the_cursor_and_resyncs_without_duplicates(): void
    {
        // Wide enough that the restored mailbox's higher UIDs still fit one
        // chunk; the point under test is the reset, not the chunking.
        config(['mailbox.sync.chunk_size' => 1_000]);

        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(5));

        $this->tick();

        $this->assertSame(5, Email::count());
        $this->assertSame(1, $account->fresh()->uid_validity);
        $this->assertSame(5, $account->fresh()->sync_cursor);

        // The mailbox is restored from backup: same messages, new UIDs, and the
        // server says so by changing UIDVALIDITY.
        $this->mailbox->seed($account, $this->messages(5, firstUid: 900));
        $this->mailbox->uidValidity($account, 77);

        $this->tick();

        $account->refresh();

        $this->assertSame(77, $account->uid_validity, 'The new UIDVALIDITY should have been adopted.');
        $this->assertSame(904, $account->sync_cursor, 'The cursor should be expressed in the new UIDs.');
        $this->assertSame(5, Email::count(), 'A resync must rewrite rows, not duplicate them.');
        $this->assertSame(0, $this->duplicateMessageIds());
        $this->assertSame(900, Email::query()->orderBy('id')->value('uid'), 'The stored UID should be the new one.');
    }

    public function test_a_chunk_queued_before_a_uid_validity_reset_is_dropped(): void
    {
        $account = $this->account(['uid_validity' => 77, 'sync_cursor' => null]);

        $this->mailbox->seed($account, $this->messages(3));

        // Queued while the account still believed in UIDVALIDITY 1; by the time
        // it runs, those UIDs name different messages.
        SyncMailAccount::dispatchSync($account, 'INBOX', 1, 100, 1);

        $this->assertSame(0, Email::count(), 'A stale chunk must not file the wrong UIDs against the right ids.');
        $this->assertNull($account->fresh()->sync_cursor);
    }

    /* Failures are recorded, not fatal ---------------------------------------- */

    public function test_a_refused_connection_is_recorded_and_the_other_accounts_still_sync(): void
    {
        $broken = $this->account(['email' => 'broken@kargah.local']);
        $working = $this->account(['email' => 'working@kargah.local']);

        $this->mailbox->fail($broken, 'connection refused');
        $this->mailbox->seed($working, $this->messages(3));

        $this->assertSame(0, Artisan::call('mailbox:sync-imap'), 'A broken mailbox is not a failed run.');

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $this->assertStringContainsString('connection refused', (string) $broken->fresh()->last_error);
        $this->assertTrue($broken->fresh()->hasFailed());

        $this->assertNull($working->fresh()->last_error, 'One bad account must not mark a good one.');
        $this->assertSame(3, Email::where('mail_account_id', $working->getKey())->count());
    }

    public function test_a_mailbox_that_fails_after_dispatch_records_the_error_without_failing_the_job(): void
    {
        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(3));

        Artisan::call('mailbox:sync-imap');

        // The server goes away between the command examining it and the worker
        // reaching the chunk — a whole cron minute later, which is long enough.
        $this->mailbox->fail($account, 'the connection was reset');

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'An unreachable server is not a failed job.');
        $this->assertStringContainsString('the connection was reset', (string) $account->fresh()->last_error);
        $this->assertNull($account->fresh()->sync_cursor, 'Nothing was read, so nothing was recorded as read.');

        // And it recovers on the next tick without intervention.
        $this->mailbox->heal($account);

        $this->tick();

        $this->assertSame(3, Email::count());
        $this->assertNull($account->fresh()->last_error);
    }

    public function test_an_inactive_account_is_left_alone(): void
    {
        $account = $this->account(['is_active' => false]);

        $this->mailbox->seed($account, $this->messages(3));

        $this->tick();

        $this->assertSame(0, Email::count());
        $this->assertSame(0, $this->mailbox->opened, 'An inactive mailbox should not even be opened.');
    }

    /* Customers and threads ---------------------------------------------------- */

    public function test_the_sender_is_resolved_to_a_customer_as_each_message_is_stored(): void
    {
        $customer = Customer::factory()->create(['email' => 'Sam@Northwind.example']);

        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(2));

        $this->tick();

        $this->assertSame(2, Email::whereNotNull('customer_id')->count());
        $this->assertSame($customer->getKey(), Email::query()->first()->customer_id);
    }

    public function test_replies_are_threaded_and_the_thread_counters_stay_accurate(): void
    {
        $account = $this->account();

        $root = new RemoteMessage(
            uid: 1,
            messageId: 'root@northwind.example',
            subject: 'Quote for the rebuild',
            fromEmail: 'sam@northwind.example',
            receivedAt: Carbon::parse('2026-04-01 08:00:00'),
        );

        $reply = new RemoteMessage(
            uid: 2,
            messageId: 'reply@northwind.example',
            inReplyTo: 'root@northwind.example',
            subject: 'Re: Quote for the rebuild',
            fromEmail: 'lee@kargah.local',
            receivedAt: Carbon::parse('2026-04-01 08:40:00'),
        );

        $this->mailbox->seed($account, [$root, $reply]);

        $this->tick();

        $this->assertSame(1, EmailThread::count(), 'A reply belongs in its parent thread.');

        $thread = EmailThread::query()->first();

        $this->assertSame(2, $thread->message_count);
        $this->assertTrue($thread->last_message_at->equalTo(Carbon::parse('2026-04-01 08:40:00')));
        $this->assertEqualsCanonicalizing(
            ['sam@northwind.example', 'lee@kargah.local'],
            $thread->participants,
        );
    }

    public function test_a_reply_synced_before_its_parent_still_ends_in_one_thread(): void
    {
        config(['mailbox.sync.chunk_size' => 1]);

        $account = $this->account();

        // The reply has the lower UID, so it is read a whole tick before the
        // message it answers — which is what happens when the parent lives in
        // another folder or arrived out of order.
        $reply = new RemoteMessage(
            uid: 1,
            messageId: 'reply@northwind.example',
            inReplyTo: 'root@northwind.example',
            subject: 'Re: Quote',
            fromEmail: 'lee@kargah.local',
            receivedAt: Carbon::parse('2026-04-01 08:40:00'),
        );

        $root = new RemoteMessage(
            uid: 2,
            messageId: 'root@northwind.example',
            subject: 'Quote',
            fromEmail: 'sam@northwind.example',
            receivedAt: Carbon::parse('2026-04-01 08:00:00'),
        );

        $this->mailbox->seed($account, [$reply, $root]);

        $this->tick();
        $this->tick();

        $this->assertSame(2, Email::count());
        $this->assertSame(1, EmailThread::count());
        $this->assertSame(2, EmailThread::query()->first()->message_count);
    }

    /* Gaps and flags ----------------------------------------------------------- */

    public function test_a_window_of_deleted_uids_still_advances_the_cursor(): void
    {
        config(['mailbox.sync.chunk_size' => 5]);

        $account = $this->account();

        // UIDs 1 to 5 were deleted on the server long ago; only 6 to 8 remain.
        $this->mailbox->seed($account, $this->messages(3, firstUid: 6));

        $this->tick();

        $this->assertSame(5, $account->fresh()->sync_cursor, 'An empty window is a finished window.');
        $this->assertSame(0, Email::count());

        $this->tick();

        $this->assertSame(3, Email::count());
        $this->assertSame(8, $account->fresh()->sync_cursor);
    }

    public function test_a_locally_read_message_is_not_marked_unread_again_by_a_resync(): void
    {
        config(['mailbox.sync.chunk_size' => 10]);

        $account = $this->account();

        $this->mailbox->seed($account, $this->messages(2));

        $this->tick();

        $email = Email::query()->first();
        $email->forceFill(['is_read' => true])->save();

        $account->update(['sync_cursor' => null]);

        $this->tick();

        $this->assertTrue($email->fresh()->is_read, 'Re-reading a message must not undo the owner marking it read.');
        $this->assertSame(2, Email::count());
    }
}
