<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\Assistant;
use Modules\Platform\Services\Assistant\ChatMessage;
use Modules\Platform\Services\Assistant\CompletionFailed;
use Modules\Platform\Services\Assistant\CompletionRequest;
use Modules\Platform\Support\AssistantDrivers;
use Modules\Platform\Support\ConnectionHealth;

/**
 * Assistant providers — add, edit, enable, disable, pick a default, test.
 *
 * **The key is never a public property once a row has one.** `$apiKey` is
 * used to *write* a key and is always blank when a form opens on an existing
 * row — see `openEdit()`. Leaving it blank on save keeps whatever key is
 * already stored; typing a new one replaces it. The page never has a value
 * to show back, so there is nothing here for `NoSecretsInHtmlTest`'s canary
 * to find even in principle.
 *
 * "Test this connection" never touches the network from a page load — only
 * from the explicit `test()` action — and it reports one of the specific
 * failures `CompletionFailed` distinguishes rather than a bare "failed",
 * because on this machine the likely cause is a missing CA bundle, not a
 * wrong key, and those look identical from "connection failed" alone.
 *
 * ⚠️ **The one-word connection state is now
 * `ConnectionHealth::forAssistantProvider()` rather than a `match` in
 * `with()`.** It answers the same question the notifications page asks about
 * social tokens and the application-passwords page asks about credentials, and
 * one class answering it means the three pages cannot start disagreeing about
 * what "untested" or "broken" looks like. It also draws the distinction this
 * page's own `match` did not: a provider with no key and a provider whose last
 * test failed are both unusable, but only one of them can be fixed by pasting
 * something.
 */
new
#[Title('Assistant — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The settings-nav search box. See `partials/settings-nav.blade.php`. */
    public string $settingsFilter = '';

    public bool $editing = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $driver = AssistantDrivers::GEMINI;

    public string $model = '';

    /** Write-only. Blank on an existing row means "keep the stored key". */
    public string $apiKey = '';

    public string $baseUrl = '';

    public bool $isActive = true;

    protected function rules(): array
    {
        $requiresKey = AssistantDrivers::requiresKey($this->driver);
        $requiresBaseUrl = AssistantDrivers::requiresBaseUrl($this->driver);

        return [
            'name' => 'required|string|min:2|max:120',
            'driver' => ['required', Rule::in(AssistantDrivers::keys())],
            'model' => 'nullable|string|max:120',
            // A new row needs a key immediately if its driver requires one;
            // an existing row may be saved with a blank key, which keeps
            // whatever is already stored.
            'apiKey' => [$requiresKey && $this->editingId === null ? 'required' : 'nullable', 'string', 'max:1000'],
            'baseUrl' => [$requiresBaseUrl ? 'required' : 'nullable', 'url', 'max:255'],
            'isActive' => 'boolean',
        ];
    }

    /**
     * Sentences, not rule names.
     *
     * ⚠️ Nothing in here interpolates `$this->apiKey`. A validation message is
     * rendered into the page, and a message quoting back "the key
     * AIzaSy… is too long" would print the secret the rest of this file works
     * to keep out of the HTML.
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Give this provider a name, so the list below tells you which is which.',
            'name.min' => 'That name is too short to recognise later. Use at least two characters.',
            'driver.in' => 'Kargah has no driver by that name. Pick one from the list.',
            'apiKey.required' => 'This provider will not answer without an API key, so one is needed before it can be added.',
            'apiKey.max' => 'That key is longer than 1,000 characters, which is longer than any provider issues. Check it was pasted whole and not doubled.',
            'baseUrl.required' => 'This driver talks to a server you host, so it needs the address of that server.',
            'baseUrl.url' => 'That is not an address Kargah can reach. It needs a scheme and a host, like https://ollama.example.com.',
        ];
    }

    public function with(): array
    {
        $providers = AssistantProvider::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            // Scalars only, chosen deliberately. `AssistantProvider` carries an
            // `api_key` behind a decrypting cast exactly like `SocialAccount`'s
            // credentials, so the model itself never enters this payload —
            // only `hasKey`, which is a boolean.
            'providers' => $providers->map(function (AssistantProvider $provider): array {
                $health = ConnectionHealth::forAssistantProvider($provider);

                return [
                    'id' => $provider->id,
                    'name' => $provider->label(),
                    'driver' => $provider->driver,
                    'driverLabel' => AssistantDrivers::label($provider->driver),
                    'icon' => $provider->icon(),
                    'tone' => $provider->tone(),
                    'model' => $provider->effectiveModel(),
                    'hasKey' => $provider->hasApiKey(),
                    'requiresKey' => $provider->requiresApiKey(),
                    'baseUrl' => $provider->base_url,
                    'requiresBaseUrl' => $provider->requiresBaseUrl(),
                    'isActive' => $provider->is_active,
                    'isDefault' => $provider->is_default,
                    'lastTested' => $health['tested'],
                    'health' => $health['headline'],
                    'healthTone' => $health['tone'],
                    'healthDetail' => $health['detail'],
                ];
            })->all(),
            'driverOptions' => AssistantDrivers::all(),
        ];
    }

    /** Opening a panel is visible in the panel opening. It does not announce itself. */
    public function openCreate(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->driver = AssistantDrivers::GEMINI;
        $this->model = '';
        $this->apiKey = '';
        $this->baseUrl = '';
        $this->isActive = true;
        $this->editing = true;
    }

    public function openEdit(int $id): void
    {
        $provider = AssistantProvider::query()->find($id);

        if ($provider === null) {
            $this->toastError('That provider is gone', 'Someone removed it since this page was loaded.');

            return;
        }

        $this->resetValidation();
        $this->editingId = $provider->id;
        $this->name = $provider->name;
        $this->driver = $provider->driver;
        $this->model = (string) $provider->model;
        // Never populated from the stored value — see the class docblock.
        $this->apiKey = '';
        $this->baseUrl = (string) $provider->base_url;
        $this->isActive = $provider->is_active;
        $this->editing = true;
    }

    public function closeForm(): void
    {
        $this->resetValidation();
        $this->editing = false;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'driver' => $this->driver,
            'model' => trim($this->model),
            'base_url' => AssistantDrivers::requiresBaseUrl($this->driver) ? rtrim($this->baseUrl, '/') : null,
            'is_active' => $this->isActive,
        ];

        // Blank means "keep the stored key" on an edit; on a create, the
        // validation above already required one when the driver needs it.
        if ($this->apiKey !== '') {
            $data['api_key'] = $this->apiKey;
        } elseif (! AssistantDrivers::requiresKey($this->driver)) {
            $data['api_key'] = null;
        }

        if ($this->editingId !== null) {
            $provider = AssistantProvider::query()->find($this->editingId);

            if ($provider === null) {
                $this->toastError('That provider is gone', 'Someone removed it since this page was loaded.');

                return;
            }

            $provider->fill($data)->save();
            $this->toastSuccess('Saved '.$provider->label());
        } else {
            $provider = AssistantProvider::create($data);
            $this->toastSuccess('Added '.$provider->label());
        }

        $this->editing = false;
        $this->editingId = null;
        $this->apiKey = '';
    }

    public function makeDefault(int $id): void
    {
        $provider = AssistantProvider::query()->find($id);

        if ($provider === null) {
            $this->toastError('That provider is gone', 'Someone removed it since this page was loaded.');

            return;
        }

        if ($provider->is_default) {
            $this->toastWarning('Already the default', $provider->label().' is already the default provider.');

            return;
        }

        $provider->makeDefault();
        $this->toastSuccess($provider->label().' is now the default');
    }

    public function toggleActive(int $id): void
    {
        $provider = AssistantProvider::query()->find($id);

        if ($provider === null) {
            $this->toastError('That provider is gone', 'Someone removed it since this page was loaded.');

            return;
        }

        $provider->update(['is_active' => ! $provider->is_active]);

        $this->toastSuccess($provider->is_active
            ? 'Enabled '.$provider->label()
            : 'Disabled '.$provider->label());
    }

    public function delete(int $id): void
    {
        $provider = AssistantProvider::query()->find($id);

        if ($provider === null) {
            $this->toastError('That provider is gone', 'Someone removed it since this page was loaded.');

            return;
        }

        $name = $provider->label();

        // A deleted default provider does not leave a dangling default —
        // AssistantProvider::booted() promotes another active one, if any,
        // as part of the delete itself.
        $provider->delete();

        $this->toastSuccess('Removed '.$name);
    }

    /**
     * Ask the provider for one short reply, and record what happened.
     *
     * Distinguishes three failures rather than reporting one "connection
     * failed", because on a machine with no CA bundle configured the honest
     * answer is "TLS could not be verified here", which sends nobody hunting
     * for a wrong API key — see `CompletionFailed`'s docblock.
     */
    public function test(int $id): void
    {
        $provider = AssistantProvider::query()->find($id);

        if ($provider === null) {
            $this->toastError('That provider is gone', 'Someone removed it since this page was loaded.');

            return;
        }

        $assistant = app(Assistant::class);

        try {
            $driver = $assistant->driverFor($provider->driver);
        } catch (\InvalidArgumentException $e) {
            $provider->markTestResult(false, $e->getMessage());
            $this->toastError('Unknown driver', $e->getMessage());

            return;
        }

        if ($reason = $driver->unavailableReason($provider)) {
            $provider->markTestResult(false, ucfirst($reason).'.');
            $this->toastError('Cannot test '.$provider->label(), ucfirst($reason).'.');

            return;
        }

        try {
            $driver->complete($provider, new CompletionRequest(
                messages: [new ChatMessage('user', 'Reply with exactly one word: ok.')],
                maxTokens: 8,
            ));
        } catch (CompletionFailed $e) {
            $provider->markTestResult(false, $e->getMessage());
            Log::warning('platform:assistant test failed for '.$provider->driver.': '.$e->getMessage());
            $this->toastError('Connection failed', $e->getMessage());

            return;
        }

        $provider->markTestResult(true, null);
        $this->toastSuccess('Connected', $provider->label().' answered.');
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
                    <h2 class="text-lg font-semibold text-mono">Assistant</h2>
                    <p class="text-sm text-secondary-foreground mt-1">
                        The AI provider Kargah asks. It reaches Kargah through the same application-password scopes
                        as anything else, and never gets a privileged shortcut.
                    </p>
                </div>
                @unless ($editing)
                    <button type="button" class="kt-btn kt-btn-primary gap-2" wire:click="openCreate">
                        <i class="ki-filled ki-plus"></i> Add provider
                    </button>
                @endunless
            </div>

            @if ($editing)
                <div class="kt-card" id="edit">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">{{ $editingId === null ? 'Add provider' : 'Edit provider' }}</h3>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label font-normal text-mono" for="asst-name">Name</label>
                                <input id="asst-name" type="text" placeholder="Gemini (default)"
                                       class="kt-input @error('name') border-destructive @enderror"
                                       wire:model="name">
                                <span class="text-xs text-muted-foreground mt-1">Changes the label this provider carries in the list below.</span>
                                @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label font-normal text-mono" for="asst-driver">Provider</label>
                                <select id="asst-driver" class="kt-select" wire:model.live="driver">
                                    @foreach ($driverOptions as $key => $option)
                                        <option value="{{ $key }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <span class="text-xs text-muted-foreground mt-1">
                                    Changes which company's service answers, and therefore which fields below are needed.
                                    {{ $driverOptions[$driver]['summary'] }}
                                </span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label font-normal text-mono" for="asst-model">Model</label>
                                <input id="asst-model" type="text" placeholder="{{ $driverOptions[$driver]['modelPlaceholder'] }}"
                                       class="kt-input @error('model') border-destructive @enderror"
                                       wire:model="model">
                                <span class="text-xs text-muted-foreground mt-1">
                                    Changes which of that provider's models answers, which changes both the quality and the
                                    price of every reply. Leave blank to use {{ $driverOptions[$driver]['modelPlaceholder'] }}.
                                </span>
                                @error('model')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1 pt-6">
                                <div class="flex items-center gap-2.5">
                                    <input id="asst-active" type="checkbox" class="kt-checkbox" wire:model="isActive">
                                    <label class="text-sm text-mono cursor-pointer" for="asst-active">Active</label>
                                </div>
                                <span class="text-xs text-muted-foreground mt-1">
                                    Unticking takes this provider out of the assistant's reach without deleting its stored key.
                                </span>
                            </div>
                        </div>

                        @if ($driverOptions[$driver]['requiresKey'])
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label font-normal text-mono" for="asst-key">API key</label>
                                <input id="asst-key" type="password" autocomplete="off"
                                       placeholder="{{ $driverOptions[$driver]['keyPlaceholder'] }}"
                                       class="kt-input @error('apiKey') border-destructive @enderror"
                                       wire:model="apiKey">
                                <span class="text-xs text-muted-foreground mt-1">
                                    Replaces the stored key, so a wrong one makes the assistant fail on the very next question.
                                    {{ $driverOptions[$driver]['keyHint'] }}
                                    @if ($editingId !== null)
                                        Leave blank to keep the key already stored.
                                    @endif
                                    Stored encrypted and never shown again once saved.
                                </span>
                                @error('apiKey')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                        @endif

                        @if ($driverOptions[$driver]['requiresBaseUrl'])
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label font-normal text-mono" for="asst-base-url">Base URL</label>
                                <input id="asst-base-url" type="text" placeholder="{{ $driverOptions[$driver]['baseUrlPlaceholder'] }}"
                                       class="kt-input @error('baseUrl') border-destructive @enderror"
                                       wire:model="baseUrl">
                                <span class="text-xs text-muted-foreground mt-1">
                                    Changes which server Kargah sends every question to. {{ $driverOptions[$driver]['baseUrlHint'] }}
                                </span>
                                @error('baseUrl')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                        @endif

                        <div class="flex items-center gap-2">
                            <button type="button" class="kt-btn kt-btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">{{ $editingId === null ? 'Add provider' : 'Save changes' }}</span>
                                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                                </span>
                            </button>
                            <button type="button" class="kt-btn kt-btn-ghost" wire:click="closeForm">Cancel</button>
                        </div>

                    </div>
                </div>
            @endif

            <div class="kt-card" id="providers">
                <div class="kt-card-header"><h3 class="kt-card-title">Configured providers</h3></div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[200px]">Provider</th>
                                    <th class="min-w-[160px]">Model</th>
                                    <th class="w-[110px]">Key</th>
                                    <th class="min-w-[240px]">Connection</th>
                                    <th class="w-[90px]">State</th>
                                    <th class="w-[220px] text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($providers as $provider)
                                    <tr wire:key="assistant-provider-{{ $provider['id'] }}">
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <i class="ki-filled {{ $provider['icon'] }} {{ $provider['tone'] }}"></i>
                                                <div>
                                                    <div class="font-medium text-mono">{{ $provider['name'] }}</div>
                                                    <div class="text-xs text-muted-foreground">
                                                        {{ $provider['driverLabel'] }}
                                                        @if ($provider['isDefault'])
                                                            <span class="kt-badge kt-badge-sm kt-badge-primary ms-1">Default</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-secondary-foreground font-mono text-xs">{{ $provider['model'] }}</td>
                                        <td>
                                            @if ($provider['requiresKey'])
                                                <span class="{{ $provider['hasKey'] ? 'kt-badge kt-badge-sm kt-badge-success' : 'kt-badge kt-badge-sm kt-badge-warning' }}">
                                                    {{ $provider['hasKey'] ? '•••• set' : 'Not set' }}
                                                </span>
                                            @else
                                                <span class="text-xs text-muted-foreground">No key needed</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="{{ $provider['healthTone'] }}">{{ $provider['health'] }}</span>
                                            <div class="text-xs text-muted-foreground mt-1">{{ $provider['healthDetail'] }}</div>
                                            <div class="text-[11px] text-muted-foreground">Tested {{ $provider['lastTested'] }}</div>
                                        </td>
                                        <td>
                                            <span class="{{ $provider['isActive'] ? 'kt-badge kt-badge-sm kt-badge-success' : 'kt-badge kt-badge-sm kt-badge-outline' }}">
                                                {{ $provider['isActive'] ? 'Active' : 'Disabled' }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="flex items-center justify-end gap-1">
                                                <button type="button" class="kt-btn kt-btn-sm kt-btn-outline"
                                                        wire:click="test({{ $provider['id'] }})"
                                                        wire:loading.attr="disabled" wire:target="test({{ $provider['id'] }})">
                                                    <span wire:loading.remove wire:target="test({{ $provider['id'] }})">Test</span>
                                                    <span wire:loading wire:target="test({{ $provider['id'] }})" class="inline-flex items-center gap-1.5">
                                                        <i class="ki-filled ki-loading animate-spin text-sm"></i> Asking…
                                                    </span>
                                                </button>
                                                @unless ($provider['isDefault'])
                                                    <button type="button" class="kt-btn kt-btn-sm kt-btn-ghost"
                                                            wire:click="makeDefault({{ $provider['id'] }})"
                                                            wire:loading.attr="disabled" wire:target="makeDefault({{ $provider['id'] }})">
                                                        Make default
                                                    </button>
                                                @endunless
                                                <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                        wire:click="toggleActive({{ $provider['id'] }})"
                                                        wire:loading.attr="disabled" wire:target="toggleActive({{ $provider['id'] }})"
                                                        title="{{ $provider['isActive'] ? 'Disable' : 'Enable' }}"
                                                        aria-label="{{ $provider['isActive'] ? 'Disable' : 'Enable' }} {{ $provider['name'] }}">
                                                    <i class="ki-filled {{ $provider['isActive'] ? 'ki-toggle-on' : 'ki-toggle-off' }} text-sm"></i>
                                                </button>
                                                <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-7"
                                                        wire:click="openEdit({{ $provider['id'] }})"
                                                        title="Edit" aria-label="Edit {{ $provider['name'] }}">
                                                    <i class="ki-filled ki-notepad-edit text-sm"></i>
                                                </button>
                                                <button type="button" class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive"
                                                        wire:click="delete({{ $provider['id'] }})"
                                                        wire:loading.attr="disabled" wire:target="delete({{ $provider['id'] }})"
                                                        wire:confirm="Remove {{ $provider['name'] }}? Its stored API key is deleted with it and cannot be recovered — you would have to paste the key again from the provider's own console. If this is the default, another active provider takes over; if there is no other, the assistant stops answering."
                                                        title="Remove" aria-label="Remove {{ $provider['name'] }}">
                                                    <i class="ki-filled ki-trash text-sm"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6">
                                            <div class="flex flex-col items-center py-14 text-center gap-3">
                                                <i class="ki-filled ki-message-programming text-4xl text-muted-foreground"></i>
                                                <p class="text-sm text-secondary-foreground">
                                                    No provider is configured yet. Add one to let the assistant answer.
                                                </p>
                                                <button type="button" class="kt-btn kt-btn-primary gap-2" wire:click="openCreate">
                                                    <i class="ki-filled ki-plus"></i> Add provider
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
                        <strong class="text-mono">The key is write-only.</strong>
                        Once saved, it is never rendered back into this page — only whether one is set. Replace a key
                        by typing a new one; leaving the field blank on an edit keeps the one already stored.
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>
