<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Models\Bookmark;

/**
 * Saving a URL worth keeping.
 *
 * The four kinds are the ones the schema names, not a free-text field: a kind
 * outside that set has no icon, no badge and no filter button, and the list page
 * would draw a blank glyph rather than fail.
 *
 * There is deliberately no bot-token field. A Telegram token grants full control
 * of the bot, which makes it a vault entry — `bookmarks` has no encrypted column
 * and should not grow one, because then there would be two places to look for a
 * secret and only one of them audited.
 */
new
#[Title('New link — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Validate('required|string|max:190')]
    public string $title = '';

    #[Validate('required|url|max:500')]
    public string $url = '';

    #[Validate('required|string')]
    public string $kind = Bookmark::KIND_DEPLOYED_PROJECT;

    /** @var array<int, string> */
    public array $tags = [];

    public string $tagInput = '';

    #[Validate('nullable|string|max:1000')]
    public string $notes = '';

    /** @return array<string, array{label: string, icon: string, hint: string}> */
    public function kinds(): array
    {
        return [
            Bookmark::KIND_TELEGRAM_BOT => ['label' => 'Telegram bot', 'icon' => 'ki-paper-plane', 'hint' => 'A bot you run or maintain'],
            Bookmark::KIND_DEPLOYED_PROJECT => ['label' => 'Project', 'icon' => 'ki-rocket', 'hint' => 'Something you deployed'],
            Bookmark::KIND_TOOL => ['label' => 'Tool', 'icon' => 'ki-setting-2', 'hint' => 'Hosting, DNS, analytics'],
            Bookmark::KIND_REFERENCE => ['label' => 'Reference', 'icon' => 'ki-book', 'hint' => 'Docs and references'],
        ];
    }

    /**
     * The descriptor the preview draws, for whatever `kind` currently holds.
     *
     * 🔴 `kind` is driven by `$set('kind', …)` from `wire:click` rather than by
     * `wire:model`, and `$set` sets whatever the client sends. The preview used
     * to index `$kinds[$kind]` directly, so an unknown value killed the whole
     * page with `Undefined array key` **during render** — before `save()` and
     * its allow-list ever ran. Measured in Chrome on 5 August 2026:
     * `$wire.$set('kind', 'not-a-kind')` answered `HTTP 500` from
     * `/livewire/update`.
     *
     * The fallback names the problem rather than hiding it behind the default
     * kind: none of the four cards is highlighted, the preview says the value
     * is not a kind, and `save()` still refuses it with the same message.
     *
     * @return array{label: string, icon: string, hint: string}
     */
    public function currentKind(): array
    {
        return $this->kinds()[$this->kind] ?? [
            'label' => 'Not a known kind',
            'icon' => 'ki-question',
            'hint' => 'Pick one of the four above.',
        ];
    }

    public function with(): array
    {
        return [
            'kinds' => $this->kinds(),
            'current' => $this->currentKind(),
            'host' => $this->url === '' ? null : (parse_url($this->url, PHP_URL_HOST) ?: null),
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

    public function save(): void
    {
        // 🔴 Two calls, and it has to be two.
        //
        // Passing a rules array to `validate()` **replaces** the `#[Validate]`
        // attribute rules for that call rather than merging them. The single
        // call that used to be here therefore validated `kind` and nothing
        // else — and `kind` carries a default, so it always passed. `title` and
        // `url` were never checked despite carrying `required|string|max:190`
        // and `required|url|max:500` a few lines up, and an empty form reached
        // `create()`: found in a browser on 4 August 2026, an empty submit
        // produced the `bookmarks` row `title="" url=""` and redirected to the
        // list with a success toast reading "Saved ".
        //
        // The bare call runs the attribute rules. The allow-list cannot join
        // them, because it needs `Bookmark::KINDS` at runtime and a PHP
        // attribute takes only constant expressions — `implode()` is a function
        // call and is not one. Hence a second call rather than one merged array.
        $this->validate();

        $this->validate([
            'kind' => 'required|string|in:'.implode(',', Bookmark::KINDS),
        ]);

        $bookmark = Bookmark::query()->create([
            'title' => $this->title,
            'url' => $this->url,
            'kind' => $this->kind,
            'notes' => $this->notes ?: null,
            'tags' => $this->tags,
            'created_by' => auth()->id(),
        ]);

        $this->flashToast('success', 'Saved '.$bookmark->title, 'It is on the links page under '.$this->kinds()[$bookmark->kind]['label'].'.');

        $this->redirectRoute('data.links', navigate: true);
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

            @if ($kind === \Modules\Data\Models\Bookmark::KIND_TELEGRAM_BOT)
                <div class="kt-card bg-info/5 border-info/30">
                    <div class="kt-card-content flex items-start gap-3 p-4">
                        <i class="ki-filled ki-lock-2 text-info text-lg mt-0.5 shrink-0"></i>
                        <div class="text-sm text-secondary-foreground">
                            <strong class="text-mono">Keep the bot token in the vault, not here.</strong>
                            A token grants full control of the bot, so it belongs somewhere encrypted and audited.
                            <a href="{{ route('data.credential-create') }}" wire:navigate class="text-primary hover:underline">Add it as a credential</a>
                            and leave a note here saying which entry it is.
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="flex flex-col gap-5">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Preview</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary shrink-0">
                            <i class="ki-filled {{ $current['icon'] }} text-lg"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="font-semibold text-mono truncate">{{ $title !== '' ? $title : 'Untitled link' }}</div>
                            <div class="text-xs text-muted-foreground">{{ $current['label'] }}</div>
                        </div>
                    </div>
                    <div class="text-sm text-primary truncate">{{ $url !== '' ? $url : '—' }}</div>
                    <div class="text-xs text-muted-foreground">{{ $host ?? 'Enter a URL and the host appears here.' }}</div>
                    @if (count($tags) > 0)
                        <div class="flex flex-wrap gap-1 pt-3 border-t border-border">
                            @foreach ($tags as $tag)
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Why bother</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3 text-sm text-secondary-foreground">
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-pulse text-info mt-0.5"></i>
                        <span>Each link can be checked on demand, and the status it answered with is kept.</span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-tag text-primary mt-0.5"></i>
                        <span>Tags are how you find a link when you have forgotten what you called it.</span>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <i class="ki-filled ki-information-2 text-warning mt-0.5"></i>
                        <span>Nothing here is fetched during a render, so a dead link never slows the page down.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
