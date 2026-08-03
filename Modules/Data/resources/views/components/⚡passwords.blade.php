<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Models\Credential;
use Modules\Data\Models\CredentialCategory;
use Modules\Data\Services\Vault;

/**
 * The vault.
 *
 * **No secret is in this page.** Not behind a `display: none`, not in a `data-`
 * attribute, not in a `wire:model`, not in the serialised component state. The
 * table renders a fixed-width mask that is the same for a four-character
 * password and a forty-character one, so it does not even leak a length.
 *
 * A reveal is a deliberate round trip that decrypts one field of one entry and
 * writes an activity entry saying who did it and when — see
 * `Modules\Data\Services\Vault`. Once revealed, the value is in the markup,
 * which is the point; anything that changes what the list is showing clears it
 * again.
 *
 * Copying is the same round trip without the display: the value goes straight
 * from the response into the clipboard and never reaches the DOM. It is logged
 * exactly like a reveal, because a copied secret has left the vault just as
 * surely as a read one.
 */
new
#[Title('Passwords — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $search = '';

    #[Url]
    public string $category = 'all';

    /** The one entry currently in clear, and the value it is showing. */
    public ?int $revealedId = null;

    public ?string $revealedSecret = null;

    public function with(): array
    {
        $categories = CredentialCategory::query()->orderBy('position')->orderBy('name')->get();

        $credentials = Credential::query()
            ->with('category')
            ->search($this->search)
            ->when(
                is_numeric($this->category),
                fn ($query) => $query->where('category_id', (int) $this->category),
            )
            ->orderBy('name')
            ->get();

        return [
            'categories' => $categories,
            'entries' => $credentials->map(fn (Credential $credential): array => [
                'id' => $credential->id,
                'name' => $credential->name,
                'username' => $credential->username ?: '—',
                'url' => $credential->url,
                'category' => $credential->category?->name,
                'mask' => $credential->mask(),
                'has_totp' => $credential->hasTotp(),
                // A code, never the seed. It is six digits that stop working in
                // under thirty seconds, which a seed emphatically is not.
                'totp' => $credential->totpCode(),
                'updated' => $credential->updated_at?->toDateString() ?? '—',
                'last_revealed' => $credential->last_revealed_at?->diffForHumans(),
            ])->all(),
        ];
    }

    /** Any change to what the list shows re-masks the entry that was open. */
    public function updatedSearch(): void
    {
        $this->hide();
    }

    public function updatedCategory(): void
    {
        $this->hide();
    }

    public function reveal(int $id): void
    {
        if ($this->revealedId === $id) {
            $this->hide();

            return;
        }

        $credential = Credential::query()->find($id);

        if ($credential === null) {
            $this->toastError('That entry is gone', 'Someone deleted it since this page was loaded.');

            return;
        }

        $secret = app(Vault::class)->reveal($credential);

        if ($secret === null) {
            $this->toastError(
                'That secret could not be decrypted',
                'It was encrypted under a different APP_KEY. Restore the old key or re-enter the secret.'
            );

            return;
        }

        $this->revealedId = $id;
        $this->revealedSecret = $secret;

        $this->toastWarning('Secret is on screen', 'It is readable by anyone who can see this display. Hide it when you are done.');
    }

    public function hide(): void
    {
        $this->revealedId = null;
        $this->revealedSecret = null;
    }

    /**
     * Decrypt one secret and send it to the clipboard without drawing it.
     *
     * Logged like a reveal. The distinction between reading a secret and
     * copying one matters to nobody after an incident.
     */
    public function copy(int $id): void
    {
        $credential = Credential::query()->find($id);

        if ($credential === null) {
            $this->toastError('That entry is gone', 'Someone deleted it since this page was loaded.');

            return;
        }

        $secret = app(Vault::class)->reveal($credential);

        if ($secret === null) {
            $this->toastError(
                'That secret could not be decrypted',
                'It was encrypted under a different APP_KEY, so there was nothing to copy.'
            );

            return;
        }

        $this->dispatch('copy-to-clipboard', text: $secret);

        $this->toastSuccess('Secret copied', $credential->name.' is on the clipboard. The reveal is in the activity log.');
    }

    public function delete(int $id): void
    {
        $credential = Credential::query()->find($id);

        if ($credential === null) {
            return;
        }

        $name = $credential->name;
        $credential->delete();

        if ($this->revealedId === $id) {
            $this->hide();
        }

        $this->toastSuccess('Deleted '.$name, 'The row is soft deleted, so it can be brought back from the database.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Passwords</h1>
            <p class="text-sm text-secondary-foreground mt-1">Encrypted at rest, revealed one at a time, every reveal logged.</p>
        </div>
        <a href="{{ route('data.credential-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Add credential
        </a>
    </div>

    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <div class="kt-input max-w-[260px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search credentials…" aria-label="Search credentials"
                       wire:model.live.debounce.300ms="search">
            </div>
            <select class="kt-select max-w-[190px]" wire:model.live="category" aria-label="Filter by category">
                <option value="all">All categories</option>
                @foreach ($categories as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[200px]">Name</th>
                            <th class="min-w-[150px]">Username</th>
                            <th class="min-w-[200px]">Secret</th>
                            <th class="w-[110px]">Code</th>
                            <th class="w-[130px]">Category</th>
                            <th class="w-[120px]">Updated</th>
                            <th class="w-[130px] text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $e)
                            <tr wire:key="credential-{{ $e['id'] }}">
                                <td>
                                    <div class="font-medium text-mono">{{ $e['name'] }}</div>
                                    @if ($e['url'])
                                        <a href="{{ $e['url'] }}" target="_blank" rel="noopener"
                                           class="text-xs text-muted-foreground hover:text-primary">{{ $e['url'] }}</a>
                                    @endif
                                </td>
                                <td class="text-secondary-foreground">{{ $e['username'] }}</td>
                                <td>
                                    {{-- The mask is a literal, not the secret styled to look like one. --}}
                                    <code class="text-xs px-2 py-1 rounded bg-muted text-secondary-foreground break-all">{{ $revealedId === $e['id'] ? $revealedSecret : $e['mask'] }}</code>
                                </td>
                                <td>
                                    @if ($e['totp'])
                                        <code class="text-xs px-2 py-1 rounded bg-info/10 text-info tracking-widest">{{ $e['totp'] }}</code>
                                    @else
                                        <span class="text-xs text-muted-foreground">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($e['category'])
                                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $e['category'] }}</span>
                                    @else
                                        <span class="text-xs text-muted-foreground">—</span>
                                    @endif
                                </td>
                                <td class="text-secondary-foreground">
                                    {{ $e['updated'] }}
                                    @if ($e['last_revealed'])
                                        <div class="text-xs text-muted-foreground">Read {{ $e['last_revealed'] }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="flex justify-end gap-1">
                                        <button wire:click="reveal({{ $e['id'] }})" wire:loading.attr="disabled" wire:target="reveal({{ $e['id'] }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                title="{{ $revealedId === $e['id'] ? 'Hide secret' : 'Reveal secret' }}"
                                                aria-label="{{ $revealedId === $e['id'] ? 'Hide secret' : 'Reveal secret' }}">
                                            <span wire:loading.remove wire:target="reveal({{ $e['id'] }})">
                                                <i class="ki-filled {{ $revealedId === $e['id'] ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                                            </span>
                                            <span wire:loading wire:target="reveal({{ $e['id'] }})">
                                                <i class="ki-filled ki-loading animate-spin text-sm"></i>
                                            </span>
                                        </button>
                                        <button wire:click="copy({{ $e['id'] }})" wire:loading.attr="disabled" wire:target="copy({{ $e['id'] }})"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Copy secret" aria-label="Copy secret">
                                            <span wire:loading.remove wire:target="copy({{ $e['id'] }})">
                                                <i class="ki-filled ki-copy text-sm"></i>
                                            </span>
                                            <span wire:loading wire:target="copy({{ $e['id'] }})">
                                                <i class="ki-filled ki-loading animate-spin text-sm"></i>
                                            </span>
                                        </button>
                                        <button wire:click="delete({{ $e['id'] }})"
                                                wire:confirm="Delete {{ $e['name'] }} from the vault?"
                                                class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive" title="Delete" aria-label="Delete">
                                            <i class="ki-filled ki-trash text-sm"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="flex flex-col items-center py-14 text-center gap-3">
                                        <i class="ki-filled ki-lock-2 text-4xl text-muted-foreground"></i>
                                        <p class="text-sm text-secondary-foreground">
                                            {{ $search !== '' || $category !== 'all'
                                                ? 'No credential matches that filter.'
                                                : 'The vault is empty.' }}
                                        </p>
                                        <a href="{{ route('data.credential-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                                            <i class="ki-filled ki-plus"></i> Add credential
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="kt-card bg-info/5 border-info/30">
        <div class="kt-card-content flex items-start gap-3 p-4">
            <i class="ki-filled ki-shield-tick text-info text-lg mt-0.5 shrink-0"></i>
            <div class="text-sm text-secondary-foreground">
                <strong class="text-mono">Nothing above is a secret.</strong>
                The dots are a literal, the same width for every entry. A secret is decrypted only when you
                press reveal or copy, and each of those writes a line to the activity log with your name on it.
            </div>
        </div>
    </div>

    @script
    <script>
    (function () {
        // One clipboard helper for the whole application, defined once however
        // many components ask for it. `navigator.clipboard` is unavailable over
        // plain HTTP, which is exactly how this runs in development.
        if (! window.kargahCopy) {
            window.kargahCopy = function (text) {
                if (! text) return;

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text);
                    return;
                }

                var field = document.createElement('textarea');
                field.value = text;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                document.body.removeChild(field);
            };
        }

        // Talk to this component, not to a global bus: a Livewire.dispatch with
        // nobody listening vanishes without an error.
        $wire.on('copy-to-clipboard', function (payload) {
            var event = Array.isArray(payload) ? payload[0] : payload;
            window.kargahCopy(event && event.text);
        });
    })();
    </script>
    @endscript
</div>
