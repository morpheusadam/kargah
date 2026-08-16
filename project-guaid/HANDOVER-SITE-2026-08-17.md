# Handover — the Site module, 17 August 2026

Kargah can now operate the website rather than only publish to it. Written so the next session can
continue without re-deriving anything, and so the parts that are **not** proven are impossible to
mistake for the parts that are.

**Branch:** `main`
**Started from:** `84e665c`
**Module added:** `Modules/Site`, enabled in `modules_statuses.json`

---

## What the brief was, and the one correction it needed

The instruction was to replace "the Statamic panel", add professional SEO management and cache
management, and be able to control every part of the website from Kargah.

**`lavzen.com` does not run Statamic. It runs WordPress.** Checked directly: the response carries
a custom theme at `wp-content/themes/lavtheme`, Rank Math PRO's schema block in the head, and
Hostinger's LiteSpeed headers. Nothing anywhere in this repository mentions Statamic either. Every
other part of the brief maps cleanly onto that WordPress install — wp-admin is the panel, Rank Math
is the SEO plugin, LiteSpeed is the cache — so the work was done against WordPress. If Statamic was
meant literally, there is a second site somewhere that nobody here has seen, and this module is
aimed at the wrong one.

---

## The shape

There is **no second connection and no second credential**. A WordPress site was already a
`social_accounts` row — `Modules\Blog` says so at length and `Networks::WORDPRESS` already names
`site_url`, `username`, `application_password`. `Modules\Site` resolves that row and drives the site
with the credential somebody already pasted. A `site_connections` table would have made one site
into two rows that can disagree, and the first disagreement is a revoked password showing as
connected on one page and broken on another.

```
Modules/Site/app/
├── Services/
│   ├── WordPressSite.php      the REST client: auth, timeouts, pagination, errors
│   ├── SiteRequestFailed.php  one exception, carrying WordPress's own code and wording
│   ├── SiteSnapshot.php       identity + capabilities + namespaces, cached five minutes
│   ├── SiteContent.php        posts and pages
│   ├── SiteSeo.php            Rank Math's fields, and what to do when they are not exposed
│   ├── SiteMedia.php          the library, and alt text
│   ├── SiteTaxonomy.php       categories and tags
│   ├── SiteComments.php       the moderation queue
│   └── SiteCache.php          purging, and what to do when nothing exposes it
└── Support/PostTypes.php      the two content types, their statuses

Modules/Site/resources/views/components/
    ⚡overview ⚡content ⚡content-edit ⚡comments ⚡taxonomies ⚡media ⚡seo ⚡cache
```

Sidebar group **Website**: Connection · Content · Comments · Terms · Media · SEO · Cache.

---

## The decisions that carry it

| Decision | Why |
|---|---|
| **Nothing is mirrored into Kargah's database.** Every list is a live round trip. | A website is a mutable document with other editors. A stale copy shown confidently is worse than being honestly slower. Mailbox mirrors IMAP for the opposite reason: mail is append-only and nobody else edits it. |
| **Every read sends `context=edit`.** | Without it WordPress returns the *rendered* body, and saving that back fills a post with the residue of its own rendering. |
| **Every write sends only the difference.** | WordPress reads an absent field as "leave alone" and an empty one as "clear". Posting the whole form back wipes the featured image, the template and every custom field the panel never drew — with a valid request and a 200. |
| **Writes are never retried; reads are retried once.** | A `POST` replayed after the site already accepted it is how one article becomes two. |
| **A plain `http://` site is refused outright.** | An application password over HTTP is a credential in cleartext, and WordPress disables application passwords without TLS anyway. |
| **Posts and pages are trashed; media and terms are force-deleted.** | Not a preference. WordPress has a trash for the first pair and refuses one for the second with `rest_trash_not_supported`. |
| **`force` goes in the query string.** | `Http::delete($url, $data)` puts `$data` in the JSON body, which WordPress ignores for a DELETE. Caught by a test; it would have made every media and term delete fail. |
| **Restore comes back as a draft.** | The API does not report the pre-trash status. Nothing should reappear on a live site because somebody clicked restore to look at it. |
| **The snapshot caches success and never caches failure.** | Five minutes of remembering a site is down is five minutes of telling somebody who has just fixed it that it is still broken. |
| **Capabilities are read from `users/me?context=edit` up front.** | A green badge on a Subscriber's password is a lie that otherwise surfaces as a 403 several pages later. |
| **Terms are ordered by use and `hide_empty` is never sent.** | Alphabetical order tells you nothing. An unused term is exactly what the page is for. |
| **The SEO page is an audit, not a score.** | A score invites optimising the score, which means padding a page until a meter turns green. |
| **The comment queue opens filtered to `hold`, and comment bodies are never rendered as HTML.** | A held comment is invisible to its author until somebody acts. And this is the one screen whose entire content is untrusted text by definition — rendering it would execute the thing being judged. |
| **Spam and trash stay separate buttons.** | Marking spam teaches the site's filter; trashing does not. Collapsing them degrades filtering over months in a way nobody traces back to the panel. |

---

## 🔴 The two facts the module is built around

Both were corroborated from sources rather than guessed, and both are the reason a page offers a
snippet instead of a button.

**1. Rank Math does not register its post meta with `show_in_rest`.** Its fields live in ordinary
post meta, and WordPress refuses meta over REST until something whitelists the key. Its own editor
sidebar does not need that, so the plugin has never had a reason to expose them. Corroborated from
Rank Math's support desk ("Unable to Update Meta Title/Description via REST API"), the WordPress.org
thread "change Focus Keyword using REST API", and the existence of `Devora-AS/rank-math-api-manager`,
a plugin whose whole purpose is that registration.

The failure mode is silent: WordPress answers **200** and drops the unregistered keys without a
word. So `SiteSeo::editable()` reads what came back, the editor offers the fields only when they are
really there, `SiteSeo::rejected()` compares what was sent against what was stored, and the page
hands over an mu-plugin rather than showing inputs that discard what is typed into them.

**2. No major cache plugin exposes purging over REST.** LiteSpeed, WP Rocket and W3 Total Cache each
have a documented entry point and every one is a PHP hook: `litespeed_purge_all`,
`rocket_clean_domain()`, `w3tc_flush_all()`. Detecting `litespeed/v1` proves the plugin is installed
and nothing about whether Kargah may call anything — those routes exist for QUIC.cloud's own
integration. `SiteCache::purgeSnippet()` is the fourteen-line mu-plugin that registers one endpoint
and dispatches to whichever plugin is present, falling back to `wp_cache_flush()`.

Both snippets are mu-plugins rather than `functions.php` additions, deliberately: a theme update or
switch empties `functions.php`, and the symptom is a feature that worked for a month and then quietly
stopped.

---

## What is proven and what is not

**Proven:** 116 tests, 254 assertions across seven files, every one under
`Http::preventStrayRequests()` — which on this machine is not ceremony, because there is no CA bundle
in `php.ini` and an escaped request dies with cURL error 60 rather than passing quietly. Full suite
green at **1522 tests, 6947 assertions**, run on a cleared view cache.

🔴 **Not proven: any of it against a real site.** Every request shape is `Http::fake()` and nothing
else, the same standing as the twelve social drivers `NEXT-SESSION.md` describes. Nobody has pointed
this module at `lavzen.com`, because doing so needs an application password and this session did not
handle credentials.

**The highest-value work available is one real connection.** In order:

1. Paste a WordPress application password for `lavzen.com` on the connect page, open
   `/site`, and press **Check the connection**. That single call proves the auth header, the URL
   building, the capability read and the namespace discovery at once.
2. Open a post in the editor and see whether `meta` carries any `rank_math_*` key. That answers fact
   1 above for this install in one look.
3. Save an SEO field and watch whether `rejected()` fires. If it does, the mu-plugin is needed and
   the page already says so.
4. Add the cache mu-plugin, re-check the connection, and purge one URL.

---

## What is deliberately not built

- **Menus, users, settings, plugins, themes.** Each is a real part of wp-admin and none is half-built
  here; adding one is a service plus a page plus tests, in the shape the seven existing ones set.
  Menus are the awkward one: `wp/v2/menus` exists only on a block theme, and a classic theme's menus
  are not exposed by core at all.
- **Custom post types and custom taxonomies.** `GET /wp/v2/types` would discover them, but their
  fields, taxonomies and statuses are unknown, so the editor drawn for one would be a guess. Adding a
  known type is a line in `PostTypes::all()`.
- **A rich text editor.** The body is a textarea holding what WordPress stores. A WYSIWYG over block
  markup strips the block comments on first save and silently converts every block into a
  classic-editor blob.
- **A whole-site SEO sweep.** The audit examines one page of results — up to 100, WordPress's own
  ceiling — and says so in the heading rather than implying more.

---

## One thing found on the way that is not this module

`Modules\Social` has three defects worth a session, all observed live rather than reasoned about:

1. **A failed publish never touches `social_accounts.last_error`.** It is written only by
   `RefreshTokens` and `SyncNotifications`, so an account whose token Meta had invalidated kept
   showing `Connected` and counted toward "4 of 4 ready to publish" while every post to it failed.
2. **`Networks` has no `min_aspect_ratio`.** Instagram's feed accepts 4:5 to 1.91:1; a 1080×2400
   screenshot has a ratio of 0.45 and the composer accepts it without a word.
3. **Retry publishes inside the web request.** `⚡post-show.blade.php` calls `publishPost` directly
   rather than queueing, so a retry holds an HTTP request for the length of a full Instagram
   container round trip.
