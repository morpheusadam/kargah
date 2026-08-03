<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Services\PostPublisher;

/**
 * One post, seen from every network it was aimed at.
 *
 * The copy shown per network is what that target actually holds — `body_override`
 * where one was set, the post's own text otherwise — rather than the composer
 * draft, because the same thought does not fit two networks the same way and
 * the whole point of the override is that they diverge.
 *
 * There are no engagement metrics here. Kargah does not collect them, and a
 * page that invented impression counts would be worse than one that does not
 * show any. What it does show is what happened to the delivery, which is the
 * thing the database actually knows.
 */
new
#[Title('Post — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public int $post = 0;

    private ?Post $resolved = null;

    /**
     * The id comes from the route and may name a post that was deleted, or
     * never existed. Resolved to null and handled by the template rather than
     * thrown, so a stale link from an old toast is an empty page rather than a
     * 404 in the middle of the application.
     */
    public function mount(int|string $post = 0): void
    {
        $this->post = (int) $post;
    }

    private function record(): ?Post
    {
        return $this->resolved ??= Post::query()
            ->with(['targets' => fn ($query) => $query->with('account')->orderBy('id')])
            ->find($this->post);
    }

    private function forget(): void
    {
        $this->resolved = null;
    }

    public function with(): array
    {
        $post = $this->record();

        return [
            'record' => $post,
            'targets' => $post?->targets ?? collect(),
            'states' => [
                PostTarget::PUBLISHED => ['label' => 'Delivered', 'badge' => 'kt-badge-success'],
                PostTarget::PENDING => ['label' => 'Queued', 'badge' => 'kt-badge-warning'],
                PostTarget::PUBLISHING => ['label' => 'Sending', 'badge' => 'kt-badge-info'],
                PostTarget::FAILED => ['label' => 'Failed', 'badge' => 'kt-badge-destructive'],
                PostTarget::SKIPPED => ['label' => 'Skipped', 'badge' => 'kt-badge-outline'],
            ],
            'statusLabels' => [
                Post::DRAFT => 'Draft',
                Post::SCHEDULED => 'Scheduled',
                Post::PUBLISHING => 'Sending',
                Post::PUBLISHED => 'Published',
                Post::PARTLY_FAILED => 'Published to some networks',
                Post::FAILED => 'Failed',
            ],
        ];
    }

    /** Send whatever is outstanding, optionally narrowed to one account. */
    public function retry(?int $accountId = null): void
    {
        $post = $this->record();

        if ($post === null) {
            $this->toastError('That post is no longer here', 'It may have been deleted from another tab.');

            return;
        }

        $report = app(PostPublisher::class)->publishPost($post, $accountId);

        $this->forget();

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
};

?>

<div class="flex flex-col gap-5">

    @if (! $record)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-document text-4xl text-muted-foreground mb-3"></i>
                <h1 class="text-lg font-semibold text-mono">That post is not here</h1>
                <p class="text-sm text-secondary-foreground mt-1">
                    It may have been deleted, or the link may be older than the database.
                </p>
                <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">All posts</a>
            </div>
        </div>

    @else

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                       title="Back to posts" aria-label="Back to posts">
                        <i class="ki-filled ki-arrow-left"></i>
                    </a>
                    <span class="text-xs text-muted-foreground">Post #{{ $record->id }}</span>
                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $statusLabels[$record->status] ?? $record->status }}</span>
                </div>
                <h1 class="text-xl font-semibold text-mono line-clamp-2 max-w-[720px]">{{ $record->excerpt(90) }}</h1>
                <p class="text-sm text-secondary-foreground mt-1">
                    @if ($record->published_at)
                        First published {{ $record->published_at->format('j M Y, H:i') }} to
                        {{ $targets->where('status', 'published')->count() }} of {{ $targets->count() }}
                        {{ $targets->count() === 1 ? 'network' : 'networks' }}.
                    @elseif ($record->scheduled_for)
                        Scheduled for {{ $record->scheduled_for->format('j M Y, H:i') }}, aimed at
                        {{ $targets->count() }} {{ $targets->count() === 1 ? 'network' : 'networks' }}.
                    @else
                        A draft, aimed at {{ $targets->count() }} {{ $targets->count() === 1 ? 'network' : 'networks' }}.
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($record->hasOutstandingTargets())
                    <button wire:click="retry" wire:loading.attr="disabled" class="kt-btn kt-btn-primary gap-2">
                        <span wire:loading.remove wire:target="retry" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-paper-plane"></i> Send what is outstanding
                        </span>
                        <span wire:loading wire:target="retry" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Sending…
                        </span>
                    </button>
                @endif
                <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                    <i class="ki-filled ki-copy"></i> Write another
                </a>
            </div>
        </div>

        {{-- The post as written --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">The post as written</h3>
                <span class="text-xs text-muted-foreground">{{ mb_strlen($record->body) }} characters</span>
            </div>
            <div class="kt-card-content p-5">
                <p class="text-sm text-mono whitespace-pre-wrap leading-relaxed">{{ $record->body }}</p>
            </div>
        </div>

        {{-- Per-network delivery --}}
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
            @forelse ($targets as $target)
                <div class="kt-card" wire:key="target-{{ $target->id }}">

                    <div class="kt-card-header">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                <i class="ki-filled {{ $target->account?->icon() ?? 'ki-abstract-26' }} text-lg text-secondary-foreground"></i>
                            </span>
                            <div class="min-w-0">
                                <h3 class="kt-card-title">{{ $target->account?->label() ?? 'Unknown network' }}</h3>
                                <p class="text-xs text-muted-foreground truncate">
                                    {{ $target->account?->handle ?? 'the account has been deleted' }}
                                </p>
                            </div>
                        </div>
                        <span class="kt-badge kt-badge-sm {{ $states[$target->status]['badge'] ?? 'kt-badge-outline' }} shrink-0">
                            {{ $states[$target->status]['label'] ?? $target->status }}
                        </span>
                    </div>

                    <div class="kt-card-content p-4 flex flex-col gap-4">

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                                <div class="text-xs text-muted-foreground">Sent</div>
                                <div class="text-sm font-medium text-mono">
                                    {{ $target->published_at?->format('j M, H:i') ?? '—' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-foreground">Attempts</div>
                                <div class="text-sm font-medium text-mono">{{ $target->attempts }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-foreground">Last tried</div>
                                <div class="text-sm font-medium text-mono">
                                    {{ $target->last_attempt_at?->diffForHumans(['short' => true]) ?? '—' }}
                                </div>
                            </div>
                        </div>

                        @if ($target->remote_id)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted px-3 py-2">
                                <span class="text-xs text-muted-foreground truncate min-w-0" title="{{ $target->remote_id }}">
                                    Remote id {{ $target->remote_id }}
                                </span>
                                @if ($target->remote_url)
                                    <a href="{{ $target->remote_url }}" target="_blank" rel="noopener"
                                       class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 shrink-0">
                                        <i class="ki-filled ki-arrow-up-right text-sm"></i> Open live post
                                    </a>
                                @else
                                    <span class="text-xs text-muted-foreground shrink-0">
                                        No public link — the chat is private
                                    </span>
                                @endif
                            </div>
                        @endif

                        {{-- The copy this network holds --}}
                        <div class="border-t border-border pt-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-secondary-foreground">
                                    {{ $target->isPublished() ? 'Published copy' : 'Copy that is queued' }}
                                    @if ($target->body_override)
                                        <span class="kt-badge kt-badge-sm kt-badge-outline ms-1">Custom text</span>
                                    @endif
                                </span>
                                <span class="text-xs text-muted-foreground">{{ mb_strlen($target->text()) }} characters</span>
                            </div>
                            <p class="text-sm text-mono whitespace-pre-wrap leading-relaxed">{{ $target->text() }}</p>
                        </div>

                        @if ($target->error)
                            <div class="flex flex-wrap items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-3">
                                <i class="ki-filled ki-information-2 text-destructive text-base mt-0.5 shrink-0"></i>
                                <p class="text-sm text-secondary-foreground grow min-w-0">{{ $target->error }}</p>
                                <div class="flex items-center gap-2 shrink-0">
                                    @unless ($target->account?->isConnected())
                                        <a href="{{ route('social.account-connect') }}?network={{ $target->account?->network }}"
                                           wire:navigate class="kt-btn kt-btn-sm kt-btn-outline">Connect</a>
                                    @endunless
                                    <button wire:click="retry({{ $target->social_account_id }})" wire:loading.attr="disabled"
                                            class="kt-btn kt-btn-sm kt-btn-primary gap-1.5">
                                        <span wire:loading.remove wire:target="retry({{ $target->social_account_id }})">Retry</span>
                                        <span wire:loading wire:target="retry({{ $target->social_account_id }})" class="inline-flex items-center gap-1.5">
                                            <i class="ki-filled ki-loading animate-spin"></i> Retrying…
                                        </span>
                                    </button>
                                </div>
                            </div>
                        @endif

                    </div>
                </div>
            @empty
                <div class="kt-card xl:col-span-2">
                    <div class="kt-card-content flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-element-11 text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">This post is not aimed at any account.</p>
                    </div>
                </div>
            @endforelse
        </div>

    @endif
</div>
