# Prompt for the next session

Paste everything below the line into a fresh context.

---

You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
Laravel 13.23 · Livewire 4.3 · `nwidart/laravel-modules` 13 · PHP 8.3 · SQLite. Eight modules:
`Accounting` `Blog` `Core` `Data` `Mailbox` `Platform` `Project` `Social`. Branch `main`, **clean and
fully pushed** to `github.com/morpheusadam/kargah` at `deee3ac`. **1,171 tests passing**, 5,670
assertions, ~7.5 minutes for the full suite.

🔴 **Use the `subagents` skill.** It is the main lever on throughput and the last two sessions both
ran on it. Read `~/.claude/skills/subagents/SKILL.md` and follow it exactly. The parts that earned
their place, in order of how much they saved:

1. **Exclusive file ownership, decided before anything launches.** Two agents editing one file
   destroy each other silently — no merge, no conflict marker, no error. Give every agent an exact
   list of files it owns *and* the list it must not touch, naming who owns those.
2. **One shared brief in the scratchpad that every agent reads before its own prompt.** Last session
   used `BRIEF.md` (environment, traps, house style, report format) plus a `BRIEF2.md` carrying only
   what was new. Recreate both; they are reproduced in substance in `HANDOVER-2026-08-04.md` and
   `HANDOVER-2026-08-05.md`.
3. 🔴 **Verify every agent report yourself before believing it.** This is not ceremony. Across two
   sessions the agents were right about five real defects the main thread had missed — and wrong
   about at least one (`bg-mono` "missing from the stylesheet"; it renders a real colour, measured
   in the browser). Run the decisive command, read the diff, look at the running thing.
4. **Agents must never run `git add`, `commit`, `checkout`, `stash`, `restore` or `reset`.** Other
   agents have uncommitted work on disk. The main thread commits.
5. **Reviewers write findings and do not edit.** The adversarial review last session found a
   credential leak precisely because it had nothing to do but read.

## Read these first

```
project-guaid/HANDOVER-2026-08-05.md    what the last session did — start here
project-guaid/HANDOVER-2026-08-04.md    the session before it
project-guaid/spec/09-destinations.md   which platforms are supported and why the rest were refused
project-guaid/DECISIONS.md              skim, but read the traps in full
docs/frontend-conventions.md            before writing any Blade
```

## Where the work stands

**Seventeen publishing destinations**, all reachable from one composer, all sharing `post_targets`,
the one-minute cron, the atomic claim, per-target status and retry:

Mastodon · Bluesky · LinkedIn · Telegram · Discord · X · Facebook Pages · Instagram · Threads ·
Slack · Tumblr · VK · Reddit · Lemmy — and three **article** destinations in `Modules/Blog`:
WordPress · DEV.to · Hashnode.

Adding another is still three edits: a `Networks` entry, a publisher class, a line in a service
provider. `NetworkRegistryTest` fails if you forget the third.

🔴 **Not one of the last twelve drivers has ever been called for real.** There is no CA bundle in
`php.ini`, so outbound HTTPS from PHP fails with cURL error 60. Every request shape is proved with
`Http::fake()` and nothing else. Each driver's docblock and each handover carries an explicit list
of what could not be corroborated from a first-party source. **Treat those lists as the highest-value
work available** — one real credential turns a guess into a fact.

## What to do, in the order I would do it

### 1. The first real post is worth more than any new feature

The owner has to obtain credentials by hand; `HANDOVER-2026-08-05.md` has the exact steps per
network, including the traps (Slack's `/invite`, VK's `offline` scope, Meta's long-lived exchange,
Lemmy's two-factor). As each one arrives, publish one post and **fix what the guesses got wrong**.
The uncorroborated lists name the likely failures: Hashnode's `PublishPostInput` field names,
Tumblr's legacy endpoint accepting a JSON body, Lemmy's pict-rs part name, Reddit's error strings.

### 2. Three findings from the last review that were left standing

Not defects, but judgement calls the owner should make:

- **DEV.to and Hashnode cover images are 30-minute signed links.** They cite Meta's reasoning, and
  Meta ingests the bytes during the call — nothing establishes that DEV or Hashnode do. If they
  render the URL instead, the cover 403s half an hour after publication, on a public article, with
  `post_targets.status` still saying `published`. `DevToPublisher.php` / `HashnodePublisher.php`,
  one argument each.
- **Tumblr and X replay one OAuth nonce across `HttpPublisher`'s retries.** A conforming server
  refuses a replayed nonce, so a transient 503 becomes a 401 that reads as a wrong credential.
  The fix is `protected const TRIES = 1;` on both drivers — no change to `HttpPublisher`.
- **VK's photo uploads are visible in the community's wall-photos album before the post exists**, so
  a failure at `wall.post` leaves them there. Acceptable, undocumented.

### 3. Open debts carried forward

- `NetworkRegistryTest`'s docblock names one: WordPress, DEV.to and Hashnode are registered from
  `Modules/Blog`, so with that module disabled Social's catalogue offers three destinations nothing
  can send to. The catalogue needs to learn which module owns an entry.
- No article edit page in `Modules/Blog`; no `Article` factory or seeder.
- `Modules\Core\Contracts\CustomerReader` returns Eloquent models where every sibling returns arrays.
- No per-card deep link — a notification lands on the board, not the card.
- Card writes and mail sending are absent from `/api/v1`.
- **Pinterest is the closest of the refused platforms to being possible** — the signed public image
  URL it needs already exists. It only wants somebody to walk an app through Pinterest's review.

## Environment — get these wrong and you lose an hour each

- PHP is `C:\Users\morph\PHP\8.3\php.exe`. Composer at `C:\Users\morph\PHP\8.3\composer.phar`.
- **`cd` to the project explicitly in EVERY shell call** — the working directory silently reverts to
  `C:\Users\morph\Projects\Visa`.
- The shell is **PowerShell 7**. `head`, `wc`, `[ -f x ]` and backtick substitution are parse errors.
- **`php artisan tinker <file>` HANGS.** `php artisan tinker --execute="…"` works and is the fast way
  to ask the booted app a question.
- `php artisan migrate --force`. **NEVER `migrate:fresh`** — the dev database holds the owner's real
  data. Never bare `php artisan module:migrate`; it is interactive and aborts.
- Tests run against `:memory:`, so the dev database is safe from `RefreshDatabase`.
- Dev login `admin@admin.com` / `admin`. Two-factor is not enabled on it.
- **Start the dev server detached or it dies with the session:**
  `Start-Process -FilePath "C:\Users\morph\PHP\8.3\php.exe" -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8123' -WorkingDirectory "C:\Users\morph\Projects\kargah" -WindowStyle Hidden`
- **`php artisan serve` is single-threaded** and `PHP_CLI_SERVER_WORKERS` does not work on Windows.
  A browser audit that loads pages in a loop queues behind itself and times out at about a dozen
  pages. One fresh iframe per page with a gap between them; and a screenshot taken during contention
  can show a **stale frame** — that cost a wrong conclusion twice.
- Full suite ~7.5 min. `--filter=SmokeTest` is ~15 s and walks every route.

## The traps. Each has already cost a full debugging cycle

1. **An island is skipped unless you name it.** `HandlesIslands::renderIslandDirective()` returns a
   `mode: skip` fragment for every `@island` nobody called `renderIsland()` for; the markup is
   computed, discarded, and never reaches the browser.
2. **SQLite: dropping a foreign-keyed column inside a transaction silently deletes rows.** Adding is
   safe. To drop, copy through a staging table.
3. **`use RuntimeException;`** — a `use` with a non-compound name — is fatal inside a Livewire
   single-file component. Write `\RuntimeException` at the throw site.
4. **`DB::table()->whereKey()` silently matches nothing.** It is Eloquent-only.
5. **A new Tailwind class does nothing until `public/assets/css/kargah.css` is rebuilt**, from
   `C:\Users\morph\Projects\admin-panel-ui\veltrix-tailwind-html-starter-kit`. Never build a class
   name by concatenation — the scanner reads source text.
6. 🔴 **A `.kt-dropdown` panel that also carries a display utility is permanently visible.** The
   theme hides it from the components layer and Tailwind's `.flex` lives in utilities, and **a
   cascade layer beats specificity outright**. Five popovers on the card back shipped this way. Put
   the utility inside the conditional. `DropdownVisibilityTest` now refuses the shape.
7. 🔴 **A flex item's `min-width: auto` will not shrink below its content**, so one wide child made
   the whole page scroll sideways. `min-w-0` is on both flex items in `layouts/app.blade.php`; it
   needs to be on *both* or it does nothing.
8. **Never guard a JS mount with a `data-*` attribute** — the morph strips it and you bind twice.
9. **`cards.board_list_id` and `cards.position` do not exist.** A card's place lives on
   `card_placements`. `Board::cards()` is a query builder, not a relation.
10. **Pivots raise no Eloquent events.** `card_label` and `card_members` notify Butler by hand.
11. **PHPUnit 12 ignores the `@dataProvider` doc annotation.** Use the `#[DataProvider]` attribute.
12. **Never put a `Carbon` or any object inside a `Cache::flexible()` value** on the database store.
13. 🔴 **A credential in a URL leaks into `post_targets.error` and onto the posts page.** Guzzle
    appends the whole URI to a timeout's exception message. VK shipped with its access token on a
    GET; it is fixed and the reason is in `VkPublisher::vkSend()`. **`TelegramPublisher` still puts
    its bot token in the URL path** and has the same exposure — worth a shared fix in
    `HttpPublisher::send()` rather than a third per-driver copy.
14. **GitHub push protection blocks a plausible-looking fake token.** A test fixture only has to be
    a distinctive string; it never has to look real. See `SlackTumblrPublisherTest::SLACK_TOKEN`.
15. **`Str::slug()` transliterates rather than strips**, so a Persian tag became `brnamhnoysy` and
    would have been published. Drop what you cannot render; never invent copy nobody wrote.

## Standing instructions

- **Decide for yourself at every step and keep going.** Pick the best option, write down why in a
  docblock at the site of the decision, and move on. Do not stop to ask unless proceeding would be
  destructive or would waste an hour if the guess were wrong.
- **Any problem found along the way can be fixed later** — note it and continue.
- Do not write new tests unless what you build genuinely has no coverage and would be dangerous
  without it. If an existing assertion describes behaviour your change legitimately altered,
  **correct it and say so**. Prefer assertions on behaviour over assertions on prose — one on the
  wording of an error message broke for no reason last session.
- Run the full suite once at the end, not between features. Run `vendor/bin/pint` on the files you
  touched, not the whole tree — much of it predates the current rules.
- Commit per batch with one-line messages ending:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- **Pushing to `origin/main` is authorised.** The repo is public; the branch is clean.
