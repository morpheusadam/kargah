<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Blog\Models\Article;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;

/**
 * Everything written here, and where each copy of it ended up.
 *
 * The list is articles, not posts: a build-log line fired at Mastodon from the
 * social composer has no `blog_articles` row and does not belong on a page about
 * writing. The two share a table underneath and that is deliberate — see
 * `Modules\Social\Support\Networks::WORDPRESS` — but they are different jobs and
 * they get different pages.
 *
 * **The status shown per destination is the target's own**, not the post's. A
 * post's `status` column is a summary that `PostPublisher` recomputes, and an
 * article that reached the blog and was refused by LinkedIn is neither published
 * nor failed; the row of chips says which is which, which is the only version of
 * that answer somebody can act on. `Modules\Social\Models\PostTarget` is where
 * the design lives.
 *
 * Nothing on this page publishes or retries. The queue page in Social already
 * does both, against the same rows, and a second button that claims targets
 * would be a second place that has to be right about claiming.
 */
new
#[Title('Articles — Kargah')]
class extends Component
{
    #[Url]
    public string $search = '';

    /** Per-request memo; see the note on social::posts about why this is private. */
    private ?Collection $resolvedArticles = null;

    /** @return Collection<int, Article> */
    private function articles(): Collection
    {
        return $this->resolvedArticles ??= Article::query()
            ->with(['post.targets.account'])
            ->orderByDesc('id')
            ->get();
    }

    public function with(): array
    {
        $term = trim(mb_strtolower($this->search));

        $rows = $this->articles()
            ->filter(fn (Article $article): bool => $term === ''
                || str_contains(mb_strtolower($article->title), $term)
                || str_contains(mb_strtolower((string) $article->post?->body), $term))
            ->values();

        return [
            'rows' => $rows,
            'total' => $this->articles()->count(),
            // Whole class strings in a map, never built by concatenation: the
            // Tailwind scanner reads source text. See docs/frontend-conventions.
            'states' => [
                PostTarget::PUBLISHED => ['label' => 'Delivered', 'badge' => 'kt-badge-success'],
                PostTarget::PENDING => ['label' => 'Queued', 'badge' => 'kt-badge-warning'],
                PostTarget::PUBLISHING => ['label' => 'Sending', 'badge' => 'kt-badge-info'],
                PostTarget::FAILED => ['label' => 'Failed', 'badge' => 'kt-badge-destructive'],
                PostTarget::SKIPPED => ['label' => 'Skipped', 'badge' => 'kt-badge-outline'],
            ],
            'postStates' => [
                Post::DRAFT => ['label' => 'Draft', 'badge' => 'kt-badge-outline'],
                Post::SCHEDULED => ['label' => 'Scheduled', 'badge' => 'kt-badge-warning'],
                Post::PUBLISHING => ['label' => 'Publishing', 'badge' => 'kt-badge-info'],
                Post::PUBLISHED => ['label' => 'Published', 'badge' => 'kt-badge-success'],
                Post::PARTLY_FAILED => ['label' => 'Partly failed', 'badge' => 'kt-badge-destructive'],
                Post::FAILED => ['label' => 'Failed', 'badge' => 'kt-badge-destructive'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Articles</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                What you have written, and where each copy of it ended up.
            </p>
        </div>
        <a href="{{ route('blog.compose') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> New article
        </a>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <span class="text-sm text-secondary-foreground">
            {{ $total }} {{ $total === 1 ? 'article' : 'articles' }}
        </span>
        <div class="flex items-center gap-3">
            <span wire:loading wire:target="search" class="text-xs text-secondary-foreground">
                <i class="ki-filled ki-loading animate-spin"></i> Searching…
            </span>
            <div class="kt-input w-full sm:max-w-[260px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search articles…" aria-label="Search articles"
                       wire:model.live.debounce.300ms="search">
            </div>
        </div>
    </div>

    <div class="kt-card kt-card-table">
        <div class="kt-scrollable-x-auto">
            <table class="kt-table">
                <thead>
                    <tr>
                        <th class="min-w-[260px]">Article</th>
                        <th class="min-w-[220px]">Destinations</th>
                        <th class="min-w-[160px]">When</th>
                        <th class="w-[140px]">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $article)
                        @php $post = $article->post; @endphp
                        <tr wire:key="article-{{ $article->id }}">
                            <td>
                                <span class="block text-sm font-medium text-mono">{{ $article->title }}</span>
                                <span class="block text-xs text-muted-foreground mt-1">
                                    {{ $article->summary(120) ?: '—' }}
                                </span>
                            </td>
                            <td>
                                @if ($post === null || $post->targets->isEmpty())
                                    <span class="text-xs text-muted-foreground">—</span>
                                @else
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach ($post->targets as $target)
                                            <span class="kt-badge kt-badge-sm {{ $states[$target->status]['badge'] ?? 'kt-badge-outline' }} gap-1"
                                                  wire:key="target-{{ $target->id }}"
                                                  title="{{ $target->error ?: ($target->account?->label() ?? 'Unknown destination') }}">
                                                <i class="ki-filled {{ $target->account?->icon() ?? 'ki-abstract-26' }} text-xs"></i>
                                                {{ $states[$target->status]['label'] ?? $target->status }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if ($post?->published_at !== null)
                                    <span class="text-xs text-secondary-foreground">
                                        Published {{ $post->published_at->format('j M Y, H:i') }}
                                    </span>
                                @elseif ($post?->scheduled_for !== null)
                                    <span class="text-xs text-secondary-foreground">
                                        Scheduled {{ $post->scheduled_for->format('j M Y, H:i') }}
                                    </span>
                                @else
                                    <span class="text-xs text-muted-foreground">Not scheduled</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-between gap-2">
                                    <span class="kt-badge kt-badge-sm {{ $postStates[$post?->status]['badge'] ?? 'kt-badge-outline' }}">
                                        {{ $postStates[$post?->status]['label'] ?? '—' }}
                                    </span>
                                    <div class="flex items-center gap-1">
                                        {{-- Offered whatever the status. An article that has already gone out can
                                             still be corrected here — the edit page is the thing that explains what
                                             that does and does not reach, and hiding the link would leave somebody
                                             with no way to fix a typo at all. --}}
                                        <a href="{{ route('blog.article-edit', ['article' => $article->id]) }}" wire:navigate
                                           class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                           title="Edit this article"
                                           aria-label="Edit {{ $article->title }}">
                                            <i class="ki-filled ki-pencil"></i>
                                        </a>
                                        @if ($post !== null)
                                            <a href="{{ route('social.post-show', ['post' => $post->id]) }}" wire:navigate
                                               class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                               title="Open the delivery record"
                                               aria-label="Open the delivery record for {{ $article->title }}">
                                                <i class="ki-filled ki-arrow-up-right"></i>
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="flex flex-col items-center py-14 text-center">
                                    <i class="ki-filled ki-notepad text-3xl text-muted-foreground mb-3"></i>
                                    <p class="text-sm text-secondary-foreground">
                                        {{ trim($search) === ''
                                            ? 'Nothing written yet. An article goes to your blog and a teaser goes everywhere else, in one go.'
                                            : 'No article matches that.' }}
                                    </p>
                                    @if (trim($search) === '')
                                        <a href="{{ route('blog.compose') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3">
                                            Write the first one
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
