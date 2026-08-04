# Which destinations Kargah supports, and why the others were refused

Written 4 August 2026, after working through the full list of platforms a competing tool
(Postiz) integrates. The point of this document is that **the refusals are decisions, not gaps**.
Each one has a reason, the reason is written down, and nobody has to rediscover it by spending a
day on an integration that could not have worked.

Three things decide whether a destination is possible here, and a platform only has to fail one:

1. **Is posting free?** Kargah is a self-hosted tool for one freelancer. A destination whose write
   API is behind a subscription is not one this project can offer.
2. **Does it fit one cron-driven HTTP job?** The founding constraint in `00-overview.md` is
   ordinary PHP shared hosting: no Redis, no daemon, no Node, no Docker. A publish is one job
   bounded by `max_execution_time`. A chunked, resumable video upload is not.
3. **Can a credential be obtained by hand?** Kargah has no public callback URL, so anything whose
   only path runs through a registered OAuth redirect cannot be connected on a fresh install.

---

## Supported — seventeen

| Destination | Credential | Notes |
|---|---|---|
| Mastodon | access token | reads notifications back |
| Bluesky | app password | reads notifications back |
| LinkedIn | member token | expires after 60 days |
| Telegram | bot token + chat | a caption is 1,024 where a message is 4,096 |
| Discord | webhook URL | one webhook is one channel |
| X | OAuth 1.0a, four values | free tier posts; mentions need a paid tier |
| Facebook Pages | Page access token | must be exchanged for a long-lived one |
| Instagram | Page token + IG account id | 🔴 needs a publicly reachable install |
| Threads | Threads token | own host, own token |
| WordPress | application password | article destination |
| **Slack** | bot token + channel | pictures ride as image blocks |
| **Tumblr** | OAuth 1.0a + blog | a picture makes it a photo post |
| **VK** | access token + wall | three-step photo upload |
| **Reddit** | 🔴 script app + account password | text posts only |
| **Lemmy** | 🔴 username + password | one picture, as the post's URL |
| **DEV.to** | API key | article destination |
| **Hashnode** | PAT + publication id | article destination |

The ten unemboldened rows were in place by 4 August 2026; **the seven in bold arrived on the 5th**.

**Reddit and Lemmy are the two entries that take a real account password**, and that is stated on
their connect pages rather than buried. Neither platform issues a scoped, revocable credential
that works without a callback URL: Reddit's only such flow is a script app's password grant, and
Lemmy's API has one way in and it is `user/login`. Kargah stores the password encrypted like every
other credential and recommends a dedicated posting account. That is a worse bargain than the rest
of this catalogue offers, and the person deserves to be told rather than protected from the choice.

---

## Refused, and the reason

### Video and streaming — fails constraint 2

**TikTok · YouTube · Twitch · Kick**

All four are video-first. TikTok and YouTube both require a chunked, resumable upload that can span
minutes; Twitch and Kick have no "post" concept at all — they are live streaming, and what looks
like a feed is chat. A publish in Kargah is one HTTP job inside one PHP request's execution budget.
An image fits; a resumable video upload does not, and a job killed halfway leaves a post half-sent.

This is the same reason video is absent from the media pipeline for the ten networks that *do*
support it. It is real, separate future work and it needs a different execution model, not a
different driver.

### Paid or gated behind a commercial plan — fails constraint 1

**beehiiv** — a paid newsletter product; API access sits on its higher tiers.
**Skool**, **Whop** — paid community platforms; there is no free posting API.
**HeyGen** — a paid AI video service, and video besides.

### The API is gone or closed — not paid, simply unavailable

**Medium** — the write API was effectively retired in 2023. Integration tokens are no longer
issued to new accounts and the endpoint is unmaintained. Cheap to build, worthless once built.

**Dribbble** — the API has been closed to new applications for years. An existing key still works;
a new install cannot obtain one, which makes it something Kargah cannot honestly offer.

### Cryptographically or structurally out of reach — fails constraint 3

**Nostr** — an event has to be signed with a schnorr signature over secp256k1 and published to
relays over WebSocket. PHP has no schnorr in core, the pure-PHP implementations are slow and
delicate, and relays are sockets rather than requests. Genuinely free and genuinely open, and still
the wrong shape for this runtime.

**Farcaster / Warpcast** — writing a cast needs a registered signer key and submission to a hub.
The practical path everyone uses is a hosted API that is itself a paid product, which collapses
this into constraint 1 as well.

### Approval-gated — fails constraint 3 in practice

**Pinterest** — the API is free, but a new app is in trial access and only reaches production
after review. Posting a pin also needs a publicly fetchable image URL, which Kargah now has. Worth
revisiting: this is the closest of the refusals to being possible.

**Google Business Profile** — free, but requires a quota request and approval, and "a local post
on a business listing" is a different object from everything else in this catalogue. Niche for the
freelancer this tool is for.

### Not a publishing destination at all

**GitHub** — an issue or a gist is not a post, and Kargah's Data module already tracks repos.
**Listmonk**, **Resend**, **node-mailer** — email senders. Mailbox is where that belongs, not Social.
**MeWe**, **Moltbook**, **ReelFarm**, **Tumblr's mobile clients**, and similar — either no public
write API or too small an audience to justify a driver nobody will connect.

---

## What would change a refusal

- **Video** becomes possible the day Kargah has a worker that outlives one request. That is a
  change to `01-architecture.md`, not to a driver.
- **Pinterest** becomes possible as soon as somebody walks an app through Pinterest's review. The
  driver itself would be small — the signed public URL it needs already exists for Instagram.
- **Medium** would come back if the API did. It will not.
- **Nostr** becomes possible with a PHP extension for secp256k1 schnorr, or by shelling out to a
  binary — neither of which is safe to assume on shared hosting.

Nothing here should be re-litigated without one of those four things having changed.
