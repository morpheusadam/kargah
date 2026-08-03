<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Models\Bookmark;

/**
 * Links and bots.
 *
 * A single place for every URL that matters: Telegram bots, deployed projects,
 * panels, references. Grouped by kind so things are found by what they are
 * rather than by when they were saved.
 *
 * `check()` is the one thing on this page that leaves the server, and it only
 * ever runs because somebody pressed the button. Nothing here calls out during
 * a render — a page that waits on someone else's uptime is a page that is down
 * whenever they are.
 */
new
#[Title('Links & Bots — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $kind = 'all';

    public string $search = '';

    /**
     * How each kind is drawn.
     *
     * Whole class strings in a map, never built by concatenation: the Tailwind
     * scanner reads these files as text and cannot see a class it has to
     * assemble at run time.
     *
     * @return array<string, array{label: string, icon: string, badge: string}>
     */
    public function kinds(): array
    {
        return [
            Bookmark::KIND_TELEGRAM_BOT => ['label' => 'Telegram bots', 'icon' => 'ki-paper-plane', 'badge' => 'kt-badge-info'],
            Bookmark::KIND_DEPLOYED_PROJECT => ['label' => 'Projects', 'icon' => 'ki-rocket', 'badge' => 'kt-badge-primary'],
            Bookmark::KIND_TOOL => ['label' => 'Tools', 'icon' => 'ki-setting-2', 'badge' => 'kt-badge-warning'],
            Bookmark::KIND_REFERENCE => ['label' => 'References', 'icon' => 'ki-book', 'badge' => 'kt-badge-outline'],
        ];
    }

    public function with(): array
    {
        $kinds = $this->kinds();

        $bookmarks = Bookmark::query()
            ->search($this->search)
            ->when(isset($kinds[$this->kind]), fn ($query) => $query->ofKind($this->kind))
            ->orderBy('kind')
            ->orderBy('title')
            ->get();

        return [
            'kinds' => $kinds,
            'links' => $bookmarks,
            'counts' => Bookmark::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind'),
        ];
    }

    /**
     * Ask a URL whether it is still there.
     *
     * A HEAD request with a short timeout, because the answer is a status code
     * and downloading a page body to learn it would be rude at best. A failure
     * is recorded as a failure rather than left blank: "checked and unreachable"
     * and "never checked" are different facts.
     */
    public function check(int $id): void
    {
        $bookmark = Bookmark::query()->find($id);

        if ($bookmark === null) {
            return;
        }

        try {
            $status = Http::timeout(5)->withoutVerifying()->head($bookmark->url)->status();
        } catch (ConnectionException $e) {
            $bookmark->forceFill(['last_checked_at' => now(), 'last_status' => null])->save();

            $this->toastError($bookmark->title.' could not be reached', $e->getMessage());

            return;
        }

        $bookmark->forceFill(['last_checked_at' => now(), 'last_status' => $status])->save();

        $status < 400
            ? $this->toastSuccess($bookmark->title.' answered '.$status, 'Checked just now.')
            : $this->toastWarning($bookmark->title.' answered '.$status, 'The link resolves but the page did not load.');
    }

    public function delete(int $id): void
    {
        $bookmark = Bookmark::query()->find($id);

        if ($bookmark === null) {
            return;
        }

        $title = $bookmark->title;
        $bookmark->delete();

        $this->toastSuccess('Removed '.$title, 'The row is soft deleted, so it can be brought back from the database.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Links &amp; Bots</h1>
            <p class="text-sm text-secondary-foreground mt-1">Every URL you would otherwise lose in a chat history.</p>
        </div>
        <a href="{{ route('data.link-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Add link
        </a>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button wire:click="$set('kind', 'all')"
                class="kt-btn kt-btn-sm gap-2 {{ $kind === 'all' ? 'kt-btn-primary' : 'kt-btn-outline' }}">
            <i class="ki-filled ki-element-11 text-sm"></i> All
        </button>
        @foreach ($kinds as $key => $k)
            <button wire:click="$set('kind', '{{ $key }}')"
                    class="kt-btn kt-btn-sm gap-2 {{ $kind === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <i class="ki-filled {{ $k['icon'] }} text-sm"></i> {{ $k['label'] }}
                <span class="text-xs opacity-70">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
        <div class="kt-input max-w-[240px] ms-auto">
            <i class="ki-filled ki-magnifier text-muted-foreground"></i>
            <input type="text" placeholder="Search links…" aria-label="Search links"
                   wire:model.live.debounce.300ms="search">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        @forelse ($links as $l)
            <div class="kt-card group" wire:key="bookmark-{{ $l->id }}">
                <div class="kt-card-content p-5 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled {{ $kinds[$l->kind]['icon'] ?? 'ki-arrow-up-right' }} text-lg"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-mono truncate">{{ $l->title }}</div>
                                <div class="text-xs text-muted-foreground truncate">{{ $l->host() ?? '—' }}</div>
                            </div>
                        </div>
                        <span class="kt-badge kt-badge-sm {{ $kinds[$l->kind]['badge'] ?? 'kt-badge-outline' }} shrink-0">
                            {{ $kinds[$l->kind]['label'] ?? $l->kind }}
                        </span>
                    </div>

                    <a href="{{ $l->url }}" target="_blank" rel="noopener"
                       class="text-sm text-primary hover:underline truncate">{{ $l->url }}</a>

                    @if ($l->notes)
                        <p class="text-xs text-secondary-foreground line-clamp-2">{{ $l->notes }}</p>
                    @endif

                    <div class="flex items-center justify-between gap-3 pt-3 border-t border-border">
                        <div class="flex flex-wrap items-center gap-1 min-w-0">
                            @foreach ($l->tagList() as $t)
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground">{{ $t }}</span>
                            @endforeach
                            @if ($l->last_checked_at)
                                <span class="kt-badge kt-badge-sm {{ $l->last_status && $l->last_status < 400 ? 'kt-badge-success' : 'kt-badge-destructive' }}">
                                    {{ $l->last_status ?? 'unreachable' }}
                                </span>
                            @endif
                        </div>
                        <div class="flex gap-1 shrink-0 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                            <button data-copy-text="{{ $l->url }}" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Copy link" aria-label="Copy link">
                                <i class="ki-filled ki-copy text-sm"></i>
                            </button>
                            <button wire:click="check({{ $l->id }})" wire:loading.attr="disabled" wire:target="check({{ $l->id }})"
                                    class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Check that it still answers" aria-label="Check that it still answers">
                                <span wire:loading.remove wire:target="check({{ $l->id }})"><i class="ki-filled ki-pulse text-sm"></i></span>
                                <span wire:loading wire:target="check({{ $l->id }})"><i class="ki-filled ki-loading animate-spin text-sm"></i></span>
                            </button>
                            <button wire:click="delete({{ $l->id }})" wire:confirm="Remove {{ $l->title }} from the list?"
                                    class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive" title="Remove" aria-label="Remove">
                                <i class="ki-filled ki-trash text-sm"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full kt-card">
                <div class="kt-card-content flex flex-col items-center py-14 text-center gap-3">
                    <i class="ki-filled ki-arrow-up-right text-4xl text-muted-foreground"></i>
                    <p class="text-sm text-secondary-foreground">
                        {{ $search !== '' || $kind !== 'all' ? 'No link matches that filter.' : 'No links saved yet.' }}
                    </p>
                    <a href="{{ route('data.link-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                        <i class="ki-filled ki-plus"></i> Add link
                    </a>
                </div>
            </div>
        @endforelse
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
            if (! $wire.$el || ! $wire.$el.isConnected) return;

            $wire.$el.querySelectorAll('[data-copy-text]').forEach(function (button) {
                // Ask the element whether it is bound rather than marking it:
                // the morph strips any attribute the new HTML does not carry.
                if (button.onclick) return;

                button.onclick = function () {
                    window.kargahCopy(button.getAttribute('data-copy-text'));
                };
            });
        }

        Livewire.hook('morphed', mount);
        mount();
    })();
    </script>
    @endscript
</div>
