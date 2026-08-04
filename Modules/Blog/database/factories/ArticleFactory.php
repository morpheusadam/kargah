<?php

namespace Modules\Blog\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Blog\Models\Article;
use Modules\Social\Models\Post;

/**
 * An article, and the post it is the other half of.
 *
 * **There is no status here and the states below do not invent one.** An
 * article's status is its post's — see the docblock on
 * `Modules\Blog\Models\Article`, which refuses to hold a third opinion about
 * whether something is published — so `draft()`, `scheduled()` and
 * `published()` set the *post's* state through `PostFactory` and write nothing
 * to `blog_articles`. The WordPress statuses (`draft`, `publish`, `pending`,
 * `private`) are a different thing again: they are per-destination and live in
 * `post_targets.options`, so they belong to a target, not to this table. None of
 * them appears here.
 *
 * `published()` publishes the *post*, not a destination. A delivered
 * destination is `PostTarget::factory()->published()`, exactly as `PostFactory`
 * leaves it, because a target claiming to be published with no remote id is a
 * row no code in this project can produce and a test built on one proves
 * nothing.
 *
 * `post_id` defaults to a fresh `Post::factory()`, so `Article::factory()->create()`
 * is one whole article with nothing else to arrange. The column is unique and
 * cascades, so two articles on one post is a constraint violation rather than a
 * second article — use `forPost()` when the post already exists.
 *
 * 🔴 **The `newFactory()` override on the model is what makes this file
 * reachable.** `Factory::resolveFactoryName()` looks for
 * `Database\Factories\Modules\Blog\Models\ArticleFactory`, which is not where a
 * module keeps anything, so without that override `Article::factory()` dies with
 * a class-not-found for a class nobody wrote. Every other module here does it
 * the same way; the failure is loud, but only if something actually calls the
 * factory, which is why `BlogModuleTest` now does.
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        [$title, $excerpt] = $this->faker->randomElement($this->articles());

        return [
            'post_id' => Post::factory(),
            'title' => $title,
            // `Str::slug()` is safe on these because they are English. It
            // transliterates rather than strips, so a Persian title would come
            // out as consonant soup — never derive a slug from copy this factory
            // did not write. `withoutSlug()` is the honest state for that case
            // and is what most articles actually carry.
            'slug' => Str::slug($title),
            'excerpt' => $excerpt,
            'canonical_url' => null,
            // An id from Data's attachments and deliberately not a foreign key —
            // see the migration. Null is a missing cover, which every page here
            // renders around.
            'featured_attachment_id' => null,
        ];
    }

    /** Written, aimed at destinations, and not going anywhere until somebody says so. */
    public function draft(): static
    {
        // `PostFactory`'s default is already `Post::DRAFT`; stated rather than
        // assumed, so this state keeps meaning what its name says if that
        // default ever moves.
        return $this->state(fn (): array => ['post_id' => Post::factory()->state(['status' => Post::DRAFT])]);
    }

    /** Waiting for a time that has not arrived, which `social:publish-due` ignores. */
    public function scheduled(?string $when = null): static
    {
        return $this->state(fn (): array => ['post_id' => Post::factory()->scheduled($when)]);
    }

    /**
     * The post went out.
     *
     * This says nothing about any one destination. Add
     * `PostTarget::factory()->published()` for a delivery that really happened,
     * with the remote id that makes it one.
     */
    public function published(): static
    {
        return $this->state(fn (): array => ['post_id' => Post::factory()->published()]);
    }

    /** Attach to a post that already exists rather than making a second one. */
    public function forPost(Post|int $post): static
    {
        return $this->state(fn (): array => ['post_id' => $post instanceof Post ? $post->id : $post]);
    }

    /**
     * No slug, which is the ordinary case and not an omission.
     *
     * Null means "let the site make one from the title" — which WordPress does
     * well, and which DEV.to and Hashnode do whatever anybody says.
     */
    public function withoutSlug(): static
    {
        return $this->state(fn (): array => ['slug' => null]);
    }

    /**
     * Titles with the excerpt that belongs to each.
     *
     * Paired rather than drawn independently, because an article whose summary
     * describes a different article is exactly the kind of fixture that makes a
     * seeded page look broken to whoever reads it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function articles(): array
    {
        return [
            [
                'Four board views and what each one cost',
                'Table, calendar, dashboard and activity — and the one that was not worth building.',
            ],
            [
                'Invoice PDFs that render right to left without collapsing',
                'Two evenings, one very stubborn font, and the layout rule that fixed both.',
            ],
            [
                'Publishing to seventeen places from one composer',
                'One post, one fan-out table, and a cron that claims each destination exactly once.',
            ],
            [
                'A freelance ledger in SQLite, and no regrets',
                'What a single-file database gets right about a one-person business, and where it stops.',
            ],
        ];
    }
}
