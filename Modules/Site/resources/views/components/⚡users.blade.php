<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteUsers;
use Modules\Site\Services\WordPressSite;

/**
 * Who can log into the website, and what each of them may do.
 *
 * One verb: change a role. Creating a user means composing somebody's password
 * here, and deleting one means deciding what happens to everything they ever
 * wrote — `SiteUsers` argues both at length. Both stay in wp-admin, and the page
 * says so rather than leaving the reader to wonder whether the buttons are
 * missing or merely not built.
 *
 * ## The guard that matters
 *
 * Demoting the last administrator locks everyone out of the site permanently:
 * nobody left can promote anybody back, not even from wp-admin, and the only
 * way out is editing the database. The check counts administrators on the page
 * in hand, which can only under-count — and under-counting is the safe
 * direction, because it refuses a change that might have been fine rather than
 * allowing one that is not survivable.
 */
new
#[Title('Site users — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public int $page = 1;

    /** The user whose role dropdown is open, if any. */
    public ?int $editing = null;

    public string $newRole = '';

    /**
     * @var array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}|null
     */
    private ?array $memo = null;

    public function updatedSearch(): void
    {
        $this->reset('page');
        $this->memo = null;
    }

    public function updatedRole(): void
    {
        $this->reset('page');
        $this->memo = null;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->memo = null;
    }

    public function edit(int $id, string $current): void
    {
        $this->editing = $id;
        $this->newRole = $current;
    }

    public function saveRole(): void
    {
        $site = WordPressSite::connected();

        if ($site === null || $this->editing === null || $this->newRole === '') {
            return;
        }

        $rows = $this->fetch()['items'];

        if (SiteUsers::wouldRemoveLastAdministrator($rows, $this->editing, $this->newRole)) {
            $this->toastError(
                'That would lock everyone out',
                'This is the only administrator Kargah can see. Demoting the last one leaves nobody able to promote '
                .'anybody back, including from wp-admin. Make somebody else an administrator first.',
            );

            return;
        }

        try {
            (new SiteUsers($site))->setRole($this->editing, $this->newRole);
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site refused it', $e->getMessage());

            return;
        }

        $this->editing = null;
        $this->memo = null;

        $this->toastSuccess('Role changed', 'It takes effect the next time they load a page on the site.');
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
            'roles' => SiteUsers::rolesFound($result['items']),
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
            $result = (new SiteUsers($site))->list([
                'search' => $this->search,
                'role' => $this->role,
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
            <h1 class="text-xl font-semibold text-mono">Site users</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Who can log into the website, and what each of them may do.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="role" class="kt-select kt-select-sm w-[160px]">
                <option value="">Every role</option>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search people…"
                   class="kt-input kt-input-sm w-[200px]">
        </div>
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-people text-4xl text-muted-foreground mb-3"></i>
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
                    <div class="text-sm font-medium text-mono">The site did not return its users</div>
                    <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                    <p class="text-xs text-muted-foreground mt-2">
                        Listing users with their roles needs list_users, which in practice means an administrator.
                    </p>
                </div>
            </div>
        </div>

    @else

        <div class="kt-card">
            <div class="kt-card-table kt-scrollable-x-auto">
                <table class="kt-table">
                    <thead>
                        <tr>
                            <th class="min-w-[220px]">Name</th>
                            <th class="w-[220px]">Email</th>
                            <th class="w-[200px]">Role</th>
                            <th class="w-[180px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($rows as $row)
                        @php($id = (int) ($row['id'] ?? 0))
                        @php($current = (string) (((array) ($row['roles'] ?? []))[0] ?? ''))
                        <tr wire:key="user-{{ $id }}">
                            <td>
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-mono">{{ $row['name'] ?? '—' }}</span>
                                    <span class="text-xs text-muted-foreground">{{ $row['slug'] ?? '' }}</span>
                                </div>
                            </td>
                            <td class="text-sm text-secondary-foreground">{{ $row['email'] ?? '—' }}</td>
                            <td>
                                @if ($editing === $id)
                                    <select wire:model="newRole" class="kt-select kt-select-sm">
                                        @foreach ($roles as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="kt-badge kt-badge-sm {{ $current === 'administrator' ? 'kt-badge-primary' : 'kt-badge-outline' }}">
                                        {{ $roles[$current] ?? ($current !== '' ? $current : 'none') }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    @if ($editing === $id)
                                        <button wire:click="saveRole" wire:loading.attr="disabled"
                                                class="kt-btn kt-btn-sm kt-btn-primary">Save</button>
                                        <button wire:click="$set('editing', null)"
                                                class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                    @else
                                        <button wire:click="edit({{ $id }}, @js($current))"
                                                class="kt-btn kt-btn-sm kt-btn-ghost">Change role</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="flex flex-col items-center py-12 text-center">
                                    <i class="ki-filled ki-people text-3xl text-muted-foreground mb-2"></i>
                                    <div class="text-sm font-medium text-mono">Nobody here</div>
                                    <p class="text-sm text-secondary-foreground mt-1">
                                        @if ($search !== '' || $role !== '')
                                            Nobody matches that.
                                        @else
                                            The site returned no users at all, which usually means the credential
                                            cannot list them.
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
                        {{ $total }} {{ \Illuminate\Support\Str::plural('person', $total) }}, page {{ $page }} of {{ $pages }}
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

        <div class="kt-card">
            <div class="kt-card-header">
                <h2 class="kt-card-title">Not offered here, and why</h2>
            </div>
            <div class="kt-card-content flex flex-col gap-3">
                <div class="flex items-start gap-2 text-sm">
                    <i class="ki-filled ki-minus-circle text-muted-foreground mt-0.5"></i>
                    <div>
                        <span class="text-mono">Adding a person</span>
                        <span class="text-secondary-foreground">
                            — it means composing their password here and sending it. WordPress already has a better
                            way: invite them from wp-admin and they set their own, which Kargah never sees.
                        </span>
                    </div>
                </div>
                <div class="flex items-start gap-2 text-sm">
                    <i class="ki-filled ki-minus-circle text-muted-foreground mt-0.5"></i>
                    <div>
                        <span class="text-mono">Deleting a person</span>
                        <span class="text-secondary-foreground">
                            — the API requires you to decide what happens to everything they ever wrote, and getting
                            it wrong deletes their posts rather than reassigning them. That belongs behind wp-admin's
                            own screen, which spells the choice out.
                        </span>
                    </div>
                </div>
            </div>
        </div>

    @endif

</div>
