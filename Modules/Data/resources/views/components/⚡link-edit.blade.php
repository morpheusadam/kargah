<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Link editor.
 *
 * Saves any URL worth keeping: a deployed project, a hosting panel, a reference
 * page, or a Telegram bot. Bot tokens are treated like vault secrets — masked in
 * the field and encrypted before storage.
 */
new
#[Title('New link — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Validate('required|string|max:120')]
    public string $title = '';

    #[Validate('required|url|max:255')]
    public string $url = '';

    #[Validate('required|string|in:bot,project,panel,resource')]
    public string $kind = 'project';

    /** @var array<int, string> */
    public array $tags = [];

    public string $tagInput = '';

    #[Validate('nullable|string|max:1000')]
    public string $notes = '';

    /** Telegram-bot extras. */
    #[Validate('nullable|string|max:64')]
    public string $botUsername = '';

    #[Validate('nullable|string|max:120')]
    public string $botToken = '';

    public bool $tokenRevealed = false;

    public function with(): array
    {
        $host = $this->url === '' ? null : parse_url($this->url, PHP_URL_HOST);

        return [
            'kinds' => [
                'bot'      => ['label' => 'Telegram bot', 'icon' => 'ki-paper-plane',      'hint' => 'A bot you run or maintain'],
                'project'  => ['label' => 'Project',      'icon' => 'ki-rocket',    'hint' => 'Something you deployed'],
                'panel'    => ['label' => 'Panel',        'icon' => 'ki-setting-2', 'hint' => 'Hosting, DNS, analytics'],
                'resource' => ['label' => 'Resource',     'icon' => 'ki-book',      'hint' => 'Docs and references'],
            ],
            'host' => $host,
            'suggestedTags' => ['laravel', 'hosting', 'client', 'telegram', 'tool', 'docs'],
        ];
    }

    public function addTag(): void
    {
        $tag = trim(mb_strtolower($this->tagInput));
        $duplicate = in_array($tag, $this->tags, true);
        $full = count($this->tags) >= 8;

        if ($tag !== '' && ! $duplicate && ! $full) {
            $this->tags[] = $tag;
        }

        $this->tagInput = '';

        // A chip that lands appears right under the field, so a success toast
        // would be noise. The two ways this silently does nothing are not.
        if ($tag === '') {
            return;
        }

        if ($duplicate) {
            $this->toastWarning('Tag already on this link', $tag);
        } elseif ($full) {
            $this->toastWarning('Tag not added', 'A link carries at most eight tags.');
        }
    }

    public function addSuggested(string $tag): void
    {
        $this->tagInput = $tag;

        // addTag() reports the outcome; a second toast here would double up.
        $this->addTag();
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags);

        // The chip disappears as you click it — that is the confirmation.
    }

    public function toggleToken(): void
    {
        $this->tokenRevealed = ! $this->tokenRevealed;

        // Worth flagging in one direction only: re-masking is visible in the field.
        if ($this->tokenRevealed) {
            $this->toastSuccess('Bot token is now readable on screen', 'A token grants full control of the bot. Hide it when you are done.');
        }
    }

    /** Call getMe on the Telegram API to confirm the token works. */
    public function testBot(): void
    {
        // Backend: GET https://api.telegram.org/bot<token>/getMe and report the result.

        $this->toastInfo('Bot test is not available yet', 'Calling getMe needs the backend, so the token is still unchecked.');
    }

    public function save(): void
    {
        // Backend: validate, encrypt the bot token, persist the link and its tags.

        $this->toastInfo('Saving is not wired up yet', 'Storing the link and encrypting the bot token both need the backend.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('data.links') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                <i class="ki-filled ki-left text-xs"></i> Links &amp; bots
            </a>
            <h1 class="text-xl font-semibold text-mono mt-1">New link</h1>
            <p class="text-sm text-secondary-foreground mt-1">Keep a URL where you will actually look for it later.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('data.links') }}" wire:navigate class="kt-btn kt-btn-outline">Cancel</a>
            <button wire:click="save" wire:loading.attr="disabled" wire:target="save" class="kt-btn kt-btn-primary gap-2">
                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-check"></i> Save link
                </span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        <div class="xl:col-span-2 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Link</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="link-title">Title</label>
                        <input id="link-title" type="text" class="kt-input @error('title') border-destructive @enderror"
                               placeholder="Northwind Studio staging" wire:model="title">
                        @error('title')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="link-url">URL</label>
                        <input id="link-url" type="url" class="kt-input @error('url') border-destructive @enderror"
                               placeholder="https://staging.northwind.studio" wire:model.live.debounce.500ms="url">
                        @error('url')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div class="flex flex-col">
                        <span class="kt-form-label">Kind</span>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            @foreach ($kinds as $key => $k)
                                <button type="button" wire:click="$set('kind', '{{ $key }}')"
                                        class="kt-card p-3 text-start transition-colors {{ $kind === $key ? 'border-primary/60 bg-primary/5' : 'hover:border-primary/40' }}">
                                    <i class="ki-filled {{ $k['icon'] }} text-lg {{ $kind === $key ? 'text-primary' : 'text-muted-foreground' }}"></i>
                                    <div class="text-sm font-medium text-mono mt-2">{{ $k['label'] }}</div>
                                    <div class="text-xs text-muted-foreground mt-0.5">{{ $k['hint'] }}</div>
                                </button>
                            @endforeach
                        </div>
                        @error('kind')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    {{-- Tags --}}
                    <div class="flex flex-col">
                        <label class="kt-form-label" for="link-tag">Tags</label>
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            @forelse ($tags as $i => $tag)
                                <span wire:key="tag-{{ $tag }}" class="kt-badge kt-badge-sm kt-badge-outline gap-1.5">
                                    {{ $tag }}
                                    <button type="button" wire:click="removeTag({{ $i }})" title="Remove {{ $tag }}" aria-label="Remove {{ $tag }}">
                                        <i class="ki-filled ki-cross text-[10px]"></i>
                                    </button>
                                </span>
                            @empty
                                <span class="text-xs text-muted-foreground">No tags yet.</span>
                            @endforelse
                        </div>
                        <div class="flex items-center gap-2">
                            <input id="link-tag" type="text" class="kt-input grow" placeholder="Type a tag and press enter"
                                   wire:model="tagInput" wire:keydown.enter.prevent="addTag">
                            <button type="button" wire:click="addTag" class="kt-btn kt-btn-outline shrink-0">Add</button>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 mt-2">
                            <span class="text-xs text-muted-foreground">Common:</span>
                            @foreach ($suggestedTags as $s)
                                <button type="button" wire:click="addSuggested('{{ $s }}')"
                                        class="text-[11px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground hover:text-primary">{{ $s }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-col">
                        <label class="kt-form-label" for="link-notes">Notes</label>
                        <textarea id="link-notes" rows="3" class="kt-textarea @error('notes') border-destructive @enderror"
                                  placeholder="Which client it belongs to, which branch deploys here, who has access."
                                  wire:model="notes"></textarea>
                        @error('notes')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            {{-- Telegram bot block --}}
            @if ($kind === 'bot')
                <div class="kt-card border-info/30">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title flex items-center gap-2">
                            <i class="ki-filled ki-paper-plane text-info"></i> Telegram bot
                        </h3>
                        <span class="kt-badge kt-badge-sm kt-badge-info">Extra fields</span>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-5">

                        <div class="flex flex-col">
                            <label class="kt-form-label" for="bot-username">Bot username</label>
                            <div class="kt-input">
                                <span class="text-muted-foreground">&#64;</span>
                                <input id="bot-username" type="text" placeholder="northwind_invoices_bot" wire:model="botUsername">
                            </div>
                            @error('botUsername')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-col">
                            <label class="kt-form-label" for="bot-token">Bot token</label>
                            <div class="flex items-center gap-2">
                                <input id="bot-token" type="{{ $tokenRevealed ? 'text' : 'password' }}"
                                       class="kt-input grow @error('botToken') border-destructive @enderror"
                                       placeholder="••••••••••••••••••••" autocomplete="off" wire:model="botToken">
                                <button type="button" wire:click="toggleToken" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0"
                                        title="{{ $tokenRevealed ? 'Hide token' : 'Reveal token' }}"
                                        aria-label="{{ $tokenRevealed ? 'Hide token' : 'Reveal token' }}">
                                    <i class="ki-filled {{ $tokenRevealed ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                                </button>
                            </div>
                            <span class="text-xs text-muted-foreground mt-1">
                                Encrypted with the application key, exactly like a vault secret. Masked until you reveal it.
                            </span>
                            @error('botToken')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div class="flex flex-wrap items-center gap-3 pt-1">
                            <button type="button" wire:click="testBot" wire:loading.attr="disabled" wire:target="testBot"
                                    class="kt-btn kt-btn-outline gap-2">
                                <span wire:loading.remove wire:target="testBot" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-pulse"></i> Test bot
                                </span>
                                <span wire:loading wire:target="testBot" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Calling getMe…
                                </span>
                            </button>
                            <span class="text-xs text-muted-foreground">
                                Calls <code class="px-1 py-0.5 rounded bg-muted">getMe</code> on the Telegram API. Last result: —
                            </span>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="flex flex-col gap-5">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Favicon</h3></div>
                <div class="kt-card-content p-5 flex flex-col items-center gap-3 text-center">
                    <div class="flex items-center justify-center size-16 rounded-lg bg-muted border border-border">
                        @if ($host)
                            <span class="text-2xl font-semibold text-primary uppercase">{{ mb_substr($host, 0, 1) }}</span>
                        @else
                            <i class="ki-filled ki-map text-2xl text-muted-foreground"></i>
                        @endif
                    </div>
                    <div class="min-w-0 w-full">
                        <div class="text-sm font-medium text-mono truncate">{{ $host ?? '—' }}</div>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ $host
                                ? 'The icon is fetched and cached locally on save, so the list never calls out to the site.'
                                : 'Enter a URL and the host appears here.' }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Preview</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary shrink-0">
                            <i class="ki-filled {{ $kinds[$kind]['icon'] }} text-lg"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="font-semibold text-mono truncate">{{ $title !== '' ? $title : 'Untitled link' }}</div>
                            <div class="text-xs text-muted-foreground">{{ $kinds[$kind]['label'] }}</div>
                        </div>
                    </div>
                    <div class="text-sm text-primary truncate">{{ $url !== '' ? $url : '—' }}</div>
                    @if (count($tags) > 0)
                        <div class="flex flex-wrap gap-1 pt-3 border-t border-border">
                            @foreach ($tags as $tag)
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
