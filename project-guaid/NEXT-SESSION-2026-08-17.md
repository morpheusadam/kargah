# Prompt for the next session — 2026-08-17

Paste everything below the line into a fresh context.

---

You are continuing **Kargah** at `C:\Users\morph\Projects\kargah`. Laravel 13.23 ·
Livewire 4.3 · `nwidart/laravel-modules` 13 · PHP 8.3 · SQLite locally, MySQL in
production. Nine modules: `Accounting` `Blog` `Core` `Data` `Mailbox` `Platform`
`Project` `Site` `Social`.

Branch `main`, clean. Live at **https://lavzen.com/panel/**, served from
`~/domains/lavzen.com/public_html/panel/index.php`, which points at
`/home/u523965318/kargah`. Server PHP is `/opt/alt/php83/usr/bin/php` — plain
`php` there is 8.2 and will fail.

## What the last session actually did

- Confirmed local `main` == `origin/main` == server, all at `84e665c`. Nothing
  was out of sync and nothing was broken.
- Cleared and rebuilt every production cache. Verified the live panel returns
  HTTP 200 and `<title>Sign in — Kargah</title>`.
- Read the production log and separated the real faults from the noise (below).
- Committed one small green piece: a users screen for the `Site` module,
  12 tests, 30 assertions.

## What it did NOT do, and why

A six-unit subagent workflow was launched. **Two units failed before writing a
line**, and they were the two that mattered — the log-error fix and post
history:

```
Refusing to use C:\Users\morph\Projects\.claude\worktrees\...-3 as an isolation
worktree: the protected checkout C:\Users\morph\Projects has git metadata that
could not be resolved
```

🔴 **`C:\Users\morph\Projects` is itself a git repository.** Every worktree the
harness creates lands underneath it, git resolves the worktree back to the outer
checkout, and isolation is refused. This bit three separate workflow runs across
two projects in one day.

**Fix it before running parallel agents again**, one of:
- delete `C:\Users\morph\Projects\.git` if that outer repo is not deliberate, or
- move `kargah` out from under `Projects\`, or
- run the work sequentially in the main thread, which for this codebase was
  measurably faster than debugging the orchestration.

## The production log, already triaged — do not redo this

Read from `~/kargah/storage/logs/laravel.log` on the server.

### 🔴 1. The only live bug

```
[2026-08-08 12:31:28] production.ERROR: Property [$0] not found on component: [social::publish]
[2026-08-08 15:54:04]  ... the same, twice, both userId 1
Livewire\Exceptions\PropertyNotFoundException at vendor/livewire/livewire/src/Component.php:127
```

What has already been ruled out, so start after this line, not before it:

- There is **no literal `$0`** anywhere in `Modules/Social` — grepped, zero hits.
- There is **no `$wire.` expression** in `⚡publish.blade.php` — zero hits.
- `$overrides` **is** declared: `public array $overrides = []` at line 75, so
  `overrides.<id>` is a legitimate path and the obvious suspect at line 897
  (`wire:model.live.debounce.300ms="overrides.{{ $account->id }}"`) does not on
  its own explain a property named `0`.
- Only five `wire:model` bindings exist, at lines 717, 798, 827, 836, 897.
- Six `wire:` actions interpolate a variable, at lines 757, 763, 772, 885, 889,
  931 — `removeUpload({{ $index }})`, `moveUpload({{ $index }}, ±1)`,
  `trimToLimit({{ $account->id }})`, `toggleOverride({{ $account->id }})`,
  `toggleTarget({{ $account->id }})`.

Livewire's message is `Property [$%s] not found`, so the name it was given was
literally `0`. That means an update or an action arrived for a path whose first
segment was `0`. The two places that could produce one are the upload list —
`$uploads` is a list, `removeUpload(int $index)` and `moveUpload(int $index,
int $by)` take positions, and positions start at zero — and `$overrides`, if
anything ever re-indexes it with `array_values()`.

🔴 **Reproduce it before fixing it.** Run the app, open `/social/publish`,
attach uploads, reorder and remove them, toggle a per-account override, and
watch for the exception. A fix for a cause nobody reproduced is a guess, and the
next person will not be able to tell it apart from a real one. Add a regression
test once you have the sequence.

Note the dev server trap: `php artisan serve` is single-worker and deadlocks
Livewire's XHR, which looks exactly like a hung request. Start it with
`PHP_CLI_SERVER_WORKERS=8`.

### 2. Not a code fault

```
[2026-08-05] production.ERROR: The "--columns" option does not exist.
```
Twice, from `Symfony\Component\Console\Input\ArgvInput`. A human at a shell
passed `--columns` to a command that has no such option. Nothing in the app
does this. Close it unless it recurs without a person at a keyboard.

### 3. Already resolved

```
[2026-08-04] production.ERROR: Class "Brick\Money\ISOCurrencyProvider" not found
  at Modules/Accounting/app/Support/Currencies.php:59
```
`vendor/brick/money` is present on the server today, so a later
`composer install` fixed it. Worth one guard: confirm `brick/money` is in
`require` and not `require-dev`, because a `--no-dev` install on this host would
bring it back.

## What the owner asked for, still outstanding

In their words, and in the order they said it:

1. **Post history** — what went out, where, per-target status, the remote URL,
   the failure reason, filters, per-target retry through the existing
   `Modules/Social/app/Services/Publishing.php` (do not write a second
   publisher), and CSV export. `⚡posts.blade.php` and `⚡post-show.blade.php`
   already exist; extend them.
2. **Calendar and scheduling** — `⚡calendar.blade.php` already exists at
   16.7 KB. Month and week views, moving a scheduled post, and a queue view.
   ⚠️ The trap is timezone: `Modules/Social/app/Console/PublishDue.php` is what
   actually publishes, so read it first and make the calendar agree with it on
   the same field and the same timezone. Test across a DST boundary.
3. **Settings, more professional** — grouped by intent rather than by table,
   each with a one-line explanation, connection health for anything external
   reusing `CheckTokenExpiry.php` and `RefreshTokens.php`, and a settings
   search.
4. **A visual pass** — read `docs/frontend-conventions.md` and `docs/theme.md`
   first. There is no bundled theme and no build step on the server; do not add
   a CSS framework. A previous session's agent claimed `bg-mono` was missing
   from the stylesheet and was **wrong** — it renders a real colour. Measure
   before claiming a class is missing.
5. **Research** — where Kargah has real gaps against Buffer, Later, Publer,
   Harvest and FreshBooks, ranked by value over cost and specific to this
   codebase. Nothing that needs a daemon, Node on the server, or more than the
   one-minute cron.

## House rules that earned their place

1. Exclusive file ownership before anything launches. Two agents editing one
   file destroy each other silently.
2. Agents never run `git add|commit|checkout|stash|reset`, and never
   `composer require|install|dump-autoload`.
3. Verify every agent report yourself. Run the decisive command.
4. Reviewers write findings and do not edit.
5. Keep shell commands **single-purpose**. Chaining several operations into one
   `ssh "a; b; c"` got refused repeatedly last session; the same commands run
   one at a time went through without trouble.
