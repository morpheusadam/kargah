<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Models\SocialNotification;
use Modules\Social\Support\Networks;

/**
 * Unified social notifications.
 *
 * One feed for every network that will say, so you stop opening four apps to
 * find out whether anything needs a reply. `social:sync-notifications` fills it
 * every fifteen minutes; nothing here calls a network, because a page render
 * must never wait on someone else's API.
 *
 * Three of the five networks cannot appear here at all. LinkedIn's
 * notifications API needs partner access nobody self-serving has, Telegram's
 * `getUpdates` would consume the update queue the bot itself depends on, and a
 * Discord incoming webhook can only write. The filter row says so rather than
 * offering a tab that is empty for a reason nobody can see.
 */
new
#[Title('Notifications — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** A network key, or 'all'. */
    #[Url]
    public string $network = 'all';

    #[Url]
    public bool $unreadOnly = false;

    /** Per-request memos; see the note on ⚡boards about why these are private. */
    private ?Collection $resolvedItems = null;

    private ?Collection $resolvedAccounts = null;

    /** @return Collection<int, SocialAccount> */
    private function accounts(): Collection
    {
        return $this->resolvedAccounts ??= SocialAccount::query()->inReadingOrder()->get();
    }

    /** @return Collection<int, SocialNotification> */
    private function items(): Collection
    {
        return $this->resolvedItems ??= SocialNotification::query()
            ->with('account')
            ->newestFirst()
            ->limit(200)
            ->get();
    }

    private function forget(): void
    {
        $this->resolvedItems = null;
    }

    /** The item ids currently on screen, which is what 'mark all read' means. */
    private function visible(): Collection
    {
        return $this->items()->filter(function (SocialNotification $item): bool {
            if ($this->unreadOnly && $item->is_read) {
                return false;
            }

            return $this->network === 'all' || $item->account?->network === $this->network;
        })->values();
    }

    public function with(): array
    {
        $items = $this->items();
        $visible = $this->visible();

        // Only networks that both have an account and can be read back are
        // offered as filters; the rest are listed underneath with the reason.
        $present = $this->accounts()
            ->pluck('network')
            ->unique()
            ->filter(fn (string $network): bool => Networks::ingestsNotifications($network))
            ->values();

        return [
            // `all()` and deliberately not `available()`: every network this
            // draws is one an account or a stored notification already names,
            // and a feed row whose module has since been switched off still
            // needs its icon and its label rather than a blank. Nothing on this
            // page offers a destination, so there is nothing here to filter.
            //
            // In practice the two would return the same thing anyway — only
            // Mastodon and Bluesky ingest, and both are Social's own — but the
            // next network that learns to read notifications may not be, and
            // the reason should not have to be rediscovered then.
            'catalogue' => Networks::all(),
            'filters' => $present,
            'items' => $visible,
            'unread' => $items->where('is_read', false)->count(),
            'unreadVisible' => $visible->where('is_read', false)->count(),
            'publishOnly' => $this->accounts()
                ->reject(fn (SocialAccount $a): bool => Networks::ingestsNotifications($a->network))
                ->pluck('network')
                ->unique()
                ->values(),
        ];
    }

    public function setNetwork(string $network): void
    {
        $this->network = $network === 'all' || Networks::has($network) ? $network : 'all';

        $this->forget();
    }

    public function toggleUnreadOnly(): void
    {
        $this->unreadOnly = ! $this->unreadOnly;

        $this->forget();
    }

    /** Mark one row read, or unread again if it was already. */
    public function toggleRead(int $id): void
    {
        $item = $this->items()->firstWhere('id', $id);

        if ($item === null) {
            $this->toastError('That notification is no longer here', 'Reload the page and try again.');

            return;
        }

        $item->forceFill(['is_read' => ! $item->is_read])->save();

        $this->forget();

        $this->toastSuccess($item->is_read ? 'Marked as read' : 'Marked as unread');
    }

    /**
     * Clear the unread flag on everything currently on screen.
     *
     * On screen rather than in the table: a filter is a statement about what
     * you are looking at, and marking the other networks read as a side effect
     * of reading one of them is how notifications get missed.
     */
    public function markAllRead(): void
    {
        $ids = $this->visible()->where('is_read', false)->pluck('id');

        if ($ids->isEmpty()) {
            $this->toastSuccess('Nothing to mark', 'Everything in this view has been read already.');

            return;
        }

        SocialNotification::query()->whereIn('id', $ids)->update(['is_read' => true]);

        $this->forget();

        $count = $ids->count();

        $this->toastSuccess(
            'Marked '.$count.' as read',
            $this->network === 'all'
                ? 'The feed is clear.'
                : Networks::label($this->network).' is clear; other networks are untouched.',
        );
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Notifications</h1>
            <p class="text-sm text-secondary-foreground mt-1">Every network that will say, in one feed.</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="toggleUnreadOnly"
                    class="kt-btn kt-btn-sm gap-2 {{ $unreadOnly ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <i class="ki-filled ki-notification-status text-sm"></i>
                {{ $unread }} unread
            </button>
            <button wire:click="markAllRead" wire:loading.attr="disabled" class="kt-btn kt-btn-outline gap-2"
                    @disabled($unreadVisible === 0)>
                <span wire:loading.remove wire:target="markAllRead" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-check-circle"></i> Mark all read
                </span>
                <span wire:loading wire:target="markAllRead" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Marking…
                </span>
            </button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button wire:click="setNetwork('all')"
                class="kt-btn kt-btn-sm gap-2 {{ $network === 'all' ? 'kt-btn-primary' : 'kt-btn-outline' }}">
            <i class="ki-filled ki-element-11 text-sm"></i> All
        </button>
        @foreach ($filters as $key)
            <button wire:click="setNetwork('{{ $key }}')" wire:key="filter-{{ $key }}"
                    class="kt-btn kt-btn-sm gap-2 {{ $network === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <i class="ki-filled {{ $catalogue[$key]['icon'] }} text-sm"></i> {{ $catalogue[$key]['label'] }}
            </button>
        @endforeach
    </div>

    @if ($publishOnly->isNotEmpty())
        <p class="text-xs text-muted-foreground">
            @foreach ($publishOnly as $key)
                {{ $catalogue[$key]['label'] ?? $key }}@if (! $loop->last), @endif
            @endforeach
            {{ $publishOnly->count() === 1 ? 'publishes only' : 'publish only' }} — neither exposes a notifications API
            Kargah can read without partner access, so nothing from
            {{ $publishOnly->count() === 1 ? 'it' : 'them' }} appears here.
        </p>
    @endif

    <div class="kt-card">
        <div class="kt-card-content p-0 divide-y divide-border">
            @forelse ($items as $item)
                <div class="flex items-start gap-3 px-5 py-4 hover:bg-accent/30 transition-colors {{ $item->is_read ? '' : 'bg-primary/[0.03]' }}"
                     wire:key="notification-{{ $item->id }}">
                    <span class="inline-flex items-center justify-center size-10 rounded-lg bg-muted shrink-0">
                        <i class="ki-filled {{ $item->account?->icon() ?? 'ki-abstract-26' }} {{ $catalogue[$item->account?->network]['tone'] ?? 'text-muted-foreground' }} text-lg"></i>
                    </span>
                    <div class="min-w-0 grow">
                        <div class="text-sm">
                            <span class="font-semibold text-mono">{{ $item->actor_handle ?? 'Someone' }}</span>
                            <span class="text-secondary-foreground">{{ $item->action() }}</span>
                            <span class="text-muted-foreground text-xs">on {{ $item->networkLabel() }}</span>
                        </div>
                        @if ($item->excerpt)
                            <p class="text-sm text-secondary-foreground mt-1 line-clamp-2">{{ $item->excerpt }}</p>
                        @endif
                        @if ($item->url)
                            <a href="{{ $item->url }}" target="_blank" rel="noopener"
                               class="text-xs text-primary inline-flex items-center gap-1 mt-1">
                                <i class="ki-filled ki-arrow-up-right text-xs"></i> Open on {{ $item->networkLabel() }}
                            </a>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-xs text-muted-foreground">
                            {{ $item->occurred_at?->diffForHumans(['short' => true]) ?? '—' }}
                        </span>
                        <button wire:click="toggleRead({{ $item->id }})"
                                class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                title="{{ $item->is_read ? 'Mark as unread' : 'Mark as read' }}"
                                aria-label="{{ $item->is_read ? 'Mark as unread' : 'Mark as read' }}">
                            @if ($item->is_read)
                                <i class="ki-filled ki-eye-slash text-muted-foreground"></i>
                            @else
                                <span class="size-2 rounded-full bg-primary"></span>
                            @endif
                        </button>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center py-14 text-center">
                    <i class="ki-filled ki-notification-status text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">
                        @if ($unreadOnly)
                            Nothing unread here.
                        @elseif ($filters->isEmpty())
                            No account can read notifications back yet.
                        @else
                            Nothing yet. The sync runs every fifteen minutes.
                        @endif
                    </p>
                    @if ($filters->isEmpty())
                        <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3">
                            Connect Mastodon or Bluesky
                        </a>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</div>
