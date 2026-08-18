<?php

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Log;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishedPost;
use Modules\Social\Services\Publishers\PublishesVideo;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\TakesTargetOptions;

/**
 * The only thing in Kargah that sends a post.
 *
 * Everything about this class is arranged around one requirement: **a retry
 * must not resend the target that already worked.** The design that delivers it
 * is on the row, not in this code — a forward-only status plus the unique index
 * on (post_id, social_account_id) — and this class's job is to never work
 * around it.
 *
 * How a target is taken:
 *
 * 1. A conditional `update` moves it from `pending`/`failed` to `publishing`.
 *    The database applies that atomically, so two workers racing produce one
 *    affected row and one that got nothing. A target already on `published`
 *    matches no condition and is skipped — which is the whole guarantee.
 * 2. The driver is called. Whatever it throws is caught, because a job that
 *    dies takes the post's other targets with it and the next run would resend
 *    the ones that succeeded.
 * 3. The outcome is written to the same row. `published` is terminal; nothing
 *    in this module moves a target back out of it.
 *
 * `attempts` is incremented on the way past and is a diagnostic only. A worker
 * killed between the increment and the send leaves a count that lies, which is
 * exactly why nothing here decides anything by reading it.
 */
class PostPublisher
{
    /**
     * Images per post id, for the length of one publish run.
     *
     * `publishPost()` walks a post's targets and every one of them wants the
     * same pictures. Resolving per target would be one attachment query per
     * network and — worse — each `MediaItem` would memo its bytes separately,
     * so a four-megabyte photo going to four networks would be read off the
     * disk four times. Keyed by post id rather than held as one field because
     * `publishTarget()` is public and the posts page calls it directly for a
     * single retry.
     *
     * @var array<int, list<MediaItem>>
     */
    private array $mediaByPost = [];

    public function __construct(
        private readonly Publishing $publishing,
        private readonly PostMedia $media,
        private readonly PublishAnnouncer $announcer,
    ) {}

    /**
     * Publish everything still outstanding on a post.
     *
     * Idempotent: called twice in a row, the second call claims nothing, sends
     * nothing, and leaves every row exactly as the first call left it —
     * including `updated_at`, because the post's summary status is only saved
     * when it is actually dirty.
     */
    public function publishPost(Post $post, ?int $onlyAccountId = null): PublishReport
    {
        $report = new PublishReport;

        $targets = $post->targets()->with('account')->orderBy('id')->get();

        foreach ($targets as $target) {
            if ($onlyAccountId !== null && (int) $target->social_account_id !== $onlyAccountId) {
                continue;
            }

            $this->publishTarget($target, $report);
        }

        $this->syncPostStatus($post);

        // Announced here rather than inside `publishTarget()`, so a post that
        // reached three networks is one message and not three — three is how a
        // bot gets muted, and muting it costs the one case it exists for, which
        // is the failure nobody was watching.
        //
        // Only when this run actually sent something. A second run that claims
        // nothing and sends nothing is not an event, and announcing it would mean
        // every stale-claim sweep pinged the operator about yesterday's post.
        if ($report->published > 0) {
            $this->announcer->announce($post);
        }

        return $report;
    }

    /**
     * Take one target and send it, or record why it could not be sent.
     *
     * Returns true only when this call reached the network and it accepted the
     * post. False covers three different things — already published, could not
     * be claimed, refused — and the report distinguishes them for the caller.
     */
    public function publishTarget(PostTarget $target, ?PublishReport $report = null): bool
    {
        $report ??= new PublishReport;

        if (! $this->claim($target)) {
            $report->recordUntouched();

            return false;
        }

        $account = $target->account;

        if ($account === null) {
            return $this->fail($target, $report, 'The account this post was going to no longer exists.');
        }

        $driver = $this->publishing->driverFor($account->network);

        if ($driver === null) {
            return $this->fail(
                $target,
                $report,
                'Kargah has no driver for '.$account->label().', so the post was not sent.',
            );
        }

        // Asked before sending rather than discovered afterwards: an account
        // with no credentials is the ordinary state of a fresh install, not an
        // exception. It lands in `post_targets.error` and reads as something
        // the owner can fix.
        if ($reason = $driver->unavailableReason($account)) {
            return $this->fail($target, $report, $reason);
        }

        try {
            $options = is_array($target->options) ? $target->options : [];

            // Three calls rather than one, decided by what the driver
            // implements — the same `instanceof` shape `Publishing::ingesterFor()`
            // uses on the read side.
            //
            // Most destinations take a string and some pictures and nothing
            // else. WordPress needs a title, categories and a draft-or-publish
            // decision, none of which are copy; a driver that has not asked for
            // options never sees them even when the target carries some, which
            // is right, because a post going to a blog and to X carries a title
            // for the first and the title is not X's business. See
            // `Publishers\TakesTargetOptions`.
            //
            // **Video is checked first, and is a different operation rather than
            // a longer version of the same one.** It takes one video and no
            // pictures and cannot promise to finish in seconds, and a driver
            // that uploads video has nothing useful to do with `publish()` at
            // all — see `Publishers\PublishesVideo`.
            $result = match (true) {
                $driver instanceof PublishesVideo => $this->publishVideoTo($driver, $account, $target, $options),
                $driver instanceof TakesTargetOptions => $driver->publishWithOptions(
                    $account,
                    $target->text(),
                    $this->mediaFor($target),
                    $options,
                ),
                default => $driver->publish($account, $target->text(), $this->mediaFor($target)),
            };
        } catch (PublishFailed $e) {
            return $this->fail($target, $report, $e->getMessage());
        } catch (\Throwable $e) {
            // A driver that throws something other than PublishFailed is a bug
            // in the driver, and it is still this target's failure rather than
            // the job's — see the class docblock.
            Log::error('social: '.$account->network.' driver threw '.$e::class.': '.$e->getMessage());

            return $this->fail(
                $target,
                $report,
                $account->label().' failed in a way Kargah did not expect: '.$e->getMessage(),
            );
        }

        $target->forceFill([
            'status' => PostTarget::PUBLISHED,
            'remote_id' => $result->remoteId,
            'remote_url' => $result->remoteUrl,
            'error' => null,
            'published_at' => now(),
        ])->save();

        $report->recordPublished();

        return true;
    }

    /**
     * Send a target to a driver whose post is a video.
     *
     * **The missing-video case is answered here rather than in the driver**, and
     * that is deliberate: `PostPublisher` is the only thing that knows what is
     * attached to the post, and a driver told "publish nothing" can only guess
     * why. `YouTubePublisher::publish()` still refuses by name for the path that
     * reaches it another way, so the sentence exists in both places and neither
     * depends on the other.
     *
     * The video is **not** memoised the way `mediaFor()` memoises pictures.
     * There is no saving to be had: one post has at most one video target today,
     * and a `VideoItem` holds no bytes to share — it opens a fresh handle per
     * upload on purpose. See `Publishers\VideoItem`.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws PublishFailed
     */
    private function publishVideoTo(
        PublishesVideo $driver,
        SocialAccount $account,
        PostTarget $target,
        array $options,
    ): PublishedPost {
        $post = $target->post;
        $video = $post === null ? null : $this->media->videoForPost($post);

        if ($video === null) {
            throw PublishFailed::rejected(
                $account->network,
                'this post has no video attached, and '.$account->label().' has no other kind of post',
            );
        }

        return $driver->publishVideo($account, $target->text(), $video, $options);
    }

    /**
     * The pictures this target should carry.
     *
     * **Resolved from the attachment rows, never from `posts.media`.** That
     * column is dead — see the note on `Modules\Social\Models\Post` — and this
     * is the reason it had to become dead rather than merely unused: a JSON
     * copy of what is attached and a table of what is attached will agree right
     * up until somebody deletes a file from the Files page, at which point one
     * of them says the post has three images and the other says two, and
     * whichever the publisher happens to read decides what the world sees.
     * There is one source and it is the one the delete button writes to.
     *
     * @return list<MediaItem>
     */
    private function mediaFor(PostTarget $target): array
    {
        $post = $target->post;

        if ($post === null) {
            return [];
        }

        return $this->mediaByPost[$post->getKey()] ??= $this->media->forPost($post);
    }

    /**
     * Move a target to `publishing`, but only from a status that allows it.
     *
     * One statement, evaluated by the database. Reading the row and then writing
     * it would leave a window in which two workers both saw `pending`.
     */
    private function claim(PostTarget $target): bool
    {
        $claimed = PostTarget::query()
            ->whereKey($target->getKey())
            ->claimable()
            ->update([
                'status' => PostTarget::PUBLISHING,
                // Read from the loaded model rather than computed in SQL: it is
                // a diagnostic, `LEAST` is not portable across SQLite and MySQL,
                // and the column is an unsigned tiny integer that would
                // overflow at 255 on a target nobody ever fixed.
                'attempts' => min($target->attempts + 1, PostTarget::MAX_ATTEMPTS_RECORDED),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        // The claim was written by a query, so the in-memory row is a version
        // behind. Everything after this reads `status` and `attempts`.
        $target->refresh();

        return true;
    }

    private function fail(PostTarget $target, PublishReport $report, string $error): bool
    {
        $target->forceFill([
            'status' => PostTarget::FAILED,
            'error' => $error,
        ])->save();

        $this->recordAccountError($target, $error);

        $report->recordFailed($error);

        return false;
    }

    /**
     * Tell the account, not only the target, that it just failed.
     *
     * 🔴 **Before this, `social_accounts.last_error` was written only by
     * `RefreshTokens` and `SyncNotifications` — never on the publish path.** The
     * consequence was observed live rather than reasoned about: an Instagram
     * account whose token Meta had invalidated kept showing `Connected` on the
     * accounts page and kept counting toward "4 of 4 ready to publish", while
     * every post to it failed. The failure was recorded, correctly, on each
     * `post_targets` row — where you only find it if you already suspect
     * something and go looking post by post.
     *
     * `CheckTokenExpiry` cannot cover this: it only examines rows with a non-null
     * `token_expires_at`, so a credential pasted without a recorded lifetime is
     * invisible to it, and a token revoked early is invisible to it whatever it
     * says.
     *
     * This matters far more now than it did. A person pressing publish sees the
     * red row in front of them; a daily curated post goes out at ten at night
     * with nobody watching, and an account that has been silently failing for a
     * fortnight is exactly the failure this feature is prone to.
     *
     * Written with a bare `update()` rather than through the model: nothing here
     * may touch `credentials_encrypted`, and a `save()` on a hydrated account
     * would rewrite every column it holds — including one whose accessor decrypts
     * and re-encrypts. `last_checked_at` moves with it so the page can say when.
     * Success does **not** clear the column, deliberately: `RefreshTokens` and
     * `SyncNotifications` own clearing it, and a target that happened to work
     * does not mean the expiry warning they recorded has stopped being true.
     */
    private function recordAccountError(PostTarget $target, string $error): void
    {
        $accountId = $target->social_account_id;

        if ($accountId === null) {
            return;
        }

        SocialAccount::query()->whereKey($accountId)->update([
            'last_error' => $error,
            'last_checked_at' => now(),
        ]);
    }

    /**
     * Recompute the post's summary status from its targets.
     *
     * The post's column is a summary and the targets are the truth, so this
     * derives rather than tracks — a post whose targets were fixed by hand
     * heals on the next run instead of staying wrong forever.
     *
     * `published_at` is the *earliest* target's publish time, never `now()`.
     * That is what makes a second run change nothing: a value recomputed from
     * the rows is the same value, and the post is only saved when the fill
     * actually made it dirty.
     */
    public function syncPostStatus(Post $post): void
    {
        $targets = $post->targets()->get();

        if ($targets->isEmpty()) {
            return;
        }

        $outstanding = $targets->whereIn('status', [PostTarget::PENDING, PostTarget::PUBLISHING])->count();
        $published = $targets->where('status', PostTarget::PUBLISHED);
        $failed = $targets->where('status', PostTarget::FAILED)->count();

        // Nothing has ever been attempted, so there is nothing to summarise. A
        // draft with three targets is a draft, and saying `publishing` here
        // would put it in the queue view and in `Post::due()`.
        if ($outstanding === $targets->count()) {
            return;
        }

        $status = match (true) {
            $outstanding > 0 => Post::PUBLISHING,
            $failed === 0 && $published->isNotEmpty() => Post::PUBLISHED,
            $published->isNotEmpty() => Post::PARTLY_FAILED,
            $failed > 0 => Post::FAILED,
            // Every target skipped, which is not a state anything writes today.
            default => $post->status,
        };

        $post->fill([
            'status' => $status,
            'published_at' => $published->isEmpty() ? null : $published->min('published_at'),
        ]);

        if ($post->isDirty()) {
            $post->save();
        }
    }
}
