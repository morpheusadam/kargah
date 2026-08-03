<?php

return [
    'name' => 'Data',

    /*
     * The disk every attachment lands on.
     *
     * `local` is rooted at `storage/app/private`, which is outside the web root:
     * nothing under it is reachable by URL, so a file is served only by a
     * request that has already passed through the router and the auth
     * middleware. Point this at `s3` and not one line of module code changes —
     * that is the entire reason `AttachmentService` exists.
     */
    'disk' => env('DATA_DISK', 'local'),

    'backups' => [
        /*
         * Backups get their own disk, registered by DataServiceProvider so it
         * does not need an edit to the shared `config/filesystems.php`. It is
         * rooted at `storage/app/backups`, again outside `public/` — a dump of
         * the whole database sitting behind a guessable URL is the worst
         * possible way to lose a client list.
         */
        'disk' => env('DATA_BACKUP_DISK', 'backups'),
        'root' => env('DATA_BACKUP_ROOT', storage_path('app/backups')),

        /*
         * Only consulted on a MySQL connection. Left empty, the binary is looked
         * up on PATH, and a host without it is skipped with a message rather
         * than recorded as a failed backup it never attempted.
         */
        'mysqldump_path' => env('DATA_MYSQLDUMP_PATH'),
    ],

    'github' => [
        /*
         * A personal access token with `repo` scope. Absent, `data:sync-repos`
         * says so and exits cleanly: a scheduler entry that fails every night
         * teaches whoever reads the cron mail to ignore it, and then the night
         * something is genuinely wrong looks the same as every other night.
         */
        'token' => env('GITHUB_TOKEN'),
        'api' => env('GITHUB_API_URL', 'https://api.github.com'),
    ],
];
