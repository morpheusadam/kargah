<?php

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Contracts\Notifier;
use Modules\Core\Models\Notification;

/**
 * The in-app notification feed.
 *
 * Everything on this page was rendered by whichever module raised it — Core
 * stores a title, a body and a URL and draws a row. That is the whole reason a
 * notification about a card Project has since deleted still appears here with
 * the name the card had at the time, instead of taking the page down with it.
 *
 * **Cursor pagination, not offset.** A feed is the exact shape that gets long,
 * and offset pagination scans and discards every row it skips; at page 200 that
 * is the whole table. The order is `created_at desc, id desc` — the id breaks
 * ties so the cursor has a total order to compare against, which a bare
 * timestamp does not when a sweep writes forty rows in the same second.
 *
 * **Toasts only where the change is invisible.** Marking one row read is
 * visible — the row changes under the pointer — so it says nothing. Marking all
 * read is a bulk change across rows that may be below the fold, so it reports.
 * Opening the filter says nothing either.
 *
 * This is the generic half. Project's "watch a card / list / board" layer sits
 * on top of `Modules\Core\Contracts\Notifier` and changes nothing here.
 */
new
#[Title('Notifications — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    private const PER_PAGE = 25;

    #[Url]
    public bool $unreadOnly = false;

    #[Url]
    public string $cursor = '';

    /**
     * Per-request memo. Private, so Livewire neither ships nor rehydrates it,
     * and a new instance starts empty — no code here may assume either a fresh
     * process or a persistent one.
     */
    private ?CursorPaginator $resolvedRows = null;

    private function userId(): int
    {
        return (int) auth()->id();
    }

    /** One page of the feed. */
    private function rows(): CursorPaginator
    {
        return $this->resolvedRows ??= Notification::query()
            ->forUser($this->userId())
            ->when($this->unreadOnly, fn ($query) => $query->unread())
            ->with('actor:id,name')
            ->newestFirst()
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $this->currentCursor());
    }

    /**
     * The cursor the address bar is carrying, if it is one.
     *
     * A cursor is base64 in a query string, so it can arrive edited, truncated
     * or pasted from a chat. An unreadable one is the first page, not a stack
     * trace.
     */
    private function currentCursor(): ?Cursor
    {
        if ($this->cursor === '') {
            return null;
        }

        return rescue(fn (): ?Cursor => Cursor::fromEncoded($this->cursor), null, false);
    }

    private function forget(): void
    {
        $this->resolvedRows = null;
    }

    public function with(): array
    {
        return [
            'items' => $this->rows(),
            'unread' => app(Notifier::class)->unreadCount($this->userId()),
            'tones' => $this->tones(),
        ];
    }

    /**
     * Whole Tailwind class strings, keyed by the first segment of the event.
     *
     * Never built by concatenation: Tailwind's scanner reads source as text and
     * cannot see a class that only exists once PHP has run.
     *
     * @return array<string, array{icon: string, tone: string}>
     */
    private function tones(): array
    {
        return [
            'card' => ['icon' => 'ki-abstract-26', 'tone' => 'text-primary'],
            'invoice' => ['icon' => 'ki-dollar', 'tone' => 'text-warning'],
            'expense' => ['icon' => 'ki-dollar', 'tone' => 'text-warning'],
            'email' => ['icon' => 'ki-sms', 'tone' => 'text-info'],
            'campaign' => ['icon' => 'ki-paper-plane', 'tone' => 'text-info'],
            'post' => ['icon' => 'ki-share', 'tone' => 'text-success'],
            'backup' => ['icon' => 'ki-folder', 'tone' => 'text-muted-foreground'],
        ];
    }

    /** Filtering is a statement about what you are looking at, so it resets the page. */
    public function toggleUnreadOnly(): void
    {
        $this->unreadOnly = ! $this->unreadOnly;
        $this->cursor = '';

        $this->forget();
    }

    public function goToCursor(string $cursor = ''): void
    {
        $this->cursor = $cursor;

        $this->forget();
    }

    /**
     * Mark one read.
     *
     * The user id goes through the contract rather than being checked here: an
     * id arriving from the browser is not a capability, and the one place that
     * rule is enforced is the one place it can be got wrong.
     */
    public function markRead(int $id): void
    {
        $marked = app(Notifier::class)->markRead($id, $this->userId());

        if (! $marked) {
            $this->toastError('That notification is no longer here', 'Reload the page and try again.');

            return;
        }

        // Deliberately silent. The row changes under the pointer, so a toast
        // would only report what the user can already see.
        $this->forget();
    }

    public function markAllRead(): void
    {
        $count = app(Notifier::class)->markAllRead($this->userId());

        $this->forget();

        if ($count === 0) {
            // Nothing happened, so nothing is announced.
            return;
        }

        $this->toastSuccess(
            'Marked '.$count.' as read',
            'The feed is clear.',
        );
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Notifications</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Everything across Kargah that wanted your attention, newest first.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="toggleUnreadOnly"
                    class="kt-btn kt-btn-sm gap-2 {{ $unreadOnly ? 'kt-btn-primary' : 'kt-btn-outline' }}"
                    aria-pressed="{{ $unreadOnly ? 'true' : 'false' }}">
                <i class="ki-filled ki-notification-status text-sm"></i>
                <span data-kargah-unread-count>{{ $unread }}</span> unread
            </button>
            <button wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead"
                    class="kt-btn kt-btn-outline gap-2" @disabled($unread === 0)>
                <span wire:loading.remove wire:target="markAllRead" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-double-check"></i> Mark all read
                </span>
                <span wire:loading wire:target="markAllRead" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Marking…
                </span>
            </button>
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-content p-0 divide-y divide-border">
            @forelse ($items as $item)
                @php
                    $family = explode('.', $item->event)[0];
                    $style = $tones[$family] ?? ['icon' => 'ki-notification-status', 'tone' => 'text-muted-foreground'];
                @endphp

                <div class="flex items-start gap-3 px-5 py-4 hover:bg-accent/30 transition-colors {{ $item->isRead() ? '' : 'bg-primary/[0.03]' }}"
                     wire:key="notification-{{ $item->id }}">

                    <span class="inline-flex items-center justify-center size-10 rounded-lg bg-muted shrink-0">
                        <i class="ki-filled {{ $style['icon'] }} {{ $style['tone'] }} text-lg"></i>
                    </span>

                    <div class="min-w-0 grow">
                        <div class="text-sm">
                            @if ($item->url)
                                <a href="{{ $item->url }}" wire:navigate class="font-semibold text-mono hover:text-primary">
                                    {{ $item->title }}
                                </a>
                            @else
                                <span class="font-semibold text-mono">{{ $item->title }}</span>
                            @endif
                        </div>

                        @if ($item->body)
                            <p class="text-sm text-secondary-foreground mt-1 line-clamp-2">{{ $item->body }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-2 mt-1.5">
                            <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $item->event }}</span>
                            @if ($item->actor?->name)
                                <span class="text-xs text-muted-foreground">by {{ $item->actor->name }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-xs text-muted-foreground">
                            {{ $item->created_at?->diffForHumans(['short' => true]) ?? '—' }}
                        </span>
                        @if ($item->isRead())
                            <span class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost text-muted-foreground"
                                  title="Read {{ $item->read_at?->diffForHumans() }}">
                                <i class="ki-filled ki-eye-slash"></i>
                            </span>
                        @else
                            <button wire:click="markRead({{ $item->id }})"
                                    wire:loading.attr="disabled" wire:target="markRead({{ $item->id }})"
                                    class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                    title="Mark as read" aria-label="Mark as read">
                                <span class="size-2 rounded-full bg-primary"></span>
                            </button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center py-14 text-center">
                    <i class="ki-filled ki-notification-status text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">
                        @if ($unreadOnly)
                            Nothing unread.
                        @else
                            Nothing yet. Cards, invoices and mail will report here as they happen.
                        @endif
                    </p>
                    @if ($unreadOnly)
                        <button wire:click="toggleUnreadOnly" class="kt-btn kt-btn-sm kt-btn-ghost mt-3">
                            Show everything
                        </button>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($items->hasPages())
            <div class="kt-card-footer flex items-center justify-between gap-3">
                <span class="text-xs text-muted-foreground">
                    Newest first, {{ $items->count() }} on this page.
                </span>
                <div class="flex items-center gap-2">
                    <button wire:click="goToCursor('{{ $items->previousCursor()?->encode() }}')"
                            wire:loading.attr="disabled" wire:target="goToCursor"
                            @disabled($items->onFirstPage())
                            class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                        <i class="ki-filled ki-black-left text-xs"></i> Newer
                    </button>
                    <button wire:click="goToCursor('{{ $items->nextCursor()?->encode() }}')"
                            wire:loading.attr="disabled" wire:target="goToCursor"
                            @disabled(! $items->hasMorePages())
                            class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                        Older <i class="ki-filled ki-arrow-right text-xs"></i>
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
