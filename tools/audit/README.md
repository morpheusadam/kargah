# Browser audits

Three harnesses that drive the **installed Chrome** through `playwright-core`. There is no browser
download: `playwright-core` is ~2 MB and `chromium.launch({ executablePath })` points at
`C:\Program Files\Google\Chrome\Application\chrome.exe`.

They exist because every assertion in this project is against server-rendered markup, and on
4 August 2026 the markup was correct while the dashboard destroyed itself on load, four pages had
never once loaded their JavaScript bundle, and two routes returned 500. **A passing test is not
evidence that a person can use the page.** These were written that day, lost to a scratchpad, and
rewritten on 5 August — hence living in the repository now.

| File | What it answers |
|---|---|
| `load.cjs` | Every route: HTTP status, console errors, failed requests, how much readable text survived, whether ApexCharts/FullCalendar actually loaded and drew, and sideways overflow at 375px with the offending element named. |
| `clicks.cjs` | Clicks **every** `wire:click` target on one page, one per fresh load, accepting every `wire:confirm`. Reports any JS error, 500 or page that emptied itself. |
| `probe.cjs` | Scratch file. Rewrite its body for whatever needs measuring today. |

## 🔴 Never point these at the dev database

`database/database.sqlite` is the owner's real book. `clicks.cjs` clicks everything, which **will**
delete an invoice, change the admin password and sign every session out — all three have happened.

```powershell
cd C:\Users\morph\Projects\kargah
Copy-Item "database\database.sqlite" "storage\app\audit-copy.sqlite" -Force
$env:DB_DATABASE = "C:\Users\morph\Projects\kargah\storage\app\audit-copy.sqlite"
C:\Users\morph\PHP\8.3\php.exe artisan migrate --force     # a stale copy 500s on the page you are auditing
C:\Users\morph\PHP\8.3\php.exe artisan db:seed --force     # rows to click on
Start-Process -FilePath "C:\Users\morph\PHP\8.3\php.exe" `
  -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8124' `
  -WorkingDirectory "C:\Users\morph\Projects\kargah" -WindowStyle Hidden
```

Then **prove the isolation by measurement** rather than assuming it: a request to 8124 must move
`storage/app/audit-copy.sqlite`'s mtime and leave `database/database.sqlite` untouched.

The override reaches the child process only because there is **no `bootstrap/cache/config.php`**. If
anybody runs `config:cache`, it stops applying *silently* and the harness starts writing to the real
book.

Port **8123** is the owner's own server against the real database. Read from it; never click on it.

## Running

```powershell
node tools\audit\load.cjs                                    # all routes
$env:AUDIT_ROUTES='/dashboard,/mail/inbox'; node tools\audit\load.cjs
$env:AUDIT_ROUTE='/mail/inbox'; node tools\audit\clicks.cjs   # every target on one page
```

`AUDIT_BASE` overrides the server (default `http://127.0.0.1:8124`); `AUDIT_START` / `AUDIT_END`
bound `clicks.cjs` to a range of targets. Each writes a JSON file of its findings beside itself —
both are gitignored.

`php artisan serve` is single-threaded and `PHP_CLI_SERVER_WORKERS` is a no-op on Windows, so run
**one browser per server**. Two servers with two copies halves the wall clock.
