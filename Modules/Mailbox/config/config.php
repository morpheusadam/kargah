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
     * Receiving mail the other way: pushed, not polled.
     *
     * A Cloudflare Email Worker bound to the domain's routing rules posts each
     * message here the moment it arrives. Nothing above applies — there is no
     * connection to open, no cursor to resume and no chunk to bound, because the
     * unit of work is one message and it is already in the request body.
     */
    'inbound' => [

        /*
         * The shared secret the Worker sends in `X-Inbound-Secret`.
         *
         * Null disables the endpoint outright rather than leaving it open: an
         * install that has not set this has not deployed a Worker either, and a
         * route that accepts anything would let anyone write rows into someone
         * else's inbox. Compared with `hash_equals`, so a wrong secret costs the
         * same time as a right one and cannot be guessed a byte at a time.
         *
         * Not the application key, and not reused from anything else. It is
         * handed to a third party — Cloudflare — and a secret one service holds
         * should never be a secret another service also relies on.
         */
        'secret' => env('MAILBOX_INBOUND_SECRET'),

        /*
         * The size past which a posted message is refused, in kilobytes.
         *
         * Cloudflare will not hand a Worker more than about 25 MB, but this
         * endpoint is reachable by anyone who has the secret and the body is
         * read into memory to be parsed. 30 MB leaves room above the real
         * ceiling while still being a number PHP can hold on a 128 MB shared
         * host without the parse itself becoming the outage.
         */
        'max_size_kb' => (int) env('MAILBOX_INBOUND_MAX_SIZE_KB', 30720),

        /*
         * The folder pushed messages are filed under.
         *
         * 'INBOX' because that is what every other part of Mailbox already
         * spells — the inbox filters on it, and a pushed message that landed
         * somewhere else would be stored correctly and then be invisible.
         */
        'folder' => env('MAILBOX_INBOUND_FOLDER', 'INBOX'),
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

    /*
     * What comes back from a message after it has been read.
     *
     * Both are on by default and both are a single switch rather than a
     * per-campaign column, because the decision they encode is one an install
     * makes once. Turning either off stops the message being changed at all —
     * no pixel is added and no link is rewritten — rather than merely stopping
     * the recording, so a campaign sent with tracking off carries nothing that
     * has to be explained to anybody who reads its source.
     *
     * The rows already written are left alone, which is what makes turning it
     * off safe: a report from last month still shows what happened last month.
     */
    'tracking' => [

        /*
         * The pixel appended to every HTML body.
         *
         * Worth knowing before trusting the number: most clients block remote
         * images by default and Gmail loads them through a proxy that caches, so
         * an open is evidence that a message was rendered somewhere and nothing
         * stronger. Corporate security gateways render messages too. The figure
         * is a floor with noise on top, which is why the report puts clicks
         * beside it rather than on their own page.
         */
        'opens' => (bool) env('MAILBOX_TRACK_OPENS', true),

        /*
         * Rewriting each link in an HTML body to a redirect that records it.
         *
         * Off is a real choice and not only a privacy one: a rewritten link
         * means the destination a person sees on hover is panel.bineret.com
         * rather than where they are going, which some recipients — and a few
         * filters — read as a warning sign. On is the default because a campaign
         * report with no click rate cannot tell a subject line that got opened
         * from one that got acted on.
         */
        'clicks' => (bool) env('MAILBOX_TRACK_CLICKS', true),
    ],

    /*
     * What a provider's report is allowed to conclude.
     *
     * Hard bounces and complaints suppress on the first one and always have —
     * they are statements that the address is wrong or that the person does not
     * want this, and neither improves with a second opinion. Only the soft
     * bounce needs a number, because it is the only report that means "not now"
     * rather than "not ever".
     */
    'bounce' => [

        /*
         * Consecutive soft bounces before an address is blocked.
         *
         * Three, because a mailbox is allowed to be full over a holiday and a
         * greylist is allowed to defer twice, but an address that has refused
         * three campaigns in a row with nothing getting through between them is
         * not coming back — and every further attempt is another point of
         * bounce rate charged against the sending domain, which is what
         * receiving servers actually score.
         *
         * Consecutive is doing the work: a delivery clears the tally, so this
         * counts a run rather than a lifetime. Set to 0 to switch the behaviour
         * off entirely and keep recording soft bounces without ever acting.
         */
        'soft_threshold' => (int) env('MAILBOX_SOFT_BOUNCE_THRESHOLD', 3),
    ],
];
