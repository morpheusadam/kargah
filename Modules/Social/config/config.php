<?php

return [
    'name' => 'Social',

    /*
     * How many due posts one `social:publish-due` tick may dispatch.
     *
     * The command never publishes; it dispatches one small job per post, and
     * this is the ceiling on how many. Bounded on purpose: on shared hosting a
     * command that walks an unbounded backlog is the command that exceeds
     * `max_execution_time` and gets the account suspended. Whatever is left
     * over is picked up by the next tick a minute later.
     */
    'due_batch' => (int) env('SOCIAL_DUE_BATCH', 25),

    /*
     * How many notifications to ask a network for per account, per run.
     *
     * Ingestion is idempotent on (social_account_id, remote_id), so a small
     * page that overlaps the previous run is cheaper than a large one that
     * risks missing the gap.
     */
    'notification_batch' => (int) env('SOCIAL_NOTIFICATION_BATCH', 40),

    /*
     * How long a claim may look live before another run may take it, in minutes.
     *
     * A worker killed mid-publish leaves its target on `publishing`, and nothing
     * would ever move it again — forward-only status is exactly what stops a
     * retry resending a success. This window is the escape hatch: past it the
     * claim is assumed dead and the target becomes claimable. Long enough that a
     * slow provider is not overtaken by a second attempt, short enough that a
     * crash does not strand a post for a working day.
     */
    'stale_claim_minutes' => (int) env('SOCIAL_STALE_CLAIM_MINUTES', 15),
];
