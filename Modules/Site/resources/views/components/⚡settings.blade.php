<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteSettings;
use Modules\Site\Services\WordPressSite;

/**
 * The website's own settings.
 *
 * ## Two things are named as refused rather than quietly absent
 *
 * The front-page setting and the permalink structure. Both are things somebody
 * looking for "site settings" will expect, and finding neither with no
 * explanation reads as an unfinished panel. Saying why is shorter than the
 * feature and more useful than its absence:
 *
 * - the front page is a page id this panel would have to look up and validate,
 *   and setting it wrongly blanks the front of the site;
 * - the permalink structure is not in core's REST settings at all, deliberately,
 *   because changing it invalidates every URL on the site at once.
 *
 * ## The admin email legitimately does not change when you save it
 *
 * WordPress sends a confirmation to the new address and keeps the old one until
 * somebody clicks the link. So a save can be entirely successful and the value
 * on screen afterwards is still the old one. The page says that in those words
 * rather than either claiming success or reporting a failure that has not
 * happened — `SiteSettings::notApplied()` is what spots it.
 */
new
#[Title('Site settings — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, string> */
    public array $original = [];

    public bool $loaded = false;

    public ?string $error = null;

    /** Settings the site accepted the request for and has not applied. */
    public array $pending = [];

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            return;
        }

        try {
            $this->values = (new SiteSettings($site))->read();
        } catch (SiteRequestFailed $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->loaded = true;
        $this->error = null;
        $this->original = $this->values;
    }

    public function save(): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            return;
        }

        try {
            $result = (new SiteSettings($site))->write($this->values, $this->original);
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site refused the change', $e->getMessage());

            return;
        }

        if ($result['changed'] === []) {
            $this->toastSuccess('Nothing to save', 'Nothing on this page differs from what the site has.');

            return;
        }

        $this->pending = SiteSettings::notApplied($result['changed'], $result['response']);

        $this->load();

        if ($this->pending !== []) {
            $this->toastWarning(
                'Saved, but the site has not applied all of it',
                'Waiting on the site: '.implode(', ', $this->pending)
                .'. Changing the admin email does this by design — WordPress emails the new address and keeps the old one until the link is clicked.',
            );

            return;
        }

        $this->toastSuccess('Settings saved', 'The site now has what is on this page.');
    }

    public function with(): array
    {
        return [
            'site' => WordPressSite::connected(),
            'fields' => SiteSettings::fields(),
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-mono">Site settings</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                What WordPress exposes over its API, and what it deliberately does not.
            </p>
        </div>
        @if ($loaded)
            <button wire:click="save" wire:loading.attr="disabled" class="kt-btn kt-btn-primary gap-2">
                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-check"></i> Save to the site
                </span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
        @endif
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-setting-2 text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @elseif (! $loaded)

        <div class="kt-card border-destructive/30">
            <div class="kt-card-content flex items-start gap-3 py-8">
                <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                <div class="min-w-0">
                    <div class="text-sm font-medium text-mono">The site did not return its settings</div>
                    <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                    <p class="text-xs text-muted-foreground mt-2">
                        Reading settings needs manage_options. An application password belonging to an editor can
                        write posts and cannot see this page.
                    </p>
                </div>
            </div>
        </div>

    @else

        <div class="kt-card">
            <div class="kt-card-header">
                <h2 class="kt-card-title">General</h2>
            </div>
            <div class="kt-card-content grid gap-4 sm:grid-cols-2">
                @foreach ($fields as $key => $field)
                    <div class="{{ in_array($key, ['title', 'description'], true) ? 'sm:col-span-2' : '' }}">
                        <label class="kt-form-label" for="setting-{{ $key }}">
                            {{ $field['label'] }}
                            @if (in_array($key, $pending, true))
                                <span class="kt-badge kt-badge-sm kt-badge-warning ms-1">waiting on the site</span>
                            @endif
                        </label>
                        <input id="setting-{{ $key }}" wire:model="values.{{ $key }}"
                               type="{{ $field['type'] }}" class="kt-input">
                        <p class="text-xs text-muted-foreground mt-1">{{ $field['hint'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h2 class="kt-card-title">Not offered here, and why</h2>
            </div>
            <div class="kt-card-content flex flex-col gap-3">
                <div class="flex items-start gap-2 text-sm">
                    <i class="ki-filled ki-minus-circle text-muted-foreground mt-0.5"></i>
                    <div>
                        <span class="text-mono">The front page</span>
                        <span class="text-secondary-foreground">
                            — it is a page id, not a text box. Set wrongly it blanks the front of the site, so it needs
                            a picker that validates against the site's real pages rather than an input.
                        </span>
                    </div>
                </div>
                <div class="flex items-start gap-2 text-sm">
                    <i class="ki-filled ki-minus-circle text-muted-foreground mt-0.5"></i>
                    <div>
                        <span class="text-mono">Permalink structure</span>
                        <span class="text-secondary-foreground">
                            — WordPress deliberately never exposed it over REST. Changing it invalidates every URL on
                            the site at once, and the redirects that would save you are a plugin's job. Change it in
                            wp-admin, with a redirect plugin already installed.
                        </span>
                    </div>
                </div>
            </div>
        </div>

    @endif

</div>
