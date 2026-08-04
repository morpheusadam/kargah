<?php

namespace Modules\Blog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Blog\Database\Factories\ArticleFactory;
use Modules\Social\Models\Post;

/**
 * The article half of a post.
 *
 * A post carries the copy, the schedule and the destinations; this carries the
 * things only an article has. The two are one row each and joined one-to-one,
 * rather than a fistful of nullable columns on `posts`, because five of the six
 * networks Kargah publishes to have no idea what a slug is and a table that
 * describes both would be mostly empty.
 *
 * **There is no status here.** The article's status is the post's, which is
 * itself a summary of the targets' — see `Modules\Social\Models\Post`. A third
 * opinion about whether something is published is a third thing that can be
 * wrong.
 *
 * The relation points at Social and nothing points back. `Modules\Social` does
 * not import this class and must not; see
 * `Modules\Blog\Providers\BlogServiceProvider`.
 */
class Article extends Model
{
    use HasFactory;

    protected $table = 'blog_articles';

    protected $fillable = [
        'post_id',
        'title',
        'slug',
        'excerpt',
        'canonical_url',
        'featured_attachment_id',
    ];

    protected function casts(): array
    {
        return [
            'featured_attachment_id' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * The first line or so of the article, for a list with no room for prose.
     *
     * Prefers what the person wrote as the excerpt, because that is what an
     * excerpt is for, and falls back to the post's own opening rather than to
     * nothing.
     */
    public function summary(int $characters = 160): string
    {
        $excerpt = trim((string) preg_replace('/\s+/u', ' ', (string) $this->excerpt));

        if ($excerpt !== '') {
            return mb_strlen($excerpt) > $characters
                ? mb_substr($excerpt, 0, $characters - 1).'…'
                : $excerpt;
        }

        return $this->post?->excerpt($characters) ?? '';
    }

    /**
     * 🔴 Without this, `Article::factory()` does not exist.
     *
     * `Factory::resolveFactoryName()` asks for
     * `Database\Factories\Modules\Blog\Models\ArticleFactory`, which is Laravel's
     * app-layout guess and is not where `nwidart/laravel-modules` keeps a module's
     * factories. Every model in Kargah that has a factory overrides this for the
     * same reason — see `Modules\Social\Models\Post` — and the symptom of leaving
     * it out is a class-not-found naming a file nobody ever wrote.
     */
    protected static function newFactory(): ArticleFactory
    {
        return ArticleFactory::new();
    }
}
