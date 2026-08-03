<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Models\Credential;
use Modules\Data\Models\CredentialCategory;
use Modules\Data\Services\Vault;
use Modules\Data\Support\Totp;

/**
 * Adding an entry to the vault.
 *
 * The one page in Data where a secret is legitimately in the markup: the person
 * filling this in typed it, and it is on their screen either way. The field is
 * masked by default all the same, because "on their screen" and "readable by
 * whoever is standing behind them" are different things.
 *
 * `save()` writes through the virtual `secret`, `totp` and `notes` attributes,
 * so the plaintext is encrypted on the way into the row and never exists as a
 * column. After the redirect the value is gone from the server entirely — the
 * list page cannot show it again without a deliberate, logged reveal.
 */
new
#[Title('New credential — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('nullable|string|max:190')]
    public string $username = '';

    #[Validate('required|string|max:255')]
    public string $secret = '';

    #[Validate('nullable|url|max:500')]
    public string $url = '';

    /**
     * Held as a string because that is what a `<select>` sends.
     *
     * A typed `?int` property turns the "Uncategorised" option's empty string
     * into 0, and 0 is not a category id — it is a foreign key violation
     * waiting for someone to press save.
     */
    #[Validate('nullable|integer|exists:credential_categories,id')]
    public ?string $categoryId = null;

    #[Validate('nullable|string|max:64')]
    public string $totp = '';

    #[Validate('nullable|string|max:2000')]
    public string $notes = '';

    /** Reveal state for the two masked fields. */
    public bool $secretRevealed = false;

    public bool $totpRevealed = false;

    /** Generator settings. */
    public int $length = 20;

    public bool $useUpper = true;

    public bool $useDigits = true;

    public bool $useSymbols = true;

    public bool $avoidAmbiguous = true;

    public function mount(): void
    {
        $id = CredentialCategory::query()->orderBy('position')->value('id');

        $this->categoryId = $id === null ? null : (string) $id;
    }

    public function with(): array
    {
        return [
            'categories' => CredentialCategory::query()->orderBy('position')->orderBy('name')->get(),
            'strength' => app(Vault::class)->strength($this->secret),
            // Checked as you type, because a mistyped seed produces no code at
            // all and finding that out a month later is a locked account.
            'totpValid' => $this->totp === '' ? null : Totp::isValidSeed($this->totp),
        ];
    }

    public function toggleSecret(): void
    {
        $this->secretRevealed = ! $this->secretRevealed;
    }

    public function toggleTotp(): void
    {
        $this->totpRevealed = ! $this->totpRevealed;
    }

    /** Build a random secret from the generator settings. */
    public function generateSecret(): void
    {
        $this->secret = app(Vault::class)->generate(
            $this->length,
            $this->useUpper,
            $this->useDigits,
            $this->useSymbols,
            $this->avoidAmbiguous,
        );

        $this->secretRevealed = true;

        // Length only — the generated value never goes into a toast.
        $this->toastSuccess(
            'Secret generated',
            mb_strlen($this->secret).' characters, visible in the field. Nothing is stored until you save.'
        );
    }

    public function save(): void
    {
        $this->validate();

        if ($this->totp !== '' && ! Totp::isValidSeed($this->totp)) {
            $this->addError('totp', 'That is not a valid base32 seed, so no code could ever be generated from it.');

            return;
        }

        $credential = Credential::query()->create([
            'name' => $this->name,
            'username' => $this->username ?: null,
            'secret' => $this->secret,
            'totp' => $this->totp ?: null,
            'notes' => $this->notes ?: null,
            'url' => $this->url ?: null,
            'category_id' => $this->categoryId === null || $this->categoryId === '' ? null : (int) $this->categoryId,
            'created_by' => auth()->id(),
            'rotated_at' => now(),
        ]);

        // Flashed rather than dispatched: the redirect would take a dispatched
        // toast with the old page.
        $this->flashToast(
            'success',
            'Saved '.$credential->name,
            'The secret is encrypted with the application key. Revealing it again will be logged.'
        );

        $this->redirectRoute('data.passwords', navigate: true);
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('data.passwords') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                <i class="ki-filled ki-left text-xs"></i> Passwords
            </a>
            <h1 class="text-xl font-semibold text-mono mt-1">New credential</h1>
            <p class="text-sm text-secondary-foreground mt-1">Add a login to the vault so it stops living in a notes app.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('data.passwords') }}" wire:navigate class="kt-btn kt-btn-outline">Cancel</a>
            <button wire:click="save" wire:loading.attr="disabled" wire:target="save" class="kt-btn kt-btn-primary gap-2">
                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-check"></i> Save credential
                </span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
        </div>
    </div>

    <div class="kt-card bg-info/5 border-info/30">
        <div class="kt-card-content flex items-start gap-3 p-4">
            <i class="ki-filled ki-lock-2 text-info text-lg mt-0.5 shrink-0"></i>
            <div class="text-sm text-secondary-foreground">
                <strong class="text-mono">Encrypted with the application key.</strong>
                The secret and the TOTP seed are encrypted before they reach the database and are decrypted
                only for a single reveal, which is logged. They are never written into a page, a log or an
                activity entry in plain text.
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        <div class="xl:col-span-2 flex flex-col gap-5">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Credential</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="cred-name">Name</label>
                            <input id="cred-name" type="text" class="kt-input @error('name') border-destructive @enderror"
                                   placeholder="Hostinger hPanel" wire:model="name">
                            @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col">
                            <label class="kt-form-label" for="cred-username">Username</label>
                            <input id="cred-username" type="text" class="kt-input @error('username') border-destructive @enderror"
                                   placeholder="morph" autocomplete="off" wire:model="username">
                            @error('username')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    {{-- Secret --}}
                    <div class="flex flex-col">
                        <label class="kt-form-label" for="cred-secret">Secret</label>

                        <div class="flex items-center gap-2">
                            <input id="cred-secret" type="{{ $secretRevealed ? 'text' : 'password' }}"
                                   class="kt-input grow @error('secret') border-destructive @enderror"
                                   placeholder="Type one, or generate it below" autocomplete="new-password"
                                   wire:model.live.debounce.500ms="secret">
                            <button type="button" wire:click="toggleSecret" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0"
                                    title="{{ $secretRevealed ? 'Hide secret' : 'Reveal secret' }}"
                                    aria-label="{{ $secretRevealed ? 'Hide secret' : 'Reveal secret' }}">
                                <i class="ki-filled {{ $secretRevealed ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                            </button>
                            <button type="button" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0"
                                    data-copy-from="#cred-secret" title="Copy secret" aria-label="Copy secret">
                                <i class="ki-filled ki-copy text-sm"></i>
                            </button>
                        </div>
                        @error('secret')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror

                        {{-- Strength meter --}}
                        <div class="mt-2.5">
                            <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                                <div class="h-full rounded-full transition-all {{ $strength['tone'] }}" style="width: {{ $strength['width'] }}%"></div>
                            </div>
                            <div class="flex items-center justify-between mt-1.5 text-xs">
                                <span class="{{ $strength['text'] }}">{{ $strength['label'] }}</span>
                                <span class="text-muted-foreground">Aim for 20 characters or more</span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="cred-url">URL</label>
                            <input id="cred-url" type="url" class="kt-input @error('url') border-destructive @enderror"
                                   placeholder="https://hpanel.hostinger.com" wire:model="url">
                            @error('url')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col">
                            <label class="kt-form-label" for="cred-category">Category</label>
                            <select id="cred-category" class="kt-select @error('categoryId') border-destructive @enderror" wire:model="categoryId">
                                <option value="">Uncategorised</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                            @error('categoryId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    {{-- TOTP --}}
                    <div class="flex flex-col">
                        <label class="kt-form-label" for="cred-totp">TOTP seed</label>
                        <div class="flex items-center gap-2">
                            <input id="cred-totp" type="{{ $totpRevealed ? 'text' : 'password' }}"
                                   class="kt-input grow @error('totp') border-destructive @enderror"
                                   placeholder="Base32 seed from the provider" autocomplete="off"
                                   wire:model.live.debounce.500ms="totp">
                            <button type="button" wire:click="toggleTotp" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0"
                                    title="{{ $totpRevealed ? 'Hide TOTP seed' : 'Reveal TOTP seed' }}"
                                    aria-label="{{ $totpRevealed ? 'Hide TOTP seed' : 'Reveal TOTP seed' }}">
                                <i class="ki-filled {{ $totpRevealed ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                            </button>
                        </div>
                        @if ($totpValid === false)
                            <span class="text-xs text-destructive mt-1">That is not valid base32, so no code can be derived from it.</span>
                        @elseif ($totpValid === true)
                            <span class="text-xs text-success mt-1">Valid seed. The vault will show a rolling six-digit code beside this entry.</span>
                        @else
                            <span class="text-xs text-muted-foreground mt-1">Optional. With a seed saved, the vault shows a rolling six-digit code next to the entry.</span>
                        @endif
                        @error('totp')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="cred-notes">Notes</label>
                        <textarea id="cred-notes" rows="4" class="kt-textarea @error('notes') border-destructive @enderror"
                                  placeholder="Recovery email, account number, who to ring when it locks."
                                  wire:model="notes"></textarea>
                        <span class="text-xs text-muted-foreground mt-1">Encrypted too. Notes beside a password are usually a second password.</span>
                        @error('notes')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-5">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Generator</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm text-mono">Length</span>
                            <span class="text-sm font-medium text-primary">{{ $length }}</span>
                        </div>
                        <input type="range" min="8" max="64" step="1" class="w-full accent-primary"
                               wire:model.live="length" aria-label="Password length">
                    </div>

                    <div class="flex flex-col gap-2.5">
                        <label class="flex items-center justify-between gap-3 text-sm text-secondary-foreground">
                            Uppercase letters
                            <span class="kt-switch"><input type="checkbox" wire:model.live="useUpper"></span>
                        </label>
                        <label class="flex items-center justify-between gap-3 text-sm text-secondary-foreground">
                            Digits
                            <span class="kt-switch"><input type="checkbox" wire:model.live="useDigits"></span>
                        </label>
                        <label class="flex items-center justify-between gap-3 text-sm text-secondary-foreground">
                            Symbols
                            <span class="kt-switch"><input type="checkbox" wire:model.live="useSymbols"></span>
                        </label>
                        <label class="flex items-center justify-between gap-3 text-sm text-secondary-foreground">
                            Avoid look-alikes
                            <span class="kt-switch"><input type="checkbox" wire:model.live="avoidAmbiguous"></span>
                        </label>
                    </div>

                    <button type="button" wire:click="generateSecret" wire:loading.attr="disabled" wire:target="generateSecret"
                            class="kt-btn kt-btn-primary w-full gap-2">
                        <span wire:loading.remove wire:target="generateSecret" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-key"></i> Generate secret
                        </span>
                        <span wire:loading wire:target="generateSecret" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Generating…
                        </span>
                    </button>
                    <p class="text-xs text-muted-foreground">
                        Drawn from the cryptographic generator, not the fast one. Look-alikes are excluded by
                        default because a secret gets read down a phone more often than anyone admits.
                    </p>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">How this is stored</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3 text-sm text-secondary-foreground">
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-shield-tick text-success mt-0.5"></i>
                        <span>Encrypted with <code class="text-xs px-1 py-0.5 rounded bg-muted">APP_KEY</code> before it touches the database.</span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-eye text-info mt-0.5"></i>
                        <span>Decrypted per reveal, one entry at a time. The list only ever shows dots.</span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-notepad-edit text-primary mt-0.5"></i>
                        <span>Every reveal and every copy is written to the activity log with your name and the time.</span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-information-2 text-warning mt-0.5"></i>
                        <span>Rotate the app key and every entry must be re-encrypted — keep the key backed up.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @script
    <script>
    (function () {
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

        function mount() {
            // A closure left behind by a wire:navigate must not touch the page
            // that replaced it.
            if (! $wire.$el || ! $wire.$el.isConnected) return;

            $wire.$el.querySelectorAll('[data-copy-from]').forEach(function (button) {
                // Ask the DOM whether this is already bound rather than marking
                // it: Livewire's morph strips any attribute the incoming HTML
                // does not carry, so a data-* flag clears itself every render.
                if (button.onclick) return;

                button.onclick = function () {
                    var source = $wire.$el.querySelector(button.getAttribute('data-copy-from'));
                    window.kargahCopy(source && source.value);
                };
            });
        }

        Livewire.hook('morphed', mount);
        mount();
    })();
    </script>
    @endscript
</div>
