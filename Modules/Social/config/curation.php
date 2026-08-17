<?php

/*
| The defaults the curator's tables are seeded from — and nothing else.
|
| 🔴 **Nothing reads this file at run time.** Every value here exists in
| `curation_settings`, `curation_feeds` or `curation_windows`, and those rows are
| the truth, because the owner edits them on the settings page. A service that
| read config instead would answer differently from the page somebody is looking
| at, which is the worst kind of disagreement to debug. `CurationSeeder` is the
| one consumer, it runs on a fresh install, and after that this file is history.
|
| Registered as `config('social.curation')` — nwidart maps every file in a
| module's config directory, and only `config.php` itself becomes the bare
| `social` key.
|
| `authority` is 0..1 and means one thing only: how much to trust this outlet
| when there is no other signal. It is not a quality ranking of journalism and it
| is not a popularity score. It decides who wins when two outlets carry the same
| story and one of them has to represent it.
*/

return [

    /*
     * The singleton settings row, as it is first written.
     *
     * `is_enabled` is false, and deliberately so: this feature publishes to live
     * social accounts with nobody present, and code arriving on a server must not
     * start posting because it was deployed. Turning it on is an act, performed
     * on the settings page.
     *
     * `timezone` — `config('app.timezone')` is UTC and stays UTC; timestamps in
     * the database being UTC is not worth trading away for this. But a window of
     * "19:00 to 23:00" only means anything in the reader's own day, and the
     * readers are in Iran. Tehran is UTC+3:30 and has not observed daylight
     * saving since 2022, so the offset is constant — the conversion still goes
     * through the timezone database rather than a hardcoded 3.5, because a
     * country reversing that decision has happened before.
     *
     * `max_age_hours` is three days rather than one. Measured on the pipeline
     * this is ported from: at 48 hours only six items a day cleared the filter,
     * because Krebs, OpenAI and MIT Technology Review leave two or three days
     * between posts, and the pool ran dry at weekends. The ranker's own time
     * decay already sinks the older ones, so a wider window costs nothing and a
     * narrower one costs whole quiet days.
     *
     * `curate_at_utc` is 01:30 UTC, which is 05:00 in Tehran — before the
     * earliest window of the day (LinkedIn's 08:00) and clear of every other
     * entry on Kargah's schedule, including the 03:00 database backup.
     */
    'settings' => [
        'is_enabled' => false,
        'timezone' => 'Asia/Tehran',
        'curate_at_utc' => '01:30',
        // Thursday and Friday: the Iranian weekend, when LinkedIn is dead.
        'weekend_days' => '4,5',
        'max_age_hours' => 72,
        // Below this many characters an RSS standfirst is a bare headline, and a
        // summary written from it can only be the headline again in other words.
        // Aggregator entries are exempt: a Hacker News title with four hundred
        // points behind it has already been vouched for.
        'min_summary_length' => 80,
        // How many further candidates to try when the model refuses the best one.
        // Without this, one awkward story costs the whole day.
        'spare_candidates' => 3,
    ],

    /*
     * When each network is posted to, and how dense its hashtags are.
     *
     * The hours come from the research recorded in the plan for this feature, and
     * the two ends of the day are the whole reason posting is scheduled per
     * network: Instagram in Iran peaks in the evening — Persian-language sources
     * say 21:00–24:00, English-language ones 17:00–21:00, and 19:00–23:00 is the
     * band all of them agree on — while LinkedIn's best hour is a weekday
     * morning. One shared window would mean posting to one of them at its worst
     * hour on purpose.
     *
     * 🔴 The hashtag ceilings are constraints, not preferences. Ten or more
     * hashtags on LinkedIn risks a 30–50% reach penalty, and its 2026 algorithm
     * does not classify by hashtag at all — it reads the copy — so the budget
     * there is three and the keywords go into the opening line instead.
     * Instagram allows thirty and dense tagging is normal there, though
     * keyword-rich captions now outperform hashtag-heavy ones, which is why the
     * ceiling is 25 rather than 30.
     */
    'windows' => [
        // Weekday mornings, and a later, narrower window at the Iranian weekend
        // rather than no post at all.
        'linkedin' => ['starts_at' => '08:00', 'ends_at' => '11:30', 'weekend_starts_at' => '10:00', 'weekend_ends_at' => '12:00', 'hashtags_min' => 2, 'hashtags_max' => 3],
        'instagram' => ['starts_at' => '19:00', 'ends_at' => '23:00', 'hashtags_min' => 18, 'hashtags_max' => 25],
        'telegram' => ['starts_at' => '20:00', 'ends_at' => '23:30', 'hashtags_min' => 2, 'hashtags_max' => 3],
        'threads' => ['starts_at' => '20:00', 'ends_at' => '23:00', 'hashtags_min' => 3, 'hashtags_max' => 5],
        // 280 characters total, so a hashtag costs a clause.
        'x' => ['starts_at' => '20:00', 'ends_at' => '23:00', 'hashtags_min' => 1, 'hashtags_max' => 2],
        'facebook_page' => ['starts_at' => '19:00', 'ends_at' => '22:00', 'hashtags_min' => 2, 'hashtags_max' => 3],
        'mastodon' => ['starts_at' => '20:00', 'ends_at' => '23:00', 'hashtags_min' => 2, 'hashtags_max' => 4],
        'bluesky' => ['starts_at' => '20:00', 'ends_at' => '23:00', 'hashtags_min' => 2, 'hashtags_max' => 4],
    ],

    /*
     * What a network with no row of its own gets.
     *
     * A default rather than a gap: connecting a seventeenth account must not need
     * a migration before it can be posted to. Kept in config rather than as a
     * row, because it is the fallback *for* the rows and giving it one of its own
     * would make it editable and therefore deletable.
     */
    'default_window' => [
        'starts_at' => '20:00',
        'ends_at' => '23:00',
        'hashtags_min' => 2,
        'hashtags_max' => 3,
    ],

    /*
     * Aggregators, which supply their own engagement numbers.
     *
     * Bluesky is deliberately absent, though the source pipeline reads eleven
     * accounts there. It cost up to twenty seconds per account and its whole
     * contribution was engagement velocity, which the ranker here does not use —
     * see the note on `Story::$engagement`. Nothing else was lost: the accounts
     * that mattered were journalists linking to articles this catalogue already
     * carries.
     */
    'aggregators' => [

        'hackernews' => [
            'enabled' => true,
            'label' => 'Hacker News',
            'authority' => 0.75,
            // Algolia applies this in the query, so raising it does not cost a
            // second request. Fifty is roughly "already climbing" rather than
            // "was submitted".
            'min_points' => 50,
            'hits_per_page' => 50,
        ],

        'lobsters' => [
            'enabled' => true,
            'label' => 'Lobsters',
            'authority' => 0.7,
            // Not optional. See the docblock on the Lobsters source: without a
            // floor, a three-point story from a small community outranked a
            // two-hundred-point discussion.
            'min_engagement' => 25,
        ],

    ],

    /*
     * The feeds. One line each; adding an outlet needs no class.
     *
     * Grouped by subject in the order the channel's own topics are stated, so
     * that a gap in coverage is visible by reading down the list.
     */
    'feeds' => [

        // ── Security and hacking ────────────────────────────────────────────
        ['url' => 'https://feeds.feedburner.com/TheHackersNews', 'label' => 'The Hacker News', 'authority' => 0.9],
        ['url' => 'https://www.bleepingcomputer.com/feed/', 'label' => 'BleepingComputer', 'authority' => 0.9],
        ['url' => 'https://krebsonsecurity.com/feed/', 'label' => 'Krebs on Security', 'authority' => 0.95],
        ['url' => 'https://therecord.media/feed', 'label' => 'The Record', 'authority' => 0.8],
        ['url' => 'https://www.darkreading.com/rss.xml', 'label' => 'Dark Reading', 'authority' => 0.75],
        ['url' => 'https://www.securityweek.com/feed/', 'label' => 'SecurityWeek', 'authority' => 0.8, 'max_age_hours' => 96],

        // ── Artificial intelligence ─────────────────────────────────────────
        ['url' => 'https://techcrunch.com/category/artificial-intelligence/feed/', 'label' => 'TechCrunch AI', 'authority' => 0.8],
        ['url' => 'https://venturebeat.com/category/ai/feed/', 'label' => 'VentureBeat AI', 'authority' => 0.7],
        ['url' => 'https://openai.com/news/rss.xml', 'label' => 'OpenAI', 'authority' => 0.9],
        ['url' => 'https://blog.google/technology/ai/rss/', 'label' => 'Google AI', 'authority' => 0.85],
        ['url' => 'https://huggingface.co/blog/feed.xml', 'label' => 'Hugging Face', 'authority' => 0.7],

        // ── The internet itself: censorship, shutdowns, digital rights ──────
        //
        // These publish every few days, not every few hours, so each carries its
        // own window. Under the general 72 hours they almost never got a turn,
        // while a shutdown report or a filtering-policy change is still the
        // freshest thing on the subject a week later.
        ['url' => 'https://www.accessnow.org/feed/', 'label' => 'Access Now', 'authority' => 0.9, 'max_age_hours' => 168],
        ['url' => 'https://www.eff.org/rss/updates.xml', 'label' => 'EFF', 'authority' => 0.85, 'max_age_hours' => 120],
        ['url' => 'https://www.article19.org/feed/', 'label' => 'Article 19', 'authority' => 0.8, 'max_age_hours' => 168],
        ['url' => 'https://ooni.org/blog/index.xml', 'label' => 'OONI', 'authority' => 0.85, 'max_age_hours' => 336],
        ['url' => 'https://globalvoices.org/-/world/middle-east-north-africa/iran/feed/', 'label' => 'Global Voices Iran', 'authority' => 0.75, 'max_age_hours' => 336],
        ['url' => 'https://blog.cloudflare.com/rss/', 'label' => 'Cloudflare', 'authority' => 0.8, 'max_age_hours' => 120],
        ['url' => 'https://restofworld.org/feed/latest/', 'label' => 'Rest of World', 'authority' => 0.8, 'max_age_hours' => 168],

        // ── Russian-language outlets ────────────────────────────────────────
        //
        // They are here because the clusterer works across languages: a signature
        // is built from Latin tokens, and a Russian article about a story keeps
        // `OpenAI`, `Cloudflare` and a CVE number in Latin. So Habr covering the
        // same story as The Verge is corroboration rather than a separate story,
        // which is exactly the signal the ranker pays for.
        ['url' => 'https://habr.com/ru/rss/articles/?fl=ru', 'label' => 'Habr', 'authority' => 0.8],
        ['url' => 'https://3dnews.ru/news/rss/', 'label' => '3DNews', 'authority' => 0.7],
        ['url' => 'https://www.ixbt.com/export/news.rss', 'label' => 'iXBT', 'authority' => 0.7],
        ['url' => 'https://hi-tech.mail.ru/rss/all/', 'label' => 'Hi-Tech Mail', 'authority' => 0.6],
        ['url' => 'https://vc.ru/rss', 'label' => 'vc.ru', 'authority' => 0.65],
        ['url' => 'https://www.cnews.ru/inc/rss/news.xml', 'label' => 'CNews', 'authority' => 0.65],

        // ── General technology ──────────────────────────────────────────────
        ['url' => 'https://feeds.arstechnica.com/arstechnica/technology-lab', 'label' => 'Ars Technica', 'authority' => 0.85],
        ['url' => 'https://www.technologyreview.com/feed/', 'label' => 'MIT Tech Review', 'authority' => 0.8],
        ['url' => 'https://www.theverge.com/rss/index.xml', 'label' => 'The Verge', 'authority' => 0.85],
        ['url' => 'https://www.wired.com/feed/rss', 'label' => 'Wired', 'authority' => 0.85],
        ['url' => 'https://www.theregister.com/headlines.atom', 'label' => 'The Register', 'authority' => 0.8],
        ['url' => 'https://www.engadget.com/rss.xml', 'label' => 'Engadget', 'authority' => 0.7],
        ['url' => 'https://www.zdnet.com/news/rss.xml', 'label' => 'ZDNet', 'authority' => 0.7],
        ['url' => 'https://www.techradar.com/rss', 'label' => 'TechRadar', 'authority' => 0.6],
        ['url' => 'https://www.tomshardware.com/feeds/all', 'label' => "Tom's Hardware", 'authority' => 0.75],
        ['url' => 'https://www.phoronix.com/rss.php', 'label' => 'Phoronix', 'authority' => 0.75],
        ['url' => 'https://rss.slashdot.org/Slashdot/slashdotMain', 'label' => 'Slashdot', 'authority' => 0.7],
        ['url' => 'https://www.techspot.com/backend.xml', 'label' => 'TechSpot', 'authority' => 0.65],
        ['url' => 'https://www.neowin.net/news/rss/', 'label' => 'Neowin', 'authority' => 0.6],
        ['url' => 'https://9to5mac.com/feed/', 'label' => '9to5Mac', 'authority' => 0.65],
        ['url' => 'https://www.androidpolice.com/feed/', 'label' => 'Android Police', 'authority' => 0.6],
        ['url' => 'https://feed.infoq.com/', 'label' => 'InfoQ', 'authority' => 0.75, 'max_age_hours' => 96],
        ['url' => 'https://thenextweb.com/feed', 'label' => 'The Next Web', 'authority' => 0.6],

    ],

];
