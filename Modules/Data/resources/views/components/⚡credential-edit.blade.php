<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Credential editor.
 *
 * The secret is encrypted with the application key before it is stored and is
 * only ever decrypted for a single reveal. The field below is masked by default
 * and the value is never printed into the markup.
 */
new
#[Title('New credential — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('nullable|string|max:120')]
    public string $username = '';

    #[Validate('required|string|max:255')]
    public string $secret = '';

    #[Validate('nullable|url|max:255')]
    public string $url = '';

    #[Validate('required|string|max:40')]
    public string $category = 'Hosting';

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

    public function with(): array
    {
        return [
            'categories' => ['Hosting', 'Email', 'Dev', 'Banking', 'Client', 'Domains', 'Other'],
            'strength' => $this->strength(),
        ];
    }

    /**
     * A rough strength read on the current secret: length plus character variety.
     * Purely for the meter — it is not a gate on saving.
     */
    protected function strength(): array
    {
        $value = $this->secret;

        if ($value === '') {
            return ['score' => 0, 'label' => 'Empty', 'tone' => 'bg-muted', 'text' => 'text-muted-foreground', 'width' => 0];
        }

        $score = 0;
        $score += mb_strlen($value) >= 12 ? 1 : 0;
        $score += mb_strlen($value) >= 20 ? 1 : 0;
        $score += preg_match('/[a-z]/', $value) && preg_match('/[A-Z]/', $value) ? 1 : 0;
        $score += preg_match('/\d/', $value) ? 1 : 0;
        $score += preg_match('/[^A-Za-z0-9]/', $value) ? 1 : 0;

        return match (true) {
            $score <= 1 => ['score' => $score, 'label' => 'Weak',       'tone' => 'bg-destructive', 'text' => 'text-destructive', 'width' => 25],
            $score === 2 => ['score' => $score, 'label' => 'Fair',      'tone' => 'bg-warning',     'text' => 'text-warning',     'width' => 50],
            $score === 3 => ['score' => $score, 'label' => 'Good',      'tone' => 'bg-info',        'text' => 'text-info',        'width' => 75],
            default      => ['score' => $score, 'label' => 'Strong',    'tone' => 'bg-success',     'text' => 'text-success',     'width' => 100],
        };
    }

    public function toggleSecret(): void
    {
        $this->secretRevealed = ! $this->secretRevealed;

        // Only the reveal is worth saying out loud — that is the state with a
        // cost. Re-masking is silent because the field shows it immediately.
        if ($this->secretRevealed) {
            $this->toastSuccess('Secret is now readable on screen', 'Hide it again when you have finished checking it.');
        }
    }

    public function toggleTotp(): void
    {
        $this->totpRevealed = ! $this->totpRevealed;

        if ($this->totpRevealed) {
            $this->toastSuccess('TOTP seed is now readable on screen', 'Anyone who can see this seed can generate your codes.');
        }
    }

    /** Build a random secret from the generator settings. */
    public function generateSecret(): void
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$%^&*()-_=+[]{}<>?';

        if (! $this->avoidAmbiguous) {
            $lower .= 'jl';
            $upper .= 'IO';
            $digits .= '01';
        }

        $pool = $lower
            .($this->useUpper ? $upper : '')
            .($this->useDigits ? $digits : '')
            .($this->useSymbols ? $symbols : '');

        $length = max(8, min(64, $this->length));
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $pool[random_int(0, mb_strlen($pool) - 1)];
        }

        $this->secret = $out;
        $this->secretRevealed = true;

        // Length only — the generated value never goes into a toast.
        $this->toastSuccess(
            'Secret generated',
            mb_strlen($this->secret).' characters, visible in the field. Nothing is stored until you save.'
        );
    }

    /** Encrypt the secret with the app key and store the entry. */
    public function save(): void
    {
        // Backend: validate, Crypt::encryptString the secret and TOTP, then persist.

        $this->toastInfo('Saving is not wired up yet', 'Encrypting and storing this credential needs the backend, so nothing was kept.');
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
                only for a single reveal. They are never written into a page, a log or a backup in plain text.
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
                        <div class="flex items-center justify-between gap-3">
                            <label class="kt-form-label" for="cred-secret">Secret</label>
                            <div data-kt-dropdown="true">
                                <button class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5" data-kt-dropdown-toggle="true" type="button">
                                    <i class="ki-filled ki-key text-sm"></i> Generate
                                </button>
                                <div class="kt-dropdown-menu w-[300px] p-4" data-kt-dropdown-menu="true">
                                    <div class="flex flex-col gap-4">
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
                                                class="kt-btn kt-btn-primary kt-btn-sm w-full gap-2">
                                            <span wire:loading.remove wire:target="generateSecret">Generate secret</span>
                                            <span wire:loading wire:target="generateSecret"><i class="ki-filled ki-loading animate-spin"></i> Generating…</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <input id="cred-secret" type="{{ $secretRevealed ? 'text' : 'password' }}"
                                   class="kt-input grow @error('secret') border-destructive @enderror"
                                   placeholder="••••••••••••" autocomplete="new-password"
                                   wire:model.live.debounce.300ms="secret">
                            <button type="button" wire:click="toggleSecret" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0"
                                    title="{{ $secretRevealed ? 'Hide secret' : 'Reveal secret' }}"
                                    aria-label="{{ $secretRevealed ? 'Hide secret' : 'Reveal secret' }}">
                                <i class="ki-filled {{ $secretRevealed ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                            </button>
                            <button type="button" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0" title="Copy secret" aria-label="Copy secret">
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
                            <select id="cred-category" class="kt-select @error('category') border-destructive @enderror" wire:model="category">
                                @foreach ($categories as $c)
                                    <option value="{{ $c }}">{{ $c }}</option>
                                @endforeach
                            </select>
                            @error('category')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    {{-- TOTP --}}
                    <div class="flex flex-col">
                        <label class="kt-form-label" for="cred-totp">TOTP secret</label>
                        <div class="flex items-center gap-2">
                            <input id="cred-totp" type="{{ $totpRevealed ? 'text' : 'password' }}"
                                   class="kt-input grow @error('totp') border-destructive @enderror"
                                   placeholder="Base32 seed from the provider" autocomplete="off" wire:model="totp">
                            <button type="button" wire:click="toggleTotp" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0"
                                    title="{{ $totpRevealed ? 'Hide TOTP seed' : 'Reveal TOTP seed' }}"
                                    aria-label="{{ $totpRevealed ? 'Hide TOTP seed' : 'Reveal TOTP seed' }}">
                                <i class="ki-filled {{ $totpRevealed ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                            </button>
                        </div>
                        <span class="text-xs text-muted-foreground mt-1">Optional. With a seed saved, the vault shows a rolling six-digit code next to the entry.</span>
                        @error('totp')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="cred-notes">Notes</label>
                        <textarea id="cred-notes" rows="4" class="kt-textarea @error('notes') border-destructive @enderror"
                                  placeholder="Recovery email, account number, who to ring when it locks."
                                  wire:model="notes"></textarea>
                        @error('notes')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-5">
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
                        <i class="ki-filled ki-user-tick text-primary mt-0.5"></i>
                        <span>Private to you. Nothing in Data is shared with a client or a collaborator.</span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-information-2 text-warning mt-0.5"></i>
                        <span>Rotate the app key and every entry must be re-encrypted — keep the key backed up.</span>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Generator</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-2 text-sm text-secondary-foreground">
                    <div class="flex items-center justify-between"><span>Length</span><span class="text-mono">{{ $length }}</span></div>
                    <div class="flex items-center justify-between"><span>Uppercase</span><span class="text-mono">{{ $useUpper ? 'On' : 'Off' }}</span></div>
                    <div class="flex items-center justify-between"><span>Digits</span><span class="text-mono">{{ $useDigits ? 'On' : 'Off' }}</span></div>
                    <div class="flex items-center justify-between"><span>Symbols</span><span class="text-mono">{{ $useSymbols ? 'On' : 'Off' }}</span></div>
                    <div class="flex items-center justify-between"><span>Look-alikes</span><span class="text-mono">{{ $avoidAmbiguous ? 'Excluded' : 'Allowed' }}</span></div>
                </div>
            </div>
        </div>
    </div>
</div>
