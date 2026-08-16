<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteCache;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteSnapshot;
use Modules\Site\Services\WordPressSite;

/**
 * Emptying the website's page cache, and being clear about what that costs.
 *
 * ## The narrow purge is offered first, and it is on purpose
 *
 * Purging one URL after editing it costs the site nothing. Purging everything
 * on a busy site sends every visitor to an uncached page at the same moment,
 * and on the shared hosting this application is built for that is how an
 * afternoon turns into a 503. So the single-URL form is at the top, unguarded,
 * and "purge everything" is a second click behind a confirmation that says what
 * it does rather than asking "are you sure".
 *
 * ## When the route is not there
 *
 * No cache plugin exposes purging over REST — the argument and the sources are
 * in `SiteCache`'s docblock. Rather than hiding the page or offering a button
 * that 404s, it explains the situation and hands over the fourteen lines that
 * make it work, exactly as the SEO panel does for Rank Math's meta.
 */
new
#[Title('Cache — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $url = '';

    public bool $confirmingAll = false;

    public bool $showSnippet = false;

    /** What the last purge reported, so the page can say which plugin acted. */
    public ?string $lastResult = null;

    private ?SiteSnapshot $memo = null;

    public function purgeUrl(): void
    {
        $site = WordPressSite::connected();

        if ($site === null || trim($this->url) === '') {
            $this->toastError('Nothing to purge', 'Give the address of a page on the site.');

            return;
        }

        try {
            $result = SiteCache::purgeUrl($site, trim($this->url));
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site did not purge it', $e->getMessage());

            return;
        }

        $this->lastResult = $result['driver'].' — '.$result['message'];

        $this->toastSuccess('Purged that address', $result['driver'].' handled it.');
    }

    public function purgeAll(): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            return;
        }

        try {
            $result = SiteCache::purgeAll($site);
        } catch (SiteRequestFailed $e) {
            $this->confirmingAll = false;
            $this->toastError('The site did not purge', $e->getMessage());

            return;
        }

        $this->confirmingAll = false;
        $this->lastResult = $result['driver'].' — '.$result['message'];

        $this->toastSuccess('The whole cache is empty', $result['driver'].' handled it. The next visitor to each page rebuilds it.');
    }

    public function with(): array
    {
        $site = WordPressSite::connected();
        $snapshot = $this->memo ??= SiteSnapshot::of($site);

        return [
            'site' => $site,
            'snapshot' => $snapshot,
            'available' => $site !== null && SiteCache::available($snapshot),
            'plugin' => $snapshot->cacheNamespace(),
            'snippet' => SiteCache::purgeSnippet(),
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-mono">Cache</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Empty the site's page cache when a change is not showing up.
            </p>
        </div>
        <a href="{{ route('site.overview') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-information-2"></i> Connection
        </a>
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-cloud-change text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @elseif (! $available)

        <div class="kt-card">
            <div class="kt-card-header">
                <h2 class="kt-card-title">This site cannot be purged from here yet</h2>
                @if ($plugin)
                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $plugin }} detected</span>
                @endif
            </div>
            <div class="kt-card-content flex flex-col gap-3">
                <p class="text-sm text-secondary-foreground">
                    No WordPress cache plugin exposes purging over the REST API. LiteSpeed, WP Rocket and
                    W3 Total Cache each have a perfectly good way in, and every one of them is a PHP hook meant
                    to be called from inside WordPress. So there is nothing for Kargah to call
                    @if ($plugin)
                        — {{ $plugin }} being installed proves the plugin is there, not that it offers a route.
                    @endif
                </p>
                <p class="text-sm text-secondary-foreground">
                    One small file changes that. It registers a single endpoint and passes the purge to whichever
                    plugin this site actually has, falling back to flushing the object cache when there is none.
                </p>
                <div>
                    <button wire:click="$toggle('showSnippet')" class="kt-btn kt-btn-sm kt-btn-outline">
                        {{ $showSnippet ? 'Hide' : 'Show' }} the file
                    </button>
                </div>

                @if ($showSnippet)
                    <div class="flex flex-col gap-2">
                        <p class="text-xs text-secondary-foreground">
                            Save as <code class="text-mono">wp-content/mu-plugins/kargah-cache.php</code>, then come
                            back and use “Check the connection” on the Connection page so Kargah sees the new route.
                        </p>
                        <pre class="kt-scrollable-x-auto w-full bg-muted rounded p-3 text-xs font-mono text-mono">{{ $snippet }}</pre>
                    </div>
                @endif
            </div>
        </div>

    @else

        <div class="grid gap-5 lg:grid-cols-2">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">One address</h2>
                    <span class="text-xs text-muted-foreground">Costs the site nothing</span>
                </div>
                <div class="kt-card-content flex flex-col gap-3">
                    <div>
                        <label class="kt-form-label" for="purge-url">Address</label>
                        <input id="purge-url" wire:model="url" type="url" class="kt-input"
                               placeholder="{{ $snapshot->siteUrl }}/four-board-views/">
                    </div>
                    <div>
                        <button wire:click="purgeUrl" wire:loading.attr="disabled" class="kt-btn kt-btn-primary gap-2">
                            <span wire:loading.remove wire:target="purgeUrl" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-eraser"></i> Purge this address
                            </span>
                            <span wire:loading wire:target="purgeUrl" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Purging…
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="kt-card border-warning/30">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">Everything</h2>
                    <span class="text-xs text-warning">Has a cost worth knowing</span>
                </div>
                <div class="kt-card-content flex flex-col gap-3">
                    <p class="text-sm text-secondary-foreground">
                        Every page becomes uncached at once, so the next visitor to each one waits while the site
                        rebuilds it. On a busy site on shared hosting that arrives as a slow few minutes, and
                        occasionally as a 503.
                    </p>

                    @if ($confirmingAll)
                        <div class="flex items-center gap-2">
                            <button wire:click="purgeAll" wire:loading.attr="disabled" class="kt-btn kt-btn-sm kt-btn-destructive">
                                <span wire:loading.remove wire:target="purgeAll">Purge everything now</span>
                                <span wire:loading wire:target="purgeAll" class="inline-flex items-center gap-1.5">
                                    <i class="ki-filled ki-loading animate-spin"></i> Purging…
                                </span>
                            </button>
                            <button wire:click="$set('confirmingAll', false)" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                        </div>
                    @else
                        <div>
                            <button wire:click="$set('confirmingAll', true)" class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                                <i class="ki-filled ki-trash"></i> Purge the whole cache
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            @if ($lastResult)
                <div class="kt-card lg:col-span-2">
                    <div class="kt-card-content py-4">
                        <div class="text-sm text-mono">
                            <i class="ki-filled ki-check-circle text-success me-1.5"></i>
                            Last purge: {{ $lastResult }}
                        </div>
                    </div>
                </div>
            @endif

        </div>

    @endif

</div>
