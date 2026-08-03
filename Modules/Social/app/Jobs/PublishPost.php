<?php

namespace Modules\Social\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Social\Models\Post;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Services\PublishReport;

/**
 * Send one post to the networks it has not reached yet.
 *
 * One job per post, dispatched by `social:publish-due` or by the publish page.
 * Small on purpose: a post has as many targets as it has accounts, so this
 * finishes well inside `max_execution_time` on shared hosting, where the only
 * runner is a `queue:work --stop-when-empty` started by cron.
 *
 * **Idempotent, and not by being careful.** Every target is claimed by a
 * conditional update that a `published` row cannot match, so running this job
 * twice sends once. That means a duplicate dispatch — two cron ticks racing, a
 * worker killed after sending — costs a wasted query and nothing else, and it
 * is why there is no unique-job lock here to go stale and block the retry.
 *
 * The id travels rather than the model: `SerializesModels` would re-query the
 * post anyway, and an id is what survives the post being edited between
 * dispatch and run.
 */
class PublishPost implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $onlyAccountId  Retry one target rather than the whole post.
     */
    public function __construct(
        public readonly int $postId,
        public readonly ?int $onlyAccountId = null,
    ) {}

    public function handle(PostPublisher $publisher): PublishReport
    {
        $post = Post::query()->find($this->postId);

        // Deleted between dispatch and run. Nothing to do and nothing wrong —
        // the queue must not retry a post that no longer exists.
        if ($post === null) {
            return new PublishReport;
        }

        return $publisher->publishPost($post, $this->onlyAccountId);
    }
}
