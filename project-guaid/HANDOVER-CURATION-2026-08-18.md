# The daily curated post — what was built, and how to switch it on

Built 2026-08-18. Seven commits on `main`, from `3e476f3` to `962b747`.

## What it does

Once a day, Kargah reads about forty technology outlets, groups the articles into
the stories they are about, picks the one the most independent newsrooms carried,
fetches the article, has the Persian copy written for each connected network, finds
a picture, and creates one scheduled post per network — each at a random minute
inside the hours that network is actually read. `social:publish-due`, which was
already running every minute, sends them.

Nothing about the existing publishing path changed. The curator creates ordinary
`posts` and `post_targets` rows.

## Turning it on, in order

1. **Deploy.** `git pull` on the server, then
   `/opt/alt/php83/usr/bin/php artisan migrate --force` — three new tables for the
   settings and two for the curated log.
2. **Seed the catalogue.** `/opt/alt/php83/usr/bin/php artisan db:seed --class="Modules\Social\Database\Seeders\CurationSeeder" --force`.
   Idempotent, and it will not overwrite anything already there.
3. **Check an AI provider is configured** at `/settings/assistant`. Gemini's free
   tier is enough — this feature makes one request a day, occasionally four when
   stories are refused. Nothing else needs a key; there is no `GEMINI_API_KEY` in
   `.env` for this and there should not be, because the key belongs in the
   encrypted `assistant_providers` row where the settings page put it.
4. **Look at `/settings/curation`.** The windows ship with the researched defaults.
   Check the outlets and switch off anything you would not want quoted.
5. **Dry run first**, on the server:
   `/opt/alt/php83/usr/bin/php artisan social:curate-daily --dry-run --explain --force`
   It creates nothing, remembers nothing, and prints the ranking table, the chosen
   story, every network's copy, and the hour each would have been scheduled for.
   This is the command for tuning the windows before anything is live.
6. **Switch it on** on the settings page. The scheduler entry is already registered
   and reads its hour from the settings row.

## The two things most likely to go wrong

**No provider configured.** The settings page says so at the top, in a warning
band, and the command stops with the reason. It also raises a notification —
deduplicated, so a provider missing for a fortnight is one notification rather than
fourteen.

**The curation hour drifting past a window.** The story is chosen once a day. If
that happens after LinkedIn's morning window has opened, LinkedIn silently never
gets a post. The settings page refuses to save such a time and names the window,
but it is worth knowing that is the failure being defended against.

## What was deliberately not done

- **Bluesky as a source.** The bot reads eleven accounts there; it cost up to
  twenty seconds each and its whole contribution was engagement velocity, which
  this ranker does not use.
- **A second Gemini client.** Kargah already had one. See the `TextGenerator`
  entry in `DECISIONS.md`.
- **`min_aspect_ratio` on `Networks`.** `Cover` normalises its own images, which is
  what this feature needs. Making the composer warn a person about a hand-attached
  1080×2400 screenshot is a separate change to a shared file, and it is still
  outstanding — it is defect 2 in `NEXT-SESSION-SOCIAL.md`.
- **Retry moved off the web request.** Defect 3 in that same list, untouched.

## Still open, and worth knowing

- **The story can be up to seventeen hours old by the time Instagram posts it.**
  One story is chosen at 05:00 Tehran and Instagram's window is the evening. That
  is the price of "the same story everywhere, each at its own best hour". The knob
  is the curation hour; the alternative is two stories a day, which is not what was
  asked for.
- **Twelve of the seventeen publishers have never spoken to a real network.**
  LinkedIn, Telegram, Threads and Instagram published for real on 16 August. If you
  enrol one of the other twelve, its first live post may fail — which is now
  visible on the accounts page, because a failed publish finally writes
  `social_accounts.last_error`.
- **`ArticleText` is hand-rolled**, not `trafilatura` and not
  `fivefilters/readability.php`, because adding a Composer package was not
  something to do unasked. If the summaries ever read as though they were written
  from a navigation menu, that class is the suspect and a real extraction library
  is the fix.

## Tests

`--filter=Curation` covers the pipeline: sources and settings (21), clustering and
ranking (11), the copywriter (15), extraction and covers (10), a whole day end to
end (14), the settings page (10). `SocialAccountHealthTest` (3) covers the
`last_error` fix. Both new pages are in `SmokeTest::pageProvider()`.
