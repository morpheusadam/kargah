# 08 — Postiz parity

**Status:** researched 3 August 2026 against Postiz's own source (`c:\Users\morph\Projects\postiz-app`,
shallow clone) and its public GitHub issues and documentation. This is an implementation checklist, not a
wish list, and it names decisions rather than deferring them.

**Why this document exists rather than an integration.** Postiz is a TypeScript monorepo needing Node,
PostgreSQL, Redis, Temporal workers and Docker — precisely the five things
[00-overview.md](00-overview.md)'s founding constraint forbids. It cannot be embedded, mounted behind a
menu item, or run alongside without giving up the shared-hosting target. So the decision was to take the
ideas and leave the code.

**Licence position.** Postiz is AGPL-3.0; Kargah is MIT. Nothing in this document, or in the module it
describes, is copied from Postiz's source. Every behaviour below — an OAuth flavour, a token lifetime, a
media limit, a rate-limit number — is a fact about a third-party network's own public API, described in
this document's own words from reading Postiz's code as reference material, never quoted. No Postiz
TypeScript appears here or should appear in the module.

---

## What Kargah already has

Precise, with file references, because that is what prevents the most expensive mistake available on this
page — rebuilding it.

- **`social_accounts`** (`Modules/Social/app/Models/SocialAccount.php`) — one row per connected account:
  `network`, `handle`, `display_name`, `avatar_url`, `token_expires_at`, `company_id`, `is_active`,
  `connected_at`, `last_checked_at`, `last_error`. Credentials are a JSON bag cast `encrypted:array`
  behind a `credentials` accessor, and both the encrypted column and the accessor are `$hidden` — the
  cast *decrypts on read*, so without that a Livewire payload would print the plaintext into the page.
- **`posts`** (`Modules/Social/app/Models/Post.php`) — `body`, `media` (unused — see *Media*, below),
  `status` (`draft`/`scheduled`/`publishing`/`published`/`partly_failed`/`failed`), `scheduled_for`,
  `published_at`, `company_id`. The status column is a **summary**, recomputed from the targets after
  every publish attempt; nothing decides whether to send by reading it.
- **`post_targets`** (`Modules/Social/app/Models/PostTarget.php`) — one row per post per account:
  `body_override`, `status` (`pending`/`publishing`/`published`/`failed`/`skipped`), `remote_id`,
  `remote_url`, `error`, `attempts`, `published_at`, `last_attempt_at`. This is where the whole design
  lives: status is **forward-only** — nothing moves a target out of `published` — and the unique index on
  `(post_id, social_account_id)` plus a conditional `UPDATE` (`PostPublisher::claim()`) is what makes the
  row itself the claim. Two workers racing on the same target produce one affected row and one that got
  nothing, with no lock table anywhere.
- **`social_notifications`** (`Modules/Social/app/Models/SocialNotification.php`) — inbound mentions,
  replies, follows, likes, reposts, unique on `(social_account_id, remote_id)` so a cron overlap costs
  nothing and `is_read` is never touched by ingestion.
- **Four network drivers**, all in `Modules/Social/app/Services/Publishers/`: Mastodon, Bluesky, LinkedIn,
  Telegram — each implementing `Publisher` (`network()`, `unavailableReason()`, `publish()`, `verify()`)
  and, where the network's API allows it, `IngestsNotifications`. `HttpPublisher` is the shared base:
  10 s timeout, 5 s connect timeout, three tries, 400 ms backoff, `throw: false` — a policy chosen so a
  post going to four networks runs inside `max_execution_time` with cron watching, rather than tuned per
  network. See *What Postiz does better* for why that becomes a problem later.
- **`PostPublisher`** (`Modules/Social/app/Services/PostPublisher.php`) — the one place in Kargah that
  sends a post. Claims a target, calls the driver, catches everything the driver throws, writes the
  outcome, recomputes the post's summary status. A failure never escapes the job, because a job that dies
  takes the post's other targets with it.
- **`Publishing`** (`Modules/Social/app/Services/Publishing.php`) — the driver registry, factories rather
  than instances, singleton, swappable in a test's `setUp()`. A network nobody publishes to is never
  built, and a swapped test network never constructs the real driver at all.
- **Scheduling on cron, not a queue class hierarchy.** `social:publish-due`
  (`Modules/Social/app/Console/PublishDue.php`) runs every minute, finds `Post::due()` (scheduled and past
  its time, or `publishing` and stale past `stale_claim_minutes` — a worker killed mid-send), claims each
  post with a conditional update, and dispatches one small `PublishPost` job per post. The command never
  publishes anything itself. The batch is bounded (`social.due_batch`, default 25) precisely so an
  unbounded backlog cannot exceed `max_execution_time` and get the account suspended.
- **`social:sync-notifications`** (`Modules/Social/app/Console/SyncNotifications.php`) — idempotent on
  `(social_account_id, remote_id)`, skips a network with no notifications API **by name** rather than
  showing an empty feed, and one failing network does not cost the others their sync.
- **Pages**, all `Modules/Social/resources/views/components/⚡*.blade.php`: `publish` (composer,
  per-network body overrides via `toggleOverride()`/`trimToLimit()`, live character counters against each
  network's limit, publish now / schedule / draft), `calendar`, `posts` (queued/published/failed/drafts
  tabs, per-target retry that cannot resend a published target), `post-show`, `accounts`,
  `account-connect`.
- **`Networks`** (`Modules/Social/app/Support/Networks.php`) — the whole catalogue as data rather than
  scattered code: label, icon, character limit, whether the network's API lets Kargah read notifications
  back, the credential fields a driver needs (with a `secret` flag, placeholder and hint), and a
  human-readable permissions list shown on the connect page. Whole Tailwind class strings, never
  concatenated.
- **Pasted tokens, not OAuth**, and this is a recorded decision (`DECISIONS.md`, phase 7): an OAuth
  callback needs a redirect URI registered per install, and shared hosting has no stable public URL to
  register one against. `account-connect` asks for the credential a network's own settings screen issues
  directly, then calls `verify()` — a real API call that asks the network who the credential belongs to
  and echoes the answer back, without posting anything.
- **About 36 tests** in `tests/Feature/SocialModuleTest.php` pin the properties that matter: independent
  per-target status, a retry that cannot resend a success (asserted on the publisher's send count, because
  a preserved row and a resend that rewrote the same values look identical from the database), the
  stale-claim reclaim window, job idempotency, credential encryption and its absence from every rendered
  page, cron firing within a minute, a bounded due-batch, and seeder idempotency.

Two things are **not** built and are recorded as deliberate absences rather than oversights
(`DECISIONS.md`, phase 7): **media attachments and engagement metrics were removed, not stubbed**, because
there was no upload path when Social was built and Kargah collected no metrics at all. `posts.media` and
every driver's `$media` parameter are still there, waiting. See *Media* — the situation has changed.

---

## What the shared-hosting constraint actually rules out

Postiz's answer to "how does this run reliably" is Temporal: a durable workflow engine with long-running
workers, one task queue per provider, declarative retry with backoff, and scheduled workflows for token
refresh and housekeeping. Kargah's answer is `queue:work --stop-when-empty` on a one-minute cron tick plus
the claim pattern above. Four capabilities are worth working out individually rather than waving at "cron
instead of a workflow engine" — they translate with very different amounts of friction.

**Token refresh on a schedule — survives, but the shape differs by network.** Because Kargah never mints an
access/refresh pair of its own, there is no daemon that must keep one alive for the four networks already
built: Mastodon and Bluesky credentials are scoped, revocable, non-expiring tokens issued from the
network's own settings screen; Telegram's bot token does not expire; LinkedIn's member token does, after
sixty days, and `Networks::LINKEDIN['requirement']` already says so — the human pastes a new one. That is
the entire refresh story today, and it is honest rather than automated.

What is missing is cheap and real: **`social_accounts.token_expires_at` is a real column with a `datetime`
cast and nothing reads it.** No warning, no notification when LinkedIn's token is five days from expiring.
Core's notification spine is built and has zero producers, so this is the obvious first one to wire.

For a **new** network whose API issues a short-lived access token (Pinterest ~1 hour, Reddit ~1 hour,
TikTok ~23 hours) behind a longer-lived refresh token, the pasted-token model still works — but only if the
refresh token is obtained once, outside Kargah, through the provider's own OAuth playground or (Reddit
specifically) its PIN-based installed-app flow, which needs no redirect URI at all. Once pasted in,
Kargah's own job can exchange it for a fresh access token on a schedule. That is a small
`social:refresh-tokens` command on the existing cron, needing nothing Kargah lacks: an HTTP client, a
lookahead window on `token_expires_at`, and somewhere in `credentials` for `refresh_token` plus the
network's client id and secret — which the existing credential-field pattern already expresses, with no
schema change. It is genuinely new work, not a translation, and it belongs with whichever OAuth-refresh
network is built first.

**Retry with backoff — Kargah has two layers, and they answer a different question than Temporal's one.**
`HttpPublisher::request()` retries a single HTTP call three times with 400 ms backoff inside one job — the
network-blip layer, applied uniformly to every driver. The **post-level** retry is deliberately not
automatic: a failed post is no longer `scheduled`, so `Post::due()` never picks it up again and a human
clicks retry. That is a considered design — no silent infinite-retry storm against a permanently wrong
credential — and it should stay. But it means there is no equivalent of "try again in ten minutes, then in
an hour, then flag it" for the case that genuinely is transient. Worth a config-gated addition later
(re-queue a failed target after N minutes, capped at M attempts, mirroring the `stale_claim_minutes`
pattern already in the codebase). Not urgent at four networks and one user.

**Rate-limit windows per network — does not exist, and one of the new networks needs it on day one.**
`PublishDue` bounds how many **posts** one tick may dispatch, so a batch scheduled for 9am cannot exceed
`max_execution_time`. Nothing bounds how many **targets on the same network** one tick or one job may
send. At four networks this has never mattered. It will the moment X is added: X's write API allows
roughly 300 posts per three hours per app, and Postiz encodes that as a hard ceiling of one in-flight
publish for that network. Kargah's equivalent is cheap — a per-network ceiling on how many targets may be
claimed in one tick — but it is new, not half-built.

**Staggered publishing across accounts — a known simplification, not a defect at current scale.**
`PublishPost` sends a post's targets sequentially inside one job. Postiz gets staggering free because each
network pulls from its own task queue. Five targets on one rate-limited network inside one Kargah job go
out back to back, spaced only by each call's latency. Nobody has connected five accounts to one network, so
this has never been exercised — worth naming rather than solving here.

None of the four needs Redis, a websocket, a daemon, or a second process.

---

## Networks

Postiz supports roughly 37. Kargah supports four. A freelancer does not need Farcaster and Nostr before X
and Instagram, so this is ranked, not alphabetised.

### Already built

| Network | What Kargah has | File |
| --- | --- | --- |
| Mastodon | Bearer token, per-instance, idempotency key on the status endpoint | `MastodonPublisher.php` |
| Bluesky | AT Protocol, app-password → session token per send, no cached session | `BlueskyPublisher.php` |
| LinkedIn | Member token + URN, 3,000-character limit, sixty-day manual re-paste | `LinkedInPublisher.php` |
| Telegram | Bot token + chat id, checks `ok: false` because a 200 is not proof of delivery | `TelegramPublisher.php` |

### Build next — the five

| Rank | Network | Why this one | Credential shape | Effort |
| --- | --- | --- | --- | --- |
| 1 | **X (Twitter)** | The single most-expected missing network for a freelancer's public presence. | OAuth 1.0a tokens are in practice permanent — no refresh flow exists or is needed. A consumer key/secret plus access token/secret is generated once from the developer portal and pasted, exactly like the existing model. The free tier is heavily rate-limited (~300 writes/3 h per app, and the monthly cap moves without much notice) — surface that on the connect page as a real constraint, not a Kargah bug. | moderate — images are resized and uploaded through a chunked endpoint even for one photo; video is materially harder and should be out of v1 |
| 2 | **Instagram** (business/creator) | High-value audience for creative and consulting freelancers. | OAuth2 via Meta's Graph API. **Requires a Business or Creator account linked to a Facebook Page** — a personal account is rejected outright. The long-lived token (~59 days) can be obtained manually from Meta's Graph API Explorer with no redirect URI, matching the LinkedIn precedent: paste, and re-paste roughly every two months. | moderate-hard — a post needs at least one image or video; captions cap at 2,200 characters; carousels (2–10 items), Stories and Reels each differ |
| 3 | **Facebook (Pages)** | Shares the Meta Graph client Instagram needs — cheap once #2 exists. | Same OAuth family and same manual long-lived-token route. Posting is **only ever as a Page**, never as the person — the API has no route to a personal timeline, which belongs on the connect page so nobody wonders why their profile did not update. | moderate |
| 4 | **Threads** | Same Meta family again, so the marginal cost is small, and it is a growing text-first network that suits a build-in-public freelancer. | Same Meta OAuth2 family. 500-character limit. Publishing is two-step — create a container, poll until processed, then publish — which is a new shape for Kargah's drivers (every existing one is a single call). Model it as a bounded internal retry loop, not a second job. | moderate |
| 5 | **Discord** | A near-free win against four moderate-to-hard Meta builds; useful for developer- and design-community-facing freelancers. | **No OAuth for posting at all.** A bot token created once in the developer portal and added to a server is the whole credential — the same shape as Telegram's, already proven here. | trivial-moderate |

### Worth having later

| Network | The constraint that pushes it down | Effort |
| --- | --- | --- |
| Slack | Token is effectively permanent and copied straight from the app's settings page, no callback — a genuinely easy fit. Lower only because fewer freelancers post client-facing content into Slack. | trivial |
| Reddit | OAuth2, ~1 h access token; a non-expiring refresh token is obtainable once through the PIN-based installed-app flow, no redirect URI. Niche value, and easy to get subreddit rules or flair wrong. | moderate |
| YouTube | Google OAuth2 with a real refresh token, obtainable once via Google's OAuth Playground. Video-only, resumable 8 MB chunks, strict daily quota — a real execution-time risk on shared hosting, not just an API detail. | hard |
| Pinterest | OAuth2, ~1 h access token behind a ~60-day refresh token. Multi-image posts need identical dimensions; video needs a separate cover image. Narrow audience outside visual niches. | moderate |
| TikTok | OAuth2, ~23 h access token with a refresh token — workable under the one-time bootstrap, but video-only, and "publish directly" needs an app-review tier most personal accounts lack. The fallback (send to inbox for manual finish) is a materially smaller feature than publishing. | hard, and smaller than it looks |
| Google Business Profile | Relevant to a local or agency presence, but a different content model — a post is a listing update, not a feed item. Genuinely a separate feature, not a row here. | out of scope |

### Do not build

- **Farcaster, Nostr** — Web3-native protocols with no plausible audience among Kargah's target.
- **Kick, Twitch, VK, Whop, Moltbook, Mewe, Skool, Lemmy** — small, niche or region-specific; none has been
  asked for.
- **Dribbble** — write access for portfolio uploads has been closed for years. This would be building
  against a network that does not let anyone in.
- **Instagram "standalone"** — a second Instagram-direct login path alongside the Facebook-mediated one.
  Redundant with the flow ranked above; pick one rather than building both.
- **Dev.to, Hashnode, Medium, WordPress, Tumblr** — publishing platforms for long-form writing, not social
  feeds. Genuinely useful for content marketing, but a different feature with different constraints (a
  Markdown or HTML editor, not a character-limited composer). Worth its own document, not a row in this one.
- **Listmonk** — an email-list integration. Kargah already has campaigns and suppressions in Mailbox; this
  would duplicate an existing module rather than extend one.

---

## Media

This is the one to think hardest about, and `DECISIONS.md`'s note — media "removed, not stubbed" because
there was no upload path — is **out of date**. `AttachmentService` shipped in phase 6 and is polymorphic
over any Eloquent model: `attach()`, `forTarget()`, `stream()`, `delete()`. Files land on a disk outside
the web root, the row is the only thing that makes them findable, and Data's own Files page already
demonstrates the whole pattern end to end with `Livewire\WithFileUploads`. **The blocker is gone.** What
remains is real work, in four parts.

1. **The composer needs an upload step.** `⚡publish.blade.php` has no `WithFileUploads`, no input, no
   preview. Follow the Files page: temporary uploads held in component state, attached to the `Post` via
   `AttachmentService::attach()` once `submit()` has created it — a post has to exist before anything can
   be attached to it, which `submit()` already does first.
2. **Resolve media at send time, not from `posts.media`.** `PostPublisher` already passes
   `$post->media ?? []` to the driver, reading a column nothing writes. Per "the database is the source of
   truth, not the UI", resolve `AttachmentService::forTarget($post)` instead, so one place holds the truth
   rather than a JSON column that can drift from the attachment table. `posts.media` and the `$media`
   parameter stay; only what feeds them changes.
3. **Every driver needs a genuinely new capability: uploading bytes, not just JSON.** `HttpPublisher` only
   does `acceptJson()->post()/get()` — no driver touches a byte stream. Each network's media step is its
   own two-call shape and none are the same: Mastodon uploads to a media endpoint and gets an id back to
   attach to the status; Bluesky uploads a blob to its repository and embeds the returned reference;
   LinkedIn registers an asset upload, `PUT`s the bytes to the URL that returns, then embeds the URN;
   Telegram switches from `sendMessage` to a multipart `sendPhoto` entirely. `HttpPublisher` needs a
   sibling method built on `Http::attach()`, and each driver needs its own upload step. There is no way to
   make this generic across networks.
4. **Limits belong in the `Networks` catalogue, not discovered from a 422.** Add a media count and accepted
   types alongside the existing character `limit`, so the composer warns before sending rather than after.

**Scope video out of the first pass.** An image fits comfortably inside one job's `max_execution_time`. A
chunked video upload — X's 1 MB chunks, LinkedIn's 2 MB, YouTube's 8 MB resumable protocol — does not: it
can span minutes, which is exactly the case durable workflows exist for and a single PHP request bounded by
`max_execution_time` is a poor fit for. Video is real, separate future work, most plausibly one attempt per
chunk rather than one HTTP call per attempt. This document should not pretend to have solved it by naming
it in the same row as an image.

---

## Features beyond networks

| Feature | Wanted? | What it costs | Effort |
| --- | --- | --- | --- |
| **Per-network content overrides** | Already built. | `toggleOverride()`, `trimToLimit()`, live per-account counters — `⚡publish.blade.php` already does all of it. | done |
| **Rich/markdown editor** | Low priority. | LinkedIn and X do not render real markdown anyway (LinkedIn fakes bold with Unicode substitution), so the value is mostly Mastodon. Not worth TinyMCE's weight for a handful of formatting characters. | trivial if scoped to a small toolbar; skip TinyMCE |
| **Link shortening** | Yes, moderate value. | Detect URLs, rewrite before sending, keep the original for the preview. Single-user, so no per-brand link styling — one configured provider is enough. Needs a URL-detection pass and an HTTP client; at most a small table of shortened → original. | moderate |
| **UTM tagging** | Yes, pairs with the above. | Append query parameters to a detected link before shortening. A query-string builder, nothing more. | trivial |
| **Threads (multi-post chains)** | Not built — one target is one post, full stop. | Genuinely new: a per-target list of body segments or a parent/child relationship between targets, a per-network splitting rule, and a publish step that posts the first segment then replies to its own `remote_id` for each one after. A schema change, not a driver tweak. | moderate-hard |
| **Carousels** | After the media pipeline. | Instagram (2–10), LinkedIn (images converted to one PDF), X (up to 4), Pinterest (up to 5, identical dimensions) each build one differently. No shared code, same as media upload. | moderate, after media |
| **First comment** | Yes — a link or hashtags in the first comment rather than the caption is an Instagram and LinkedIn convention. | `Publisher` has no "reply to what I just posted" method. Needs a small optional contract, the same shape as `IngestsNotifications`, a composer field, and a follow-up step run only after the parent publish succeeds. | moderate |
| **Basic engagement counts** | Modest value, cheap for the two networks that already expose it. | Mastodon and Bluesky both return favourite/reblog counts on a status lookup — the same periodic sync shape as `social:sync-notifications`. Not a dashboard, just numbers on the posts page. | moderate |
| **Full analytics** | Low priority for one user. | Per-network insights APIs with entirely different shapes, most gated behind Business tiers, plus a time-series table and a dashboard. Good to know what landed; not worth this cost against publishing reliability. | large |
| **Approval flow** | No — pointless for one user. | It exists in Postiz because an agency has a client who signs off. Kargah has nobody to approve for. | do not build |
| **Teams** | No — pointless for one user. | Same reasoning: multi-tenancy Kargah does not have. | do not build |
| **Public API for Social** | Yes, and cheap. | `Modules/Platform` is already a scoped Basic-auth API gateway built for exactly this. Exposing create-a-post, list-accounts and connect reuses the scope system, the application-password model and `NoDeadEndpointsTest`'s allowlist. None of Postiz's bespoke OAuth-app machinery is needed. | moderate |
| **Webhooks** | Optional, orthogonal to team size. | An outbound POST on a target's status change, for wiring Kargah into Zapier or Make. Reuses `HttpPublisher`'s patterns; needs a URL and a small dispatch job. Single-user does not make this less useful — automation-mindedness is independent of headcount. | moderate |
| **AI-assisted copy** | Speculative — flag, do not commit. | The assistant's provider layer now exists in `Modules/Platform`, so the dependency is no longer hypothetical. Still competes with a freelancer's own voice. Worth a "rewrite for X's limit" button eventually; not a priority. | moderate now that the provider layer exists |

---

## Do not build

- **A content marketplace** — needs multiple users and a rights and payment model on top. There is no
  single-user version of this feature.
- **Streak tracking or gamification** — a retention mechanic for a multi-tenant SaaS. Nothing to retain a
  user from in a tool they already own outright.
- **A bespoke digest-email workflow** — Core's notification system already has a feed and a
  delivery-preference model. Reuse that sweep rather than building a second one for Social.
- **AI video or UGC generation** — a heavy external dependency entirely out of proportion to a
  shared-hosting freelancer tool.
- **A browser extension** — a separate product surface, orthogonal to what Kargah is.
- **A Social-specific SDK or MCP server** — `Platform`'s scoped API already gives programmatic access to
  one user's own data. A second integration layer is ceremony nobody asked for.
- **A Temporal-shaped worker pool** — the thing the shared-hosting constraint exists to rule out, and
  Postiz's own issue tracker makes the cost concrete rather than theoretical: reports of dozens of
  auto-started workers, one per provider, burning idle CPU and RAM regardless of how many integrations are
  configured; and of scheduled posts silently not firing because a worker was terminated mid-run. Kargah's
  cron-plus-claim model was built specifically to survive a killed process — see the stale-claim test in
  `SocialModuleTest.php` — at the cost of nothing beyond the cron entry it already runs.

---

## What Postiz does better, plainly

**Per-network rate and concurrency awareness, encoded as data on the provider.** Postiz gives each network a
concurrency ceiling matched to its real API limits — one in-flight publish for X, a couple for Threads, far
more for YouTube's generous upload quota. Kargah applies one uniform policy (10 s timeout, three tries,
400 ms backoff) to every network, which has been fine at four low-volume networks and stops being fine the
moment X is added with its much stricter window.

**Adopting it is small** and costs nothing about Kargah's design: a rate-limit note per entry in
`Networks::all()` — the same table that already carries the character limit — plus a per-network ceiling on
what one tick will claim. No schema change; `PostTarget` already carries `social_account_id` and the
network is one join away.

A related, smaller point: Postiz's driver errors carry a **category** — bad request, retryable, needs
reconnect — rather than a free-text string, which is what lets it decide automatically whether to retry, ask
for a fresh token, or give up. Kargah's `PublishFailed` is a human-readable message and nothing else, and
every failed target is treated the same regardless of why it failed. Defensible at single-user scale, where
a human reads the error and decides — but it is what the retry-with-backoff gap above is really pointing at.
Postiz can auto-retry a rate limit and leave an expired credential alone because it knows which is which;
Kargah currently cannot tell them apart without a person reading the string.

## What Postiz does the hard way, because it has Redis and a workflow engine to spend

- **Meta's token refresh is admittedly incomplete in Postiz's own code** — its Facebook refresh path
  returns empty values and the "refresh token" it stores is the access token itself. Even a well-resourced
  project with a full OAuth stack has not solved Meta's sixty-day expiry cleanly; it falls back to asking
  the user to reconnect, which is Kargah's manual-paste model reached by a more expensive road.
- **Always-on infrastructure that idles.** A durable workflow engine buys Postiz real value at its actual
  scale — many organisations, many concurrent workflows worth distributing. It buys Kargah nothing at one
  user's scale, while costing exactly the five things the founding constraint forbids.

---

## Suggested order

1. Wire the one gap that already exists: a daily check against `social_accounts.token_expires_at`,
   notifying through Core's built-and-unused notification pipeline. Small, and immediately useful for
   LinkedIn today.
2. Media, in the four parts above, **images only**. Unblocks carousels, materially improves any
   Instagram or X build, and closes the one absence `DECISIONS.md` flagged as no longer blocked.
3. X, using the media pipeline just built.
4. Instagram and Facebook together — one Meta Graph client, two networks.
5. Threads, off the same client.
6. Discord, as the cheap win alongside four harder builds.
7. Link shortening and UTM tagging — small, independent, high value per hour.
8. First comment.
9. A per-network rate and concurrency ceiling, once X's stricter window makes it matter rather than before.
10. Expose Social through `Platform`'s existing API gateway.
11. Slack, Reddit, YouTube, Pinterest — each needing its one-time manual token bootstrap worked out first.
12. Engagement counts for Mastodon and Bluesky, webhooks, threads-as-chains, TikTok. Useful, none urgent.
13. Full analytics, AI-assisted copy — revisit once everything above is stable and in use.

---

## How much of Postiz is reachable here

Roughly **80–85%** of what a freelancer uses day to day. The publishing core, scheduling, per-network
overrides, notification ingestion, link shortening, UTM tagging, first comment, a public API and even
per-network rate awareness all translate onto cron plus the claim pattern Kargah already has. None of it
needs a daemon.

What does not survive cleanly is a small, expensive tail: true resumable video upload inside one PHP
request's execution budget; a handful of networks whose only credential is a short-lived OAuth token
needing a one-time manual bootstrap most people will find awkward (TikTok, Pinterest, Reddit, YouTube); and
the genuinely multi-tenant features — approval flow, teams, a marketplace — which have no single-user shape
at all. The missing 15–20% is concentrated in those three buckets rather than spread across the feature
list.
