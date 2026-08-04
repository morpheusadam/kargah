# Prompt for the next session

Paste everything below the line into a fresh context.

---

You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
Laravel 13.23 · Livewire 4.3 · nwidart/laravel-modules 13 · PHP 8.3 · SQLite. Seven modules:
`Accounting` `Core` `Data` `Mailbox` `Platform` `Project` `Social`. Branch `main`, **8 commits
ahead of origin and not pushed**. 1086 tests passing, 106 routes.

## Your mission, in three parts

### 1. Integrate Mixpost's backend into `Modules/Social`

`https://github.com/inovector/mixpost` — **already cloned, read-only, at
`C:\Users\morph\Projects\mixpost-ref`** (outside the Kargah tree, deliberately).

**Facts already verified last session. Do not re-derive them:**

- **Licence is MIT.** Real MIT, full text in `LICENSE.md`: *"use, copy, modify, merge, publish,
  distribute, sublicense, and/or sell"*. You may take its code. Keep the copyright notice in any
  file you lift substantially from — that is the one condition MIT imposes.
- **It supports only THREE networks.** `src/SocialProviderManager.php:22-24`:
  `twitter`, `facebook_page`, `mastodon`. LinkedIn, TikTok, YouTube, Pinterest are in the paid Pro
  edition and are **not** in this repo. Do not go looking for them.
- **Kargah already has FIVE**: Mastodon, Bluesky, LinkedIn, Telegram, Discord. So Mixpost's net
  contribution is **X (Twitter), Facebook Pages, and Instagram (via Meta)**. Mastodon is duplicate.
- **Do not `composer require inovector/mixpost`.** It declares
  `illuminate/contracts ^10.47|^11.0|^12.0` and Kargah is on Laravel 13 — composer will refuse. It
  also pulls `laravel/horizon` (Redis), `inertiajs/inertia-laravel` + `tightenco/ziggy` (a Vue
  frontend you do not want), and `php-ffmpeg` (needs an ffmpeg binary that shared hosting lacks).
- **Redis is NOT actually required by its publishing path.** Jobs use `->onQueue('publish-post')`
  — a queue *name*, not a connection. Horizon appears in exactly three cosmetic places
  (`SystemStatusController`, `HandleInertiaRequests`, `Support/HorizonStatus`). This is why lifting
  the provider code is viable where installing the package is not.

**So: read Mixpost as MIT-licensed source material and port what is useful into Kargah's own
Social module.** The valuable directories are `src/SocialProviders/`, `src/Actions/`, `src/Jobs/`,
`src/Services/`, `src/Http/Controllers/` (for the OAuth callback shapes). Its OAuth flows and media
upload handling are the hard parts already solved — that is what you are there for.

**What Kargah's Social module already has. Do NOT rebuild any of it:**

- One composer that publishes to several accounts at once, with per-network body overrides and live
  character counters against each network's own limit (`⚡publish.blade.php`).
- `post_targets` — one row per post per account with independent status, so a retry physically
  cannot resend a network that already succeeded.
- `social:publish-due` on the one-minute cron, claiming work with a conditional `UPDATE`.
- A **complete image media pipeline** built last session: upload in the composer, resolution at
  send time from `AttachmentService`, a binary-upload leg on `HttpPublisher`
  (`uploadRequest`/`sendMultipart`/`sendBytes`/`putBytes`), and per-network upload steps for all
  five existing networks. Media limits (count, bytes, MIME, dimensions, caption) live in
  `Modules/Social/app/Support/Networks.php`.
- Token-expiry warnings through Core's notification spine.

Adding a network is therefore **one publisher class + one entry in the `Networks` catalogue**.
Follow `DiscordPublisher.php` or `TelegramPublisher.php` as the template.

**Target: X, Facebook Pages, Instagram, and Threads** (Threads is not in Mixpost — build it from
the Meta Graph client you write for Facebook/Instagram; it is the same API family).

⚠️ OAuth for X and Meta needs a real callback URL and app credentials. **You cannot complete a live
OAuth handshake on this machine** — there is no CA bundle in `php.ini` so outbound HTTPS fails with
cURL error 60, and there is no public callback URL. Build the flow, prove the request shapes with
`Http::fake()`, and leave the credential paste-in working. Say clearly in your report what the owner
must do by hand to finish connecting each network.

### 2. Add a Blog module that publishes to WordPress and to social at once

New module `Modules/Blog`. The owner will supply a **WordPress application password**; ask for it
when you need it and do not block on it — build against a fake until it arrives.

- Publish a post to a WordPress site over the **WP REST API** (`/wp-json/wp/v2/posts`) using HTTP
  Basic with an application password. That is WordPress's own supported mechanism and needs no
  plugin.
- The same composer action should be able to publish **to the website and to the social networks
  together**, as one intention. Reuse `post_targets`' shape: a WordPress site is just another
  target with its own independent status, so one failing does not resend the others.
- Decide yourself whether WordPress becomes a `Networks` catalogue entry with its own publisher
  (probably the cleanest — everything downstream then works for free: scheduling, the cron, retry,
  per-target status, media) or a separate concept. **Make the call and record it in a docblock.**
- Categories, tags, featured image, draft vs publish, and a canonical link back are all worth
  having. Featured image means uploading to `/wp-json/wp/v2/media` first — the media pipeline
  already knows how to hand bytes to an uploader.

### 3. Fix the board UI, with a browser

The owner is connecting **@browser** access. Use it. Last session had no browser and could only
render pages through the HTTP kernel, which catches missing `aria-label`s and uncompiled Tailwind
classes but cannot see spacing, alignment or overflow.

- Walk **every** page of the app in the browser, logged in as `admin@kargah.local` / `kargah1234`.
- **The board section matters most to the owner.** `/projects` and its four sibling views
  (`/projects/table`, `/projects/calendar`, `/projects/dashboard`, `/projects/activity`), plus the
  card drawer, board settings, the archive, and `/projects/butler`.
- Fix what you find. Screenshot before and after.
- Also verify the Mixpost integration visually once it lands — connect an account, compose a post,
  see it queued.

A UI audit ran last session without a browser and already fixed a batch of defects; read its
commit before starting so you do not redo it.

## Environment — get these wrong and you lose an hour each

- PHP is `C:\Users\morph\PHP\8.3\php.exe`. Composer at `C:\Users\morph\PHP\8.3\composer.phar`.
- **`cd` to the project explicitly in EVERY shell call** — the working directory silently reverts
  to `C:\Users\morph\Projects\Visa`.
- **`php artisan tinker <file>` HANGS** in this non-interactive shell. Never use it. Run a scratch
  script instead:
  ```php
  require __DIR__.'/vendor/autoload.php';
  $app = require_once __DIR__.'/bootstrap/app.php';
  $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  ```
  then `php that-file.php`. **Delete every scratch file when done** — last session left six in the
  repo root.
- `php artisan migrate --force`. **NEVER `migrate:fresh`** — it destroys the dev database, which
  holds the owner's real data. Never bare `php artisan module:migrate` — it is interactive and
  aborts.
- Dev login `admin@kargah.local` / `kargah1234`. SQLite at `database/database.sqlite`.
- **No CA bundle in `php.ini`**, so real outbound HTTPS from PHP fails with cURL error 60. `git`
  has its own bundle and *does* reach GitHub. Tests must never touch the network.
- Full suite is ~6.5 minutes. `--filter=SmokeTest` is ~20s and walks every route looking for a 500.

## The traps. Each one has already cost a full debugging cycle

1. **An island is skipped unless you name it.** On any request after the first,
   `HandlesIslands::renderIslandDirective()` returns a `mode: skip` fragment for every `@island`
   nobody called `renderIsland()` for — the markup is computed, discarded, and never reaches the
   browser. `⚡boards.blade.php` wraps its canvas, its list ⋯ menu **and both inline forms** in one
   island; every handler there calls `redrawCanvas()`. This was a live bug that made the list menu
   and both forms never open.
2. **SQLite: dropping a foreign-keyed column inside a transaction silently deletes rows.** The
   rebuild fires every `ON DELETE CASCADE` pointing at the table, and `PRAGMA foreign_keys` is a
   **no-op inside an open transaction** — which `RefreshDatabase` wraps every test in. Adding
   columns is safe. To drop one, copy through a staging table.
3. **`use RuntimeException;`** — a `use` with a non-compound name — is **fatal** inside a Livewire
   single-file component. Write `\RuntimeException` at the throw site.
4. **`DB::table()->whereKey()` silently matches nothing.** It is Eloquent-only; on the base builder
   it becomes a dynamic where on a column named `key`.
5. **A new Tailwind class does nothing until `kargah.css` is rebuilt.** Grep the compiled sheet
   before using a class you have not seen elsewhere. The command is in
   `docs/frontend-conventions.md`; `node`/`npx` and the template are on this machine.
6. **Never guard a JS mount with a `data-*` attribute** — the morph strips any attribute the
   incoming HTML lacks, so the flag clears itself and you bind twice. Use a JS-side registry.
7. **A closure registered once outlives its component.** After a `wire:navigate` a captured `$wire`
   points at a torn-down instance. Keep the current one on a `window` registry.
8. **`cards.board_list_id` and `cards.position` do not exist.** A card's place lives on
   `card_placements` (`card_id`, `board_list_id`, `position`, `is_origin`, unique on the first two).
   One origin placement per card, any number of mirrors. `Board::cards()` is a **query builder, not
   a relation** — call it, never read it as a property.
9. **Pivots raise no Eloquent events.** `card_label` and `card_members` changes must notify Butler
   and the notification spine by hand — there are six such call sites already.
10. **PHPUnit 12 ignores the `@dataProvider` doc annotation.** Use the `#[DataProvider]` attribute.
11. **Never put a `Carbon` or any object inside a `Cache::flexible()` value** on the database store
    — it comes back as `__PHP_Incomplete_Class` and 500s a *later* request.

## How to run subagents

Use the `subagents` skill. Fan out hard — it is the main lever on speed. But:

🔴 **Exclusive file ownership, always.** Two agents editing one file destroy each other's work
silently: no merge, no conflict marker, no error, the second write simply wins. Give every agent an
exact list of files it owns **and** the list it must not touch. The two hottest files are
`⚡boards.blade.php` and `⚡card-detail.blade.php` — one owner each, design the partition around
them from the start.

🔴 **When an agent needs a file another owns, it builds a nested Livewire component and reports ONE
MOUNT LINE** for the owner to paste. This worked cleanly six times last session.

🔴 **Agents must never run `git add`, `commit`, `checkout`, `stash` or `restore`.** The main thread
commits. Other agents have uncommitted work on disk and a checkout deletes it.

🔴 **Verify every agent report yourself.** Last session an agent reported "0 rows left" when a row
was still there, and another's claimed verification used the wrong array key so its own test proved
nothing. Run the decisive command yourself before believing a green report.

Write a shared brief in the scratchpad and have every agent read it before its own prompt.

## Standing instructions

- **Decide for yourself at every step and keep going.** The owner wants throughput. Pick the best
  option, write down why in a docblock, and move on. Do not stop to ask unless proceeding would be
  destructive or would waste an hour if the guess were wrong.
- **Any problem found along the way can be fixed later** — note it and continue rather than
  stopping the world.
- Do not write new tests unless something you build genuinely has no coverage and would be
  dangerous without it. If an existing assertion describes behaviour your change legitimately
  altered, **correct it** and say so.
- Run the full suite once at the end, not between features.
- Commit per batch with one-line messages, ending with:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- **Do not push** without asking. `origin/main` is 8 commits behind and the repo is public.

## Read these first

```
project-guaid/HANDOVER.md              the session before last
project-guaid/DECISIONS.md             skim, but read the traps
project-guaid/spec/06-trello-parity.md
project-guaid/spec/07-platform.md
project-guaid/spec/08-postiz-parity.md the brief for the social work
docs/frontend-conventions.md
```

## What is already done, so you do not rebuild it

**Project (Trello parity)** — card placements and mirrors · the full Trello search language ·
custom fields · markdown descriptions and comments · multiple members · start dates · due colour
scale · mark complete · card numbers · covers · attachments · board backgrounds and the ten label
colours · Table, Calendar, Dashboard and Activity views · a signed ICS feed · watching with five
notification producers · WIP limits · list sort · move-all · collapse · starred and recently-viewed
boards · CSV/JSON export · a print view · copy list and copy board · voting · comment reactions ·
@mentions · per-item checklist assignee and due date · a keyboard layer · **Butler** (rules, card
buttons, board buttons, with a loop guard).

**Core** — the notification spine, preferences, digest and quiet hours.

**Platform** — application passwords · `/api/v1` covering whoami, customers, emails, invoices,
expenses, boards, lists, cards, companies, threads and the vault · an assistant provider layer with
five drivers · a **tool layer with thirteen tools** and `php artisan kargah:ask`.

**Social** — Mastodon, Bluesky, LinkedIn, Telegram, Discord · the composer · `post_targets` · the
cron · the image media pipeline.

**App level** — dashboard · settings · **two-factor now enforced at login**, with
`php artisan two-factor:disable <email>` for the lockout case.

## Open debts

- `Modules\Core\Contracts\CustomerReader` returns Eloquent models where every sibling contract
  returns arrays.
- No per-card deep link — a notification lands on the board, not the card.
- `has:stickers` is the last search operator that compiles to "match nothing".
- Butler has no calendar or due-date commands (both need the cron sweep), and no if/else branching.
- `/api/v1/customers` is uncursored.
- Card writes and mail sending are absent from the API — they need a `CardWriter` and an
  `EmailSender` contract that do not exist.

## Two things the owner explored and rejected

- **Postiz** (`C:\Users\morph\Projects\postiz-app`, read-only) — needs PostgreSQL, Redis, Temporal,
  Docker and Node, which the founding shared-hosting constraint forbids, and it is AGPL-3.0 against
  Kargah's MIT. Ideas were taken; `spec/08-postiz-parity.md` came from it. **Do not integrate it.**
- **Stackposts** (`C:\Users\morph\Downloads\stackpost` and `stackpost-installed`) — a commercial
  licensed CodeCanyon-style product with a purchase-code check. The owner holds a licence, but
  Kargah's GitHub repo is **public**, so merging its source would be redistribution. Also the
  installed copy is a different, older build with **no channel or publishing modules at all**.
  **Do not integrate it.**

Mixpost is the one that is actually MIT and actually usable. That is why it won.
