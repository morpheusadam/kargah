<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Every network Kargah can post to, and whether it actually can.
 *
 * 'Connected' here means something specific and checkable: the account is
 * switched on *and* every credential its driver needs is present. An account
 * row on its own proves nothing — a fresh install seeds all four and none of
 * them can publish — so the page says which of the two is missing rather than
 * showing a green badge nobody can act on.
 *
 * The credential itself is never read here and never rendered. `hasCredentials()`
 * answers a yes-or-no question; nothing on this page can ask for the value.
 */
new
#[Title('Social accounts — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The account whose disconnect confirmation is open, if any. */
    public ?int $confirming = null;

    /**
     * A per-request memo. Private, so Livewire neither ships nor rehydrates it
     * and a new instance starts empty — no code here may assume either a fresh
     * process or a persistent one.
     */
    private ?Collection $resolvedAccounts = null;

    /** @return Collection<int, SocialAccount> */
    private function accounts(): Collection
    {
        return $this->resolvedAccounts ??= SocialAccount::query()
            ->withCount(['targets as published_count' => fn ($query) => $query->where('status', 'published')])
            ->inReadingOrder()
            ->get();
    }

    public function with(): array
    {
        $accounts = $this->accounts();

        return [
            'accounts' => $accounts,
            'catalogue' => Networks::all(),
            'connected' => $accounts->filter(fn (SocialAccount $a): bool => $a->isConnected())->count(),
            // The networks with no row at all, so the page offers them rather
            // than looking like Kargah supports only what happens to be seeded.
            'missing' => array_diff(Networks::keys(), $accounts->pluck('network')->all()),
        ];
    }

    private function account(int $id): ?SocialAccount
    {
        return $this->accounts()->firstWhere('id', $id);
    }

    private function forget(): void
    {
        $this->resolvedAccounts = null;
    }

    public function confirmDisconnect(int $id): void
    {
        $account = $this->account($id);

        if ($account === null) {
            $this->toastError('That account is no longer here', 'Reload the page and try again.');

            return;
        }

        $this->confirming = $id;

        $queued = $account->targets()->where('status', 'pending')->count();

        $this->toastWarning(
            'Disconnect '.$account->label().'?',
            $queued === 0
                ? 'Nothing is queued for it. Posts already published stay up on the network.'
                : $queued.' queued '.($queued === 1 ? 'post' : 'posts').' would stop going to it.',
        );
    }

    public function cancelDisconnect(): void
    {
        if ($this->confirming === null) {
            return;
        }

        $this->confirming = null;

        $this->toastSuccess('Left connected', 'Nothing was changed.');
    }

    /**
     * Forget the credential and switch the account off.
     *
     * The row stays, because its posts and notifications point at it and the
     * history is worth more than the tidiness. Only the secret goes.
     */
    public function disconnect(int $id): void
    {
        $account = $this->account($id);

        if ($account === null) {
            $this->toastError('That account is no longer here', 'Reload the page and try again.');

            return;
        }

        $had = $account->hasCredentials();

        $account->forceFill([
            'credentials' => null,
            'is_active' => false,
            'connected_at' => null,
            'token_expires_at' => null,
            'last_error' => null,
        ])->save();

        $this->confirming = null;
        $this->forget();

        $this->toastSuccess(
            $account->label().' disconnected',
            $had
                ? 'The stored credential was deleted. Its published posts stay up on the network.'
                : 'It had no credential stored; it is now switched off as well.',
        );
    }

    /** Switch an account back on without re-entering its credential. */
    public function reactivate(int $id): void
    {
        $account = $this->account($id);

        if ($account === null) {
            $this->toastError('That account is no longer here', 'Reload the page and try again.');

            return;
        }

        $account->forceFill(['is_active' => true])->save();

        $this->forget();

        $account->hasCredentials()
            ? $this->toastSuccess($account->label().' switched on', 'It will take the next post scheduled for it.')
            : $this->toastWarning(
                $account->label().' switched on, but it cannot publish',
                'Its credentials are not configured. Connect it before scheduling anything for it.',
            );
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Social accounts</h1>
            <p class="text-sm text-secondary-foreground mt-1">Connect a network once; publishing and notifications follow.</p>
        </div>
        <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Connect an account
        </a>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm text-secondary-foreground">
        <span class="kt-badge kt-badge-sm {{ $connected > 0 ? 'kt-badge-success' : 'kt-badge-outline' }}">
            {{ $connected }} of {{ $accounts->count() }} ready to publish
        </span>
        @if ($connected < $accounts->count())
            <span class="text-xs text-muted-foreground">
                An account without its credentials records the reason on the post rather than sending it.
            </span>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        @forelse ($accounts as $account)
            @php
                $meta = $catalogue[$account->network] ?? null;
                $ready = $account->isConnected();
            @endphp
            <div class="kt-card" wire:key="account-{{ $account->id }}">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled {{ $account->icon() }} text-xl"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-mono">{{ $account->label() }}</div>
                                <div class="text-sm text-secondary-foreground truncate">{{ $account->handle }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ $account->published_count }} published ·
                                    {{ number_format($account->characterLimit()) }} characters
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-2 shrink-0">
                            @if ($ready)
                                <span class="kt-badge kt-badge-sm kt-badge-success">Connected</span>
                            @elseif (! $account->is_active)
                                <span class="kt-badge kt-badge-sm kt-badge-outline">Switched off</span>
                            @else
                                <span class="kt-badge kt-badge-sm kt-badge-warning">No credentials</span>
                            @endif

                            @if ($meta && ! $meta['ingests'])
                                <span class="text-[11px] text-muted-foreground">Publishing only</span>
                            @endif
                        </div>
                    </div>

                    @unless ($ready)
                        <p class="text-xs text-secondary-foreground rounded-lg bg-muted px-3 py-2">
                            @if (! $account->is_active)
                                This account is switched off, so nothing is sent to it.
                            @else
                                Credentials are not configured, so a post aimed here records the reason instead of going out.
                            @endif
                        </p>
                    @endunless

                    @if ($account->last_error)
                        <p class="text-xs text-secondary-foreground rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2">
                            {{ $account->last_error }}
                        </p>
                    @endif

                    <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                        <a href="{{ route('social.account-connect') }}?network={{ $account->network }}" wire:navigate
                           class="kt-btn kt-btn-sm {{ $ready ? 'kt-btn-outline' : 'kt-btn-primary' }}">
                            {{ $ready ? 'Replace credentials' : 'Connect' }}
                        </a>

                        @if (! $account->is_active)
                            <button wire:click="reactivate({{ $account->id }})" wire:loading.attr="disabled"
                                    class="kt-btn kt-btn-sm kt-btn-ghost">
                                <span wire:loading.remove wire:target="reactivate({{ $account->id }})">Switch on</span>
                                <span wire:loading wire:target="reactivate({{ $account->id }})" class="inline-flex items-center gap-1.5">
                                    <i class="ki-filled ki-loading animate-spin"></i> Working…
                                </span>
                            </button>
                        @elseif ($confirming === $account->id)
                            <button wire:click="disconnect({{ $account->id }})" wire:loading.attr="disabled"
                                    class="kt-btn kt-btn-sm kt-btn-primary bg-destructive border-destructive">
                                <span wire:loading.remove wire:target="disconnect({{ $account->id }})">Yes, disconnect</span>
                                <span wire:loading wire:target="disconnect({{ $account->id }})" class="inline-flex items-center gap-1.5">
                                    <i class="ki-filled ki-loading animate-spin"></i> Disconnecting…
                                </span>
                            </button>
                            <button wire:click="cancelDisconnect" class="kt-btn kt-btn-sm kt-btn-ghost">Keep it</button>
                        @else
                            <button wire:click="confirmDisconnect({{ $account->id }})"
                                    class="kt-btn kt-btn-sm kt-btn-ghost text-destructive">Disconnect</button>
                        @endif
                    </div>

                </div>
            </div>
        @empty
            <div class="kt-card md:col-span-2">
                <div class="kt-card-content flex flex-col items-center py-14 text-center">
                    <i class="ki-filled ki-abstract-26 text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">No accounts yet. Connect one and publishing follows.</p>
                    <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3 gap-2">
                        <i class="ki-filled ki-plus"></i> Connect an account
                    </a>
                </div>
            </div>
        @endforelse
    </div>

    @if ($missing)
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Networks Kargah supports</h3>
                <span class="text-xs text-muted-foreground">Not set up on this install yet</span>
            </div>
            <div class="kt-card-content p-4 flex flex-wrap gap-2">
                @foreach ($missing as $network)
                    <a href="{{ route('social.account-connect') }}?network={{ $network }}" wire:navigate
                       class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                        <i class="ki-filled {{ $catalogue[$network]['icon'] }} text-sm"></i>
                        {{ $catalogue[$network]['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
