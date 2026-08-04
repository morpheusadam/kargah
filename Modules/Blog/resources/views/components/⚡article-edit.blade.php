<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Blog\Models\Article;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Services\PostMedia;
use Modules\Social\Support\Networks;

/**
 * Change an article that has already been written.
 *
 * ## Why this is a separate component and not a mode of the composer
 *
 * The obvious alternative — one optional route parameter on `blog::compose`, so
 * the page becomes "compose or edit" — is a much smaller diff and was rejected.
 * `⚡compose.blade.php` is a thousand lines whose one job is to *create*: it
 * writes a `posts` row, a `blog_articles` row and one `post_targets` row per
 * ticked destination, attaches temporary uploads to a post that did not exist a
 * moment ago, and then hands the whole thing to `PostPublisher`. Editing is a
 * different verb at every one of those steps — update rather than insert, keep
 * the destinations somebody already chose rather than tick the connected ones,
 * and above all **do not publish**. Teaching it both would have put an
 * `if ($this->articleId)` through `submit()`, `mount()`, `attachTo()` and the
 * schedule block, in the only file in Kargah through which anything reaches a
 * network. A mistake there does not show up as a broken edit page; it shows up
 * as an article that publishes twice, or not at all.
 *
 * **What that costs, said plainly.** The two files now describe the same four
 * fields, and a fifth field added to `blog_articles` has to be added twice or it
 * will be editable in one place and not the other. That is a real maintenance
 * tax and it is the price of the composer being untouched by this work.
 *
 * ## The one thing this page must never do
 *
 * **It never publishes, and it must never come to.** Nothing here constructs or
 * calls `PostPublisher`, and there is no `Http` anywhere in the file. That is
 * not caution, it is the only correct behaviour available: **none of the
 * seventeen drivers can update a post it has already made.** They have
 * `publish()`, `publishWithOptions()` and `verify()`, and no fourth method —
 * because most of these networks either do not offer an edit endpoint or offer
 * one that needs a scope Kargah never asked for. A "save and republish" button
 * would therefore not correct the published article; it would post a second copy
 * of it, and `post_targets.status` being forward-only means the retry design
 * would then have two remote ids for one row and keep only one.
 *
 * So an already-delivered destination is frozen, and **the page says so on
 * screen** rather than in a toast somebody may have missed. That sentence — that
 * saving here changes Kargah's copy and not the one people can read — is the
 * whole reason this page can be allowed to exist at all.
 *
 * ## What a save actually writes
 *
 * - `blog_articles`: the title, slug, excerpt and canonical link.
 * - `posts.body`: the article itself.
 * - `post_targets.options`, **only on destinations that have not gone out.**
 *
 * That last one is the non-obvious half. An article destination carries a copy
 * of the title, slug, excerpt and canonical link in its own `options` bag — the
 * composer writes it there because a status or a category is per-destination —
 * so correcting the title here and leaving the bag alone would mean a queued
 * WordPress target publishing the *old* title next Tuesday, with the corrected
 * one sitting in `blog_articles` looking right. That is the silent divergence
 * this method exists to prevent.
 *
 * A **published** target's bag is left exactly as it was, and that is equally
 * deliberate: it is the record of what was actually sent, and rewriting it would
 * turn the only evidence of what the site received into a guess. `status`,
 * `categories`, `tags`, `create_missing_terms` and `featured_attachment_id` are
 * never touched by this page either, published or not — they are per-destination
 * decisions this form does not ask about, and quietly resetting somebody's
 * "leave it as a draft on the client's site" would be worse than not offering it.
 */
new
#[Title('Edit article — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public ?int $articleId = null;

    /** The article was deleted, here or in another tab. The page says so rather than 404ing. */
    public bool $missing = false;

    public string $title = '';

    public string $slug = '';

    public string $excerpt = '';

    public string $canonicalUrl = '';

    /** `posts.body` — the article itself. It lives on the post, not on `blog_articles`. */
    public string $body = '';

    /** Per-request memo; see the note on social::posts about why this is private. */
    private ?Article $resolved = null;

    public function mount(string $article): void
    {
        $found = Article::query()->with(['post.targets.account'])->find($article);

        if ($found === null) {
            $this->missing = true;

            return;
        }

        $this->articleId = (int) $found->getKey();
        $this->resolved = $found;

        $this->title = $found->title;
        $this->slug = (string) $found->slug;
        $this->excerpt = (string) $found->excerpt;
        $this->canonicalUrl = (string) $found->canonical_url;
        $this->body = (string) $found->post?->body;
    }

    /* Reading ---------------------------------------------------------------- */

    private function article(): ?Article
    {
        if ($this->articleId === null) {
            return null;
        }

        return $this->resolved ??= Article::query()
            ->with(['post.targets.account'])
            ->find($this->articleId);
    }

    /**
     * The destinations that receive the article rather than a teaser.
     *
     * The same three `blog::compose` names, and for the same reason its docblock
     * gives: it is this module's question — "does this destination get the
     * article" — and `Networks` has no opinion about it. Duplicated rather than
     * shared because the composer keeps it private and prising it out would mean
     * editing the composer, which this component exists not to do. When a fourth
     * article destination arrives, both lists change.
     *
     * @return list<string>
     */
    private function articleNetworks(): array
    {
        return [Networks::WORDPRESS, Networks::DEVTO, Networks::HASHNODE];
    }

    /**
     * Every destination, split by whether it has already gone out.
     *
     * The split is the page. A delivered destination is a thing this form cannot
     * change; an outstanding one is a thing it can. Showing them in one list
     * would leave the reader to work out which of the two sentences applies to
     * which row, which is exactly the confusion that lets somebody believe a
     * correction went out.
     *
     * `length` and `limit` are here because editing the body can push a queued
     * teaser over a network's allowance — a target with no `body_override`
     * inherits `posts.body` — and the person deserves to see that while they are
     * typing rather than an hour later from a red row on the queue page.
     *
     * @return array{delivered: list<array<string, mixed>>, outstanding: list<array<string, mixed>>}
     */
    private function destinations(): array
    {
        $post = $this->article()?->post;

        if ($post === null) {
            return ['delivered' => [], 'outstanding' => []];
        }

        // Whether a picture is attached changes Telegram's allowance from 4,096
        // to a caption's 1,024. `PostMedia` is the one place that answers what a
        // post actually carries — see its docblock on why there is no second copy.
        $hasMedia = app(PostMedia::class)->forPost($post) !== [];

        $delivered = [];
        $outstanding = [];

        foreach ($post->targets as $target) {
            $account = $target->account;
            $isArticle = $account !== null && in_array($account->network, $this->articleNetworks(), true);

            // What this destination would send if it went out now: its own
            // override, or the body being edited above.
            $copy = $isArticle
                ? trim($this->body)
                : ($target->body_override ?? trim($this->body));

            $limit = $account?->characterLimitWith($hasMedia) ?? 0;

            $row = [
                'target' => $target,
                'account' => $account,
                'label' => $account?->label() ?? 'A destination that is no longer here',
                'icon' => $account?->icon() ?? 'ki-abstract-26',
                'is_article' => $isArticle,
                'length' => mb_strlen($copy),
                'limit' => $limit,
                'over' => $limit > 0 && mb_strlen($copy) > $limit,
            ];

            if ($target->isPublished()) {
                $delivered[] = $row;
            } else {
                $outstanding[] = $row;
            }
        }

        return ['delivered' => $delivered, 'outstanding' => $outstanding];
    }

    public function with(): array
    {
        $article = $this->article();
        $split = $this->destinations();

        return [
            'article' => $article,
            'post' => $article?->post,
            'delivered' => $split['delivered'],
            'outstanding' => $split['outstanding'],
            // Whole class strings in a map, never built by concatenation: the
            // Tailwind scanner reads source text. See docs/frontend-conventions.
            'postStates' => [
                Post::DRAFT => ['label' => 'Draft', 'badge' => 'kt-badge-outline'],
                Post::SCHEDULED => ['label' => 'Scheduled', 'badge' => 'kt-badge-warning'],
                Post::PUBLISHING => ['label' => 'Publishing', 'badge' => 'kt-badge-info'],
                Post::PUBLISHED => ['label' => 'Published', 'badge' => 'kt-badge-success'],
                Post::PARTLY_FAILED => ['label' => 'Partly failed', 'badge' => 'kt-badge-destructive'],
                Post::FAILED => ['label' => 'Failed', 'badge' => 'kt-badge-destructive'],
            ],
            'states' => [
                PostTarget::PENDING => ['label' => 'Queued', 'badge' => 'kt-badge-warning'],
                PostTarget::PUBLISHING => ['label' => 'Sending', 'badge' => 'kt-badge-info'],
                PostTarget::FAILED => ['label' => 'Failed', 'badge' => 'kt-badge-destructive'],
                PostTarget::SKIPPED => ['label' => 'Skipped', 'badge' => 'kt-badge-outline'],
            ],
        ];
    }

    /* Writing ---------------------------------------------------------------- */

    /**
     * The rules, built rather than declared as attributes, for one field.
     *
     * `canonicalUrl` is a text input, so an empty one arrives as `''` and not as
     * null — and `nullable` only skips the remaining rules for a genuine null.
     * `nullable|starts_with:http://,https://` would therefore refuse a blank
     * field, which is the ordinary case. Asking for the rule only when there is
     * something to check is the version that behaves.
     *
     * The 200 and 500 are the columns' own; see the migration, which says the
     * limit is Kargah's rather than any network's.
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'slug' => 'nullable|string|max:200',
            'excerpt' => 'nullable|string|max:2000',
            'canonicalUrl' => trim($this->canonicalUrl) === ''
                ? 'string|max:500'
                : 'string|max:500|starts_with:http://,https://',
            'body' => 'required|string',
        ];
    }

    /**
     * Write the article, the body, and the bags of the destinations still waiting.
     *
     * Nothing here publishes. See the class docblock for why that is the only
     * available behaviour rather than a choice.
     */
    public function save(): void
    {
        $article = $this->article();

        if ($article === null) {
            $this->missing = true;

            $this->toastError(
                'That article is gone',
                'It was deleted while this page was open, so nothing was written.',
            );

            return;
        }

        $this->validate($this->rules(), [
            'title.required' => 'An article needs a title — WordPress will not take a post without one.',
            'canonicalUrl.starts_with' => 'A canonical link has to start with http:// or https://, or the destinations will not credit it.',
            'body.required' => 'An article with nothing in it is not something Kargah can publish.',
        ]);

        $article->update([
            'title' => trim($this->title),
            'slug' => $this->orNull($this->slug),
            'excerpt' => $this->orNull($this->excerpt),
            'canonical_url' => $this->orNull($this->canonicalUrl),
        ]);

        $post = $article->post;

        $post?->update(['body' => trim($this->body)]);

        $carried = $post === null ? 0 : $this->carryToOutstanding($post);

        // Re-read, so the destination lists below reflect what was just written
        // rather than the relation this request loaded before the update.
        $this->resolved = null;

        $delivered = count($this->destinations()['delivered']);

        if ($delivered === 0) {
            $this->toastSuccess(
                'Saved',
                $carried === 0
                    ? 'Nothing has gone out yet, so this is the version that will.'
                    : $carried.' '.($carried === 1 ? 'destination is' : 'destinations are')
                        .' still waiting, and each will send this version.',
            );

            return;
        }

        // A warning rather than a success, because the thing the person most
        // likely wanted — the published article to change — did not happen. The
        // panel on the page says the same thing at more length and does not
        // disappear after seven seconds.
        $this->toastWarning(
            'Saved in Kargah, and only in Kargah',
            $delivered.' '.($delivered === 1 ? 'destination has' : 'destinations have')
                .' already been sent the old version. Kargah cannot update a post it has already published, so those '
                .'copies stay as they are until you correct them on the site itself.',
        );
    }

    /**
     * Carry the four article-level fields onto the destinations still waiting.
     *
     * Only targets that already carry an `options` bag, and only ones that have
     * not published. A target with no bag is one the ordinary social composer
     * created; writing one now would change how it behaves — from deriving a
     * title out of the body's first line to being handed one — which is a
     * decision nobody made on this page.
     *
     * @return int how many bags were rewritten
     */
    private function carryToOutstanding(Post $post): int
    {
        $carried = 0;

        foreach ($post->targets as $target) {
            if ($target->isPublished() || ! is_array($target->options)) {
                continue;
            }

            $options = $target->options;

            $options['title'] = trim($this->title);

            foreach (['slug' => $this->slug, 'excerpt' => $this->excerpt, 'canonical_url' => $this->canonicalUrl] as $key => $value) {
                $value = trim($value);

                // A cleared field clears the key rather than sending an empty
                // string. `WordPressPublisher::stringOption()` treats `''` as
                // absent anyway, but `slug: ''` reaching a driver that did not
                // is an empty permalink, and the two should not disagree.
                if ($value === '') {
                    unset($options[$key]);

                    continue;
                }

                $options[$key] = $value;
            }

            $target->update(['options' => $options]);

            $carried++;
        }

        return $carried;
    }

    private function orNull(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Edit article</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Correct what Kargah holds, and see which destinations that reaches.
            </p>
        </div>
        <a href="{{ route('blog.articles') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-notepad"></i> Articles
        </a>
    </div>

    @if ($missing || $article === null)

        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="flex flex-col items-center py-14 text-center">
                    <i class="ki-filled ki-notepad text-3xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">
                        That article is not here. It was deleted, or the address was typed by hand.
                    </p>
                    <a href="{{ route('blog.articles') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3">
                        Back to the articles
                    </a>
                </div>
            </div>
        </div>

    @else

        {{--
            The sentence this page exists for.

            Said on the page rather than in a toast, because a toast is gone in
            seven seconds and this is the thing somebody has to know *before*
            they type a correction: none of Kargah's drivers can update a post
            it has already made, so a delivered destination is frozen. See the
            class docblock.
        --}}
        @if ($delivered !== [])
            <div class="flex flex-col gap-3 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3.5">
                <div class="flex items-start gap-2.5">
                    <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                    <div class="flex flex-col gap-1 min-w-0">
                        <span class="text-sm font-medium text-mono">
                            {{ count($delivered) === 1 ? 'One destination already has this article' : count($delivered) . ' destinations already have this article' }}
                        </span>
                        <span class="text-xs text-secondary-foreground">
                            Kargah can publish an article to a site and has no way to update one it has already
                            published — none of its drivers has an edit call, because most of these networks do not
                            offer one. Saving this page changes Kargah's own copy and nothing else. To correct what
                            people can read, open each destination below and change it there.
                        </span>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    @foreach ($delivered as $row)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-background px-3 py-2"
                             wire:key="delivered-{{ $row['target']->id }}">
                            <div class="flex items-center gap-2 min-w-0">
                                <i class="ki-filled {{ $row['icon'] }} text-base text-muted-foreground shrink-0"></i>
                                <span class="text-sm text-mono truncate">{{ $row['label'] }}</span>
                                <span class="kt-badge kt-badge-sm kt-badge-success shrink-0">Delivered</span>
                            </div>
                            @if ($row['target']->remote_url)
                                <a href="{{ $row['target']->remote_url }}" target="_blank" rel="noopener"
                                   class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                                    <i class="ki-filled ki-arrow-up-right"></i> Correct it there
                                </a>
                            @else
                                <span class="text-xs text-muted-foreground">
                                    No address came back, so Kargah cannot link to the published copy.
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-12 gap-5 items-start">

            {{-- The article --}}
            <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">

                <div class="kt-card">
                    <div class="kt-card-content p-5 flex flex-col gap-4">

                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label text-xs" for="edit-title">Title</label>
                            <input id="edit-title" type="text"
                                   class="kt-input @error('title') border-destructive @enderror"
                                   wire:model.live.debounce.300ms="title">
                            @error('title')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label text-xs" for="edit-body">Article</label>
                            <textarea id="edit-body"
                                      class="kt-textarea min-h-[280px] text-sm @error('body') border-destructive @enderror"
                                      wire:model.live.debounce.300ms="body"></textarea>
                            @error('body')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            <span class="text-xs text-muted-foreground">{{ number_format(mb_strlen($body)) }} characters</span>
                        </div>

                        <div class="border-t border-border pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label text-xs" for="edit-slug">Slug</label>
                                <input id="edit-slug" type="text"
                                       class="kt-input @error('slug') border-destructive @enderror"
                                       wire:model="slug">
                                @error('slug')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                                <span class="text-[11px] text-muted-foreground">
                                    WordPress only, and only for a destination that has not gone out. A published
                                    permalink is the site's and changing it here does not move it.
                                </span>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label text-xs" for="edit-canonical">Canonical link</label>
                                <input id="edit-canonical" type="url"
                                       class="kt-input @error('canonicalUrl') border-destructive @enderror"
                                       placeholder="https://kargah.dev/notes/board-views"
                                       wire:model="canonicalUrl">
                                @error('canonicalUrl')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                                <span class="text-[11px] text-muted-foreground">
                                    Where it was first published, if that is somewhere else.
                                </span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label text-xs" for="edit-excerpt">Excerpt</label>
                            <textarea id="edit-excerpt"
                                      class="kt-textarea min-h-[110px] text-sm @error('excerpt') border-destructive @enderror"
                                      wire:model.live.debounce.300ms="excerpt"></textarea>
                            @error('excerpt')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                    </div>
                </div>

            </div>

            {{-- Where it stands, and what a save reaches --}}
            <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">This article</h3>
                        <span class="kt-badge kt-badge-sm {{ $postStates[$post?->status]['badge'] ?? 'kt-badge-outline' }}">
                            {{ $postStates[$post?->status]['label'] ?? '—' }}
                        </span>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-2">
                        @if ($post?->published_at !== null)
                            <span class="text-xs text-secondary-foreground">
                                Published {{ $post->published_at->format('j M Y, H:i') }}
                            </span>
                        @elseif ($post?->scheduled_for !== null)
                            <span class="text-xs text-secondary-foreground">
                                Scheduled for {{ $post->scheduled_for->format('j M Y, H:i') }}
                            </span>
                        @else
                            <span class="text-xs text-muted-foreground">Not scheduled</span>
                        @endif

                        @if ($post !== null)
                            <a href="{{ route('social.post-show', ['post' => $post->id]) }}" wire:navigate
                               class="kt-btn kt-btn-sm kt-btn-outline gap-2 self-start mt-1">
                                <i class="ki-filled ki-arrow-up-right"></i> The delivery record
                            </a>
                            <span class="text-[11px] text-muted-foreground">
                                Scheduling, retrying and adding a destination all live there. This page changes what
                                is written, not where it goes.
                            </span>
                        @endif
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Still to go out</h3>
                        <span class="text-xs text-muted-foreground">These will send this version</span>
                    </div>
                    <div class="kt-card-content p-0 divide-y divide-border">
                        @forelse ($outstanding as $row)
                            <div class="px-4 py-3 flex items-center justify-between gap-3"
                                 wire:key="outstanding-{{ $row['target']->id }}">
                                <div class="flex items-center gap-2 min-w-0">
                                    <i class="ki-filled {{ $row['icon'] }} text-base text-muted-foreground shrink-0"></i>
                                    <span class="text-sm text-mono truncate">{{ $row['label'] }}</span>
                                    <span class="kt-badge kt-badge-sm {{ $states[$row['target']->status]['badge'] ?? 'kt-badge-outline' }} shrink-0">
                                        {{ $states[$row['target']->status]['label'] ?? $row['target']->status }}
                                    </span>
                                </div>
                                {{-- The limit is only zero when the account row has gone, in which case
                                     there is no allowance to count against and an em dash is the honest
                                     answer. Every network in the catalogue has one. --}}
                                <span class="text-xs shrink-0 {{ $row['over'] ? 'text-destructive' : 'text-muted-foreground' }}">
                                    @if ($row['limit'] > 0)
                                        {{ number_format($row['length']) }} / {{ number_format($row['limit']) }}
                                    @else
                                        &mdash;
                                    @endif
                                </span>
                            </div>
                        @empty
                            <div class="flex flex-col items-center py-10 text-center">
                                <i class="ki-filled ki-check-circle text-3xl text-muted-foreground mb-3"></i>
                                <p class="text-sm text-secondary-foreground">
                                    Every destination has already been sent this article.
                                </p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{--
                    Said only when it is true, and as a warning rather than a
                    block: refusing to save would stop somebody fixing a typo
                    because an unrelated queued destination is one character
                    over. `HttpPublisher::acceptableMedia()`'s sibling check
                    fails that target at send time either way; this is the same
                    news, early enough to act on.
                --}}
                @if (collect($outstanding)->contains(fn (array $row): bool => $row['over']))
                    <div class="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 px-3.5 py-3">
                        <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                        <span class="text-xs text-secondary-foreground">
                            One of the destinations above allows fewer characters than this article has, and it takes
                            the article body because nothing shorter was written for it. It will be recorded as a
                            failed destination when it goes out.
                        </span>
                    </div>
                @endif

                <div class="kt-card">
                    <div class="kt-card-content p-5 flex flex-wrap items-center justify-between gap-3">
                        <span class="text-xs text-muted-foreground">
                            Saving never publishes and never republishes.
                        </span>
                        <button wire:click="save" wire:loading.attr="disabled"
                                class="kt-btn kt-btn-primary gap-2"
                                @disabled(trim($title) === '' || trim($body) === '')>
                            <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-check"></i> Save changes
                            </span>
                            <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Saving…
                            </span>
                        </button>
                    </div>
                </div>

            </div>

        </div>

    @endif

</div>
