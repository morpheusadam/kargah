<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteTaxonomy;
use Modules\Site\Services\WordPressSite;

/**
 * The site's categories and tags, sorted by how much they are actually used.
 *
 * ## Most-used first, and unused terms are not hidden
 *
 * Alphabetical order tells you nothing about a site. Order by use and the top
 * tells you what it is about while the bottom tells you what went wrong — the
 * tag somebody mistyped once, the category left behind by a section that was
 * abandoned. Both ends are useful and the bottom is the actionable one, which
 * is why `hide_empty` is never sent.
 *
 * ## Deleting a term does not delete the writing
 *
 * This is stated in the confirmation rather than left to be discovered. "Delete
 * category" reads to most people as though it might take the posts with it; it
 * does not, WordPress detaches them and a post left with no category falls back
 * to the default one. Somebody who knows that deletes the dead category they
 * have been avoiding for a year.
 */
new
#[Title('Categories and tags — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $taxonomy = SiteTaxonomy::CATEGORY;

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    #[Validate('nullable|string|max:200')]
    public string $newName = '';

    public ?int $confirming = null;

    public ?int $editing = null;

    public string $editName = '';

    /**
     * @var array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}|null
     */
    private ?array $memo = null;

    public function updatedTaxonomy(): void
    {
        $this->reset('page', 'confirming', 'editing');
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

    public function add(): void
    {
        $this->validate();

        $site = WordPressSite::connected();
        $name = trim($this->newName);

        if ($site === null || $name === '') {
            $this->toastError('Nothing to add', 'Give the new '.strtolower(SiteTaxonomy::label($this->taxonomy)).' a name.');

            return;
        }

        try {
            (new SiteTaxonomy($site))->create($this->taxonomy, ['name' => $name]);
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site did not create it', $e->getMessage());

            return;
        }

        $this->reset('newName');
        $this->memo = null;

        $this->toastSuccess(SiteTaxonomy::label($this->taxonomy).' created', '“'.$name.'” is now on the site.');
    }

    public function edit(int $id, string $current): void
    {
        $this->editing = $id;
        $this->editName = $current;
    }

    public function rename(): void
    {
        $site = WordPressSite::connected();

        if ($site === null || $this->editing === null || trim($this->editName) === '') {
            return;
        }

        try {
            (new SiteTaxonomy($site))->update($this->taxonomy, $this->editing, ['name' => trim($this->editName)]);
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site refused the rename', $e->getMessage());

            return;
        }

        $this->editing = null;
        $this->memo = null;

        $this->toastSuccess(
            'Renamed',
            'The slug is unchanged, so nothing that links to its archive page has broken.',
        );
    }

    public function delete(int $id): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            return;
        }

        try {
            (new SiteTaxonomy($site))->delete($this->taxonomy, $id);
        } catch (SiteRequestFailed $e) {
            $this->toastError('It was not deleted', $e->getMessage());

            return;
        }

        $this->confirming = null;
        $this->memo = null;

        $this->toastSuccess(
            SiteTaxonomy::label($this->taxonomy).' deleted',
            'Everything filed under it is still on the site; WordPress detached it rather than removing it.',
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
            'taxonomies' => SiteTaxonomy::all(),
            'thin' => SiteTaxonomy::thin($result['items']),
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
            $result = (new SiteTaxonomy($site))->list($this->taxonomy, ['search' => $this->search], $this->page);

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
            <h1 class="text-xl font-semibold text-mono">Categories and tags</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Ordered by how much the site actually uses them, so the dead ones are visible.
            </p>
        </div>
        <div class="flex items-center gap-1">
            @foreach ($taxonomies as $key => $meta)
                <button wire:click="$set('taxonomy', '{{ $key }}')"
                        class="kt-btn kt-btn-sm {{ $taxonomy === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }} gap-1.5">
                    <i class="ki-filled {{ $meta['icon'] }}"></i> {{ $meta['plural'] }}
                </button>
            @endforeach
        </div>
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-folder text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @else

        <div class="kt-card">
            <div class="kt-card-header flex-wrap gap-3">
                <h2 class="kt-card-title">
                    Add a {{ strtolower(\Modules\Site\Services\SiteTaxonomy::label($taxonomy)) }}
                </h2>
                @if ($thin['unused'] > 0 || $thin['usedOnce'] > 0)
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($thin['unused'] > 0)
                            <span class="kt-badge kt-badge-sm kt-badge-warning">
                                {{ $thin['unused'] }} used by nothing
                            </span>
                        @endif
                        @if ($thin['usedOnce'] > 0)
                            <span class="kt-badge kt-badge-sm kt-badge-outline">
                                {{ $thin['usedOnce'] }} used once
                            </span>
                        @endif
                    </div>
                @endif
            </div>
            <div class="kt-card-content flex flex-wrap items-end gap-3">
                <div class="min-w-[240px] grow">
                    <label class="kt-form-label" for="new-term">Name</label>
                    <input id="new-term" wire:model="newName" type="text" class="kt-input"
                           placeholder="{{ $taxonomy === 'category' ? 'Release notes' : 'android' }}">
                    @error('newName')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                </div>
                <button wire:click="add" wire:loading.attr="disabled" class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="add" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-plus"></i> Create on the site
                    </span>
                    <span wire:loading wire:target="add" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Creating…
                    </span>
                </button>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header flex-wrap gap-3">
                <h2 class="kt-card-title">{{ $taxonomies[$taxonomy]['plural'] ?? 'Terms' }}</h2>
                <div class="flex items-center gap-2">
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search…"
                           class="kt-input kt-input-sm w-[200px]">
                    <span wire:loading wire:target="search,taxonomy,goToPage" class="text-xs text-muted-foreground">
                        <i class="ki-filled ki-loading animate-spin"></i> Asking the site…
                    </span>
                </div>
            </div>

            @if ($error)
                <div class="kt-card-content">
                    <div class="flex items-start gap-3 py-6">
                        <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-mono">The site did not return its terms</div>
                            <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                        </div>
                    </div>
                </div>
            @else
                <div class="kt-card-table kt-scrollable-x-auto">
                    <table class="kt-table">
                        <thead>
                            <tr>
                                <th class="min-w-[240px]">Name</th>
                                <th class="w-[160px]">Slug</th>
                                <th class="w-[100px]">Used by</th>
                                <th class="w-[220px]"></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($rows as $row)
                            @php($id = (int) ($row['id'] ?? 0))
                            @php($name = (string) ($row['name'] ?? ''))
                            @php($count = (int) ($row['count'] ?? 0))
                            <tr wire:key="term-{{ $id }}">
                                <td>
                                    @if ($editing === $id)
                                        <input wire:model="editName" type="text" class="kt-input kt-input-sm">
                                    @else
                                        <span class="text-sm font-medium text-mono">{{ $name }}</span>
                                    @endif
                                </td>
                                <td class="text-sm text-muted-foreground">{{ $row['slug'] ?? '' }}</td>
                                <td>
                                    <span class="kt-badge kt-badge-sm {{ $count === 0 ? 'kt-badge-warning' : 'kt-badge-outline' }}">
                                        {{ $count }} {{ \Illuminate\Support\Str::plural('post', $count) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($editing === $id)
                                            <button wire:click="rename" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-primary">Save</button>
                                            <button wire:click="$set('editing', null)"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                        @elseif ($confirming === $id)
                                            <span class="text-xs text-secondary-foreground me-1">
                                                Posts stay, the {{ strtolower(\Modules\Site\Services\SiteTaxonomy::label($taxonomy)) }} goes.
                                            </span>
                                            <button wire:click="delete({{ $id }})" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-destructive">Delete</button>
                                            <button wire:click="$set('confirming', null)"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                        @else
                                            <button wire:click="edit({{ $id }}, @js($name))"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost">Rename</button>
                                            <button wire:click="$set('confirming', {{ $id }})"
                                                    class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" aria-label="Delete">
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
                                        <i class="ki-filled ki-folder text-3xl text-muted-foreground mb-2"></i>
                                        <div class="text-sm font-medium text-mono">Nothing here</div>
                                        <p class="text-sm text-secondary-foreground mt-1">
                                            @if ($search !== '')
                                                Nothing matches “{{ $search }}”.
                                            @else
                                                Create one above and it appears here.
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
            @endif
        </div>

    @endif

</div>
