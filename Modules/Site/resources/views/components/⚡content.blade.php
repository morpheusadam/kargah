<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteContent;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\WordPressSite;
use Modules\Site\Support\PostTypes;

/**
 * Everything on the site that somebody wrote, and the verbs on it.
 *
 * ## The list is never cached and never mirrored
 *
 * `SiteContent`'s docblock argues this at length; the consequence here is that
 * every filter, search and page turn is a round trip to somebody else's server,
 * and every one of them has a `wire:loading` state because it is visibly not
 * instant. That is the honest trade for never showing a stale copy of a live
 * website.
 *
 * ## Why the error is drawn in the table and not thrown
 *
 * `SiteContent` throws — a list page with no list has nothing to draw. But the
 * *page* still has a heading, a type switcher and a search box that all work,
 * and losing them because the site returned a 502 would strand the person on an
 * error screen with no way back. So the failure is caught here, at the boundary,
 * and rendered where the rows would have been.
 *
 * ## Trash, not delete
 *
 * The destructive action moves something to WordPress's own trash and says so.
 * It is where the owner will look for it, it is a better undo than anything
 * here, and a panel that permanently destroys a page on one click is a panel
 * nobody lets near their site. Emptying the trash stays a wp-admin job.
 */
new
#[Title('Content — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $type = PostTypes::POST;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'any';

    #[Url]
    public int $page = 1;

    /** The item whose trash confirmation is open, if any. */
    public ?int $confirming = null;

    /**
     * A per-request memo of the fetch. Private, so Livewire neither ships nor
     * rehydrates it — every request asks the site once and no more than once,
     * however many times the template reads it.
     *
     * @var array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}|null
     */
    private ?array $memo = null;

    public function updatedType(): void
    {
        $this->reset('page', 'confirming');
        $this->forget();
    }

    public function updatedSearch(): void
    {
        $this->reset('page');
        $this->forget();
    }

    public function updatedStatus(): void
    {
        $this->reset('page');
        $this->forget();
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->forget();
    }

    public function trash(int $id): void
    {
        $content = $this->content();

        if ($content === null) {
            return;
        }

        try {
            $content->trash($this->type, $id);
        } catch (SiteRequestFailed $e) {
            $this->toastError('It was not trashed', $e->getMessage());

            return;
        }

        $this->confirming = null;
        $this->forget();

        $this->toastSuccess(
            PostTypes::label($this->type).' moved to the trash',
            'It is still on the site and can be restored from here or from wp-admin.',
        );
    }

    public function restore(int $id): void
    {
        $content = $this->content();

        if ($content === null) {
            return;
        }

        try {
            $content->restore($this->type, $id);
        } catch (SiteRequestFailed $e) {
            $this->toastError('It was not restored', $e->getMessage());

            return;
        }

        $this->forget();

        $this->toastSuccess(
            PostTypes::label($this->type).' restored as a draft',
            'WordPress does not report what its status was before it was trashed, so it comes back unpublished.',
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
            'types' => PostTypes::all(),
            'statuses' => PostTypes::filterableStatuses(),
        ];
    }

    private function forget(): void
    {
        $this->memo = null;
    }

    private function content(): ?SiteContent
    {
        $site = WordPressSite::connected();

        return $site === null ? null : new SiteContent($site);
    }

    /**
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}
     */
    private function fetch(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $content = $this->content();

        if ($content === null) {
            return $this->memo = ['items' => [], 'total' => 0, 'pages' => 1, 'error' => null];
        }

        try {
            $result = $content->list($this->type, [
                'search' => $this->search,
                'status' => $this->status,
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
            <h1 class="text-xl font-semibold text-mono">Content</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Posts and pages on the live site, read and written over its REST API.
            </p>
        </div>
        <a href="{{ route('site.overview') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-information-2"></i> Connection
        </a>
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-notepad text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <p class="text-sm text-secondary-foreground mt-1 max-w-md">
                    Connect a WordPress site and its content appears here.
                </p>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @else

        <div class="kt-card">
            <div class="kt-card-header flex-wrap gap-3">
                <div class="flex items-center gap-1">
                    @foreach ($types as $key => $meta)
                        <button wire:click="$set('type', '{{ $key }}')"
                                class="kt-btn kt-btn-sm {{ $type === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }} gap-1.5">
                            <i class="ki-filled {{ $meta['icon'] }}"></i> {{ $meta['plural'] }}
                        </button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select wire:model.live="status" class="kt-select kt-select-sm w-[150px]">
                        <option value="any">Any status</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <input wire:model.live.debounce.300ms="search" type="search"
                           placeholder="Search {{ strtolower($types[$type]['plural'] ?? 'content') }}…"
                           class="kt-input kt-input-sm w-[220px]">

                    <span wire:loading wire:target="search,status,type,goToPage" class="text-xs text-muted-foreground">
                        <i class="ki-filled ki-loading animate-spin"></i> Asking the site…
                    </span>
                </div>
            </div>

            @if ($error)

                <div class="kt-card-content">
                    <div class="flex items-start gap-3 py-6">
                        <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-mono">The site did not return its {{ strtolower($types[$type]['plural'] ?? 'content') }}</div>
                            <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                        </div>
                    </div>
                </div>

            @else

                <div class="kt-card-table kt-scrollable-x-auto">
                    <table class="kt-table">
                        <thead>
                            <tr>
                                <th class="min-w-[280px]">Title</th>
                                <th class="w-[120px]">Status</th>
                                <th class="w-[160px]">Modified</th>
                                <th class="w-[120px]"></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($rows as $row)
                            @php($id = (int) ($row['id'] ?? 0))
                            @php($title = \Modules\Site\Services\SiteContent::text($row['title'] ?? ''))
                            @php($rowStatus = (string) ($row['status'] ?? 'draft'))
                            <tr wire:key="row-{{ $id }}">
                                <td>
                                    <div class="flex flex-col">
                                        <a href="{{ route('site.content-edit', ['type' => $type, 'id' => $id]) }}"
                                           wire:navigate class="text-sm font-medium text-mono hover:text-primary">
                                            {{ $title !== '' ? $title : '(no title)' }}
                                        </a>
                                        <span class="text-xs text-muted-foreground">/{{ $row['slug'] ?? '' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="kt-badge kt-badge-sm {{ $rowStatus === 'publish' ? 'kt-badge-success' : ($rowStatus === 'trash' ? 'kt-badge-destructive' : 'kt-badge-outline') }}">
                                        {{ $statuses[$rowStatus] ?? ucfirst($rowStatus) }}
                                    </span>
                                </td>
                                <td class="text-sm text-secondary-foreground">
                                    {{ isset($row['modified']) ? \Illuminate\Support\Carbon::parse($row['modified'])->diffForHumans() : '—' }}
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($rowStatus === 'trash')
                                            <button wire:click="restore({{ $id }})" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost">Restore</button>
                                        @elseif ($confirming === $id)
                                            <button wire:click="trash({{ $id }})" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-destructive">Confirm</button>
                                            <button wire:click="$set('confirming', null)"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                        @else
                                            <a href="{{ route('site.content-edit', ['type' => $type, 'id' => $id]) }}"
                                               wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Edit</a>
                                            <button wire:click="$set('confirming', {{ $id }})"
                                                    class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" aria-label="Trash">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="flex flex-col items-center py-12 text-center">
                                        <i class="ki-filled ki-file-sheet text-3xl text-muted-foreground mb-2"></i>
                                        <div class="text-sm font-medium text-mono">
                                            Nothing here
                                        </div>
                                        <p class="text-sm text-secondary-foreground mt-1">
                                            @if ($search !== '')
                                                The site has no {{ strtolower($types[$type]['plural'] ?? 'content') }} matching “{{ $search }}”.
                                            @else
                                                This site has no {{ strtolower($types[$type]['plural'] ?? 'content') }} in that state.
                                            @endif
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($pages > 1)
                    <div class="kt-card-footer flex items-center justify-between gap-3">
                        <span class="text-xs text-muted-foreground">
                            {{ $total }} {{ \Illuminate\Support\Str::plural('item', $total) }}, page {{ $page }} of {{ $pages }}
                        </span>
                        <div class="flex items-center gap-1">
                            <button wire:click="goToPage({{ $page - 1 }})" @disabled($page <= 1)
                                    class="kt-btn kt-btn-sm kt-btn-ghost">Previous</button>
                            <button wire:click="goToPage({{ $page + 1 }})" @disabled($page >= $pages)
                                    class="kt-btn kt-btn-sm kt-btn-ghost">Next</button>
                        </div>
                    </div>
                @endif

            @endif
        </div>

    @endif

</div>
