<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Support\Networks;

/**
 * Cross-network composer.
 *
 * Write once, pick accounts, publish or schedule. The shared text feeds every
 * account unless one has been given its own copy, and each target renders a
 * live preview so the limit is something you see rather than something you are
 * told about after the fact.
 *
 * **Publishing here is the same code path the scheduler uses.** `submit()`
 * writes the post and its targets and then hands them to `PostPublisher`, which
 * claims each target exactly as the cron job would. Pressing publish twice
 * cannot send twice, and an account with no credentials records the reason on
 * its own target while the others go out.
 *
 * The toast says what actually happened, per network. 'Published' is not a
 * truthful summary of a post that reached two networks out of three, and the
 * person's next action depends on which.
 */
new
#[Title('Publish — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $body = '';

    /** @var array<int, int|string> Account ids this post is going to. */
    public array $targets = [];

    /** Per-account copy. A key exists only while that account is overridden. */
    public array $overrides = [];

    /** One of 'now', 'later', 'draft'. */
    public string $schedule = 'now';

    public string $scheduledAt = '';

    /** Per-request memo; see the note on ⚡boards about why these are private. */
    private ?Collection $resolvedAccounts = null;

    /**
     * Start with every account that could actually receive a post ticked.
     *
     * An unconnected account is left unticked rather than hidden: it is still
     * something you can aim at, and doing so records that its credentials are
     * missing, which is more useful than the account quietly not being there.
     */
    public function mount(): void
    {
        $this->targets = $this->accounts()
            ->filter(fn (SocialAccount $a): bool => $a->isConnected())
            ->pluck('id')
            ->all();
    }

    /** @return Collection<int, SocialAccount> */
    private function accounts(): Collection
    {
        return $this->resolvedAccounts ??= SocialAccount::query()->inReadingOrder()->get();
    }

    /** `#[Url]`-shaped arrays and form input arrive as strings. Compare ids as ids. */
    private function targetIds(): array
    {
        return array_map('intval', $this->targets);
    }

    /** @return Collection<int, SocialAccount> */
    private function selected(): Collection
    {
        $ids = $this->targetIds();

        return $this->accounts()->filter(fn (SocialAccount $a): bool => in_array($a->id, $ids, true))->values();
    }

    public function with(): array
    {
        $selected = $this->selected();

        return [
            'accounts' => $this->accounts(),
            'selected' => $selected,
            'catalogue' => Networks::all(),
            'connectedCount' => $selected->filter(fn (SocialAccount $a): bool => $a->isConnected())->count(),
        ];
    }

    /** The copy a given account will actually receive. */
    public function textFor(int $accountId): string
    {
        return $this->overrides[$accountId] ?? $this->body;
    }

    public function isOverridden(int $accountId): bool
    {
        return array_key_exists($accountId, $this->overrides);
    }

    private function account(int $id): ?SocialAccount
    {
        return $this->accounts()->firstWhere('id', $id);
    }

    public function toggleTarget(int $accountId): void
    {
        $account = $this->account($accountId);

        if ($account === null) {
            $this->toastError('That account is no longer here', 'Reload the page and try again.');

            return;
        }

        $current = $this->targetIds();

        $this->targets = in_array($accountId, $current, true)
            ? array_values(array_diff($current, [$accountId]))
            : [...$current, $accountId];

        if (! in_array($accountId, $this->targetIds(), true)) {
            unset($this->overrides[$accountId]);

            return;
        }

        // Selecting is visible in the list and in the previews; the only thing
        // worth saying is what the tick does not show.
        if (! $account->isConnected()) {
            $this->toastWarning(
                $account->label().' credentials are not configured',
                'It will be recorded as a failed target rather than published to.',
            );
        }
    }

    /** Fork this account's copy off the shared text, or fold it back in. */
    public function toggleOverride(int $accountId): void
    {
        $account = $this->account($accountId);

        if ($account === null) {
            return;
        }

        if ($this->isOverridden($accountId)) {
            unset($this->overrides[$accountId]);

            return;
        }

        $this->overrides[$accountId] = $this->body;
    }

    /** Cut the overridden copy down to whatever this network allows. */
    public function trimToLimit(int $accountId): void
    {
        $account = $this->account($accountId);

        if ($account === null) {
            return;
        }

        $limit = $account->characterLimit();

        // The textarea and its counter show the result, so nothing is said.
        $this->overrides[$accountId] = rtrim(mb_substr($this->textFor($accountId), 0, $limit));
    }

    /**
     * Write the post and its targets, then do what the composer was asked to.
     *
     * The write is one post plus one target per account, and it happens for all
     * three modes — a draft is a real row with real targets, which is why
     * scheduling it later is an edit rather than a fresh composition.
     */
    public function submit(): void
    {
        $body = trim($this->body);

        if ($body === '') {
            $this->toastError('The post has nothing in it', 'Write something before publishing or scheduling.');

            return;
        }

        $accounts = $this->selected();

        if ($accounts->isEmpty()) {
            $this->toastError('Pick at least one account', 'Nothing was written.');

            return;
        }

        $when = $this->scheduledFor();

        if ($this->schedule === 'later' && $when === null) {
            $this->toastError('That is not a date Kargah can read', 'Pick the day and time this should go out.');

            return;
        }

        if ($this->schedule === 'later' && $when->isPast()) {
            $this->toastError('That time has already passed', 'Pick a time in the future, or publish it now.');

            return;
        }

        $post = Post::query()->create([
            'body' => $body,
            'media' => null,
            'status' => match ($this->schedule) {
                'later' => Post::SCHEDULED,
                default => Post::DRAFT,
            },
            'scheduled_for' => $when,
            'created_by' => auth()->id(),
        ]);

        foreach ($accounts as $account) {
            PostTarget::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                // Stored only when it genuinely differs, so a target with no
                // override keeps following the post if the post is edited.
                'body_override' => $this->isOverridden($account->id) && trim($this->textFor($account->id)) !== $body
                    ? trim($this->textFor($account->id))
                    : null,
                'status' => PostTarget::PENDING,
            ]);
        }

        if ($this->schedule === 'draft') {
            $this->flashToast(
                'success',
                'Saved as a draft',
                'It is aimed at '.$accounts->count().' '.($accounts->count() === 1 ? 'account' : 'accounts')
                .' and will not go out until you schedule or publish it.',
            );

            $this->redirectRoute('social.posts', navigate: true);

            return;
        }

        if ($this->schedule === 'later') {
            $this->flashToast(
                'success',
                'Scheduled for '.$when->format('j M Y, H:i'),
                'The scheduler checks every minute, so it goes out within a minute of that time.',
            );

            $this->redirectRoute('social.calendar', navigate: true);

            return;
        }

        // Publish now, through the same claim-and-send path cron uses.
        $report = app(PostPublisher::class)->publishPost($post->refresh());

        if ($report->failed === 0) {
            $this->flashToast('success', 'Published', $report->summary());
        } elseif ($report->published > 0) {
            $this->flashToast('warning', 'Published to some networks and not others', $report->firstError());
        } else {
            $this->flashToast('error', 'Nothing was published', $report->firstError());
        }

        $this->redirectRoute('social.post-show', ['post' => $post->id], navigate: true);
    }

    /**
     * The scheduled moment, or null when there is not one to read.
     *
     * `datetime-local` gives 'Y-m-d\TH:i' when a browser fills it and anything
     * at all when a person types into it, so this refuses rather than guesses.
     */
    private function scheduledFor(): ?\Illuminate\Support\Carbon
    {
        if ($this->schedule !== 'later' || trim($this->scheduledAt) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($this->scheduledAt);
        } catch (\Throwable) {
            return null;
        }
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Publish</h1>
            <p class="text-sm text-secondary-foreground mt-1">One post, every account you pick.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('social.calendar') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-calendar"></i> Calendar
            </a>
            <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-questionnaire-tablet"></i> Queue
            </a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Composer --}}
        <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <textarea class="kt-textarea min-h-[220px] text-sm"
                              placeholder="What are you shipping today?"
                              aria-label="Post text"
                              wire:model.live.debounce.300ms="body"></textarea>

                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">{{ mb_strlen($body) }} characters</span>
                        <span class="text-xs text-muted-foreground">
                            {{ $connectedCount }} of {{ $selected->count() }} selected accounts can publish
                        </span>
                    </div>

                    <div class="border-t border-border pt-4 flex flex-wrap items-end justify-between gap-3">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label text-xs" for="publish-when">When</label>
                                <select id="publish-when" class="kt-select max-w-[180px]" wire:model.live="schedule">
                                    <option value="now">Publish now</option>
                                    <option value="later">Schedule…</option>
                                    <option value="draft">Save as draft</option>
                                </select>
                            </div>
                            @if ($schedule === 'later')
                                <div class="flex flex-col gap-1">
                                    <label class="kt-form-label text-xs" for="publish-at">Date and time</label>
                                    <input id="publish-at" type="datetime-local" class="kt-input max-w-[220px]" wire:model="scheduledAt">
                                </div>
                            @endif
                        </div>

                        <button wire:click="submit" wire:loading.attr="disabled"
                                class="kt-btn kt-btn-primary gap-2"
                                @disabled(empty($targets) || trim($body) === '')>
                            <span wire:loading.remove wire:target="submit" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-paper-plane"></i>
                                {{ $schedule === 'now' ? 'Publish' : ($schedule === 'later' ? 'Schedule' : 'Save draft') }}
                            </span>
                            <span wire:loading wire:target="submit" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Working…
                            </span>
                        </button>
                    </div>

                </div>
            </div>

            {{-- Per-account copy --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Per-network copy</h3>
                    <span class="text-xs text-muted-foreground">Fork one network without touching the rest</span>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($selected as $account)
                        @php
                            $text = $this->textFor($account->id);
                            $forked = $this->isOverridden($account->id);
                            $limit = $account->characterLimit();
                            $over = mb_strlen($text) - $limit;
                        @endphp
                        <div class="p-4 flex flex-col gap-3" wire:key="override-{{ $account->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <i class="ki-filled {{ $account->icon() }} text-base text-muted-foreground"></i>
                                    <span class="text-sm font-medium text-mono">{{ $account->label() }}</span>
                                    <span class="text-xs {{ $over > 0 ? 'text-destructive' : 'text-muted-foreground' }}">
                                        {{ mb_strlen($text) }} / {{ number_format($limit) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    @if ($forked && $over > 0)
                                        <button wire:click="trimToLimit({{ $account->id }})" class="kt-btn kt-btn-sm kt-btn-outline">
                                            Trim to fit
                                        </button>
                                    @endif
                                    <button wire:click="toggleOverride({{ $account->id }})" class="kt-btn kt-btn-sm kt-btn-ghost">
                                        {{ $forked ? 'Use shared text' : 'Customise' }}
                                    </button>
                                </div>
                            </div>

                            @if ($forked)
                                <textarea class="kt-textarea min-h-[110px] text-sm {{ $over > 0 ? 'border-destructive' : '' }}"
                                          wire:model.live.debounce.300ms="overrides.{{ $account->id }}"
                                          aria-label="{{ $account->label() }} copy"></textarea>
                            @else
                                <p class="text-xs text-muted-foreground">Follows the shared text above.</p>
                            @endif
                        </div>
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-element-11 text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Pick at least one account to post to.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>

        {{-- Targets and previews --}}
        <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Post to</h3>
                    <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Connect</a>
                </div>
                <div class="kt-card-content p-3 flex flex-col gap-1">
                    @forelse ($accounts as $account)
                        @php
                            $active = in_array($account->id, array_map('intval', $targets), true);
                            $length = mb_strlen($this->textFor($account->id));
                            $over = $length > $account->characterLimit();
                        @endphp
                        <button wire:click="toggleTarget({{ $account->id }})" wire:key="target-{{ $account->id }}"
                                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-start transition-colors
                                       {{ $active ? 'bg-primary/10' : 'hover:bg-accent/50' }}">
                            <i class="ki-filled {{ $account->icon() }} text-lg shrink-0 {{ $active ? 'text-primary' : 'text-muted-foreground' }}"></i>
                            <span class="min-w-0 grow">
                                <span class="block text-sm font-medium text-mono">{{ $account->label() }}</span>
                                <span class="block text-xs text-muted-foreground truncate">{{ $account->handle }}</span>
                                <span class="block text-xs {{ $over ? 'text-destructive' : 'text-muted-foreground' }}">
                                    {{ $account->isConnected()
                                        ? $length . ' / ' . number_format($account->characterLimit())
                                        : 'Credentials not configured' }}
                                </span>
                            </span>
                            @if ($active)
                                <i class="ki-filled ki-check-circle text-primary text-base shrink-0"></i>
                            @endif
                        </button>
                    @empty
                        <div class="flex flex-col items-center py-10 text-center">
                            <i class="ki-filled ki-abstract-26 text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">No accounts yet.</p>
                            <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3">Connect one</a>
                        </div>
                    @endforelse
                </div>
            </div>

            @foreach ($selected as $account)
                <livewire:social::post-preview
                    :key="'preview-'.$account->id"
                    :network-key="$account->network"
                    :handle="$account->handle"
                    :body="$this->textFor($account->id)"
                    :overridden="$this->isOverridden($account->id)" />
            @endforeach

            @if ($selected->isEmpty())
                <div class="kt-card">
                    <div class="kt-card-content flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-eye text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">Previews appear once you select an account.</p>
                    </div>
                </div>
            @endif

        </div>

    </div>
</div>
