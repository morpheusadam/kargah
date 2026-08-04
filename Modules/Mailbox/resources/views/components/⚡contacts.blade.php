<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Models\Contact;
use Modules\Mailbox\Models\Suppression;

/**
 * Contacts, grouped by the tags an import gave them.
 *
 * A list is a tag rather than a table, so a contact belongs to as many as they
 * were imported into and nothing has to be moved to change that.
 *
 * The status column reads from two places on purpose. `is_subscribed` is what
 * this person asked for; the suppression list is what a provider reported, is
 * global, and outranks it. An address can be subscribed here and still blocked,
 * and showing only one of the two is how somebody comes to believe a hard
 * bounce is still mailable.
 */
new
#[Title('Contacts — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Url]
    public string $activeList = 'all';

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectList(string $key): void
    {
        $this->activeList = $key;
        $this->resetPage();
    }

    public function with(): array
    {
        $contacts = Contact::query()
            ->when($this->activeList !== 'all', fn ($q) => $q->tagged($this->activeList))
            ->search($this->search)
            ->inReadingOrder()
            ->paginate(25);

        return [
            'lists' => Contact::tagCounts(),
            'total' => Contact::query()->count(),
            'contacts' => $contacts,
            // One query for the whole page rather than an existence check per
            // row, which is what a 25-row table would otherwise cost.
            'blocked' => Suppression::among($contacts->pluck('email')->all()),
            'suppressedTotal' => Suppression::query()->count(),
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Contacts</h1>
            <p class="text-sm text-secondary-foreground mt-1">Lists, subscribers and the suppression file.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('mail.contact-import') }}" class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-file-up"></i> Import CSV
            </a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-3">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Lists</h3></div>
                <div class="kt-card-content p-2 flex flex-col gap-0.5">
                    <button wire:click="selectList('all')"
                            class="kt-btn kt-btn-ghost justify-between w-full {{ $activeList === 'all' ? 'bg-accent/60 text-primary' : '' }}">
                        <span class="truncate text-sm">Everyone</span>
                        <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">{{ $total }}</span>
                    </button>

                    @forelse ($lists as $tag => $count)
                        <button wire:click="selectList('{{ $tag }}')" wire:key="list-{{ $tag }}"
                                class="kt-btn kt-btn-ghost justify-between w-full {{ $activeList === $tag ? 'bg-accent/60 text-primary' : '' }}">
                            <span class="truncate text-sm">{{ $tag }}</span>
                            <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">{{ $count }}</span>
                        </button>
                    @empty
                        <p class="text-xs text-muted-foreground px-3 py-4 text-center">
                            No lists yet. An import puts contacts into one.
                        </p>
                    @endforelse
                </div>
            </div>

            <div class="kt-card mt-5">
                <div class="kt-card-content p-4">
                    <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                        <i class="ki-filled ki-shield-cross text-destructive"></i>
                        Suppression list
                    </div>
                    <p class="text-xs text-muted-foreground mt-2">
                        Hard bounces and complaints land here and are blocked across every provider.
                        {{ $suppressedTotal }} {{ str('address')->plural($suppressedTotal) }} currently blocked.
                    </p>
                    <a href="{{ route('mail.suppression') }}" class="kt-btn kt-btn-sm kt-btn-outline w-full justify-center mt-3">View suppressed</a>
                </div>
            </div>
        </div>

        <div class="col-span-12 lg:col-span-9">
            <div class="kt-card">
                <div class="kt-card-header">
                    <div class="kt-input max-w-[260px]">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input type="text" placeholder="Search contacts…" wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[240px]">Email</th>
                                    <th class="min-w-[160px]">Name</th>
                                    <th class="w-[150px]">Status</th>
                                    <th class="w-[130px]">Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($contacts as $c)
                                    @php $block = $blocked[\Modules\Mailbox\Models\Suppression::normalise($c->email)] ?? null; @endphp
                                    <tr wire:key="contact-{{ $c->id }}" wire:loading.class="opacity-50" wire:target="search,selectList">
                                        <td>
                                            <div class="font-medium text-mono">{{ $c->email }}</div>
                                            @if ($c->company_name)
                                                <div class="text-xs text-muted-foreground">{{ $c->company_name }}</div>
                                            @endif
                                        </td>
                                        <td class="text-secondary-foreground">{{ $c->name ?: '—' }}</td>
                                        <td>
                                            @if ($block)
                                                <span class="kt-badge kt-badge-sm {{ $block->badge() }}"
                                                      title="{{ $block->detail }}">{{ $block->reasonLabel() }}</span>
                                            @elseif ($c->is_subscribed)
                                                <span class="kt-badge kt-badge-sm kt-badge-success">Subscribed</span>
                                            @else
                                                <span class="kt-badge kt-badge-sm kt-badge-outline">Unsubscribed</span>
                                            @endif
                                        </td>
                                        <td class="text-secondary-foreground">{{ $c->created_at?->format('Y-m-d') ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="flex flex-col items-center justify-center text-center py-14">
                                                <i class="ki-filled ki-users text-4xl text-muted-foreground mb-3"></i>
                                                <p class="text-sm text-secondary-foreground">
                                                    @if (trim($search) !== '')
                                                        No contact matches that search.
                                                    @else
                                                        No contacts in this list yet.
                                                    @endif
                                                </p>
                                                <a href="{{ route('mail.contact-import') }}" class="kt-btn kt-btn-primary gap-2 mt-4">
                                                    <i class="ki-filled ki-file-up"></i> Import CSV
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($contacts->hasPages())
                    <div class="kt-card-footer">{{ $contacts->links() }}</div>
                @endif
            </div>
        </div>

    </div>
</div>
