<?php

use Modules\Mailbox\Services\Imap\WebklexConnector;

return [
    'name' => 'Mailbox',

    /*
     * Receiving mail.
     *
     * Every number here exists because the target is shared hosting, where a
     * request gets perhaps thirty seconds and a process that will not exit is
     * what gets an account suspended. The scheduled command uses them to bound
     * how much it asks for; it never does the work itself.
     */
    'sync' => [

        /*
         * Which IMAP implementation to use.
         *
         * A config value rather than a container binding so an install can
         * point Mailbox at a different client — or at
         * `Services\Imap\FakeConnector` while diagnosing a host — without
         * editing code. Every test replaces this; the real client is never
         * constructed in one.
         */
        'connector' => WebklexConnector::class,

        /*
         * Messages fetched and stored by one queued job.
         *
         * A hundred messages with bodies is a few seconds of network and a few
         * hundred inserts, which finishes well inside a 30-second
         * `max_execution_time` with room for a slow server. Raise it on a VPS;
         * lower it if the host's limit is 10 seconds or its memory is 64 MB.
         */
        'chunk_size' => (int) env('MAILBOX_SYNC_CHUNK_SIZE', 100),

        /*
         * Jobs queued per account per tick.
         *
         * One, because the worker gets 50 seconds a minute and shares them with
         * campaigns, crawls and everything else. One chunk per account every
         * five minutes still drains a 2,000-message backlog inside two hours,
         * and it never lets a single mailbox monopolise the queue. Raise it
         * only where the worker has time to spare.
         */
        'chunks_per_tick' => (int) env('MAILBOX_SYNC_CHUNKS_PER_TICK', 1),

        /*
         * Accounts examined per tick.
         *
         * The command opens one connection per account to read UIDNEXT, which
         * is cheap but not free, so the account list is bounded too — least
         * recently synced first, which is what the `is_active, last_synced_at`
         * index on `mail_accounts` exists for. Fifty accounts therefore take
         * ten ticks to come round rather than one tick of fifty handshakes.
         */
        'accounts_per_tick' => (int) env('MAILBOX_SYNC_ACCOUNTS_PER_TICK', 5),

        /*
         * Seconds webklex may spend on one IMAP conversation.
         *
         * Short on purpose. A sync that fails now retries in five minutes; a
         * socket that hangs holds a process open until the host kills it.
         */
        'timeout' => (int) env('MAILBOX_IMAP_TIMEOUT', 20),
    ],

    /*
     * Sending mail.
     *
     * The same hosting constraint as above, from the other direction. The
     * scheduled command finds a bounded amount of outstanding work and queues
     * small jobs; nothing here does the sending itself, and no single number
     * may be raised to the point where one job outlives `max_execution_time`.
     */
    'sending' => [

        /*
         * Recipients one queued chunk may take.
         *
         * Fifty messages is a few seconds of SMTP or fifty API calls, which
         * finishes well inside a 30-second `max_execution_time` with room for a
         * slow provider. A 500-recipient campaign is therefore ten chunks
         * across as many ticks as the worker needs, and no tick is longer than
         * any other. Raise it on a VPS; lower it if the host's limit is 10
         * seconds.
         */
        'chunk_size' => (int) env('MAILBOX_SEND_CHUNK_SIZE', 50),

        /*
         * Chunks queued per campaign per tick.
         *
         * One, because the worker gets 50 seconds a minute and shares them with
         * the IMAP sync, crawls and everything else. One chunk a minute still
         * drains 500 recipients inside ten minutes, and it never lets a single
         * campaign monopolise the queue. Raise it only where the worker has
         * time to spare.
         */
        'chunks_per_tick' => (int) env('MAILBOX_SEND_CHUNKS_PER_TICK', 1),

        /*
         * Campaigns examined per tick.
         *
         * Bounded for the same reason the account list is: the command reads
         * each campaign's outstanding count and runs its pre-flight, which is
         * cheap but not free. Five campaigns sending at once is already more
         * than a freelance install will ever have.
         */
        'campaigns_per_tick' => (int) env('MAILBOX_SEND_CAMPAIGNS_PER_TICK', 5),

        /*
         * How long a claim may look live before it is written off, in minutes.
         *
         * A worker killed mid-send leaves its recipient on `claimed` with no
         * way to know whether the provider accepted the message. Past this
         * window the row is written off as failed — **not** retried, because
         * sending a campaign twice to the same person is worse than not sending
         * it at all. Long enough that a slow provider is not overtaken by the
         * next tick, short enough that a crash does not strand a recipient for
         * a working day.
         */
        'stale_claim_minutes' => (int) env('MAILBOX_STALE_CLAIM_MINUTES', 15),
    ],
];
