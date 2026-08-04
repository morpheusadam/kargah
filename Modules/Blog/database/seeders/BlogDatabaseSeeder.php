<?php

namespace Modules\Blog\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Blog\Models\Article;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Three articles, so a fresh install's Articles page shows what it is for.
 *
 * **Additive and idempotent, and both of those are load-bearing.** This runs
 * against the owner's own database from the deploy script. Nothing here
 * truncates, deletes or soft-deletes anything, and every write is an
 * `updateOrCreate` keyed on something the database already distinguishes — the
 * post's own body, the article's `post_id`, a target's (post, account) pair, an
 * account's handle on its network. Running it twice leaves the same rows with
 * the same ids; running it after somebody has written real articles leaves those
 * alone.
 *
 * **No credentials.** The two destinations are seeded unconnected, exactly as
 * `SocialDatabaseSeeder` does it and for the same reason: an unconnected account
 * makes the pages say that credentials are not configured, which is the honest
 * state of a fresh install, rather than pretending an article could go out.
 * `credentials` is absent from every update array here, so a site somebody
 * connected by hand is never overwritten.
 *
 * **The delivered article's target carries a `remote_id` and a `remote_url`.**
 * A target claiming to be published with neither is a row no code in this
 * project can produce, and it is also the row the edit page has to reason about
 * — see `blog::article-edit`, which uses exactly that to say a correction will
 * not reach the site. A seeded database that could not produce that sentence
 * would be a seeded database that hides the one thing worth showing.
 *
 * Times are offsets from today rather than fixed dates, anchored to midnight
 * plus an explicit time, so the list keeps showing something recent however long
 * after this was written it is run and the second run writes what the first one
 * wrote.
 */
class BlogDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = $this->owner();
            $accounts = $this->seedDestinations($user);

            $this->seedArticles($accounts, $user);
        });
    }

    /**
     * Whoever this database belongs to, or nobody.
     *
     * Null is a perfectly good answer — `posts.created_by` is nullable and an
     * article with no author is better than a second invented user on a database
     * that already has one. `SocialDatabaseSeeder` creates one because it runs
     * first; this one never needs to.
     */
    private function owner(): ?User
    {
        return User::query()->first();
    }

    /**
     * A WordPress site and a DEV.to account, both unconnected.
     *
     * These two rather than all three article destinations: they are the pair
     * that shows the interesting shape — one article, one destination that took
     * it and one that has not been set up — without seeding a third row nobody
     * looks at.
     *
     * @return array<string, SocialAccount> keyed by network
     */
    private function seedDestinations(?User $user): array
    {
        $accounts = [];

        $wanted = [
            [
                'network' => Networks::WORDPRESS,
                // A site's handle is its host. `WordPressPublisher` reads the
                // real address out of `credentials.site_url`, which this seeder
                // has none of and must not invent.
                'handle' => 'blog.kargah.dev',
                'display_name' => 'Kargah build log',
            ],
            [
                'network' => Networks::DEVTO,
                'handle' => '@morpheusadam',
                'display_name' => 'Nima Fazlipour',
            ],
        ];

        foreach ($wanted as $data) {
            $accounts[$data['network']] = SocialAccount::query()->updateOrCreate(
                ['network' => $data['network'], 'handle' => $data['handle']],
                [
                    'display_name' => $data['display_name'],
                    // `credentials` is deliberately absent. See the class docblock.
                    'is_active' => true,
                    'created_by' => $user?->id,
                ],
            );
        }

        // Whatever social account is already here, if one is, so the delivered
        // article can show a teaser beside the article itself. Looked up rather
        // than created: seeding Social's own accounts is Social's seeder's job
        // and duplicating it here would give a fresh install two Mastodons.
        $teaser = SocialAccount::query()
            ->whereIn('network', [Networks::MASTODON, Networks::BLUESKY, Networks::LINKEDIN])
            ->orderBy('id')
            ->first();

        if ($teaser !== null) {
            $accounts['teaser'] = $teaser;
        }

        return $accounts;
    }

    /** @param  array<string, SocialAccount>  $accounts */
    private function seedArticles(array $accounts, ?User $user): void
    {
        foreach ($this->articles() as $data) {
            $post = Post::query()->updateOrCreate(
                ['body' => $data['body']],
                [
                    'status' => $data['status'],
                    'scheduled_for' => $this->at($data['scheduled_for'] ?? null),
                    'published_at' => $this->at($data['published_at'] ?? null),
                    'company_id' => null,
                    'created_by' => $user?->id,
                ],
            );

            Article::query()->updateOrCreate(
                ['post_id' => $post->id],
                [
                    'title' => $data['title'],
                    'slug' => $data['slug'] ?? null,
                    'excerpt' => $data['excerpt'] ?? null,
                    'canonical_url' => $data['canonical_url'] ?? null,
                    // Nothing is attached, so there is no cover. A dangling id
                    // here would be a broken image on every page that shows one.
                    'featured_attachment_id' => null,
                ],
            );

            foreach ($data['targets'] as $key => $target) {
                $account = $accounts[$key] ?? null;

                if ($account === null) {
                    continue;
                }

                PostTarget::query()->updateOrCreate(
                    ['post_id' => $post->id, 'social_account_id' => $account->id],
                    [
                        'body_override' => $target['body_override'] ?? null,
                        'options' => $target['options'] ?? null,
                        'status' => $target['status'],
                        'remote_id' => $target['remote_id'] ?? null,
                        'remote_url' => $target['remote_url'] ?? null,
                        'error' => $target['error'] ?? null,
                        'attempts' => $target['attempts'] ?? 0,
                        'published_at' => $target['status'] === PostTarget::PUBLISHED
                            ? $this->at($target['attempted'] ?? null)
                            : null,
                        'last_attempt_at' => $this->at($target['attempted'] ?? null),
                    ],
                );
            }
        }
    }

    /**
     * A day-and-time offset from today, or null.
     *
     * @param  array{0: int, 1: int, 2: int}|null  $offset  [days from today, hour, minute]
     */
    private function at(?array $offset): ?Carbon
    {
        return $offset === null
            ? null
            : Carbon::today()->addDays($offset[0])->setTime($offset[1], $offset[2]);
    }

    /**
     * One delivered, one waiting, one still being written.
     *
     * The options bag on each article destination is the union the three drivers
     * read — see `Modules\Blog\Services\WordPressPublisher` for the table of keys
     * — written once and shared, which is exactly what the composer does.
     *
     * @return list<array<string, mixed>>
     */
    private function articles(): array
    {
        return [
            [
                'title' => 'Four board views and what each one cost',
                'slug' => 'four-board-views',
                'excerpt' => 'Table, calendar, dashboard and activity — and the one that was not worth building.',
                'body' => "We rebuilt the project board four times this quarter, and each view answered a different question.\n\nThe table is the one people actually use. The calendar earns its keep the week before a deadline and is dead weight the rest of the month. The dashboard took the longest and is the one I would cut first.\n\nWhat it cost, and what I would keep, is below.",
                'status' => Post::PUBLISHED,
                'scheduled_for' => [-6, 9, 30],
                'published_at' => [-6, 9, 30],
                'targets' => [
                    Networks::WORDPRESS => [
                        'status' => PostTarget::PUBLISHED,
                        'remote_id' => '412',
                        'remote_url' => 'https://blog.kargah.dev/four-board-views/',
                        'attempts' => 1,
                        'attempted' => [-6, 9, 30],
                        'options' => [
                            'title' => 'Four board views and what each one cost',
                            'slug' => 'four-board-views',
                            'excerpt' => 'Table, calendar, dashboard and activity — and the one that was not worth building.',
                            'status' => 'publish',
                            'categories' => ['Build log'],
                            'tags' => ['livewire', 'laravel'],
                            'create_missing_terms' => true,
                        ],
                    ],
                    'teaser' => [
                        'status' => PostTarget::PUBLISHED,
                        'body_override' => 'New post: four board views and what each one cost. The one that took longest is the one I would cut first.',
                        'remote_id' => '112934402118440077',
                        'remote_url' => 'https://mastodon.social/@kargah/112934402118440077',
                        'attempts' => 1,
                        'attempted' => [-6, 9, 32],
                    ],
                ],
            ],
            [
                'title' => 'Invoice PDFs that render right to left without collapsing',
                'slug' => null,
                'excerpt' => 'Two evenings, one very stubborn font, and the layout rule that fixed both.',
                'body' => "Right-to-left invoice templates are mostly a font problem pretending to be a layout problem.\n\nThe totals column kept jumping to the wrong side, which looked like a direction bug and was actually a table cell inheriting an alignment nobody set. One rule fixed it. Finding the rule took two evenings.",
                'status' => Post::SCHEDULED,
                'scheduled_for' => [2, 10, 0],
                'targets' => [
                    Networks::WORDPRESS => [
                        'status' => PostTarget::PENDING,
                        'options' => [
                            'title' => 'Invoice PDFs that render right to left without collapsing',
                            'excerpt' => 'Two evenings, one very stubborn font, and the layout rule that fixed both.',
                            'status' => 'publish',
                            'categories' => ['Build log'],
                            'tags' => ['pdf', 'typography'],
                            'create_missing_terms' => true,
                        ],
                    ],
                    Networks::DEVTO => [
                        'status' => PostTarget::PENDING,
                        'options' => [
                            'title' => 'Invoice PDFs that render right to left without collapsing',
                            'excerpt' => 'Two evenings, one very stubborn font, and the layout rule that fixed both.',
                            'status' => 'publish',
                            'tags' => ['pdf', 'typography'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'A freelance ledger in SQLite, and no regrets',
                'slug' => null,
                'excerpt' => 'What a single-file database gets right about a one-person business, and where it stops.',
                'body' => "Half-written. The argument is that a one-person business never has the concurrency problem SQLite is criticised for, and does have the backup problem it solves outright.\n\nStill to write: where it stops being the right answer.",
                'status' => Post::DRAFT,
                'targets' => [
                    Networks::WORDPRESS => [
                        'status' => PostTarget::PENDING,
                        'options' => [
                            'title' => 'A freelance ledger in SQLite, and no regrets',
                            'status' => 'draft',
                            'create_missing_terms' => true,
                        ],
                    ],
                ],
            ],
        ];
    }
}
