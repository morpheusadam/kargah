<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;

/**
 * Connect a network.
 *
 * Every network here is a token you paste rather than an OAuth round trip, and
 * that is a deliberate choice rather than an unfinished one: an OAuth callback
 * needs a registered redirect URI per install, and Kargah's whole point is that
 * it runs on shared hosting somebody set up in an afternoon. Mastodon, Bluesky
 * and Telegram all issue a scoped, revocable credential from their own settings
 * screen. LinkedIn does not, which is why its field asks for a member token and
 * the page says it expires after sixty days.
 *
 * **Checking is a real call.** `verify()` asks the network who the credentials
 * belong to and echoes the answer back, so 'connected' means Kargah reached
 * that account rather than merely reached that network. It does not post
 * anything — a test post is something the person then has to go and delete.
 *
 * The secret is written once, encrypted, and never read back into this form.
 * Reopening the page to replace a credential shows an empty field, because a
 * field prefilled with a decrypted secret is the secret in the page source.
 */
new
#[Title('Connect an account — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $network = '';

    public string $handle = '';

    /** @var array<string, string> Credential values as typed, keyed by field name. */
    public array $fields = [];

    /** @var array<string, bool> Which secret fields are currently shown in the clear. */
    public array $revealed = [];

    /** What the last check said, so the answer survives the re-render. */
    public string $checkResult = '';

    public bool $checkFailed = false;

    public function mount(): void
    {
        $this->choose($this->network);
    }

    /** @return array<string, mixed>|null */
    private function chosen(): ?array
    {
        return Networks::get($this->network);
    }

    /** The row this form would write to, if one already exists. */
    private function existing(): ?SocialAccount
    {
        $handle = trim($this->handle);

        if ($this->network === '' || $handle === '') {
            return null;
        }

        return SocialAccount::query()
            ->onNetwork($this->network)
            ->where('handle', $handle)
            ->first();
    }

    public function with(): array
    {
        return [
            'catalogue' => Networks::all(),
            'chosen' => $this->chosen(),
            'existing' => $this->existing(),
            'connectedNetworks' => SocialAccount::query()->pluck('network')->unique()->all(),
        ];
    }

    public function choose(string $network): void
    {
        $this->network = Networks::has($network) ? $network : '';

        $this->fields = [];
        $this->revealed = [];
        $this->checkResult = '';
        $this->checkFailed = false;
        $this->resetValidation();

        foreach (Networks::credentialFields($this->network) as $field) {
            $this->fields[$field] = '';
        }

        if ($this->network === '') {
            return;
        }

        // A row for this network already exists on almost every install,
        // because the seeder creates one per network with no credential. Its
        // handle is the useful default; its secret is not read back.
        $this->handle = SocialAccount::query()->onNetwork($this->network)->value('handle') ?? '';
    }

    public function back(): void
    {
        $this->network = '';
        $this->handle = '';
        $this->fields = [];
        $this->revealed = [];
        $this->checkResult = '';
        $this->checkFailed = false;
        $this->resetValidation();
    }

    public function toggleReveal(string $field): void
    {
        $this->revealed[$field] = ! ($this->revealed[$field] ?? false);
    }

    /**
     * Everything typed, trimmed, with empty fields dropped.
     *
     * @return array<string, string>
     */
    private function credentials(): array
    {
        $credentials = [];

        foreach (Networks::credentialFields($this->network) as $field) {
            $value = trim((string) ($this->fields[$field] ?? ''));

            if ($value !== '') {
                $credentials[$field] = $value;
            }
        }

        return $credentials;
    }

    /** @return list<string> The labels of the fields still empty. */
    private function missingLabels(): array
    {
        $chosen = $this->chosen();
        $credentials = $this->credentials();

        $missing = [];

        foreach ($chosen['credentials'] ?? [] as $field => $meta) {
            if (! array_key_exists($field, $credentials)) {
                $missing[] = $meta['label'];
            }
        }

        return $missing;
    }

    /**
     * An unsaved account carrying what was typed.
     *
     * Unsaved on purpose: checking a credential must not store it, or a typo
     * would leave a broken connection behind every time somebody tried.
     */
    private function draftAccount(): SocialAccount
    {
        $account = $this->existing() ?? new SocialAccount([
            'network' => $this->network,
            'handle' => trim($this->handle),
        ]);

        $account->network = $this->network;
        $account->credentials = $this->credentials();
        $account->is_active = true;

        return $account;
    }

    public function check(): void
    {
        $chosen = $this->chosen();

        if ($chosen === null) {
            $this->toastError('Pick a network first', 'Nothing was checked.');

            return;
        }

        if ($missing = $this->missingLabels()) {
            $this->checkFailed = true;
            $this->checkResult = 'Nothing was checked — '.implode(' and ', $missing).' still empty.';

            $this->toastWarning(
                $chosen['label'].' credentials are not configured',
                implode(' and ', $missing).' '.(count($missing) === 1 ? 'is' : 'are').' still empty, so nothing was sent.',
            );

            return;
        }

        $driver = app(Publishing::class)->driverFor($this->network);

        if ($driver === null) {
            $this->toastError('Kargah has no driver for '.$chosen['label'], 'Nothing was checked.');

            return;
        }

        try {
            $who = $driver->verify($this->draftAccount());
        } catch (PublishFailed $e) {
            $this->checkFailed = true;
            $this->checkResult = $e->getMessage();

            $this->toastError($chosen['label'].' refused the credentials', $e->getMessage());

            return;
        }

        $this->checkFailed = false;
        $this->checkResult = $chosen['label'].' answered as '.$who.'. Nothing was posted.';
    }

    /**
     * Write the credential, encrypted, against the account it belongs to.
     *
     * `updateOrCreate` on (network, handle) because that pair is what the
     * database is unique on — replacing an expired token is the same gesture as
     * connecting for the first time, and it must not make a second row.
     */
    public function save(): void
    {
        $chosen = $this->chosen();

        if ($chosen === null) {
            $this->toastError('Pick a network first', 'Nothing was saved.');

            return;
        }

        $handle = trim($this->handle);

        if ($handle === '') {
            $this->toastError('The account needs a handle', 'It is how the queue and the feed name this connection.');

            return;
        }

        if ($missing = $this->missingLabels()) {
            $this->toastError(
                $chosen['label'].' credentials are not configured',
                implode(' and ', $missing).' '.(count($missing) === 1 ? 'is' : 'are').' still empty, so nothing was saved.',
            );

            return;
        }

        $existing = $this->existing();

        $lifetimeDays = Networks::tokenLifetimeDays($this->network);

        $account = SocialAccount::query()->updateOrCreate(
            ['network' => $this->network, 'handle' => $handle],
            [
                'credentials' => $this->credentials(),
                'display_name' => $existing?->display_name ?? auth()->user()?->name,
                'is_active' => true,
                'connected_at' => now(),
                // Every field is required for `save()` to succeed, so a save
                // is always a fresh paste, never an edit to a handle alone —
                // see `missingLabels()` above. That is what makes recomputing
                // this on every save correct rather than something that needs
                // to detect whether the credential actually changed.
                'token_expires_at' => $lifetimeDays === null ? null : now()->addDays($lifetimeDays),
                'last_error' => null,
                'created_by' => $existing?->created_by ?? auth()->id(),
            ],
        );

        $queued = $account->targets()->where('status', 'pending')->count();

        $this->fields = array_map(fn (): string => '', $this->fields);
        $this->revealed = [];
        $this->checkResult = '';
        $this->checkFailed = false;

        $this->flashToast(
            'success',
            $chosen['label'].' connected as '.$handle,
            $queued === 0
                ? 'The credential is stored encrypted. Anything scheduled for it from now on will go out.'
                : $queued.' queued '.($queued === 1 ? 'post' : 'posts').' can now go to it.',
        );

        $this->redirectRoute('social.accounts', navigate: true);
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Connect an account</h1>
            <p class="text-sm text-secondary-foreground mt-1">Hand over the least access a network will accept, and see it written down first.</p>
        </div>
        <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-arrow-left"></i> All accounts
        </a>
    </div>

    {{-- Step indicator --}}
    <div class="flex items-center gap-3 text-sm">
        <span class="inline-flex items-center gap-2 {{ $chosen ? 'text-muted-foreground' : 'text-mono font-medium' }}">
            <span class="inline-flex items-center justify-center size-6 rounded-full text-xs {{ $chosen ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground' }}">1</span>
            Choose a network
        </span>
        <span class="grow h-px bg-border max-w-[80px]"></span>
        <span class="inline-flex items-center gap-2 {{ $chosen ? 'text-mono font-medium' : 'text-muted-foreground' }}">
            <span class="inline-flex items-center justify-center size-6 rounded-full text-xs {{ $chosen ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground' }}">2</span>
            Hand over the credential
        </span>
    </div>

    @if (! $chosen)

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
            @foreach ($catalogue as $key => $n)
                <button wire:click="choose('{{ $key }}')" wire:key="pick-{{ $key }}"
                        class="kt-card text-start hover:border-primary/40 transition-colors">
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary">
                            <i class="ki-filled {{ $n['icon'] }} text-xl"></i>
                        </span>
                        <div>
                            <div class="font-semibold text-mono">{{ $n['label'] }}</div>
                            <p class="text-sm text-secondary-foreground mt-1">{{ $n['summary'] }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="kt-badge kt-badge-sm kt-badge-outline">
                                {{ number_format($n['limit']) }} characters
                            </span>
                            @if ($n['ingests'])
                                <span class="kt-badge kt-badge-sm kt-badge-info">Reads notifications</span>
                            @else
                                <span class="kt-badge kt-badge-sm kt-badge-outline">Publishing only</span>
                            @endif
                            @if (in_array($key, $connectedNetworks, true))
                                <span class="kt-badge kt-badge-sm kt-badge-success">Already set up</span>
                            @endif
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

    @else

        <div class="grid grid-cols-12 gap-5 items-start">

            <div class="col-span-12 lg:col-span-7">
                <div class="kt-card">
                    <div class="kt-card-header">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled {{ $chosen['icon'] }} text-lg"></i>
                            </span>
                            <div class="min-w-0">
                                <h3 class="kt-card-title">{{ $chosen['label'] }}</h3>
                                <p class="text-xs text-muted-foreground truncate">{{ $chosen['summary'] }}</p>
                            </div>
                        </div>
                        <button wire:click="back" class="kt-btn kt-btn-sm kt-btn-ghost shrink-0">Change</button>
                    </div>

                    <div class="kt-card-content p-5 flex flex-col gap-5">

                        <div class="flex items-start gap-2.5 rounded-lg bg-muted px-3.5 py-3">
                            <i class="ki-filled ki-information-2 text-secondary-foreground text-base mt-0.5 shrink-0"></i>
                            <p class="text-sm text-secondary-foreground">{{ $chosen['requirement'] }}</p>
                        </div>

                        @if ($existing && $existing->tokenExpired())
                            <div class="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-3">
                                <i class="ki-filled ki-cross-circle text-destructive text-base mt-0.5 shrink-0"></i>
                                <p class="text-sm text-secondary-foreground">
                                    Its stored token expired {{ $existing->token_expires_at->diffForHumans() }}, which is likely why you are here. Saving a fresh credential below replaces it.
                                </p>
                            </div>
                        @elseif ($existing && $existing->tokenExpiringSoon())
                            <div class="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 px-3.5 py-3">
                                <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                                <p class="text-sm text-secondary-foreground">
                                    Its stored token expires {{ $existing->token_expires_at->diffForHumans() }}. Saving a fresh credential below replaces it and resets the clock.
                                </p>
                            </div>
                        @endif

                        <div class="flex flex-col gap-1.5">
                            <label class="kt-form-label" for="account-handle">Handle</label>
                            <input id="account-handle" type="text" class="kt-input"
                                   placeholder="How this account is named in Kargah"
                                   wire:model="handle">
                            <span class="text-xs text-muted-foreground">
                                @if ($existing)
                                    An account with this handle already exists; saving replaces its credential rather than adding a second row.
                                @else
                                    Used on the queue, the calendar and the notification feed. One handle per network.
                                @endif
                            </span>
                        </div>

                        @foreach ($chosen['credentials'] as $field => $meta)
                            @php $shown = $revealed[$field] ?? false; @endphp
                            <div class="flex flex-col gap-1.5" wire:key="field-{{ $network }}-{{ $field }}">
                                <label class="kt-form-label" for="cred-{{ $field }}">{{ $meta['label'] }}</label>
                                @if ($meta['secret'])
                                    <div class="flex items-center gap-2">
                                        <input id="cred-{{ $field }}"
                                               type="{{ $shown ? 'text' : 'password' }}"
                                               class="kt-input grow"
                                               placeholder="{{ $meta['placeholder'] }}"
                                               autocomplete="off"
                                               wire:model="fields.{{ $field }}">
                                        <button wire:click="toggleReveal('{{ $field }}')"
                                                class="kt-btn kt-btn-icon kt-btn-outline shrink-0"
                                                title="{{ $shown ? 'Hide' : 'Show' }} {{ $meta['label'] }}"
                                                aria-label="{{ $shown ? 'Hide' : 'Show' }} {{ $meta['label'] }}">
                                            <i class="ki-filled {{ $shown ? 'ki-eye-slash' : 'ki-eye' }}"></i>
                                        </button>
                                    </div>
                                @else
                                    <input id="cred-{{ $field }}" type="text" class="kt-input"
                                           placeholder="{{ $meta['placeholder'] }}"
                                           wire:model="fields.{{ $field }}">
                                @endif
                                <span class="text-xs text-muted-foreground">{{ $meta['hint'] }}</span>
                            </div>
                        @endforeach

                        <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                            <button wire:click="check" wire:loading.attr="disabled" class="kt-btn kt-btn-outline gap-2">
                                <span wire:loading.remove wire:target="check" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-shield-tick"></i> Check the credentials
                                </span>
                                <span wire:loading wire:target="check" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Asking {{ $chosen['label'] }}…
                                </span>
                            </button>
                            <button wire:click="save" wire:loading.attr="disabled" class="kt-btn kt-btn-primary gap-2">
                                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-check-circle"></i> Save connection
                                </span>
                                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                                </span>
                            </button>
                        </div>

                        @if ($checkResult !== '')
                            <div class="flex items-start gap-2.5 rounded-lg px-3.5 py-3 {{ $checkFailed ? 'border border-destructive/30 bg-destructive/5' : 'border border-success/30 bg-success/10' }}">
                                <i class="ki-filled {{ $checkFailed ? 'ki-cross-circle text-destructive' : 'ki-check-circle text-success' }} text-base mt-0.5 shrink-0"></i>
                                <p class="text-sm text-secondary-foreground">{{ $checkResult }}</p>
                            </div>
                        @else
                            <p class="text-xs text-muted-foreground">
                                The check asks {{ $chosen['label'] }} who the credential belongs to and posts nothing.
                            </p>
                        @endif

                    </div>
                </div>
            </div>

            <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">What Kargah will be able to do</h3>
                    </div>
                    <div class="kt-card-content p-4 flex flex-col gap-2.5">
                        @foreach ($chosen['permissions'] as $p)
                            <div class="flex items-start gap-2.5">
                                <i class="ki-filled {{ $p['allowed'] ? 'ki-check-circle text-success' : 'ki-cross-circle text-muted-foreground' }} text-base mt-0.5 shrink-0"></i>
                                <span class="text-sm {{ $p['allowed'] ? 'text-secondary-foreground' : 'text-muted-foreground' }}">{{ $p['text'] }}</span>
                            </div>
                        @endforeach
                        <p class="text-xs text-muted-foreground border-t border-border pt-3 mt-1">
                            The credential is encrypted with the application key, kept out of every page this application
                            renders, and read only by the job that sends your posts.
                        </p>
                    </div>
                </div>

                <div class="kt-card border-warning/30">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title text-warning">Before you disconnect later</h3>
                    </div>
                    <div class="kt-card-content p-4 flex flex-col gap-2.5">
                        <div class="flex items-start gap-2.5">
                            <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                            <span class="text-sm text-secondary-foreground">
                                Disconnecting {{ $chosen['label'] }} deletes the stored credential and stops anything queued
                                from reaching it. Posts already published stay up on the network.
                            </span>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                            <span class="text-sm text-secondary-foreground">
                                Revoking the credential on {{ $chosen['label'] }} itself is the stronger move, and it works
                                whether or not Kargah is still running.
                            </span>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    @endif
</div>
