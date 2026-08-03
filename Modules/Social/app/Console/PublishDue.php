<?php

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Jobs\PublishPost;
use Modules\Social\Models\Post;

/**
 * Hand every post whose time has come to a job.
 *
 * **This command does no publishing.** It finds a bounded amount of outstanding
 * work and dispatches one small job per post, which is the pattern every long
 * operation in Kargah follows — see project-guaid/spec/01-architecture.md. A
 * command that sent the posts itself would be a command that exceeds
 * `max_execution_time` the first time somebody schedules ten posts for nine
 * o'clock, and on shared hosting that is how an account gets suspended.
 *
 * Runs every minute, so a post fires within a minute of its time. Re-running is
 * harmless twice over: the post is claimed by a conditional update before it is
 * dispatched, and even a duplicate dispatch sends nothing extra, because each
 * target is claimed the same way and a `published` target cannot be claimed at
 * all.
 */
class PublishDue extends Command
{
    protected $signature = 'social:publish-due {--limit= : How many posts this tick may dispatch}';

    protected $description = 'Dispatch a publishing job for every scheduled post whose time has passed';

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?? config('social.due_batch', 25));

        $posts = Post::query()->due()->limit(max(1, $limit))->get();

        if ($posts->isEmpty()) {
            $this->components->info('Nothing is due.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($posts as $post) {
            if (! $this->claim($post)) {
                continue;
            }

            PublishPost::dispatch($post->id);

            $dispatched++;

            $this->components->info(
                'Dispatched post '.$post->id.', due '.$post->scheduled_for?->format('j M Y, H:i').'.',
            );
        }

        $this->components->info(
            'Dispatched '.$dispatched.' '.str('post')->plural($dispatched).'.'
            .($posts->count() === $limit ? ' More may be due; the next tick will take them.' : ''),
        );

        return self::SUCCESS;
    }

    /**
     * Move the post off `scheduled` before dispatching it.
     *
     * Conditional on the status this run actually read, so a second tick a
     * minute later does not queue the same post again while the first job is
     * still waiting on a slow network. This is a tidiness measure rather than
     * the safety one — the safety is on `post_targets` — which is why losing
     * the race here simply skips the post rather than reporting anything.
     */
    private function claim(Post $post): bool
    {
        return Post::query()
            ->whereKey($post->getKey())
            ->where('status', $post->status)
            ->update(['status' => Post::PUBLISHING, 'updated_at' => now()]) === 1;
    }
}
