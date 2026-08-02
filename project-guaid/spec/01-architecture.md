# 01 — Architecture

Every choice here was checked against published 2026 benchmarks and current framework
documentation. Where the honest answer is "it depends", the dependency is stated.

---

## Runtime

**Decision: PHP-FPM + OPcache. Octane is a VPS-only option, never a requirement.**

FrankenPHP, RoadRunner and Swoole all report real gains — roughly 2.5–3× the throughput of
PHP-FPM in Laravel Octane benchmarks. All three also require binding a long-lived process to a
port, which shared hosting does not permit. They are therefore irrelevant to Kargah's primary
target.

There is a second reason to keep Octane off the critical path: Livewire has open memory-exhaustion
reports under Octane as recently as February 2026, and the recurring cause is static state
accumulating across requests in a long-lived worker. A Livewire-heavy application is exactly the
profile that suffers. If Kargah later adds an Octane mode, it needs a deliberate audit of every
singleton and static property first, not a config flag.

**Rule:** no code may assume a fresh process per request *or* a persistent one. Avoid static
caches in module code entirely.

## PHP configuration

Target **PHP 8.3 or 8.4**. Both are fine; 8.4 is preferred where the host offers it.

```ini
; The settings that actually matter
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; production only — see note
opcache.revalidate_freq=0
```

`validate_timestamps=0` removes a filesystem stat on every included file per request. It is the
single largest lever available on shared hosting. The cost is that deploys must clear the cache;
on a host where you cannot restart PHP-FPM, ship a deploy route that calls `opcache_reset()` behind
a token, or leave `validate_timestamps=1` and accept the smaller win.

**JIT: leave it off.** Kargah's work is database and network bound — Eloquent queries, IMAP reads,
HTTP calls to delivery providers. Published 2026 benchmarks show JIT delivering large gains on
CPU-bound loops and **zero to −7% on I/O-bound applications**. Revisit only if profiling shows a
genuine CPU hotspot, such as PDF generation or image processing.

## Database

**Primary target: MySQL 8.4 or MariaDB 10.11+.** Not MySQL 8.0 — it reached end of life in April
2026 and no longer receives security patches, yet it is still what many cPanel stacks provision by
default. The installer must check the server version and say so.

**SQLite in WAL mode is a supported option** for a genuinely single-user install, and is the
default for local development. WAL lets readers and writers stop blocking each other. The limits
are real and must be documented: one writer at a time, and the exit signals are a second
application process, recurring `database is locked` errors, or a dataset past roughly 10 GB.

```php
// config/database.php — sqlite connection
'journal_mode' => 'WAL',
'busy_timeout' => 5000,
'foreign_key_constraints' => true,
```

PostgreSQL 17 is better on paper for full-text search and JSON. It is not a target, because shared
hosts rarely offer it. Support it if it costs nothing; require nothing of it.

## Queue

**Driver: `database`. Runner: cron. Never `queue:listen`.**

One cron entry:

```
* * * * * cd /path/to/kargah && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler dispatches; it never does the work. The pattern for every long operation — IMAP
sync, a 300-recipient campaign, a crawl — is:

1. A scheduled command finds a bounded amount of outstanding work (say 200 rows).
2. It dispatches one small job per chunk.
3. Each job completes well inside `max_execution_time`, and is **idempotent**: running it twice
   must not send the same email twice.
4. The dispatching command is wrapped in `withoutOverlapping()`.

```php
Schedule::command('mailbox:dispatch-sends')->everyMinute()->withoutOverlapping();
Schedule::command('mailbox:sync-imap')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
```

`--stop-when-empty` is load bearing. Without it, cron stacks a new never-exiting worker every
minute until the host's connection limit is hit — a documented way to get an account suspended.

Atomic locks work on the `database` cache driver, so `withoutOverlapping()` needs no Redis.

## Cache

**`CACHE_STORE=database`.** `config:cache`, `route:cache`, `view:cache` and `event:cache` all work
with it.

Two Laravel 13 features are worth using deliberately:

- **`Cache::flexible()`** — serves stale data immediately while refreshing in the background.
  Correct for dashboard aggregates and report figures, where a slow cache miss otherwise blocks
  the page.
- **`Cache::memo()`** — in-request memoisation. Correct for a value read many times while
  rendering one page, such as the active exchange rate.

**Constraint to design around: cache tags do not work on the `database`, `file` or `storage`
drivers.** Only Redis and Memcached support them. Invalidation must therefore be by explicit key.
Every module must publish its cache keys as constants rather than building them inline, so
invalidation is greppable.

## Module boundaries

`nwidart/laravel-modules` stays. The package is not the risk; discipline is. Three rules:

**1. Each module exposes a `Contracts` namespace. Everything else is private.**

```
Modules/Accounting/app/Contracts/InvoiceReader.php     ← other modules may depend on this
Modules/Accounting/app/Models/Invoice.php              ← nobody outside Accounting may touch this
```

**2. Side effects travel by event; data travels by contract.**

Accounting does not call Mailbox. It fires `InvoiceIssued`. Mailbox listens if it cares.

**3. Database foreign keys across modules are allowed — to Core only.**

`invoices.customer_id → core.customers.id` is a real ownership relationship and gets a real
foreign key. This is the pragmatic position and it is what production Laravel applications
actually ship. The distinction that matters: a foreign key is a *schema* fact, while a module
boundary is a *code* fact. A migration referencing another table does not couple two PHP
namespaces. Reaching into another module's model class does.

A feature module may never hold a foreign key into another feature module. Those relationships go
through Core's `links` table — see [02-data-model.md](02-data-model.md).

### Migration ordering — a known trap

Module migrations run in alphabetical order by default. `Accounting` sorts before `Core`, so a
foreign key from invoices to customers fails on a fresh install.

Two mitigations, both required:

- Set `priority` in each `module.json`: Core `0`, every feature module `10`.
- **Use `php artisan module:migrate`, never bare `php artisan migrate:fresh`.** The global command
  ignores module priority and falls back to filename order. This must be in the deploy script and
  in `CONTRIBUTING.md`.

## Adding a module later

The goal is that adding a sixth module touches nothing that already exists except one navigation
array. The checklist:

```bash
php artisan module:make Crm
composer dump-autoload
```

1. Add its Livewire namespace to `config/livewire.php`.
2. Add `"priority": 10` to its `module.json`.
3. Add one group to `resources/views/partials/sidebar.blade.php`.
4. Register its morph aliases in Core's morph map.
5. Add its routes to `tests/Feature/SmokeTest.php`.

Nothing else in the application may need editing. If it does, the boundary is wrong.
