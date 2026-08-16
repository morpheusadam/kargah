<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteSnapshot;
use Modules\Site\Services\WordPressSite;

/**
 * What Kargah can see of the website, and what it is allowed to do to it.
 *
 * The first page of the module and deliberately a report rather than a
 * dashboard of numbers. Everything else here writes to somebody else's live
 * site, and the question a person has before they do that is not "how many
 * posts are there" — it is "which site am I about to change, as whom, and with
 * what permission". So that is what this answers, in that order.
 *
 * ## Three states, and the middle one is the point
 *
 * - **Nothing connected.** A fresh install. The page explains where to connect
 *   a site rather than showing an error, because nothing is wrong.
 * - **Connected and refusing.** A revoked application password, an expired
 *   certificate, a plugin printing a notice before the REST response. The
 *   sentence the site itself sent is shown verbatim; see `SiteRequestFailed`.
 * - **Connected and answering.** Identity, capabilities, and what this install
 *   has that the rest of the module can therefore offer.
 *
 * The middle state is why `SiteSnapshot` returns a value instead of throwing.
 * A page that fatals when the connection is broken is a page that cannot report
 * a broken connection.
 *
 * ## Why the capabilities are named and not summarised
 *
 * A green "connected" badge on a credential belonging to a Subscriber is a lie
 * that only comes out on the first write, several pages later, as a `403` with
 * WordPress's own wording. Asking `wp/v2/users/me?context=edit` up front costs
 * one request and turns that into a sentence on this page, before anybody has
 * typed anything.
 */
new
#[Title('Website — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /**
     * A per-request memo. Private, so Livewire neither ships it in the payload
     * nor rehydrates it — a new instance starts empty and no code here may
     * assume either a fresh process or a persistent one.
     */
    private ?SiteSnapshot $memo = null;

    public function with(): array
    {
        $snapshot = $this->snapshot();

        return [
            'snapshot' => $snapshot,
            'site' => $this->site(),
            'capabilities' => SiteSnapshot::CAPABILITIES,
        ];
    }

    /**
     * Ask the site again, ignoring the five-minute memory.
     *
     * The one action on the page, and the reason the snapshot is allowed to be
     * cached at all: somebody who has just fixed a password or activated a
     * plugin has a button that proves it rather than a wait.
     */
    public function recheck(): void
    {
        $site = $this->site();

        if ($site === null) {
            $this->toastError('No site is connected', 'Connect a WordPress site under Social → Accounts first.');

            return;
        }

        $this->memo = SiteSnapshot::of($site, fresh: true);

        $this->memo->connected
            ? $this->toastSuccess('The site answered', $this->memo->name ?? $this->memo->siteUrl ?? 'Connection is working.')
            : $this->toastError('The site refused', (string) $this->memo->error);
    }

    private function site(): ?WordPressSite
    {
        return WordPressSite::connected();
    }

    private function snapshot(): SiteSnapshot
    {
        return $this->memo ??= SiteSnapshot::of($this->site());
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-mono">Website</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                The WordPress site Kargah is allowed to operate, and what it may do to it.
            </p>
        </div>

        @if ($site)
            <button wire:click="recheck" wire:loading.attr="disabled" class="kt-btn kt-btn-outline gap-2">
                <span wire:loading.remove wire:target="recheck" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-arrows-circle"></i> Check the connection
                </span>
                <span wire:loading wire:target="recheck" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Asking the site…
                </span>
            </button>
        @endif
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-notepad text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <p class="text-sm text-secondary-foreground mt-1 max-w-md">
                    These pages drive a WordPress site over its own REST API, using the site URL,
                    username and application password stored against the WordPress account.
                </p>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @elseif (! $snapshot->connected)

        <div class="kt-card border-destructive/30">
            <div class="kt-card-content flex flex-col gap-3 py-8">
                <div class="flex items-start gap-3">
                    <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-mono">
                            {{ $snapshot->siteUrl ?: 'The site' }} is not answering Kargah
                        </h2>
                        <p class="text-sm text-secondary-foreground mt-1">{{ $snapshot->error }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 pt-1">
                    <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-outline">
                        Replace the credential
                    </a>
                </div>
            </div>
        </div>

    @else

        <div class="grid gap-5 lg:grid-cols-2">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">The site</h2>
                    <span class="kt-badge kt-badge-sm kt-badge-success">Answering</span>
                </div>
                <div class="kt-card-content flex flex-col gap-3">
                    <div>
                        <div class="text-sm font-medium text-mono">{{ $snapshot->name ?: 'Untitled site' }}</div>
                        @if ($snapshot->description)
                            <div class="text-sm text-secondary-foreground mt-0.5">{{ $snapshot->description }}</div>
                        @endif
                    </div>

                    <div class="text-sm">
                        <span class="text-muted-foreground">Address</span>
                        <a href="{{ $snapshot->siteUrl }}" target="_blank" rel="noopener"
                           class="text-primary hover:underline ms-2">{{ $snapshot->siteUrl }}</a>
                    </div>

                    <div class="text-sm">
                        <span class="text-muted-foreground">Acting as</span>
                        <span class="text-mono ms-2">{{ $snapshot->userName ?: 'unknown' }}</span>
                        @foreach ($snapshot->roles as $role)
                            <span class="kt-badge kt-badge-sm kt-badge-outline ms-1">{{ $role }}</span>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">What Kargah may do</h2>
                </div>
                <div class="kt-card-content flex flex-col gap-2">
                    @foreach ($capabilities as $capability => $label)
                        @php($allowed = $snapshot->capabilities[$capability] ?? false)
                        <div class="flex items-center gap-2 text-sm">
                            <i class="ki-filled {{ $allowed ? 'ki-check-circle text-success' : 'ki-cross-circle text-muted-foreground' }}"></i>
                            <span class="{{ $allowed ? 'text-mono' : 'text-muted-foreground' }}">{{ $label }}</span>
                        </div>
                    @endforeach

                    @if ($snapshot->missingCapabilities())
                        <p class="text-xs text-secondary-foreground mt-2">
                            The WordPress user this application password belongs to cannot do everything these
                            pages offer. Give that user a role with the missing permissions, or paste a password
                            belonging to one that has them.
                        </p>
                    @endif
                </div>
            </div>

            <div class="kt-card lg:col-span-2">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">What this install has</h2>
                    <span class="text-xs text-muted-foreground">Read from the site, not assumed</span>
                </div>
                <div class="kt-card-content flex flex-col gap-3">

                    <div class="flex items-center gap-2 text-sm">
                        <i class="ki-filled {{ $snapshot->hasRankMath() ? 'ki-check-circle text-success' : 'ki-minus-circle text-muted-foreground' }}"></i>
                        <span class="{{ $snapshot->hasRankMath() ? 'text-mono' : 'text-muted-foreground' }}">
                            Rank Math
                            <span class="text-muted-foreground">
                                — {{ $snapshot->hasRankMath()
                                    ? 'installed, so SEO fields can be edited from here'
                                    : 'not detected; SEO editing needs it' }}
                            </span>
                        </span>
                    </div>

                    <div class="flex items-center gap-2 text-sm">
                        <i class="ki-filled {{ $snapshot->cacheNamespace() ? 'ki-check-circle text-success' : 'ki-minus-circle text-muted-foreground' }}"></i>
                        <span class="{{ $snapshot->cacheNamespace() ? 'text-mono' : 'text-muted-foreground' }}">
                            Cache plugin
                            <span class="text-muted-foreground">
                                — {{ $snapshot->cacheNamespace() ?: 'none detected on a REST namespace' }}
                            </span>
                        </span>
                    </div>

                    <div class="pt-1">
                        <div class="text-xs text-muted-foreground mb-1.5">
                            REST namespaces this site registers ({{ count($snapshot->namespaces) }})
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($snapshot->namespaces as $namespace)
                                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $namespace }}</span>
                            @endforeach
                        </div>
                    </div>

                </div>
            </div>

        </div>

    @endif

</div>
