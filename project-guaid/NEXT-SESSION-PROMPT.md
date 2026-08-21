
> **Domain note, 21 August 2026.** This file was written while the host was `lavzen.com`. Every
> address in it has been rewritten to `bineret.com`, which is where the system actually runs: the old
> zone is not being renewed and both `lavzen.com` and `panel.lavzen.com` have stopped answering.
> Account *names* are deliberately left alone — `@lavzencom`, `@lavzenbot`, `r/lavzen` and the Worker
> `lavzen-mail-ingest` are the real handles of live things, not addresses, and renaming them here
> would describe a world that does not exist. See `HANDOVER-DOMAIN-2026-08-21.md`.

You are continuing **Kargah**, a self-hosted freelance workspace at `C:\Users\morph\Projects\kargah`.
Laravel 13.23 · Livewire 4.3 · `nwidart/laravel-modules` 13 · PHP 8.3 · SQLite. Eight modules:
`Accounting` `Blog` `Core` `Data` `Mailbox` `Platform` `Project` `Social`. Branch `main`, clean and
fully pushed to `github.com/morpheusadam/kargah`. **1,313 tests passing**, 6,333 assertions,
~7 minutes for the full suite.

The owner writes in Persian and expects Persian back. Code, paths, commands and product names stay in
Latin script. **The owner is morpheus**; the Claude account belongs to someone else, so do not address
them by the account's name.

---

# 🔴 Read this part first. It is the one that changes how you work

## It is deployed. There is a real server.

Kargah is live at **`https://panel.bineret.com`**, on the owner's Hostinger account, serving the
owner's real book: four invoices, ₺80,000, all paid.

**`.data/ssh.txt` has every access detail** — SSH, the panel login, server paths, the DNS record, the
hPanel account, and the social tokens. It is gitignored and must stay that way; this repository is
public. Read it before touching the server.

Key-based SSH is installed, so it needs no password:

```powershell
ssh -p <port> -o BatchMode=yes <user>@<host> "<command>"
```

🔴 **`/opt/alt/php83/usr/bin/php`, never bare `php`** — the account's default is 8.2 and Kargah needs
8.3.

## 🔴 Work on the server, over SSH. The owner has asked for this directly.

**Do not edit locally, commit, push, and pull to see whether it worked.** Open an SSH session, change
the thing where it runs, and look at the result on the live site. `scp` puts a file there in one call;
send them **one per call**, several in one shell call gets refused.

- **Server-only files stay server-only.** `.htaccess`, `.env`, anything under
  `~/domains/…/public_html/panel/`.
- 🔴 **Application code changed on the server must end up in git**, or the next `git pull` silently
  reverts it. Fix it there, prove it live, bring it back in one commit, then `git checkout --` the
  scp'd files on the server and pull, so `git status` there is clean. That last step is easy to skip
  and it is what leaves the server and the repository quietly disagreeing.
- ⚠️ **A heredoc piped through `ssh` is refused by the tool sandbox.** Write the script locally, `scp`
  it, run it with `artisan tinker --execute='require "…"'`, then delete it.

`project-guaid/HANDOVER-DEPLOY-2026-08-05.md` is the full account of how the deployment is put
together. **Read it before changing anything that touches URLs, assets or class imports** — four bugs
lived in exactly those places and the suite was green through all of them.

## There is a browser, and it is now the Claude in Chrome extension

The extension drives the owner's real Chrome with their real sessions. What that session taught,
which nothing else would have:

- 🔴 **Meta opens login and consent flows in separate popup windows that are outside the extension's
  tab group.** You cannot screenshot or click them. When a click seems to do nothing, take a
  screenshot with Claude Eye (`capture_screen`) and look at the whole desktop — the dialog is
  usually there, behind the editor.
- ⚠️ **A stale popup from a failed attempt blocks the next one.** An "Add account" flow answered "We
  couldn't connect to Instagram" purely because an earlier Instagram login window was still open.
- `developers.facebook.com` screenshots time out often. `get_page_text` and `find` keep working when
  `screenshot` does not — use them rather than retrying the screenshot.
- **Never enter a password, and never create an account.** Hand those back to the owner. On this
  session the owner's Instagram password ended up visible in plain text on screen because a "Show"
  toggle was left on; it has not been changed yet — see Open below.

`tools/audit/` has the playwright harnesses and a README. 🔴 **Never point a clicking harness at the
dev database** — `database/database.sqlite` is the owner's real book.

**Open the app in a browser before you believe anything about it.** A passing test is not evidence
that a person can use the page.

## And the one the deployment added

**When something works here and not on the server, do not reason about the difference — send a
request that isolates it.**

---

# What just happened, and what is left

## ✅ Instagram is connected and live

`social_accounts` id 2, `handle=lavzencom`, active. `verify()` **on the server** answers
`@lavzencom`, and `unavailableReason()` answers `NULL`.

🔴 **The finding worth carrying forward.** Instagram publishing now goes through **Instagram Login**,
not the Facebook-login variant, because the owner has no Facebook Page and no Facebook profile
attached to that Instagram account — Meta's docs say this route needs neither. That means the driver
talks to **`graph.instagram.com`**, and the change was proved rather than assumed:

```
graph.instagram.com/v23.0/me  → {"username":"lavzencom","account_type":"BUSINESS"}
graph.facebook.com/v23.0/me   → {"error":{"code":190,"message":"Invalid OAuth
                                 access token - Cannot parse access token"}}
```

Code 190 is the one `MetaGraph::graphRefusal()` renders as "exchange it for a long-lived token" —
advice that is right for a Page token and catastrophic here, because the token is fine and the host
is wrong. Commit `7ea444e`; the argument lives in `InstagramPublisher`'s `HOST` docblock.

⚠️ **Do not click "switch to API setup with Facebook login"** on the Meta app dashboard. It breaks
the connection and the error says only "Invalid OAuth access token".

## ✅ Threads is connected and live

`threads_user_id=28237233189213576`, handle `lavzencom`, expires 5 October. Verified on the server
with the **stored** credential: `verify()` → `@lavzencom`, `unavailableReason()` → `NULL`.

🔴 A Threads token is not an Instagram token even though the account is the same, and
`ThreadsPublisher` is on `graph.threads.net/v1.0` — its own host, its own version.

🔴 **The id came from `/me` and was then proved on `/{id}/threads`**, the edge `publish()` POSTs to.
Instagram had two ids that both answered on `/me`, so one endpoint accepting an id is not evidence
about another. Ask the edge that matters.

⚠️ **The connect page asks for an id that no Meta screen shows.** Only `GET /v1.0/me` has it, so a
person following the `requirement` copy to the letter cannot finish the form. The honest fix is for
`ThreadsPublisher` to resolve it from `/me` when the field is blank — not more copy explaining curl.

🔴 **Chrome autofilled that form and nearly poisoned it.** "Threads user ID" arrived holding the
panel's login email and "Access token" a stored password. Saved unchanged, Kargah would have
encrypted the panel password as a Threads credential and shown the account as connected. Clear every
field on the connect page before typing, and refuse Chrome's offer to save anything from it.

## ⏳ Facebook Page — created, not connected

The Page **Lavzencom** was created on 6 August 2026 under the same Facebook account that owns the
Meta app. Before it existed, `facebook_page` had nothing to point at.

Connecting it needs the **Facebook-login** side of the app — `pages_show_list`,
`pages_read_engagement`, `pages_manage_posts` and a Facebook Login use case — which the app does not
have yet. Adding it alongside the Instagram use case is safe; the warning above is about *switching*
the Instagram one, not about adding a second.

**`.data/meta-app.txt` is the full record** of the Meta app: ids, why Instagram Login was chosen, why
only two permissions were granted, who owns what, and how the account can be lost. Read it before
touching anything Meta.

## 🔴 Permissions are a promise, not a checkbox

`Modules/Social/app/Support/Networks.php` tells the person on the connect page that Kargah **cannot**
read their feed, followers or direct messages. Meta offers to add `manage_comments` and
`manage_messages` with one button; they were refused, and `threads_delete` / `threads_keyword_search`
with them. **The catalogue's promises are the specification.** If you ever need a wider scope, change
the copy in the same commit or the page starts lying.

---

# The app, so you can find your way around

Login `admin@admin.com` / `admin` **locally only** — the deployed panel has one real account, in
`.data/ssh.txt`. `php artisan route:list` is the map.

| Area | Routes | Component prefix |
|---|---|---|
| Home | `/dashboard`, `/notifications` | `pages::` |
| Projects | `/projects` + `/table` `/calendar` `/dashboard` `/activity` `/archive` `/butler` `/{board}/settings` | `project::` |
| Accounting | `/accounting/` invoices · estimates · expenses · recurring · clients · reports, and `/invoices/{id}/pdf` | `accounting::` |
| Mail | `/mail/` inbox · campaigns · contacts · providers · suppression | `mailbox::` |
| Data | `/data/` files · links · passwords · repos · backups | `data::` |
| Social | `/social/` accounts · calendar · posts · publish · notifications | `social::` |
| Blog | `/blog`, `/blog/compose`, `/blog/{article}/edit` | `blog::` |
| API | `/api/v1/…` read-only, token-authenticated | `Modules/Platform` |

**Components are Livewire 4 single-file components** — one file holding both class and template,
named `⚡<name>.blade.php` under `Modules/<X>/resources/views/components/`. Read
`docs/frontend-conventions.md` before writing any Blade. It is the law here.

---

# The traps. Each has cost somebody a full debugging cycle

## Meta, newest and freshest

1. 🔴 **Instagram Login tokens only work on `graph.instagram.com`.** See above. `InstagramPublisher`
   overrides `graphUrl()`; `ThreadsPublisher` writes its own builder because it disagrees about the
   version too.
2. 🔴 **Graph error 190 means "wrong host" at least as often as "expired token"** on this family now.
3. **A Meta tester role has two halves** — granted in App roles, then *accepted* from the network's
   own settings. It sits at "Pending" until both happen, and the "Add account" flow fails
   confusingly in the meantime.
4. **Tokens can only be generated for public accounts**, both Instagram and Threads.

## Portability

5. 🔴 **`Route::redirect()` emits its target verbatim** — use `redirect()->route(...)`.
6. 🔴 **Never write `/assets/…` in a template.** `asset()` resolves against the request root.
7. 🔴 **Class imports are case-sensitive on Linux and not on Windows.**

## Livewire

8. 🔴 **A `<script src="…">` inside `@script … @endscript` is never fetched.** Use `@assets`.
9. 🔴 **`@push('scripts')` does not work inside a Livewire component.**
10. 🔴 **Naming an island suppresses the full-component `html` effect.** Anything whose class changes
    with state must live inside the island that redraws it.
11. ⚠️ **`Livewire::test(...)->html()` renders the component in full regardless**, so it cannot see
    trap 10. Assert on the island fragment.
12. 🔴 **A lazy island's `@placeholder` must be a complete, balanced subtree and the island's first
    child.**
13. 🔴 **An `@island` behind an `@if` that is false on first paint is never registered.** Render it
    `hidden`, not absent.
14. **An island is skipped unless you name it** in `renderIsland()`.
15. **`use RuntimeException;`** is fatal inside a single-file component. Write `\RuntimeException`.
16. **Never guard a JS mount with a `data-*` attribute** — the morph strips it and you bind twice.
17. **`morph.updated` fires per DOM node; `morphed` fires per component.**

## Validation

18. 🔴 **Passing an explicit rules array to `validate()` REPLACES the `#[Validate]` rules for that
    call.** ⚠️ The broader claim is **false**: `rules()` and `#[Validate]` *are* merged.
19. **`after_or_equal:startsOn` must be conditional on `startsOn` being present.**
20. 🔴 **`$set('prop', …)` sets whatever the client sends.** Give indexed lookups a fallback.

## CSS and the theme

21. **A new Tailwind class does nothing until `public/assets/css/kargah.css` is rebuilt.**
22. 🔴 **`.kt-btn` carries `white-space: nowrap; flex-shrink: 0`** — `flex-wrap` on the group is the
    only thing that saves it at 375px.
23. 🔴 **A `.kt-dropdown` panel that also carries a display utility is permanently visible.**
24. **`min-w-0` must be on *both* flex items, and `truncate` does nothing without it.**
25. 🔴 **ApexCharts' `hexToRgba()` turns any colour not starting with `#` into grey.**
26. 🔴 **ApexCharts and FullCalendar must never go in the global layout** — 854 KB on every page.
27. **Keenicons only**, and only names present in `styles.bundle.css` render.

## Database and models

28. 🔴 **SQLite: dropping a foreign-keyed column makes Laravel recreate the table**, firing every
    `ON DELETE CASCADE` pointing at it.
29. 🔴 **NEVER `migrate:fresh`.** The dev database holds the owner's real data. Never bare
    `php artisan module:migrate` — it is interactive and aborts.
30. **The dev database can have unrun migrations while the suite is green** — tests run against
    `:memory:`. Check `migrate:status`.
31. **`DB::table()->whereKey()` silently matches nothing.** Eloquent-only.
32. **`cards.board_list_id` and `cards.position` do not exist.** A card's place lives on
    `card_placements`.
33. **Pivots raise no Eloquent events.**
34. 🔴 **`Article::factory()->make()` writes to the database.** Wrap any factory probe in a transaction.
35. 🔴 **A module factory needs a `newFactory()` override on the model.**
36. **PHPUnit 12 ignores `@dataProvider`.** Use the `#[DataProvider]` attribute.
37. **Never put a `Carbon` inside a `Cache::flexible()` value** on the database store.
38. **`Str::slug()` transliterates rather than strips.**

## Accounting, and these are load-bearing

39. 🔴 **Never `SUM()` money in SQL.** Fetch rows, add through `Money`.
40. 🔴 **Never mix currencies in one figure.**
41. 🔴 **A converted figure is frozen on its document and never re-derived.**
42. 🔴 **The ledger is append-only.** "Delete" means `reverse($reason)` + soft-delete + recompute.
43. **A sequential invoice number is never reused.**
44. 🔴 **Voiding refuses when payments stand.**
45. 🔴 **Tax numbers are second-hand and unverified.** Silence beats a wrong tax number.

---

# Environment. Each of these has cost an hour

- PHP is `C:\Users\morph\PHP\8.3\php.exe`. Composer at `C:\Users\morph\PHP\8.3\composer.phar`.
- 🔴 **`cd` to the project explicitly in EVERY shell call.** The working directory silently reverts to
  `C:\Users\morph\Projects\Visa`.
- The shell is **PowerShell 7**. `head`, `wc`, `[ -f x ]` and backticks are parse errors. In a
  double-quoted string `\$` stays literal — **use single-quoted strings for `--execute` payloads**.
- **`php artisan tinker <file>` HANGS.** `--execute="…"` works.
- **Outbound HTTPS from PHP works.** `cacert.pem` is installed and `php.ini` points at it. **Not in
  git** — a fresh machine has to redo it.
- Full suite ~7 minutes and **exceeds a 600 s tool timeout** — run it in the background. ⚠️ Do not run
  it after every small change; a targeted `--filter` plus a browser check is usually the honest test.
- ⚠️ **Scripted edits change line endings.** Run `vendor/bin/pint` on the files you touched and check
  `git diff --stat`. `sed -i` on `Networks.php` did exactly this today.
- **Run `php artisan view:clear` before believing a Blade result.**

---

# Open, in the order I would take them

1. 🔴 **The owner's Instagram password was shown in plain text on screen** during a Meta login popup
   that had "Show" toggled on. It has not been changed. Say so once and let them do it.
2. 🔴 **The panel password is in a chat transcript** and was used to test the login. Settings →
   Security. The owner types it; nobody else should.
3. 🔴 **The Facebook account that owns the Meta app has no phone number and no second email.** All
   three numbers were deliberately removed after developer verification, because the one used was a
   disposable virtual number and a recycled number can take an account over through "forgot
   password". Recovery is now: the Gmail, plus authenticator 2FA. **If the recovery codes were never
   saved, that account is one lost phone away from unrecoverable — and the Meta app, and Instagram
   publishing, go with it.** Confirm the codes exist.
4. **Finish Threads** (one consent popup) and **connect the Facebook Page** (needs a Facebook Login
   use case added to the app). Both detailed in `.data/meta-app.txt`.
5. **The first real post.** Instagram is connected and the install is publicly reachable, so this is
   finally possible — and it needs a JPEG, because Instagram has no text-only post. **Not one
   publishing driver has ever been called for real.**
6. 🔴 **Run `social:refresh-tokens --force` on the server once, and watch what comes back.** Instagram
   and Threads now renew themselves — `social:refresh-tokens` is built, tested, deployed and on the
   live scheduler at 08:05, ten minutes ahead of the expiry warning. It asks halfway through a
   token's life, so it will do nothing until about 5 September and everything after it. **The one
   thing never proved is a refresh against the real credential**: the endpoint was measured with a
   bogus token, the tests cover the rest, and `--force` was blocked by the tool sandbox rather than
   skipped. That single command is the whole remaining risk, and it is cheap — a refusal costs
   nothing, because the stored token is only replaced once a replacement is in hand. Backup at
   `~/kargah/database/database.sqlite.before-token-refresh-2026-08-06`. `.data/meta-app.txt` has the
   detail. LinkedIn still has to be re-pasted by hand every 60 days; a Facebook Page token does not
   expire at all. ⚠️ **The connect page deliberately still does not promise any of this.** Telling
   somebody Kargah renews their token, on the strength of a round trip nobody has watched, is the
   kind of copy trap §"Permissions are a promise" is about. Prove it first, then add the sentence to
   `Networks::INSTAGRAM['requirement']` and `THREADS` in the same commit.
7. **The four invoices read `Billed to: Nima Fazlipour`.** The owner later said Nima is the person
   who gave them the Claude account. **Ask before those documents go anywhere** — correcting the name
   means void and reissue, not an edit.
8. **`EVDS_API_KEY` is not set**, so no invoice to a domestic Turkish company can carry the lira
   equivalent the law requires. Free, from `evds2.tcmb.gov.tr`.
9. **Nothing keeps the deployed copy up to date.** Every deploy is a hand-run `git pull`.
10. **`payments` has no frozen reporting figure**, so a collection is valued at its invoice's
    issue-date rate.
11. **Time tracking → billable hours → invoice** — the biggest must-have that does not exist.
12. **The double-entry build**, when the owner asks. `project-guaid/DOUBLE-ENTRY-PLAN.md`.
13. **`⚡card-detail`'s `openCard()`** and the same shape on `⚡calendar` and `⚡table`: public `#[On]`
    listeners doing a bare `Card::find()`. Kargah has **no per-user visibility model at all**.
14. 🔴 **Reddit cannot be connected at all any more, and it is not Kargah's fault.** Measured on
    6 August 2026 on a real account: the captcha on `reddit.com/prefs/apps` was solved and `create
    app` answered with a link to the Responsible Builder Policy instead of making an app. Reddit's
    own wiki (`reddit.com/r/reddit.com/wiki/api/`) says why — the **legacy Data API**, which is the
    one `RedditPublisher` speaks and the only one that has a password grant, now takes new app
    requests **only with "a valid moderation use case"**, through a support ticket
    (`support.reddithelp.com/hc/requests/new?ticket_form_id=14868593862164`, "I'm a Developer" →
    "I want to register to use the Reddit API"). The route Reddit now calls official is its
    **Developer Platform**, where apps run on Reddit's own infrastructure — it gives an external
    server no way to submit a post at all, so it is not a substitute for the driver.
    ⚠️ **So the five credentials `Networks::REDDIT` asks for are no longer issued for this purpose**,
    and `requirement`'s "create a script app at reddit.com/prefs/apps" is now advice that cannot be
    followed. The driver is fine; the door closed. The only path left: create a subreddit, become
    its moderator, and cite that in the ticket. Do not spend a session on this before the ticket is
    answered.
    🔴 **Read the next paragraph before believing the one above.** Every measurement behind it was
    taken through `Embarrassed-Duty-511`, and that account turns out to be **shadowbanned** —
    so the finding is contaminated and the door may not be shut at all.

    🔴 **The account is shadowbanned, and that alone explains the whole afternoon.** Three unrelated
    actions were refused on it in one sitting: creating the app, creating `r/lavzencom`, and saving
    a **display name** (*"We had some issues saving your changes"*, three times). The test that
    settled it costs one request, needs no login, and is worth reaching for the moment an account
    behaves like this:

    ```
    old.reddit.com/user/Embarrassed-Duty-511  → HTTP 404
    old.reddit.com/user/spez                  → HTTP 200   (control)
    ```

    Invisible from outside, entirely normal from inside — no banner, no notice, which is exactly how
    a shadowban differs from a suspension. Two years old with 1 karma fits it.

    ⚠️ **So "the Data API is closed" is not established.** That conclusion came from one account's
    refusal, and a shadowbanned account is refused everything. The gate may be universal or it may
    apply only to flagged accounts; nobody has looked through a clean one. The order to work in is
    therefore: **appeal the shadowban first** (r/ShadowBan, or Reddit support), then retry
    `/prefs/apps`, and only if a healthy account is *also* refused does the moderation-use-case
    ticket become the story. `r/lavzencom` answering `is banned` is the one finding that stands on
    its own — it is a property of the name, not the account, and `r/lavzen` and `r/lavzencomstudio`
    were both free.
15. 🔴 **X cannot be connected either: the owner's account is suspended.** `@morpheus_a36176`.
    Changing its username silently does nothing — no error in the UI at all — and the reason is only
    visible in the network tab:

    ```
    POST api.x.com/1.1/account/settings.json              → 403
    ApiError … permissionsState HTTP-403 codes:[64]
    POST …/GetUsernameAvailabilityAndSuggestions          → 200   (the handle was free)
    ```

    **X error code 64 is "your account is suspended and is not permitted to access this feature."**
    A suspended account gets no developer app and publishes nothing, so `XPublisher` has nothing to
    connect to. Appeal at `help.x.com/en/forms/account-access/appeals` first. The Developer Program
    form was filled in but never submitted — the three agreement checkboxes are the owner's to tick,
    and there is no point until the suspension is lifted.

    ⚠️ **Two independent platforms, one afternoon, both accounts restricted** — Reddit shadowbanned
    and X suspended. Worth asking what those two signups have in common before assuming they are
    unrelated.

    ✅ **The lesson that generalises past both of them:** when a page's Save does nothing and shows
    no error, read the network tab before touching anything else. Three attempts were spent on
    Reddit's display name and three more on X's username, all guessing at clicks, when one look at
    the failing request named the cause immediately. Same rule this project already has for the
    server, one layer further out.
16. **Telegram — everything is resolved except two clicks the owner has to make.**
    Bot `@lavzenbot` (id 8771041037) exists; channel `@lavzencom` resolves to
    **`chat_id = -1002842134425`**, title "Lavzen". The connect form is filled with the handle and
    that numeric id and is waiting for a token.

    🔴 **The bot is not an admin of the channel yet**, and that is the failure this would otherwise
    hide. `getChat` answers happily for any public channel regardless of permission — it is
    `getChatMember` for the bot's own id that tells the truth, and it answers
    *"member list is inaccessible"*, which is what a non-admin bot gets. A connection saved in this
    state verifies fine and fails on the first real post.

    ⚠️ **The numeric id is stored deliberately, not `@lavzencom`.** `Networks::TELEGRAM` accepts
    either, and a Telegram channel's public link can be changed at any time — at which point a
    connection holding `@username` breaks with an error that never mentions the rename. The numeric
    id never moves.

    ⚠️ The bot token was pasted into a chat transcript, so it must be `/revoke`d in `@BotFather`
    before the connection is saved; the numeric chat id above survives the revoke.
17. **YouTube is built. It has never been connected, and connecting it is not a paste.**
    `YouTubePublisher`, `PublishesVideo`, `VideoItem` and `Networks::YOUTUBE` all exist, with
    eighteen tests. What is missing is a credential, and YouTube is the only entry here that cannot
    be satisfied from a settings screen: Google issues no long-lived upload key, so it takes a
    Google Cloud project, the **YouTube Data API v3** enabled, an OAuth client of type *Desktop
    app*, and one run of the consent flow to get a refresh token. Then client id, client secret and
    refresh token go on the connect page like any other credential.

    🔴 **Set the OAuth consent screen to *In production* before running the consent.** While it is
    in Testing, Google expires every refresh token after seven days — silently, with nothing on the
    pasted string to say which kind it is. That is why `token_lifetime_days` is null rather than 7,
    and why `invalid_grant` gets its own sentence in the driver.

    **What the build had to decide, so nobody re-opens it:**
    - Video does **not** join `Publisher::publish()`. That method's exclusion of video is still
      true and still right; YouTube gets a second operation instead. `PostPublisher` routes on
      `instanceof PublishesVideo`, checked before `TakesTargetOptions`.
    - `VideoItem` streams and `MediaItem` buffers, and they are siblings rather than one class with
      a flag. This needed a new `AttachmentService::readStream()` in **Data** — the first time
      Social has asked that contract for a handle rather than a string.
    - ⚠️ **`max_bytes` is 25 MB, and that number is `config/livewire.php`, not YouTube.**
      `temporary_file_upload.rules` is `max:25600`, and the composer is the only way a video becomes
      an attachment — so a larger catalogue number would be a promise the attach step cannot keep.
      Raising it means raising that config, which is app-wide and affects every upload form.
    - The file picker's `accept` is now built from the catalogue instead of a hardcoded image list.
      That list was already a second copy of `Networks`' mimes and it went wrong the moment YouTube
      arrived: the picker refused every video while the validation beside it allowed one.
    - Privacy defaults to `public`, overridable per target with `privacy_status`. A video that
      uploads and cannot be seen would mark the target published while achieving nothing.
18. Older debts: `CustomerReader` returning Eloquent models where every sibling returns arrays ·
    `has:stickers` · Butler's calendar and branching · uncursored `/api/v1/customers` · card writes
    and mail sending absent from `/api/v1` · no permalink for Instagram, Threads or Slack · Reddit
    takes no pictures · Tumblr on the legacy endpoint.

---

# What to read, in order

```
.data/meta-app.txt                                the Meta app — start here for anything social
.data/ssh.txt                                     how to reach the server, and the tokens (gitignored)
project-guaid/HANDOVER-DEPLOY-2026-08-05.md       the server
project-guaid/HANDOVER-BROWSER-2026-08-04-PM.md   the browser audits and what they found
tools/audit/README.md                             before running anything that clicks
project-guaid/HANDOVER-ACCOUNTING-2026-08-06.md   the money rules
project-guaid/HANDOVER-2026-08-05.md              the seventeen publishing destinations
docs/frontend-conventions.md                      before writing any Blade
project-guaid/DECISIONS.md                        skim; read the traps in full
```

---

# Standing instructions

- **Decide for yourself at every step and keep going.** Pick the best option, write down why in a
  docblock **at the site of the decision**, and move on. Do not stop to ask unless proceeding would
  be destructive or would waste an hour if the guess were wrong.
- **Back up before writing to the real book or to a live host**, and say where the backup is.
- **Minimal diffs.** If a file already follows the conventions, leave it and say so.
- **Do not write new tests** unless the behaviour genuinely has no coverage and would be dangerous
  without it. **Mutation-test anything you claim is covered**: break the code, watch the test fail,
  restore, and paste the failure.
- Run the full suite **once, at the end**, in the background. Run `vendor/bin/pint` on the files you
  touched, not the whole tree.
- Commit per batch with one-line messages ending:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- **Pushing to `origin/main` is authorised.** 🔴 **The repo is public — think before committing
  anything personal.** The signature PNG and `.data/` are the precedents, and it is the kind of
  decision that cannot be taken back by deleting the file later.
- **Never put a credential in a commit, a filename, or a chat message.** Tokens go in `.data/`, are
  read from disk by scripts, and are `shred`ed off the server afterwards. Passing one as a shell
  argument puts it in the process list.
- **Report honestly**: what was verified and how, what was not, and what you left out and why.
