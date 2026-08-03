<?php

use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * File preview drawer.
 *
 * Nested inside the file browser and given the selected file as a plain array —
 * the same shape `AttachmentService` hands to every other module, so this drawer
 * is not privileged over an invoice page that shows the same file.
 *
 * The share link is a Laravel signed URL with an expiry. It is not stored,
 * because there is nothing to store: the signature is derived from the URL and
 * the application key, so the server can check it without having written
 * anything down. That is also why there is no revoke button — an expiry is the
 * revocation, and offering a button that cannot actually withdraw a link would
 * be worse than offering nothing.
 */
new
class extends Component
{
    use InteractsWithToasts;

    /** The selected file, passed down from the browser. */
    public array $file = [];

    /** Expiry chosen for a new shareable link. */
    public string $expiry = '7d';

    /** The link, once one has been signed. Held for this page view only. */
    public ?string $shareUrl = null;

    public function with(): array
    {
        return [
            'expiries' => [
                '24h' => 'Expires in 24 hours',
                '7d' => 'Expires in 7 days',
                '30d' => 'Expires in 30 days',
            ],
            'imageTypes' => ['png', 'jpg', 'jpeg', 'svg', 'webp'],
        ];
    }

    /** Sign a temporary URL that anyone holding it can open, until it expires. */
    public function createLink(): void
    {
        $until = match ($this->expiry) {
            '24h' => now()->addDay(),
            '30d' => now()->addDays(30),
            default => now()->addDays(7),
        };

        $this->shareUrl = URL::temporarySignedRoute(
            'data.file-share',
            $until,
            ['attachment' => $this->file['id']],
        );

        $this->toastSuccess(
            'Link signed',
            'It stops working '.$until->diffForHumans().'. Nothing else on the disk becomes reachable.'
        );
    }
};

?>

<aside class="kt-card sticky top-5 self-start" aria-label="File preview">
    <div class="kt-card-header">
        <h3 class="kt-card-title truncate">Preview</h3>
        <button wire:click="$parent.closePreview()" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Close preview" aria-label="Close preview">
            <i class="ki-filled ki-cross text-sm"></i>
        </button>
    </div>

    <div class="kt-card-content flex flex-col gap-5 p-5">

        <div class="flex items-center justify-center h-36 rounded-lg bg-muted border border-border">
            @if (in_array($file['extension'] ?? '', $imageTypes, true))
                <i class="ki-filled ki-picture text-5xl text-info"></i>
            @else
                <i class="ki-filled {{ $file['icon'] ?? 'ki-document' }} text-5xl text-muted-foreground"></i>
            @endif
        </div>

        <div class="min-w-0">
            <div class="font-semibold text-mono break-words">{{ $file['name'] ?? '—' }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ $file['target_label'] ?? '—' }}</div>
        </div>

        <dl class="flex flex-col gap-2.5 text-sm">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Size</dt>
                <dd class="text-secondary-foreground">{{ $file['size'] ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Type</dt>
                <dd><span class="kt-badge kt-badge-sm kt-badge-outline uppercase">{{ $file['extension'] ?? '—' }}</span></dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Content type</dt>
                <dd class="text-secondary-foreground truncate">{{ $file['mime'] ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Added</dt>
                <dd class="text-secondary-foreground">{{ $file['added'] ?? '—' }}</dd>
            </div>
        </dl>

        <a href="{{ $file['download_url'] ?? '#' }}" class="kt-btn kt-btn-outline w-full gap-2">
            <i class="ki-filled ki-exit-down"></i> Download
        </a>

        {{-- Shareable link --}}
        <div class="pt-4 border-t border-border flex flex-col gap-3">
            <h4 class="text-sm font-semibold text-mono">Shareable link</h4>

            @if ($shareUrl)
                <div class="flex items-center gap-2">
                    <div class="kt-input grow min-w-0">
                        <i class="ki-filled ki-arrow-up-right text-muted-foreground"></i>
                        <input type="text" readonly value="{{ $shareUrl }}" aria-label="Shareable link">
                    </div>
                    <button data-copy-text="{{ $shareUrl }}" class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0"
                            title="Copy link" aria-label="Copy link">
                        <i class="ki-filled ki-copy text-sm"></i>
                    </button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Signed with the application key and stamped with an expiry. It cannot be withdrawn early —
                    the expiry is the only revocation a stateless signature has.
                </p>
            @else
                <p class="text-xs text-muted-foreground">
                    No link yet. A signed link opens this one file and stops working the moment it expires.
                    The disk itself stays private throughout.
                </p>
                <select class="kt-select" wire:model="expiry" aria-label="Link expiry">
                    @foreach ($expiries as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <button wire:click="createLink" wire:loading.attr="disabled" wire:target="createLink" class="kt-btn kt-btn-primary w-full gap-2">
                    <span wire:loading.remove wire:target="createLink" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-arrow-up-right"></i> Create link
                    </span>
                    <span wire:loading wire:target="createLink" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Signing…
                    </span>
                </button>
            @endif
        </div>

        {{-- Integrity and removal --}}
        <div class="pt-4 border-t border-border flex flex-col gap-3">
            <h4 class="text-sm font-semibold text-mono">Checksum</h4>
            <code class="block text-[11px] leading-relaxed break-all rounded-lg bg-muted p-2.5 text-secondary-foreground">{{ $file['checksum'] ?? '—' }}</code>
            <p class="text-xs text-muted-foreground">
                SHA-256 of the bytes as stored. It is what turns "the file is there" into "the file is intact".
            </p>

            <button wire:click="$parent.deleteFile({{ $file['id'] ?? 0 }})"
                    wire:confirm="Remove {{ $file['name'] ?? 'this file' }}?"
                    class="kt-btn kt-btn-outline text-destructive w-full gap-2">
                <i class="ki-filled ki-trash"></i> Remove
            </button>
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
            if (! $wire.$el || ! $wire.$el.isConnected) return;

            $wire.$el.querySelectorAll('[data-copy-text]').forEach(function (button) {
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
</aside>
