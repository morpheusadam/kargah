<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteComments;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\WordPressSite;

/**
 * The moderation queue.
 *
 * ## It opens on what is waiting
 *
 * Every other page in this module opens on everything, because a content list
 * that hid drafts would be a list somebody loses work in. This one is the
 * opposite: a comment on hold is invisible to the person who wrote it until
 * somebody acts, so the default view is the one with a job in it. Everything
 * else is one dropdown away.
 *
 * ## Spam and trash stay separate
 *
 * WordPress distinguishes them and collapsing the two into one button would
 * make the site's spam filter worse over time, quietly, in a way nobody would
 * trace back to this panel. `SiteComments` argues it at length.
 *
 * ## The comment body is never rendered as HTML
 *
 * 🔴 A moderation queue holds untrusted text written by strangers — it is the
 * one screen in this application whose entire content is hostile input by
 * definition. `SiteComments::excerpt()` strips tags, decodes entities and
 * strips again, and Blade escapes what is left. Printing `content.rendered`
 * here would mean executing the exact thing this page exists to let somebody
 * reject.
 */
new
#[Title('Comments — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $status = 'hold';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    /**
     * @var array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}|null
     */
    private ?array $memo = null;

    public function updatedStatus(): void
    {
        $this->reset('page');
        $this->memo = null;
    }

    public function updatedSearch(): void
    {
        $this->reset('page');
        $this->memo = null;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->memo = null;
    }

    public function moveTo(int $id, string $status): void
    {
        $site = WordPressSite::connected();

        if ($site === null || ! array_key_exists($status, SiteComments::statuses())) {
            return;
        }

        try {
            (new SiteComments($site))->setStatus($id, $status);
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site refused it', $e->getMessage());

            return;
        }

        $this->memo = null;

        $this->toastSuccess(
            'Moved to '.strtolower(SiteComments::statuses()[$status]),
            match ($status) {
                'approve' => 'It is visible on the site now.',
                'spam' => 'Marking it as spam teaches the site\'s filter, which trashing it would not.',
                'trash' => 'It is recoverable from the trash in wp-admin.',
                default => 'It is hidden from the site until it is approved.',
            },
        );
    }

    public function with(): array
    {
        $result = $this->fetch();

        return [
            'site' => WordPressSite::connected(),
            'rows' => $result['items'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'error' => $result['error'],
            'filters' => SiteComments::filters(),
            'statuses' => SiteComments::statuses(),
            'waiting' => SiteComments::waiting($result['items']),
        ];
    }

    /**
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}
     */
    private function fetch(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $site = WordPressSite::connected();

        if ($site === null) {
            return $this->memo = ['items' => [], 'total' => 0, 'pages' => 1, 'error' => null];
        }

        try {
            $result = (new SiteComments($site))->list([
                'status' => $this->status,
                'search' => $this->search,
            ], $this->page);

            return $this->memo = $result + ['error' => null];
        } catch (SiteRequestFailed $e) {
            return $this->memo = ['items' => [], 'total' => 0, 'pages' => 1, 'error' => $e->getMessage()];
        }
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-mono">Comments</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                What is waiting on you. A held comment is invisible to whoever wrote it until it is approved.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="status" class="kt-select kt-select-sm w-[150px]">
                @foreach ($filters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search comments…"
                   class="kt-input kt-input-sm w-[200px]">
        </div>
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-messages text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @elseif ($error)

        <div class="kt-card border-destructive/30">
            <div class="kt-card-content flex items-start gap-3 py-8">
                <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                <div class="min-w-0">
                    <div class="text-sm font-medium text-mono">The site did not return its comments</div>
                    <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                </div>
            </div>
        </div>

    @else

        <div class="kt-card">
            <div class="kt-card-header flex-wrap gap-3">
                <h2 class="kt-card-title">{{ $filters[$status] ?? 'Comments' }}</h2>
                <div class="flex items-center gap-2">
                    @if ($waiting > 0)
                        <span class="kt-badge kt-badge-sm kt-badge-warning">
                            {{ $waiting }} waiting on this page
                        </span>
                    @endif
                    <span wire:loading wire:target="status,search,goToPage,moveTo" class="text-xs text-muted-foreground">
                        <i class="ki-filled ki-loading animate-spin"></i> Asking the site…
                    </span>
                </div>
            </div>

            <div class="kt-card-content flex flex-col gap-3">
                @forelse ($rows as $row)
                    @php($id = (int) ($row['id'] ?? 0))
                    @php($rowStatus = \Modules\Site\Services\SiteComments::normalise((string) ($row['status'] ?? '')))
                    <div wire:key="comment-{{ $id }}" class="border border-border rounded-lg p-3 flex flex-col gap-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-sm font-medium text-mono truncate">
                                    {{ $row['author_name'] ?? 'Anonymous' }}
                                </span>
                                <span class="kt-badge kt-badge-sm {{ $rowStatus === 'approve' ? 'kt-badge-success' : ($rowStatus === 'spam' || $rowStatus === 'trash' ? 'kt-badge-destructive' : 'kt-badge-warning') }}">
                                    {{ $statuses[$rowStatus] ?? ucfirst($rowStatus) }}
                                </span>
                            </div>
                            <span class="text-xs text-muted-foreground">
                                {{ isset($row['date_gmt']) ? \Illuminate\Support\Carbon::parse($row['date_gmt'])->diffForHumans() : '' }}
                            </span>
                        </div>

                        {{-- Text, never markup. See the class docblock. --}}
                        <p class="text-sm text-secondary-foreground">
                            {{ \Modules\Site\Services\SiteComments::excerpt($row) }}
                        </p>

                        <div class="flex flex-wrap items-center gap-1">
                            @if (is_string($row['link'] ?? null))
                                <a href="{{ $row['link'] }}" target="_blank" rel="noopener"
                                   class="text-xs text-muted-foreground hover:text-primary me-2">In context</a>
                            @endif

                            @if ($rowStatus !== 'approve')
                                <button wire:click="moveTo({{ $id }}, 'approve')" wire:loading.attr="disabled"
                                        class="kt-btn kt-btn-sm kt-btn-primary">Approve</button>
                            @else
                                <button wire:click="moveTo({{ $id }}, 'hold')" wire:loading.attr="disabled"
                                        class="kt-btn kt-btn-sm kt-btn-ghost">Unapprove</button>
                            @endif

                            @if ($rowStatus !== 'spam')
                                <button wire:click="moveTo({{ $id }}, 'spam')" wire:loading.attr="disabled"
                                        class="kt-btn kt-btn-sm kt-btn-ghost">Spam</button>
                            @endif

                            @if ($rowStatus !== 'trash')
                                <button wire:click="moveTo({{ $id }}, 'trash')" wire:loading.attr="disabled"
                                        class="kt-btn kt-btn-sm kt-btn-ghost">Trash</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-check-circle text-3xl {{ $status === 'hold' ? 'text-success' : 'text-muted-foreground' }} mb-2"></i>
                        <div class="text-sm font-medium text-mono">
                            {{ $status === 'hold' ? 'Nothing is waiting' : 'Nothing here' }}
                        </div>
                        <p class="text-sm text-secondary-foreground mt-1">
                            @if ($search !== '')
                                Nothing matches “{{ $search }}”.
                            @elseif ($status === 'hold')
                                Every comment on the site has been dealt with.
                            @else
                                The site has no comments in that state.
                            @endif
                        </p>
                    </div>
                @endforelse
            </div>

            @if ($pages > 1)
                <div class="kt-card-footer flex items-center justify-between gap-3">
                    <span class="text-xs text-muted-foreground">
                        {{ $total }} total, page {{ $page }} of {{ $pages }}
                    </span>
                    <div class="flex items-center gap-1">
                        <button wire:click="goToPage({{ $page - 1 }})" @disabled($page <= 1)
                                class="kt-btn kt-btn-sm kt-btn-ghost">Previous</button>
                        <button wire:click="goToPage({{ $page + 1 }})" @disabled($page >= $pages)
                                class="kt-btn kt-btn-sm kt-btn-ghost">Next</button>
                    </div>
                </div>
            @endif
        </div>

    @endif

</div>
