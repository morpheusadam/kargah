<?php

namespace Modules\Data\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Data\Models\Backup;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Taking a backup, and putting one back.
 *
 * The row is written before the dump starts and updated when it ends, so a
 * process killed halfway leaves a row stuck in `running` rather than leaving
 * nothing at all. A table with no row looks exactly like a job that was never
 * scheduled, and that ambiguity is what stops anyone noticing the night the
 * backups quietly stopped.
 *
 * Three dump strategies, picked from the connection rather than configured:
 *
 * - **SQLite backed by a file** — the file is copied verbatim. It is already a
 *   complete, restorable database; re-deriving one from SQL would be slower and
 *   could only ever be less faithful.
 * - **SQLite in memory** — there is no file to copy, so the schema and rows are
 *   written out as SQL. This is the path the test suite runs on, which means the
 *   restore is exercised on every run rather than only in production.
 * - **MySQL** — `mysqldump`, when the binary exists. Shared hosting often does
 *   not ship it, so its absence is reported before a row is created and the run
 *   is skipped rather than recorded as a failure it is not.
 *
 * A restore always names the connection it writes into. Defaulting to the live
 * one would make "restore" a single mistyped command away from destroying the
 * database it was meant to protect.
 */
class DatabaseBackups
{
    /**
     * Why a backup cannot run right now, or null when it can.
     *
     * Checked before anything is written, so an unsupported host produces one
     * clear sentence rather than a half-finished archive and a failed row.
     */
    public function unavailableReason(?string $connection = null): ?string
    {
        $connection ??= (string) config('database.default');
        $driver = (string) config('database.connections.'.$connection.'.driver');

        return match ($driver) {
            'sqlite' => null,
            'mysql', 'mariadb' => $this->mysqldumpPath() === null
                ? 'mysqldump was not found on this host, so a MySQL database cannot be dumped. Install the MySQL client tools or set DATA_MYSQLDUMP_PATH.'
                : null,
            default => 'Backing up a '.($driver ?: 'unknown').' database is not supported yet. Only SQLite and MySQL are.',
        };
    }

    /**
     * Take one backup and record it.
     *
     * Returns the row whatever happens: a failed run is a fact worth keeping,
     * and the error column is where the reason for the next morning's silence
     * is written down.
     */
    public function run(?string $connection = null): Backup
    {
        $connection ??= (string) config('database.default');
        $disk = $this->disk();

        $backup = Backup::query()->create([
            'target' => Backup::TARGET_DATABASE,
            'disk' => $disk,
            'status' => Backup::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            [$path, $contents] = $this->dump($connection, $backup);

            Storage::disk($disk)->put($path, $contents);

            $backup->forceFill([
                'path' => $path,
                'size_bytes' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'status' => Backup::STATUS_COMPLETE,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $backup->forceFill([
                'status' => Backup::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();
        }

        return $backup->refresh();
    }

    /**
     * Put a backup into a database.
     *
     * `$intoConnection` is required in spirit even though it has a default:
     * pass the connection you mean. The checksum is verified before a single
     * statement runs, because restoring a corrupted archive over a working
     * database turns one problem into two.
     */
    public function restore(Backup $backup, string $intoConnection): void
    {
        if (! $backup->isComplete() || $backup->path === null) {
            throw new RuntimeException('Backup '.$backup->id.' did not complete, so there is nothing to restore.');
        }

        $storage = Storage::disk($backup->disk);

        if (! $storage->exists($backup->path)) {
            throw new RuntimeException('The archive for backup '.$backup->id.' is missing from disk '.$backup->disk.'.');
        }

        $contents = (string) $storage->get($backup->path);

        if ($backup->checksum !== null && hash('sha256', $contents) !== $backup->checksum) {
            throw new RuntimeException('The archive for backup '.$backup->id.' does not match its recorded checksum and was not restored.');
        }

        str_ends_with($backup->path, '.sqlite')
            ? $this->restoreSqliteFile($contents, $intoConnection)
            : $this->restoreSql($contents, $intoConnection);
    }

    /**
     * Send a stored archive to the browser.
     *
     * The backups disk is outside the web root, so this is the only way to it.
     * That is deliberate: a route can be put behind auth, a public directory
     * cannot.
     */
    public function stream(Backup $backup): StreamedResponse
    {
        if ($backup->path === null) {
            throw new RuntimeException('Backup '.$backup->id.' produced no archive, so there is nothing to download.');
        }

        $storage = Storage::disk($backup->disk);

        if (! $storage->exists($backup->path)) {
            throw new RuntimeException('The archive for backup '.$backup->id.' is missing from disk '.$backup->disk.'.');
        }

        return $storage->download($backup->path, basename($backup->path));
    }

    /** Re-hash a stored archive and compare it to what was recorded. */
    public function verify(Backup $backup): bool
    {
        if ($backup->path === null || $backup->checksum === null) {
            return false;
        }

        $storage = Storage::disk($backup->disk);

        return $storage->exists($backup->path)
            && hash('sha256', (string) $storage->get($backup->path)) === $backup->checksum;
    }

    /* Dumping --------------------------------------------------------------- */

    /**
     * @return array{0: string, 1: string} the path to write and the bytes
     */
    private function dump(string $connection, Backup $backup): array
    {
        $driver = (string) config('database.connections.'.$connection.'.driver');
        $stamp = $backup->started_at?->format('Y-m-d-His') ?? now()->format('Y-m-d-His');

        if ($driver === 'sqlite') {
            $file = (string) config('database.connections.'.$connection.'.database');

            if ($file !== ':memory:' && $file !== '' && is_readable($file)) {
                return ['kargah-'.$stamp.'.sqlite', (string) file_get_contents($file)];
            }

            return ['kargah-'.$stamp.'.sql', $this->sqliteToSql($connection)];
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return ['kargah-'.$stamp.'.sql', $this->mysqldump($connection)];
        }

        throw new RuntimeException('Backing up a '.($driver ?: 'unknown').' database is not supported yet.');
    }

    /**
     * A SQLite database written out as SQL.
     *
     * `DROP TABLE IF EXISTS` before each `CREATE`, so the result restores into a
     * database that is clean *or* one that already holds an older copy. Foreign
     * keys are switched off around the whole thing because a dump is written in
     * `sqlite_master` order, not dependency order, and re-ordering it correctly
     * is a graph problem nobody needs to solve twice.
     */
    private function sqliteToSql(string $connection): string
    {
        $db = DB::connection($connection);

        $lines = [
            '-- Kargah database backup, taken '.now()->toDateTimeString().' UTC.',
            'PRAGMA foreign_keys = OFF;',
        ];

        $tables = $db->select(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        foreach ($tables as $table) {
            if ($table->sql === null) {
                continue;
            }

            $lines[] = 'DROP TABLE IF EXISTS "'.$table->name.'";';
            $lines[] = rtrim(trim($table->sql), ';').';';

            foreach ($db->cursor('SELECT * FROM "'.$table->name.'"') as $row) {
                $lines[] = $this->insertStatement($connection, $table->name, (array) $row);
            }
        }

        $indexes = $db->select(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL ORDER BY name"
        );

        foreach ($indexes as $index) {
            $lines[] = rtrim(trim($index->sql), ';').';';
        }

        $lines[] = 'PRAGMA foreign_keys = ON;';

        return implode("\n", $lines)."\n";
    }

    /** @param  array<string, mixed>  $row */
    private function insertStatement(string $connection, string $table, array $row): string
    {
        $pdo = DB::connection($connection)->getPdo();

        $columns = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', array_keys($row)));

        $values = implode(', ', array_map(function ($value) use ($pdo): string {
            if ($value === null) {
                return 'NULL';
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            // Anything that is not valid UTF-8 is a blob, and a blob inside a
            // quoted string would be mangled on the way back in. Hex literals
            // survive the round trip byte for byte.
            return mb_check_encoding((string) $value, 'UTF-8')
                ? $pdo->quote((string) $value)
                : "X'".bin2hex((string) $value)."'";
        }, array_values($row)));

        return 'INSERT INTO "'.$table.'" ('.$columns.') VALUES ('.$values.');';
    }

    private function mysqldump(string $connection): string
    {
        $binary = $this->mysqldumpPath();

        if ($binary === null) {
            throw new RuntimeException('mysqldump was not found on this host.');
        }

        $config = (array) config('database.connections.'.$connection);

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
            '--password='.($config['password'] ?? ''),
            '--single-transaction',
            '--skip-lock-tables',
            '--add-drop-table',
            (string) ($config['database'] ?? ''),
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    private function mysqldumpPath(): ?string
    {
        $configured = config('data.backups.mysqldump_path');

        if (is_string($configured) && $configured !== '') {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find('mysqldump');
    }

    /* Restoring ------------------------------------------------------------- */

    private function restoreSqliteFile(string $contents, string $intoConnection): void
    {
        $target = (string) config('database.connections.'.$intoConnection.'.database');

        if ($target === '' || $target === ':memory:') {
            throw new RuntimeException(
                'A SQLite file backup can only be restored into a file-backed connection, and '.$intoConnection.' is not one.'
            );
        }

        // The connection has to let go of the file before it is replaced, or
        // the open handle keeps reading the old bytes on Windows and the
        // journal disagrees with the database on everything else.
        DB::purge($intoConnection);

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, $contents);

        DB::reconnect($intoConnection);
    }

    private function restoreSql(string $contents, string $intoConnection): void
    {
        $db = DB::connection($intoConnection);
        $driver = (string) config('database.connections.'.$intoConnection.'.driver');

        if ($driver === 'sqlite') {
            // Set outside a transaction on purpose: SQLite ignores the pragma
            // while one is open, and the dump's own PRAGMA line would be a
            // silent no-op if it were the only guard.
            $db->statement('PRAGMA foreign_keys = OFF');
        }

        foreach ($this->splitStatements($contents) as $statement) {
            $db->unprepared($statement);
        }

        if ($driver === 'sqlite') {
            $db->statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Cut a dump into statements.
     *
     * A plain `explode(';')` breaks the moment a stored value contains one,
     * which for this application means the first invoice description with a
     * semicolon in it. The scan below tracks whether it is inside a quoted
     * literal — SQL escapes a quote by doubling it, so a doubled quote inside a
     * string simply toggles twice and lands back where it started.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'") {
                $inString = ! $inString;
                $current .= $char;

                continue;
            }

            if (! $inString && $char === '-' && ($sql[$i + 1] ?? '') === '-' && trim($current) === '') {
                // A comment on its own line. Skip to the newline; a `--` inside
                // a statement is left alone, since it could be an operator.
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline;

                continue;
            }

            if (! $inString && $char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /** The disk backups land on. Outside `public/`, and checked on boot. */
    private function disk(): string
    {
        return (string) config('data.backups.disk', 'backups');
    }
}
