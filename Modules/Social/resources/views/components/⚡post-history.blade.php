<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Support\Networks;

/**
 * What went out, where, and what broke.
 *
 * `⚡posts.blade.php` is a queue: it answers "what needs my attention right
 * now" and is organised around tabs that mirror the post's own summary
 * status. This page answers a different question — "what happened, on this
 * network, in this window" — so the row here is a **target**, not a post. A
 * post that reached three of four networks appears once per network it was
 * aimed at, because "published" is not one fact about that post, it is four.
 *
 * Grouped back under the post it belongs to for display, because a person
 * reading a history screen still wants to see the thought that went out, not
 * just the delivery record — but every filter, and the CSV, work on the
 * target rows underneath, which is the granularity `post_targets` actually
 * has.
 *
 * **Retry never resends a delivered target.** The button is not rendered for
 * one — see the `@if ($target->isFailed())` guard below — and even a
 * fabricated `wire:click` naming a published target's account id would still
 * lose at `PostPublisher::claim()`, which only claims `pending`/`failed`/a
 * stale `publishing` row. Nothing about "the button is missing" is the actual
 * guarantee; the database condition is.
 */
new
#[Title('Post history — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $network = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $search = '';

    /** Per-request memo of the filtered (post, target) pairs; see ⚡boards for why these stay private. */
    private ?Collection $resolvedRows = null;

    /**
     * The label this page shows in the status filter and on the CSV.
     *
     * Spelled out here rather than reusing the `Sending`/`Delivered` wording
     * the queue page uses for the same enum, because the brief asks this
     * screen's filter for the words "pending" and "retrying" by name, and a
     * `publishing` row on a history screen — one that already has at least
     * one attempt recorded — reads truer as "retrying" than "sending".
     *
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            PostTarget::PUBLISHED => 'Published',
            PostTarget::FAILED => 'Failed',
            PostTarget::PENDING => 'Pending',
            PostTarget::PUBLISHING => 'Retrying',
            PostTarget::SKIPPED => 'Skipped',
        ];
    }

    /** The moment this page sorts and filters a post by: the first honest answer among what it has. */
    private function when(Post $post): ?Carbon
    {
        return $post->published_at ?? $post->scheduled_for ?? $post->created_at;
    }

    /**
     * Every (post, target) pair the current filters allow, newest first.
     *
     * Loaded whole and filtered in memory, same trade-off `⚡posts.blade.php`
     * makes and for the same reason: a freelance install has posts in the
     * hundreds, not the millions, and the alternative is a `whereHas` per
     * filter that would still need the parent post's other targets loaded
     * anyway to draw the group.
     *
     * @return Collection<int, array{post: Post, target: PostTarget, when: ?Carbon}>
     */
    private function rows(): Collection
    {
        if ($this->resolvedRows !== null) {
            return $this->resolvedRows;
        }

        $term = trim(mb_strtolower($this->search));
        $from = $this->from !== '' ? Carbon::parse($this->from)->startOfDay() : null;
        $to = $this->to !== '' ? Carbon::parse($this->to)->endOfDay() : null;

        $posts = Post::query()
            ->with(['targets' => fn ($query) => $query->with('account')->orderBy('id')])
            ->orderByDesc('created_at')
            ->get();

        $pairs = collect();

        foreach ($posts as $post) {
            if ($term !== '' && ! str_contains(mb_strtolower($post->body), $term)) {
                continue;
            }

            $when = $this->when($post);

            if ($from !== null && ($when === null || $when->lt($from))) {
                continue;
            }

            if ($to !== null && ($when === null || $when->gt($to))) {
                continue;
            }

            foreach ($post->targets as $target) {
                if ($this->network !== '' && $target->account?->network !== $this->network) {
                    continue;
                }

                if ($this->status !== '' && $target->status !== $this->status) {
                    continue;
                }

                $pairs->push(['post' => $post, 'target' => $target, 'when' => $when]);
            }
        }

        // A stable sort — PHP's has been since 8.0 — so a post's own targets,
        // pushed consecutively above, stay together once sorted by the shared
        // `when` they all carry. That is what lets `with()` group by post id
        // afterwards and still get every post's rows sitting next to each
        // other rather than scattered by whichever target loop reached them.
        return $this->resolvedRows = $pairs
            ->sortByDesc(fn (array $row): int => $row['when']?->timestamp ?? 0)
            ->values();
    }

    private function forget(): void
    {
        $this->resolvedRows = null;
    }

    public function with(): array
    {
        $rows = $this->rows();

        return [
            // Collection<int, Collection<int, array{post: Post, target: PostTarget, when: ?Carbon}>>
            'grouped' => $rows->groupBy(fn (array $row): int => $row['post']->id),
            'networks' => Networks::all(),
            'statusOptions' => $this->statusLabels(),
            'totalTargets' => $rows->count(),
            'totalPosts' => $rows->pluck('post.id')->unique()->count(),
        ];
    }

    /** Whether any filter is narrowing the result, for the empty-state copy. */
    public function isFiltered(): bool
    {
        return $this->network !== '' || $this->status !== '' || $this->from !== ''
            || $this->to !== '' || trim($this->search) !== '';
    }

    public function clearFilters(): void
    {
        $this->reset('network', 'status', 'from', 'to', 'search');
    }

    /**
     * Retry one target on one post, through the same publisher every other
     * retry button in this module calls — see the class docblock.
     */
    public function retry(int $postId, int $accountId): void
    {
        $post = Post::query()->with(['targets' => fn ($query) => $query->with('account')])->find($postId);

        if ($post === null) {
            $this->toastError('That post is no longer here', 'Reload the page and try again.');

            return;
        }

        $target = $post->targets->firstWhere('social_account_id', $accountId);

        // Belt and braces: the button that calls this is never rendered for a
        // published target, but a stale page or a replayed request could
        // still name one, and `claim()` would already refuse it — this just
        // answers honestly rather than spending a claim attempt on a target
        // that cannot need one.
        if ($target === null || $target->isPublished()) {
            $this->toastError('Nothing to retry', 'That delivery already went out and cannot be resent.');

            return;
        }

        $report = app(PostPublisher::class)->publishPost($post, $accountId);

        $this->forget();

        if (! $report->didAnything()) {
            $this->toastSuccess('Nothing needed sending', $report->summary());

            return;
        }

        $report->failed === 0
            ? $this->toastSuccess('Retried', $report->summary())
            : $this->toastError('It failed again', $report->firstError());
    }

    /**
     * The same rows the table is showing, as a CSV.
     *
     * Livewire ships a downloaded file straight from a component action when
     * the action returns a `Response` — no route, no controller, nothing
     * this page does not already own. Streamed rather than built as a
     * string, because a freelance install's whole history is small but there
     * is no reason to hold the formatted text twice.
     */
    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = $this->rows();
        $labels = $this->statusLabels();

        return response()->streamDownload(function () use ($rows, $labels): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Post ID', 'Post', 'Network', 'Account', 'Status', 'When', 'Remote URL', 'Error']);

            foreach ($rows as $row) {
                /** @var Post $post */
                $post = $row['post'];
                /** @var PostTarget $target */
                $target = $row['target'];

                fputcsv($out, [
                    $post->id,
                    $post->excerpt(120),
                    $target->account?->label() ?? 'Unknown',
                    $target->account?->handle ?? '',
                    $labels[$target->status] ?? $target->status,
                    $target->isPublished()
                        ? $target->published_at?->format('Y-m-d H:i')
                        : $row['when']?->format('Y-m-d H:i'),
                    $target->remote_url ?? '',
                    $target->error ?? '',
                ]);
            }

            fclose($out);
        }, 'post-history-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Post history</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Every delivery attempt, per network, with the reason behind anything that did not go out.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-time"></i> Queue
            </a>
            <button wire:click="exportCsv" wire:loading.attr="disabled" class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-exit-down"></i> Export CSV
            </button>
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-content p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div>
                    <label class="kt-form-label" for="history-network">Destination</label>
                    <select id="history-network" class="kt-select" wire:model.live="network">
                        <option value="">All destinations</option>
                        @foreach ($networks as $key => $meta)
                            <option value="{{ $key }}">{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="kt-form-label" for="history-status">Status</label>
                    <select id="history-status" class="kt-select" wire:model.live="status">
                        <option value="">Any status</option>
                        @foreach ($statusOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="kt-form-label" for="history-from">From</label>
                    <input id="history-from" type="date" class="kt-input" wire:model.live="from">
                </div>
                <div>
                    <label class="kt-form-label" for="history-to">To</label>
                    <input id="history-to" type="date" class="kt-input" wire:model.live="to">
                </div>
                <div>
                    <label class="kt-form-label" for="history-search">Post text</label>
                    <div class="kt-input">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input id="history-search" type="text" placeholder="Search the post body…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
            </div>
            @if ($this->isFiltered())
                <div class="flex items-center justify-between mt-3 pt-3 border-t border-border">
                    <span class="text-xs text-muted-foreground">
                        {{ $totalTargets }} {{ $totalTargets === 1 ? 'delivery' : 'deliveries' }}
                        across {{ $totalPosts }} {{ $totalPosts === 1 ? 'post' : 'posts' }}.
                    </span>
                    <button wire:click="clearFilters" class="kt-btn kt-btn-sm kt-btn-ghost">Clear filters</button>
                </div>
            @endif
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-content p-0">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table kt-card-table align-middle">
                    <thead>
                        <tr>
                            <th class="min-w-[280px]">Post</th>
                            <th class="w-[170px]">Destination</th>
                            <th class="w-[140px]">Status</th>
                            <th class="w-[170px]">When</th>
                            <th class="min-w-[220px]">Remote link / reason</th>
                            <th class="w-[100px] text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grouped as $postId => $targetRows)
                            @php $post = $targetRows->first()['post']; @endphp
                            @foreach ($targetRows as $index => $row)
                                @php $target = $row['target']; @endphp
                                <tr wire:key="history-{{ $target->id }}"
                                    class="{{ $index === 0 ? '' : 'border-t-0' }}">
                                    <td>
                                        @if ($index === 0)
                                            <a href="{{ route('social.post-show', $post->id) }}" wire:navigate
                                               class="text-sm text-mono hover:text-primary line-clamp-2 max-w-[420px]">
                                                {{ $post->excerpt() }}
                                            </a>
                                        @else
                                            <span class="text-xs text-muted-foreground">↳ same post</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-1.5">
                                            <i class="ki-filled {{ $target->account?->icon() ?? 'ki-abstract-26' }} text-sm text-secondary-foreground"></i>
                                            <span class="text-sm text-mono">{{ $target->account?->label() ?? 'Unknown network' }}</span>
                                        </div>
                                        <div class="text-xs text-muted-foreground truncate max-w-[160px]">
                                            {{ $target->account?->handle ?? 'account deleted' }}
                                        </div>
                                    </td>
                                    <td>
                                        <span class="kt-badge kt-badge-sm
                                            {{ match ($target->status) {
                                                \Modules\Social\Models\PostTarget::PUBLISHED => 'kt-badge-success',
                                                \Modules\Social\Models\PostTarget::FAILED => 'kt-badge-destructive',
                                                \Modules\Social\Models\PostTarget::PUBLISHING => 'kt-badge-info',
                                                \Modules\Social\Models\PostTarget::PENDING => 'kt-badge-warning',
                                                default => 'kt-badge-outline',
                                            } }}">
                                            {{ $statusOptions[$target->status] ?? $target->status }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-sm text-mono">
                                            {{ ($target->isPublished() ? $target->published_at : $row['when'])?->format('j M, H:i') ?? '—' }}
                                        </div>
                                        @if ($target->attempts > 0)
                                            <div class="text-xs text-muted-foreground">
                                                {{ $target->attempts }} {{ $target->attempts === 1 ? 'attempt' : 'attempts' }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($target->isPublished() && $target->remote_url)
                                            <a href="{{ $target->remote_url }}" target="_blank" rel="noopener"
                                               class="text-xs text-primary inline-flex items-center gap-1">
                                                <i class="ki-filled ki-arrow-up-right text-xs"></i> Open live post
                                            </a>
                                        @elseif ($target->isPublished())
                                            <span class="text-xs text-muted-foreground">No public link — the chat is private</span>
                                        @elseif ($target->error)
                                            <span class="text-xs text-secondary-foreground line-clamp-2 max-w-[320px] block" title="{{ $target->error }}">
                                                {{ $target->error }}
                                            </span>
                                        @else
                                            <span class="text-xs text-muted-foreground">{{ $statusOptions[$target->status] ?? $target->status }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($target->isFailed())
                                            <button wire:click="retry({{ $post->id }}, {{ $target->social_account_id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="retry({{ $post->id }}, {{ $target->social_account_id }})"
                                                    class="kt-btn kt-btn-sm kt-btn-outline gap-1.5">
                                                <span wire:loading.remove wire:target="retry({{ $post->id }}, {{ $target->social_account_id }})">Retry</span>
                                                <span wire:loading wire:target="retry({{ $post->id }}, {{ $target->social_account_id }})" class="inline-flex items-center gap-1.5">
                                                    <i class="ki-filled ki-loading animate-spin"></i>
                                                </span>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="flex flex-col items-center py-14 text-center">
                                        <i class="ki-filled ki-document text-4xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">
                                            {{ $this->isFiltered()
                                                ? 'Nothing in this history matches those filters.'
                                                : 'Nothing has been sent yet.' }}
                                        </p>
                                        @if ($this->isFiltered())
                                            <button wire:click="clearFilters" class="kt-btn kt-btn-sm kt-btn-outline mt-3">
                                                Clear filters
                                            </button>
                                        @else
                                            <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3 gap-2">
                                                <i class="ki-filled ki-plus"></i> New post
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
</div>
