<?php

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;

/**
 * One row per place a post is going.
 *
 * The default is `pending` with no attempts, which is what the composer writes
 * and what a publish run claims. `published()` deliberately sets a remote id
 * and a publish time together, because a target claiming to be published with
 * neither is a row no code in this module can produce and a test built on one
 * would prove nothing.
 */
class PostTargetFactory extends Factory
{
    protected $model = PostTarget::class;

    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'body_override' => null,
            'status' => PostTarget::PENDING,
            'remote_id' => null,
            'remote_url' => null,
            'error' => null,
            'attempts' => 0,
            'published_at' => null,
            'last_attempt_at' => null,
        ];
    }

    public function published(string $remoteId = 'remote-1'): static
    {
        return $this->state(fn (): array => [
            'status' => PostTarget::PUBLISHED,
            'remote_id' => $remoteId,
            'remote_url' => 'https://example.test/posts/'.$remoteId,
            'error' => null,
            'attempts' => 1,
            'published_at' => now()->subMinutes(10),
            'last_attempt_at' => now()->subMinutes(10),
        ]);
    }

    public function failed(string $error = 'The network refused the post.'): static
    {
        return $this->state(fn (): array => [
            'status' => PostTarget::FAILED,
            'error' => $error,
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes(10),
        ]);
    }

    /** A claim nobody released, as a killed worker leaves behind. */
    public function stuckPublishing(int $minutesAgo = 60): static
    {
        return $this->state(fn (): array => [
            'status' => PostTarget::PUBLISHING,
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
