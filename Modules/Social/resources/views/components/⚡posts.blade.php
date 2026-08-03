<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Services\PostPublisher;

/**
 * Queue and history.
 *
 * One post can land on four networks and fail on one of them, so every column
 * that says anything about delivery reads `post_targets` rather than the post.
 * The post's own status is only used to decide which tab it belongs in.
 *
 * **Retry claims only what is outstanding.** It hands the post to the same
 * `PostPublisher` the cron job uses, which cannot claim a target that is
 * already `published` — so pressing retry on a post that reached two networks
 * out of three sends exactly one thing. The toast then says how many, because
 * 'retried' on its own would leave the reader wondering what it retried.
 */
new
#[Title('Posts — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $tab = 'queued';

    #[Url]
    public string $search = '';

    /** The post whose error detail is open, if any. */
    public ?int $expanded = null;

    /** Per-request memo; see the note on ⚡boards about why these are private. */
    private ?Collection $resolvedPosts = null;

    /**
     * The post statuses each tab collects.
     *
     * A map rather than a computed name, because the tab keys are what live in
     * the address bar and the statuses are what live in the database, and
     * letting one become the other would tie a URL to a column value.
     *
     * @return array<string, array{label: string, icon: string, statuses: list<string>}>
     */
    private function tabs(): array
    {
        return [
            'queued' => [
                'label' => 'Queued',
                'icon' => 'ki-time',
                'statuses' => [Post::SCHEDULED, Post::PUBLISHING],
            ],
            'published' => [
                'label' => 'Published',
                'icon' => 'ki-check-circle',
                'statuses' => [Post::PUBLISHED],
            ],
            'failed' => [
                'label' => 'Failed',
                'icon' => 'ki-cross-circle',
                'statuses' => [Post::FAILED, Post::PARTLY_FAILED],
            ],
            'drafts' => [
                'label' => 'Drafts',
                'icon' => 'ki-notepad-edit',
                'statuses' => [Post::DRAFT],
            ],
        ];
    }

    private function activeTab(): string
    {
        return array_key_exists($this->tab, $this->tabs()) ? $this->tab : 'queued';
    }

    /**
     * Every post, with its targets and their accounts.
     *
     * Loaded once and filtered in memory rather than queried per tab: the tab
     * counts need all of them anyway, and a freelance install has posts in the
     * hundreds rather than the millions.
     *
     * @return Collection<int, Post>
     */
    private function posts(): Collection
    {
        return $this->resolvedPosts ??= Post::query()
            ->with(['targets' => fn ($query) => $query->with('account')->orderBy('id')])
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->get();
    }

    private function forget(): void
    {
        $this->resolvedPosts = null;
    }

    public function with(): array
    {
        $tabs = $this->tabs();
        $active = $this->activeTab();
        $term = trim(mb_strtolower($this->search));

        $counts = [];

        foreach ($tabs as $key => $tab) {
            $counts[$key] = $this->posts()->whereIn('status', $tab['statuses'])->count();
        }

        $rows = $this->posts()
            ->whereIn('status', $tabs[$active]['statuses'])
            ->filter(fn (Post $post): bool => $term === '' || str_contains(mb_strtolower($post->body), $term))
            ->values();

        return [
            'tabs' => $tabs,
            'active' => $active,
            'counts' => $counts,
            'rows' => $rows,
            'states' => [
                PostTarget::PUBLISHED => ['label' => 'Delivered', 'badge' => 'kt-badge-success'],
                PostTarget::PENDING => ['label' => 'Queued', 'badge' => 'kt-badge-warning'],
                PostTarget::PUBLISHING => ['label' => 'Sending', 'badge' => 'kt-badge-info'],
                PostTarget::FAILED => ['label' => 'Failed', 'badge' => 'kt-badge-destructive'],
                PostTarget::SKIPPED => ['label' => 'Skipped', 'badge' => 'kt-badge-outline'],
            ],
        ];
    }

    private function post(int $id): ?Post
    {
        return $this->posts()->firstWhere('id', $id);
    }

    public function toggleError(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    /**
     * Send whatever is still outstanding on a post.
     *
     * `$accountId` narrows it to one network; without it every target that is
     * not already published is attempted. Either way a published target is left
     * alone, because the claim is what decides and it is a database condition
     * rather than a decision made here.
     */
    public function retry(int $id, ?int $accountId = null): void
    {
        $post = $this->post($id);

        if ($post === null) {
            $this->toastError('That post is no longer here', 'Reload the page and try again.');

            return;
        }

        $report = app(PostPublisher::class)->publishPost($post, $accountId);

        $this->forget();
        $this->expanded = null;

        if (! $report->didAnything()) {
            $this->toastSuccess('Nothing needed sending', $report->summary());

            return;
        }

        if ($report->failed === 0) {
            $this->toastSuccess('Retried', $report->summary());

            return;
        }

        $report->published > 0
            ? $this->toastWarning('Some of it went out', $report->firstError())
            : $this->toastError('It failed again', $report->firstError());
    }

    /**
     * Take a scheduled post out of the queue.
     *
     * It becomes a draft rather than being deleted: the text is usually worth
     * keeping and the targets already record which networks it was meant for.
     * Anything already published to stays published — cancelling cannot unsend.
     */
    public function cancel(int $id): void
    {
        $post = $this->post($id);

        if ($post === null) {
            $this->toastError('That post is no longer here', 'Reload the page and try again.');

            return;
        }

        if (! in_array($post->status, [Post::SCHEDULED, Post::PUBLISHING], true)) {
            $this->toastError('That post is not queued', 'Only a scheduled post can be taken out of the queue.');

            return;
        }

        $alreadyOut = $post->targets->where('status', PostTarget::PUBLISHED)->count();

        $post->forceFill(['status' => Post::DRAFT, 'scheduled_for' => null])->save();

        $this->forget();

        $this->toastSuccess(
            'Taken out of the queue',
            $alreadyOut === 0
                ? 'It is a draft again and nothing was sent. Schedule it from the composer when you are ready.'
                : 'It is a draft again. The '.$alreadyOut.' '.($alreadyOut === 1 ? 'network' : 'networks')
                    .' it already reached keep the post.',
        );
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Posts</h1>
            <p class="text-sm text-secondary-foreground mt-1">Track what is waiting, what went out and what did not.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('social.calendar') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-calendar"></i> Calendar
            </a>
            <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New post
            </a>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($tabs as $key => $t)
                <button wire:click="$set('tab', '{{ $key }}')" wire:key="tab-{{ $key }}"
                        class="kt-btn kt-btn-sm gap-2 {{ $active === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                    <i class="ki-filled {{ $t['icon'] }} text-sm"></i>
                    {{ $t['label'] }}
                    <span class="kt-badge kt-badge-sm {{ $active === $key ? 'kt-badge-outline' : '' }}">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        <div class="kt-input w-full sm:max-w-[260px]">
            <i class="ki-filled ki-magnifier text-muted-foreground"></i>
            <input type="text" placeholder="Search posts…" aria-label="Search posts" wire:model.live.debounce.300ms="search">
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-content p-0">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table kt-card-table align-middle">
                    <thead>
                        <tr>
                            <th class="min-w-[280px]">Post</th>
                            <th class="w-[140px]">Networks</th>
                            <th class="w-[190px]">
                                {{ $active === 'published' ? 'Published' : ($active === 'drafts' ? 'Last edited' : 'Scheduled') }}
                            </th>
                            <th class="w-[280px]">Delivery</th>
                            <th class="w-[120px] text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $post)
                            @php
                                $when = match ($active) {
                                    'published' => $post->published_at,
                                    'drafts' => $post->updated_at,
                                    default => $post->scheduled_for,
                                };
                                $failedTargets = $post->targets->where('status', 'failed');
                            @endphp
                            <tr wire:key="post-{{ $post->id }}">
                                <td>
                                    <a href="{{ route('social.post-show', $post->id) }}" wire:navigate
                                       class="text-sm text-mono hover:text-primary line-clamp-2 max-w-[420px]">
                                        {{ $post->excerpt() }}
                                    </a>
                                    @if ($failedTargets->isNotEmpty())
                                        <button wire:click="toggleError({{ $post->id }})"
                                                class="text-xs text-destructive inline-flex items-center gap-1 mt-1">
                                            <i class="ki-filled ki-information-2 text-xs"></i>
                                            {{ $expanded === $post->id ? 'Hide error' : 'Show error' }}
                                        </button>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        @foreach ($post->targets as $target)
                                            <span class="inline-flex items-center justify-center size-7 rounded-md bg-muted"
                                                  title="{{ $target->account?->label() }} — {{ $states[$target->status]['label'] ?? $target->status }}">
                                                <i class="ki-filled {{ $target->account?->icon() ?? 'ki-abstract-26' }} text-sm text-secondary-foreground"></i>
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="text-sm text-mono">{{ $when?->format('D j M, H:i') ?? '—' }}</div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ $when?->diffForHumans(['short' => true]) ?? 'Not scheduled' }}
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-col gap-1">
                                        @foreach ($post->targets as $target)
                                            <div class="flex items-center gap-2">
                                                <span class="kt-badge kt-badge-sm {{ $states[$target->status]['badge'] ?? 'kt-badge-outline' }} shrink-0">
                                                    {{ $target->account?->label() ?? 'Unknown' }}
                                                </span>
                                                <span class="text-xs text-muted-foreground truncate max-w-[170px]"
                                                      title="{{ $target->error ?? $target->remote_url }}">
                                                    @if ($target->isPublished())
                                                        {{ $target->published_at?->format('j M, H:i') ?? 'Delivered' }}
                                                    @elseif ($target->error)
                                                        {{ $target->error }}
                                                    @else
                                                        {{ $states[$target->status]['label'] ?? $target->status }}
                                                    @endif
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($failedTargets->isNotEmpty())
                                            <button wire:click="retry({{ $post->id }})" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-outline gap-1.5">
                                                <span wire:loading.remove wire:target="retry({{ $post->id }})">Retry</span>
                                                <span wire:loading wire:target="retry({{ $post->id }})" class="inline-flex items-center gap-1.5">
                                                    <i class="ki-filled ki-loading animate-spin"></i> Retrying…
                                                </span>
                                            </button>
                                        @elseif ($active === 'queued')
                                            <button wire:click="cancel({{ $post->id }})"
                                                    class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost text-destructive"
                                                    title="Take this post out of the queue"
                                                    aria-label="Take this post out of the queue">
                                                <i class="ki-filled ki-cross-circle"></i>
                                            </button>
                                        @endif
                                        <a href="{{ route('social.post-show', $post->id) }}" wire:navigate
                                           class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                           title="Open post" aria-label="Open post">
                                            <i class="ki-filled ki-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            @if ($expanded === $post->id && $failedTargets->isNotEmpty())
                                <tr wire:key="error-{{ $post->id }}">
                                    <td colspan="5" class="bg-destructive/5">
                                        <div class="flex flex-col gap-2 py-1">
                                            @foreach ($failedTargets as $target)
                                                <div class="flex flex-wrap items-start gap-3">
                                                    <i class="ki-filled ki-information-2 text-destructive text-base mt-0.5 shrink-0"></i>
                                                    <div class="min-w-0 grow">
                                                        <p class="text-sm text-mono">{{ $target->error }}</p>
                                                        <p class="text-xs text-muted-foreground mt-1">
                                                            {{ $target->attempts }} {{ $target->attempts === 1 ? 'attempt' : 'attempts' }} ·
                                                            last tried {{ $target->last_attempt_at?->diffForHumans() ?? 'never' }}
                                                        </p>
                                                    </div>
                                                    <div class="flex items-center gap-2 shrink-0">
                                                        @unless ($target->account?->isConnected())
                                                            <a href="{{ route('social.account-connect') }}?network={{ $target->account?->network }}"
                                                               wire:navigate class="kt-btn kt-btn-sm kt-btn-outline">Connect</a>
                                                        @endunless
                                                        <button wire:click="retry({{ $post->id }}, {{ $target->social_account_id }})"
                                                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5">
                                                            <i class="ki-filled {{ $target->account?->icon() ?? 'ki-abstract-26' }} text-sm"></i>
                                                            Retry {{ $target->account?->label() }}
                                                        </button>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="flex flex-col items-center py-14 text-center">
                                        <i class="ki-filled {{ $tabs[$active]['icon'] }} text-4xl text-muted-foreground mb-3"></i>
                                        <p class="text-sm text-secondary-foreground">
                                            {{ trim($search) !== ''
                                                ? 'No posts match that search.'
                                                : 'Nothing in ' . strtolower($tabs[$active]['label']) . '.' }}
                                        </p>
                                        <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3 gap-2">
                                            <i class="ki-filled ki-plus"></i> New post
                                        </a>
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
