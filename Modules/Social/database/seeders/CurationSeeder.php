<?php

namespace Modules\Social\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Social\Models\CurationFeed;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\CurationWindow;

/**
 * The curator's settings, outlets and windows, as a fresh install first gets them.
 *
 * 🔴 **Every write is `firstOrCreate`, never `updateOrCreate`, and that is the
 * one thing about this seeder that matters.** It runs from the deploy script.
 * These rows are settings the owner edits — a feed switched off because it went
 * to clickbait, an authority nudged down, a window moved after watching what
 * actually got read. An `updateOrCreate` would silently undo every one of those
 * on the next deploy, and the operator would have no way to tell that had
 * happened short of remembering what they changed.
 *
 * So: a row that exists is left exactly as it is. A feed shipped in a later
 * version arrives on the next deploy because its label is not there yet, which is
 * the useful half of re-running and the only half worth having.
 *
 * The consequence, stated because it is a real one: **changing a default in
 * `config/curation.php` does not change an install that has already been
 * seeded.** That is correct — a default is what somebody gets before they have an
 * opinion — but it means fixing a wrong URL in that file fixes nothing on a live
 * install, and the row has to be edited too.
 */
class CurationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedSettings();
            $this->seedFeeds();
            $this->seedWindows();
        });
    }

    /**
     * The singleton row.
     *
     * `CurationSetting::current()` would create it with the database's own column
     * defaults, which are the same numbers. It is written from config here anyway,
     * so that a fresh install and the config file cannot disagree about what the
     * defaults are — there is one list, and it is the one in `config/curation.php`
     * with the reasoning attached.
     */
    private function seedSettings(): void
    {
        if (CurationSetting::query()->exists()) {
            return;
        }

        $settings = (array) config('social.curation.settings', []);
        $aggregators = (array) config('social.curation.aggregators', []);

        $hn = (array) ($aggregators['hackernews'] ?? []);
        $lobsters = (array) ($aggregators['lobsters'] ?? []);

        CurationSetting::query()->create([
            'is_enabled' => (bool) ($settings['is_enabled'] ?? false),
            'timezone' => (string) ($settings['timezone'] ?? 'Asia/Tehran'),
            'curate_at_utc' => (string) ($settings['curate_at_utc'] ?? '01:30'),
            'weekend_days' => (string) ($settings['weekend_days'] ?? '4,5'),
            'max_age_hours' => (int) ($settings['max_age_hours'] ?? 72),
            'min_summary_length' => (int) ($settings['min_summary_length'] ?? 80),
            'spare_candidates' => (int) ($settings['spare_candidates'] ?? 3),
            'hackernews_enabled' => (bool) ($hn['enabled'] ?? true),
            'hackernews_authority' => (float) ($hn['authority'] ?? 0.75),
            'hackernews_min_points' => (int) ($hn['min_points'] ?? 50),
            'lobsters_enabled' => (bool) ($lobsters['enabled'] ?? true),
            'lobsters_authority' => (float) ($lobsters['authority'] ?? 0.70),
            'lobsters_min_engagement' => (int) ($lobsters['min_engagement'] ?? 25),
        ]);
    }

    /**
     * The outlets, in the order the config file lists them.
     *
     * `sort_order` is taken from that position so the settings page opens showing
     * them grouped by subject the way the config file groups them, rather than
     * alphabetically — which would interleave security, AI and Russian-language
     * outlets into one undifferentiated list of forty.
     */
    private function seedFeeds(): void
    {
        foreach ((array) config('social.curation.feeds', []) as $index => $entry) {
            $entry = (array) $entry;

            $label = trim((string) ($entry['label'] ?? ''));
            $url = trim((string) ($entry['url'] ?? ''));

            if ($label === '' || ! str_starts_with($url, 'http')) {
                continue;
            }

            CurationFeed::query()->firstOrCreate(
                ['label' => $label],
                [
                    'url' => $url,
                    'authority' => (float) ($entry['authority'] ?? 0.5),
                    'max_age_hours' => isset($entry['max_age_hours'])
                        ? (int) $entry['max_age_hours']
                        : null,
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }

    /**
     * One window per network that has an opinion about its own best hour.
     *
     * Networks absent from the config fall back to `default_window` at run time
     * rather than getting a row here — see the note on that key for why the
     * fallback is deliberately not editable.
     */
    private function seedWindows(): void
    {
        $default = (array) config('social.curation.default_window', []);

        foreach ((array) config('social.curation.windows', []) as $network => $window) {
            $network = trim((string) $network);

            if ($network === '') {
                continue;
            }

            $window = (array) $window;

            CurationWindow::query()->firstOrCreate(
                ['network' => $network],
                [
                    'starts_at' => (string) ($window['starts_at'] ?? $default['starts_at'] ?? '20:00'),
                    'ends_at' => (string) ($window['ends_at'] ?? $default['ends_at'] ?? '23:00'),
                    'weekend_starts_at' => $window['weekend_starts_at'] ?? null,
                    'weekend_ends_at' => $window['weekend_ends_at'] ?? null,
                    'hashtags_min' => (int) ($window['hashtags_min'] ?? $default['hashtags_min'] ?? 2),
                    'hashtags_max' => (int) ($window['hashtags_max'] ?? $default['hashtags_max'] ?? 3),
                    'is_active' => true,
                ],
            );
        }
    }
}
