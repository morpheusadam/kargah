<?php

namespace Modules\Social\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Models\SocialNotification;
use Modules\Social\Support\Networks;

/**
 * The accounts, posts and notifications the Social pages read.
 *
 * **Nothing here has a credential.** Every account is seeded unconnected, which
 * is the honest state of a fresh install: the pages then say that credentials
 * are not configured rather than pretending a post could go out. Connecting one
 * is a deliberate act on the connect page, and it is the only thing that writes
 * a secret to this database.
 *
 * Idempotent. Every write is an `updateOrCreate` keyed on something a person
 * would recognise and the database already enforces — an account's handle on
 * its network, a post's own text, a target's (post, account) pair, a
 * notification's remote id — so running it twice leaves the same rows with the
 * same ids. It runs from the deploy script, and a deploy that duplicated the
 * queue would send everything twice.
 *
 * Times are offsets from today rather than fixed dates, so the calendar keeps
 * showing something scheduled and something published however long after this
 * was written it is run. They are anchored to midnight plus an explicit time,
 * so the second run writes the value the first one wrote and nothing comes out
 * dirty.
 */
class SocialDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = $this->owner();

        DB::transaction(function () use ($user): void {
            $accounts = $this->seedAccounts($user);

            $this->seedPosts($accounts, $user);
            $this->seedNotifications($accounts);
        });
    }

    /**
     * Whoever this database belongs to.
     *
     * An existing user is preferred over inventing a second one on a database
     * that already has somebody in it.
     */
    private function owner(): User
    {
        return User::query()->first() ?? User::query()->create([
            'name' => 'Nima Fazlipour',
            'email' => 'nima@kargah.test',
            'password' => 'password',
        ]);
    }

    /** @return array<string, SocialAccount> keyed by network */
    private function seedAccounts(User $user): array
    {
        $accounts = [];

        foreach ($this->accounts() as $data) {
            $accounts[$data['network']] = SocialAccount::query()->updateOrCreate(
                ['network' => $data['network'], 'handle' => $data['handle']],
                [
                    'display_name' => $data['display_name'],
                    'avatar_url' => null,
                    // `credentials` is deliberately absent from this array. A
                    // seeder must never overwrite a secret somebody connected
                    // by hand, and it has none of its own to write.
                    'company_id' => null,
                    'is_active' => $data['is_active'],
                    'created_by' => $user->id,
                ],
            );
        }

        return $accounts;
    }

    /** @param  array<string, SocialAccount>  $accounts */
    private function seedPosts(array $accounts, User $user): void
    {
        foreach ($this->posts() as $data) {
            $post = Post::query()->updateOrCreate(
                ['body' => $data['body']],
                [
                    'status' => $data['status'],
                    'scheduled_for' => $this->at($data['scheduled_for'] ?? null),
                    'published_at' => $this->at($data['published_at'] ?? null),
                    'company_id' => null,
                    'created_by' => $user->id,
                ],
            );

            foreach ($data['targets'] as $network => $target) {
                $account = $accounts[$network] ?? null;

                if ($account === null) {
                    continue;
                }

                PostTarget::query()->updateOrCreate(
                    ['post_id' => $post->id, 'social_account_id' => $account->id],
                    [
                        'body_override' => $target['body_override'] ?? null,
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

    /** @param  array<string, SocialAccount>  $accounts */
    private function seedNotifications(array $accounts): void
    {
        foreach ($this->notifications() as $data) {
            $account = $accounts[$data['network']] ?? null;

            if ($account === null) {
                continue;
            }

            SocialNotification::query()->updateOrCreate(
                ['social_account_id' => $account->id, 'remote_id' => $data['remote_id']],
                [
                    'kind' => $data['kind'],
                    'actor_handle' => $data['actor_handle'],
                    'excerpt' => $data['excerpt'],
                    'url' => $data['url'],
                    // Written rather than left, because a seeded feed with
                    // nothing unread does not show what the page is for. A
                    // person who marks one read keeps that until the next
                    // deploy, which is the right trade for a demonstration row
                    // and the wrong one for an ingested one — which is why
                    // `social:sync-notifications` never touches this column.
                    'is_read' => $data['is_read'],
                    'occurred_at' => $this->at($data['occurred']),
                ],
            );
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

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        return [
            [
                'network' => Networks::MASTODON,
                'handle' => '@kargah@mastodon.social',
                'display_name' => 'Kargah build log',
                'is_active' => true,
            ],
            [
                'network' => Networks::BLUESKY,
                'handle' => '@morpheusadam.bsky.social',
                'display_name' => 'Nima Fazlipour',
                'is_active' => true,
            ],
            [
                'network' => Networks::LINKEDIN,
                'handle' => 'in/morpheusadam',
                'display_name' => 'Nima Fazlipour',
                'is_active' => true,
            ],
            [
                'network' => Networks::TELEGRAM,
                'handle' => '@kargah_buildlog',
                'display_name' => 'Kargah build log',
                'is_active' => false,
            ],
            [
                'network' => Networks::DISCORD,
                // A webhook posts to one channel, so the channel is what the
                // queue and the calendar should name — not a server or a bot.
                'handle' => '#build-log',
                'display_name' => 'Kargah build log',
                'is_active' => false,
            ],
        ];
    }

    /**
     * The queue, the history and the one that went wrong.
     *
     * The failed target carries the message an unconfigured install actually
     * produces, because that is what a reader of this database sees on their
     * own machine — a made-up API error would send them looking for a problem
     * they do not have.
     *
     * @return list<array<string, mixed>>
     */
    private function posts(): array
    {
        return [
            [
                'body' => "Shipped the drag-and-drop board in Kargah this week. Cards keep their order after a refresh, which sounds trivial until you try it without a full page reload.\n\nIt is Livewire 4 single-file components plus a thin Sortable wrapper — no single-page framework, no build step to babysit. It runs on a small shared host and still feels instant.",
                'status' => Post::SCHEDULED,
                'scheduled_for' => [1, 9, 30],
                'targets' => [
                    Networks::LINKEDIN => ['status' => PostTarget::PENDING],
                    Networks::BLUESKY => [
                        'status' => PostTarget::PENDING,
                        'body_override' => 'Shipped drag-and-drop boards in Kargah. Cards keep their order after a refresh — Livewire 4 plus a thin Sortable wrapper, no single-page framework and no build step.',
                    ],
                ],
            ],
            [
                'body' => 'Build log: invoice PDF templates now render right to left without the layout collapsing. Two evenings and one very stubborn font.',
                'status' => Post::SCHEDULED,
                'scheduled_for' => [3, 18, 0],
                'targets' => [
                    Networks::MASTODON => ['status' => PostTarget::PENDING],
                ],
            ],
            [
                'body' => 'Benchmarked the whole application on a small shared host. Median page render 84 ms with no cache warm-up, and not a single-page framework in sight.',
                'status' => Post::PUBLISHED,
                'scheduled_for' => [-2, 10, 5],
                'published_at' => [-2, 10, 5],
                'targets' => [
                    Networks::MASTODON => [
                        'status' => PostTarget::PUBLISHED,
                        'remote_id' => '112934402118440021',
                        'remote_url' => 'https://mastodon.social/@kargah/112934402118440021',
                        'attempts' => 1,
                        'attempted' => [-2, 10, 5],
                    ],
                    Networks::BLUESKY => [
                        'status' => PostTarget::PUBLISHED,
                        'remote_id' => 'at://did:plc:kargahbuildlog/app.bsky.feed.post/3kxq2vh7t2s2f',
                        'remote_url' => 'https://bsky.app/profile/morpheusadam.bsky.social/post/3kxq2vh7t2s2f',
                        'attempts' => 1,
                        'attempted' => [-2, 10, 5],
                    ],
                ],
            ],
            [
                'body' => 'Client onboarding checklist I use before writing a line of code. It saves at least one awkward call per project.',
                'status' => Post::PARTLY_FAILED,
                'scheduled_for' => [-1, 12, 15],
                'published_at' => [-1, 12, 15],
                'targets' => [
                    Networks::MASTODON => [
                        'status' => PostTarget::PUBLISHED,
                        'remote_id' => '112928811004592118',
                        'remote_url' => 'https://mastodon.social/@kargah/112928811004592118',
                        'attempts' => 1,
                        'attempted' => [-1, 12, 15],
                    ],
                    Networks::LINKEDIN => [
                        'status' => PostTarget::FAILED,
                        'error' => 'LinkedIn credentials are not configured — Member URN and Access token are missing, so the post was not sent.',
                        'attempts' => 2,
                        'attempted' => [-1, 12, 20],
                    ],
                ],
            ],
            [
                'body' => 'Half-written thread about keeping a freelance invoice ledger in SQLite and never regretting it.',
                'status' => Post::DRAFT,
                'targets' => [
                    Networks::BLUESKY => ['status' => PostTarget::PENDING],
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function notifications(): array
    {
        return [
            [
                'network' => Networks::MASTODON,
                'remote_id' => 'mastodon-notification-44119',
                'kind' => SocialNotification::REPLY,
                'actor_handle' => '@rita@fosstodon.org',
                'excerpt' => 'This is exactly the workflow I was missing. Is the ordering code public anywhere?',
                'url' => 'https://mastodon.social/@rita/112934500118440099',
                'is_read' => false,
                'occurred' => [0, 8, 40],
            ],
            [
                'network' => Networks::MASTODON,
                'remote_id' => 'mastodon-notification-44107',
                'kind' => SocialNotification::REPOST,
                'actor_handle' => '@devsam@hachyderm.io',
                'excerpt' => 'Benchmarked the whole application on a small shared host.',
                'url' => 'https://mastodon.social/@kargah/112934402118440021',
                'is_read' => false,
                'occurred' => [-1, 19, 12],
            ],
            [
                'network' => Networks::BLUESKY,
                'remote_id' => 'at://did:plc:ritavance/app.bsky.feed.like/3kxq5m2p8ab2c',
                'kind' => SocialNotification::LIKE,
                'actor_handle' => '@ritavance.bsky.social',
                'excerpt' => 'Shipped drag-and-drop boards in Kargah.',
                'url' => 'https://bsky.app/profile/ritavance.bsky.social',
                'is_read' => true,
                'occurred' => [-2, 11, 5],
            ],
            [
                'network' => Networks::BLUESKY,
                'remote_id' => 'at://did:plc:harbourfinch/app.bsky.feed.post/3kxq7a1d4kk9z',
                'kind' => SocialNotification::MENTION,
                'actor_handle' => '@harbourfinch.bsky.social',
                'excerpt' => 'Anyone else running Livewire on shared hosting? Curious how the queue is handled without a daemon.',
                'url' => 'https://bsky.app/profile/harbourfinch.bsky.social/post/3kxq7a1d4kk9z',
                'is_read' => false,
                'occurred' => [-3, 14, 48],
            ],
        ];
    }
}
