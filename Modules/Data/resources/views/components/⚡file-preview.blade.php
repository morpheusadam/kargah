<?php

use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * File preview drawer.
 *
 * Nested inside the file browser. Receives the selected file as an array and
 * shows its metadata, the shareable link for it and the version history the
 * backend keeps per file. Nothing here writes; the actions are stubs.
 */
new
class extends Component
{
    use InteractsWithToasts;

    /** The selected file, passed down from the browser. */
    public array $file = [];

    /** Expiry chosen for a new shareable link. */
    public string $expiry = '7d';

    public function with(): array
    {
        return [
            'expiries' => [
                '24h'   => 'Expires in 24 hours',
                '7d'    => 'Expires in 7 days',
                '30d'   => 'Expires in 30 days',
                'never' => 'No expiry',
            ],
            'imageTypes' => ['png', 'jpg', 'svg', 'webp'],
        ];
    }

    /** Issue a signed, expiring link for this file. */
    public function createLink(): void
    {
        // Backend: sign a temporary URL with the chosen expiry.

        $this->toastInfo('Share link was not created', 'Signing an expiring URL needs the backend. The link shown here is a sample.');
    }

    /** Withdraw the active shareable link. */
    public function revokeLink(): void
    {
        // Backend: delete the share token so the URL stops resolving.

        // Say plainly that the link is still live — a false "revoked" here
        // would leave a shared file reachable while the owner believed it was not.
        $this->toastInfo('Revoking is not available yet', 'The existing link stays live until the backend can withdraw the token.');
    }

    /** Roll the file back to an earlier stored version. */
    public function restoreVersion(int $version): void
    {
        // Backend: copy the stored version over the current one, keeping history.

        $this->toastInfo('Version was not restored', 'Rolling back to version '.$version.' needs the backend. The current file is untouched.');
    }

    public function download(): void
    {
        // Backend: stream the file from the private disk.

        $this->toastInfo('Download is not available yet', 'Streaming from the private disk needs the backend.');
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

        {{-- Thumbnail or type icon --}}
        <div class="flex items-center justify-center h-36 rounded-lg bg-muted border border-border">
            @if (in_array($file['type'] ?? '', $imageTypes, true))
                <div class="flex flex-col items-center gap-2 text-muted-foreground">
                    <i class="ki-filled ki-picture text-4xl text-info"></i>
                    <span class="text-[11px]">Thumbnail generated on upload</span>
                </div>
            @else
                <i class="ki-filled {{ $file['icon'] ?? 'ki-document' }} text-5xl text-muted-foreground"></i>
            @endif
        </div>

        <div class="min-w-0">
            <div class="font-semibold text-mono break-words">{{ $file['name'] ?? '—' }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ $file['path'] ?? '—' }}</div>
        </div>

        {{-- Metadata --}}
        <dl class="flex flex-col gap-2.5 text-sm">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Size</dt>
                <dd class="text-secondary-foreground">{{ $file['size'] ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Type</dt>
                <dd><span class="kt-badge kt-badge-sm kt-badge-outline uppercase">{{ $file['type'] ?? '—' }}</span></dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Created</dt>
                <dd class="text-secondary-foreground">{{ $file['created'] ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-muted-foreground">Modified</dt>
                <dd class="text-secondary-foreground">{{ $file['modified'] ?? '—' }}</dd>
            </div>
        </dl>

        <button wire:click="download" wire:loading.attr="disabled" wire:target="download" class="kt-btn kt-btn-outline w-full gap-2">
            <span wire:loading.remove wire:target="download" class="inline-flex items-center gap-2">
                <i class="ki-filled ki-exit-down"></i> Download
            </span>
            <span wire:loading wire:target="download" class="inline-flex items-center gap-2">
                <i class="ki-filled ki-loading animate-spin"></i> Preparing…
            </span>
        </button>

        {{-- Shareable link --}}
        <div class="pt-4 border-t border-border flex flex-col gap-3">
            <h4 class="text-sm font-semibold text-mono">Shareable link</h4>

            @if (! empty($file['share']))
                <div class="flex items-center gap-2">
                    <div class="kt-input grow min-w-0">
                        <i class="ki-filled ki-arrow-up-right text-muted-foreground"></i>
                        <input type="text" readonly value="{{ $file['share']['url'] }}" aria-label="Shareable link">
                    </div>
                    <button class="kt-btn kt-btn-icon kt-btn-outline size-9 shrink-0" title="Copy link" aria-label="Copy link">
                        <i class="ki-filled ki-copy text-sm"></i>
                    </button>
                </div>
                <div class="flex items-center justify-between gap-3 text-xs">
                    <span class="text-muted-foreground">Expires {{ $file['share']['expires'] }}</span>
                    <button wire:click="revokeLink" wire:loading.attr="disabled" wire:target="revokeLink"
                            class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1">
                        <span wire:loading.remove wire:target="revokeLink">Revoke</span>
                        <span wire:loading wire:target="revokeLink"><i class="ki-filled ki-loading animate-spin"></i> Revoking…</span>
                    </button>
                </div>
            @else
                <p class="text-xs text-muted-foreground">
                    No link yet. A link is signed and stops working the moment it expires.
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
                        <i class="ki-filled ki-loading animate-spin"></i> Creating…
                    </span>
                </button>
            @endif
        </div>

        {{-- Version history --}}
        <div class="pt-4 border-t border-border flex flex-col gap-3">
            <h4 class="text-sm font-semibold text-mono">Version history</h4>

            @forelse ($file['versions'] ?? [] as $v)
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm text-mono">
                            v{{ $v['version'] }}
                            @if ($loop->first)
                                <span class="kt-badge kt-badge-sm kt-badge-success ms-1">Current</span>
                            @endif
                        </div>
                        <div class="text-xs text-muted-foreground">{{ $v['when'] }} · {{ $v['size'] }}</div>
                    </div>
                    @unless ($loop->first)
                        <button wire:click="restoreVersion({{ $v['version'] }})" wire:loading.attr="disabled"
                                wire:target="restoreVersion({{ $v['version'] }})"
                                class="kt-btn kt-btn-sm kt-btn-ghost shrink-0">
                            <span wire:loading.remove wire:target="restoreVersion({{ $v['version'] }})">Restore</span>
                            <span wire:loading wire:target="restoreVersion({{ $v['version'] }})"><i class="ki-filled ki-loading animate-spin"></i></span>
                        </button>
                    @endunless
                </div>
            @empty
                <p class="text-xs text-muted-foreground">Only one version stored so far.</p>
            @endforelse
        </div>
    </div>
</aside>
