<?php

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Platform\Models\ApplicationPassword;
use Modules\Platform\Services\ApplicationPasswordIssuer;
use Modules\Platform\Support\ConnectionHealth;
use Modules\Platform\Support\Scopes;

/**
 * Application passwords — issue, inspect, revoke.
 *
 * **The secret is not a public property, and that is the whole design.** A
 * public property is serialised into the page and sent back on every round
 * trip, so a freshly issued secret held in one would sit in the browser's
 * memory, in the back button and in any proxy in between for as long as the tab
 * stayed open. `$issuedSecret` is `protected`: Livewire does not serialise it,
 * so it exists only for the request that created it. Render it once, and the
 * very next interaction — any interaction — comes back without it. Nothing has
 * to remember to clear it, which is why it cannot be forgotten.
 *
 * Everything else on this page is deliberately not a secret: a name somebody
 * chose, the first six characters, the scopes, and when it was last used and
 * from where. That is exactly enough to recognise a row and decide to revoke
 * it, and not enough to use it.
 *
 * ⚠️ **Health lives in `Modules\Platform\Support\ConnectionHealth`, not here.**
 * A credential expiring in four days is the same class of problem as a social
 * token expiring in four days, and the window that counts as "soon" is
 * `SocialAccount::TOKEN_EXPIRY_WARNING_DAYS` — borrowed, not restated, because
 * Kargah holding two different answers to "how much warning is enough" makes
 * both of them untrustworthy. Unlike a social token, nothing scheduled warns
 * about these at all, so this page's badge is the only warning there is.
 */
new
#[Title('Application passwords — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The settings-nav search box. See `partials/settings-nav.blade.php`. */
    public string $settingsFilter = '';

    public string $name = '';

    /** @var list<string> */
    public array $selectedScopes = [Scopes::CORE_READ];

    public string $expiresOn = '';

    public bool $creating = false;

    /**
     * The plaintext, for the length of this one request only.
     *
     * Protected on purpose — see the class comment. Do not make it public to
     * "keep the panel open across a click": that is the bug this avoids.
     */
    protected ?string $issuedSecret = null;

    protected ?string $issuedName = null;

    /** Whole class strings. Tailwind's scanner reads source as text and cannot see "kt-badge-{$state}". */
    private const STATE_TONES = [
        'active' => 'kt-badge kt-badge-sm kt-badge-success',
        'expired' => 'kt-badge kt-badge-sm kt-badge-warning',
        'revoked' => 'kt-badge kt-badge-sm kt-badge-destructive',
    ];

    public function with(): array
    {
        $credentials = ApplicationPassword::query()
            ->where('user_id', auth()->id())
            ->orderByRaw('revoked_at is null desc')
            ->orderByDesc('created_at')
            ->get();

        return [
            'credentials' => $credentials->map(function (ApplicationPassword $credential): array {
                // Scalars only, chosen by hand. The model carries a `token_hash`
                // it hides and a scopes array it casts; handing the whole thing
                // to the view would put both into the component payload for no
                // gain. `ConnectionHealth` gets the model and gives back four
                // strings, which is all the template needs.
                $health = ConnectionHealth::forApplicationPassword($credential);

                return [
                    'id' => $credential->id,
                    'name' => $credential->name,
                    'prefix' => $credential->prefix,
                    'scopes' => $credential->grantedScopes(),
                    'created' => $credential->created_at?->toDateString() ?? ConnectionHealth::UNKNOWN,
                    'last_used' => $credential->last_used_at?->diffForHumans() ?? 'Never used',
                    // The address a request came from is a fact only a request
                    // can supply; a credential nothing has used has no address,
                    // so it prints an em dash rather than a zero or a blank.
                    'last_ip' => $credential->last_used_ip ?: ConnectionHealth::UNKNOWN,
                    'expires' => $credential->expires_at?->toDateString() ?? 'Never',
                    'state' => $credential->state(),
                    'tone' => self::STATE_TONES[$credential->state()],
                    'revoked' => $credential->isRevoked(),
                    'health' => $health['headline'],
                    'healthTone' => $health['tone'],
                    'healthDetail' => $health['detail'],
                ];
            })->all(),
            'scopeGroups' => Scopes::groups(),
            'scopeDescriptions' => Scopes::describe(),
            'issuedSecret' => $this->issuedSecret,
            'issuedName' => $this->issuedName,
        ];
    }

    /** Opening a panel is visible in the panel opening. It does not announce itself. */
    public function openForm(): void
    {
        $this->resetValidation();
        $this->name = '';
        $this->selectedScopes = [Scopes::CORE_READ];
        $this->expiresOn = '';
        $this->creating = true;
    }

    public function closeForm(): void
    {
        $this->resetValidation();
        $this->creating = false;
    }

    /**
     * Dismiss the one-time panel.
     *
     * The body is empty because there is genuinely nothing to clear: the secret
     * only ever existed for the request that issued it, and this round trip
     * re-renders without it. No toast — a panel closing is visible in the panel
     * being closed.
     */
    public function dismissSecret(): void {}

    public function create(): void
    {
        $this->validate([
            'name' => 'required|string|min:2|max:120',
            'selectedScopes' => 'required|array|min:1',
            'selectedScopes.*' => ['string', Rule::in(Scopes::all())],
            'expiresOn' => 'nullable|date|after:today',
        ], [
            'name.required' => 'Give it a name. In six months it is the only way to tell which script this belongs to before revoking it.',
            'name.min' => 'A name of one character will not tell you anything in six months. Use at least two.',
            'selectedScopes.required' => 'Choose at least one scope. A credential that can do nothing is not worth issuing.',
            'selectedScopes.*.in' => 'One of those scopes is not a scope Kargah recognises.',
            'expiresOn.date' => 'That is not a date Kargah can read. Pick one from the calendar, or leave it blank for no expiry.',
            'expiresOn.after' => 'An expiry has to be in the future. A credential that expired yesterday would be refused the first time a script used it.',
        ]);

        $user = auth()->user();

        if ($user === null) {
            $this->toastError('You are not signed in', 'Sign in again and retry.');

            return;
        }

        $issued = app(ApplicationPasswordIssuer::class)->issue(
            user: $user,
            name: $this->name,
            scopes: $this->selectedScopes,
            // End of day: an expiry of "the 30th" should last through the 30th.
            expiresAt: $this->expiresOn === '' ? null : CarbonImmutable::parse($this->expiresOn)->endOfDay(),
            causer: $user,
        );

        $this->issuedSecret = $issued['secret'];
        $this->issuedName = $issued['credential']->name;

        $this->creating = false;
        $this->name = '';
        $this->selectedScopes = [Scopes::CORE_READ];
        $this->expiresOn = '';

        $this->toastSuccess('Issued '.$this->issuedName, 'Copy the secret now — this is the only time it is shown.');
    }

    public function revoke(int $id): void
    {
        $credential = ApplicationPassword::query()
            ->where('user_id', auth()->id())
            ->find($id);

        if ($credential === null) {
            $this->toastError('That credential is gone', 'Someone removed it since this page was loaded.');

            return;
        }

        // Revoking an already-revoked credential writes nothing and logs
        // nothing, so it must not claim success either.
        if (! app(ApplicationPasswordIssuer::class)->revoke($credential, auth()->user())) {
            $this->toastWarning('Already revoked', $credential->name.' stopped working when it was first revoked.');

            return;
        }

        $this->toastSuccess('Revoked '.$credential->name, 'Any program still using it now gets a 401.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div>
        <h1 class="text-xl font-semibold text-mono">Settings</h1>
        <p class="text-sm text-secondary-foreground mt-1">How Kargah behaves for you.</p>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-3">
            @include('partials.settings-nav')
        </div>

        <div class="col-span-12 lg:col-span-9 flex flex-col gap-5">

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-mono">Application passwords</h2>
                    <p class="text-sm text-secondary-foreground mt-1">
                        Credentials for scripts, the command line and anything else that talks to Kargah without you.
                    </p>
                </div>
                @unless ($creating)
                    <button type="button" class="kt-btn kt-btn-primary gap-2" wire:click="openForm">
                        <i class="ki-filled ki-plus"></i> New application password
                    </button>
                @endunless
            </div>

            {{-- The one-time reveal. Rendered from a value that does not survive this request. --}}
            @if ($issuedSecret)
                <div class="kt-card border-success/40 bg-success/5">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title flex items-center gap-2">
                            <i class="ki-filled ki-check-circle text-success"></i> {{ $issuedName }} is ready
                        </h3>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <code id="kargah-issued-secret"
                                  class="text-sm px-3 py-2 rounded bg-muted text-mono tracking-wide break-all">{{ $issuedSecret }}</code>
                            <button type="button"
                                    class="kt-btn kt-btn-sm kt-btn-outline gap-2"
                                    data-kargah-copy="kargah-issued-secret"
                                    title="Copy the secret" aria-label="Copy the secret">
                                <i class="ki-filled ki-copy text-sm"></i> Copy
                            </button>
                        </div>
                        <p class="text-sm text-secondary-foreground">
                            This is the only time Kargah will show it. Only a hash is stored, so it cannot be looked up
                            again — if it is lost, revoke this credential and issue another.
                        </p>
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-muted-foreground">Use it as the password, with your email address as the user:</span>
                            <code class="text-xs px-3 py-2 rounded bg-muted text-secondary-foreground break-all">curl -u {{ auth()->user()?->email }}:{{ $issuedSecret }} {{ url('/api/v1/whoami') }}</code>
                        </div>
                        <div>
                            <button type="button" class="kt-btn kt-btn-sm kt-btn-ghost" wire:click="dismissSecret">
                                Done, I have copied it
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @if ($creating)
                <div class="kt-card" id="new">
                    <div class="kt-card-header"><h3 class="kt-card-title">New application password</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-5">

                        <p class="text-sm text-secondary-foreground">
                            Issuing one creates a credential a script can sign in with. The secret is shown once,
                            on the next screen, and never again.
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label font-normal text-mono" for="ap-name">Name</label>
                                <input id="ap-name" type="text" placeholder="Laptop CLI"
                                       class="kt-input @error('name') border-destructive @enderror"
                                       wire:model="name">
                                <span class="text-xs text-muted-foreground mt-1">
                                    Changes nothing but the label in the list below — which is what you will read when
                                    deciding which one to revoke.
                                </span>
                                @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label font-normal text-mono" for="ap-expires">Expires on</label>
                                <input id="ap-expires" type="date"
                                       class="kt-input @error('expiresOn') border-destructive @enderror"
                                       wire:model="expiresOn">
                                <span class="text-xs text-muted-foreground mt-1">
                                    Sets the day this stops working on its own, with no action from you. Leave it blank
                                    and it never expires — a decision, not a default.
                                </span>
                                @error('expiresOn')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div>
                                <span class="kt-form-label font-normal text-mono">Scopes</span>
                                <p class="text-xs text-muted-foreground mt-1">
                                    Each tick decides which part of Kargah this one credential may reach. Give it the
                                    least it needs: a credential that can read the boards has no business reading the vault.
                                </p>
                                @error('selectedScopes')<span class="block text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                                @foreach ($scopeGroups as $group)
                                    <div class="rounded-lg border border-border p-3 flex flex-col gap-2">
                                        <span class="text-xs font-medium text-mono uppercase tracking-wide">{{ $group['label'] }}</span>
                                        @foreach ($group['scopes'] as $scope)
                                            <label class="flex items-start gap-2.5 cursor-pointer">
                                                <input type="checkbox" class="kt-checkbox mt-0.5 shrink-0"
                                                       value="{{ $scope }}" wire:model="selectedScopes">
                                                <span class="min-w-0">
                                                    <span class="block text-sm text-mono font-mono">{{ $scope }}</span>
                                                    <span class="block text-xs text-muted-foreground">{{ $scopeDescriptions[$scope] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" class="kt-btn kt-btn-primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                                <span wire:loading.remove wire:target="create">Issue password</span>
                                <span wire:loading wire:target="create" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Issuing…
                                </span>
                            </button>
                            <button type="button" class="kt-btn kt-btn-ghost" wire:click="closeForm">Cancel</button>
                        </div>

                    </div>
                </div>
            @endif

            <div class="kt-card" id="issued">
                <div class="kt-card-header"><h3 class="kt-card-title">Issued credentials</h3></div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[180px]">Name</th>
                                    <th class="min-w-[220px]">Scopes</th>
                                    <th class="w-[110px]">Created</th>
                                    <th class="w-[160px]">Last used</th>
                                    <th class="w-[110px]">Expires</th>
                                    <th class="min-w-[220px]">Health</th>
                                    <th class="w-[90px] text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($credentials as $credential)
                                    <tr wire:key="application-password-{{ $credential['id'] }}">
                                        <td>
                                            <div class="font-medium text-mono">{{ $credential['name'] }}</div>
                                            <div class="text-xs text-muted-foreground font-mono">{{ $credential['prefix'] }}…</div>
                                        </td>
                                        <td>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($credential['scopes'] as $scope)
                                                    <span class="{{ \Modules\Platform\Support\Scopes::tone($scope) }}">{{ $scope }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="text-secondary-foreground">{{ $credential['created'] }}</td>
                                        <td class="text-secondary-foreground">
                                            {{ $credential['last_used'] }}
                                            <div class="text-xs text-muted-foreground">{{ $credential['last_ip'] }}</div>
                                        </td>
                                        <td class="text-secondary-foreground">{{ $credential['expires'] }}</td>
                                        <td>
                                            <span class="{{ $credential['healthTone'] }}">{{ $credential['health'] }}</span>
                                            <div class="text-xs text-muted-foreground mt-1">{{ $credential['healthDetail'] }}</div>
                                        </td>
                                        <td class="text-end">
                                            @unless ($credential['revoked'])
                                                <button type="button"
                                                        class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive"
                                                        wire:click="revoke({{ $credential['id'] }})"
                                                        wire:loading.attr="disabled" wire:target="revoke({{ $credential['id'] }})"
                                                        wire:confirm="Revoke {{ $credential['name'] }}? Every live script, cron job and command line still using it starts getting a 401 the moment you confirm. The secret cannot be recovered, so anything that breaks has to be given a new credential by hand."
                                                        title="Revoke" aria-label="Revoke {{ $credential['name'] }}">
                                                    <i class="ki-filled ki-trash text-sm"></i>
                                                </button>
                                            @endunless
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">
                                            <div class="flex flex-col items-center py-14 text-center gap-3">
                                                <i class="ki-filled ki-key text-4xl text-muted-foreground"></i>
                                                <p class="text-sm text-secondary-foreground">
                                                    No application password has been issued. Your own password is not one, and never will be.
                                                </p>
                                                <button type="button" class="kt-btn kt-btn-primary gap-2" wire:click="openForm">
                                                    <i class="ki-filled ki-plus"></i> New application password
                                                </button>
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
                        Only a hash is stored, so no page and no query can produce the secret again. Revoking one of these
                        does not touch your own password and does not affect the others, and both issuing and revoking are
                        written to the activity log with your name on them.
                    </div>
                </div>
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

        // One delegated listener on the document rather than a binding per
        // render: the morph replaces the button, and a listener bound to the
        // old node would go with it.
        if (! window.__kargahCopyTargetsBound) {
            window.__kargahCopyTargetsBound = true;

            document.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-kargah-copy]');
                if (! trigger) return;

                var source = document.getElementById(trigger.getAttribute('data-kargah-copy'));
                if (! source) return;

                window.kargahCopy(source.textContent.trim());

                if (window.kargahToast) {
                    window.kargahToast({ type: 'success', message: 'Copied to the clipboard' });
                }
            });
        }
    })();
    </script>
    @endscript
</div>
