<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SitePlugins;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteSnapshot;
use Modules\Site\Services\WordPressSite;

/**
 * What is installed on the website, and a switch for each.
 *
 * Two verbs, deliberately: on and off. Installing runs somebody else's code
 * chosen by a slug typed into a box; deleting is not undoable; updating can
 * white-screen a site and its safe version needs a backup this application does
 * not have. `SitePlugins` argues each. Deactivating is the first thing anybody
 * does when a site misbehaves, it is completely reversible, and it is the one
 * worth having somewhere other than wp-admin — particularly when the thing that
 * broke is what makes wp-admin slow to load.
 *
 * ## The confirmation is not "are you sure"
 *
 * Switching off a plugin whose name suggests security, login or REST
 * authentication can end this connection or lock somebody out of the site.
 * Neither is predictable from a name, so the page says which plugin and what
 * could happen instead of asking a question with no information in it. A panel
 * that refused everything it could not vouch for would refuse all of them.
 */
new
#[Title('Plugins — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The plugin whose confirmation is open, keyed by its `plugin` path. */
    public ?string $confirming = null;

    /**
     * @var array{items: list<array<array-key, mixed>>, error: ?string}|null
     */
    private ?array $memo = null;

    public function toggle(string $plugin, bool $active): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            return;
        }

        try {
            (new SitePlugins($site))->setStatus($plugin, $active);
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site refused it', $e->getMessage());

            return;
        }

        $this->confirming = null;
        $this->memo = null;

        // The snapshot lists REST namespaces, and a plugin going on or off is
        // exactly the thing that changes them — leaving a five-minute-old copy
        // would have the SEO and cache pages disagreeing with this one.
        SiteSnapshot::forget($site);

        $this->toastSuccess(
            $active ? 'Activated' : 'Deactivated',
            $active
                ? 'It is running on the site now.'
                : 'It is switched off. Nothing was deleted, so switching it back on restores it as it was.',
        );
    }

    public function with(): array
    {
        $result = $this->fetch();

        return [
            'site' => WordPressSite::connected(),
            'rows' => $result['items'],
            'error' => $result['error'],
            'active' => SitePlugins::activeCount($result['items']),
        ];
    }

    /**
     * @return array{items: list<array<array-key, mixed>>, error: ?string}
     */
    private function fetch(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $site = WordPressSite::connected();

        if ($site === null) {
            return $this->memo = ['items' => [], 'error' => null];
        }

        try {
            return $this->memo = ['items' => (new SitePlugins($site))->list(), 'error' => null];
        } catch (SiteRequestFailed $e) {
            return $this->memo = ['items' => [], 'error' => $e->getMessage()];
        }
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-mono">Plugins</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                What is installed, and a switch for each. Installing and updating stay in wp-admin.
            </p>
        </div>
        @if ($rows)
            <span class="kt-badge kt-badge-sm kt-badge-outline">
                {{ $active }} of {{ count($rows) }} active
            </span>
        @endif
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-plug text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @elseif ($error)

        <div class="kt-card border-destructive/30">
            <div class="kt-card-content flex items-start gap-3 py-8">
                <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                <div class="min-w-0">
                    <div class="text-sm font-medium text-mono">The site did not return its plugins</div>
                    <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                    <p class="text-xs text-muted-foreground mt-2">
                        This endpoint needs activate_plugins, which in practice means an administrator. It is also
                        absent on WordPress before 5.5 and on a site where file modification has been disabled.
                    </p>
                </div>
            </div>
        </div>

    @else

        <div class="kt-card">
            <div class="kt-card-table kt-scrollable-x-auto">
                <table class="kt-table">
                    <thead>
                        <tr>
                            <th class="min-w-[260px]">Plugin</th>
                            <th class="w-[100px]">Version</th>
                            <th class="w-[120px]">Status</th>
                            <th class="w-[260px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($rows as $row)
                        @php($path = (string) ($row['plugin'] ?? ''))
                        @php($isActive = ($row['status'] ?? '') === 'active')
                        @php($risky = \Modules\Site\Services\SitePlugins::isRisky($row))
                        <tr wire:key="plugin-{{ $path }}">
                            <td>
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-mono">{{ $row['name'] ?? $path }}</span>
                                    <span class="text-xs text-muted-foreground">{{ $row['author'] ?? '' }}</span>
                                </div>
                            </td>
                            <td class="text-sm text-secondary-foreground">{{ $row['version'] ?? '—' }}</td>
                            <td>
                                <span class="kt-badge kt-badge-sm {{ $isActive ? 'kt-badge-success' : 'kt-badge-outline' }}">
                                    {{ $isActive ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    @if ($confirming === $path)
                                        <span class="text-xs text-warning me-1 text-end">
                                            This one looks like it handles logging in or authentication. Switching it
                                            off can change how the site authenticates, and can end Kargah's own
                                            connection to it.
                                        </span>
                                        <button wire:click="toggle(@js($path), false)" wire:loading.attr="disabled"
                                                class="kt-btn kt-btn-sm kt-btn-destructive">Switch off anyway</button>
                                        <button wire:click="$set('confirming', null)"
                                                class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                    @elseif ($isActive)
                                        <button wire:click="{{ $risky ? '$set(\'confirming\', '.\Illuminate\Support\Js::from($path).')' : 'toggle('.\Illuminate\Support\Js::from($path).', false)' }}"
                                                wire:loading.attr="disabled"
                                                class="kt-btn kt-btn-sm kt-btn-ghost">Deactivate</button>
                                    @else
                                        <button wire:click="toggle(@js($path), true)" wire:loading.attr="disabled"
                                                class="kt-btn kt-btn-sm kt-btn-primary">Activate</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="flex flex-col items-center py-12 text-center">
                                    <i class="ki-filled ki-plug text-3xl text-muted-foreground mb-2"></i>
                                    <div class="text-sm font-medium text-mono">Nothing installed</div>
                                    <p class="text-sm text-secondary-foreground mt-1">
                                        The site reported no plugins at all, which is unusual on a live install.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
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
                        <span class="text-mono">Installing</span>
                        <span class="text-secondary-foreground">
                            — it downloads and runs somebody else's code, chosen by a slug typed into a box. That
                            decision wants the author, the install count and the reviews in front of you, which is
                            what wp-admin's plugin browser already shows.
                        </span>
                    </div>
                </div>
                <div class="flex items-start gap-2 text-sm">
                    <i class="ki-filled ki-minus-circle text-muted-foreground mt-0.5"></i>
                    <div>
                        <span class="text-mono">Updating and deleting</span>
                        <span class="text-secondary-foreground">
                            — an update can white-screen a site and a delete usually takes the plugin's data with it.
                            The safe version of both needs a backup of this site taken first, and Kargah does not have
                            one.
                        </span>
                    </div>
                </div>
            </div>
        </div>

    @endif

</div>
