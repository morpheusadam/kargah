<?php

use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\CurationFeed;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\CurationWindow;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Curation\Copywriter;
use Modules\Social\Support\Networks;

/**
 * Everything the daily curator does, adjustable without a deploy.
 *
 * The owner's instruction was that all of this be manageable from the settings
 * pages, and that is what decided the shape of the feature behind it: the forty
 * outlets, the thresholds and the posting windows are database rows rather than
 * a config file, and `Modules/Social/config/curation.php` survives only as the
 * defaults a fresh install is seeded from. This page is the reason those tables
 * exist.
 *
 * ## Three things on this page are load bearing
 *
 * **The master switch.** It publishes to live accounts with nobody present, so
 * it ships off and turning it on is an act performed here.
 *
 * **The curation hour has to precede the earliest window.** The command chooses
 * the day's story once; if it runs after LinkedIn's morning window has closed,
 * LinkedIn silently never gets a post and nothing anywhere says so. `save()`
 * refuses that rather than letting it be discovered a week later, which is the
 * one piece of validation here that is about correctness rather than tidiness.
 *
 * **A window's hashtag ceiling is not cosmetic.** Ten or more hashtags on
 * LinkedIn risks a 30–50% reach penalty, so the field carries the number and the
 * page says why, rather than presenting it as a taste setting.
 *
 * ## What is deliberately *not* here
 *
 * Which AI provider writes the copy. That is already chosen on Settings →
 * Assistant, and a second control for it here would be a second place the same
 * decision lives — one of which would be wrong the day a key is replaced. The
 * page links there and reports whether it is usable.
 */
new
#[Title('Curation — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The settings-nav search box. See `partials/settings-nav.blade.php`. */
    public string $settingsFilter = '';

    // ── The singleton row ───────────────────────────────────────────────────

    public bool $isEnabled = false;

    public string $timezone = 'Asia/Tehran';

    public string $curateAtUtc = '01:30';

    public string $weekendDays = '4,5';

    public int $maxAgeHours = 72;

    public int $minSummaryLength = 80;

    public int $spareCandidates = 3;

    public bool $hackernewsEnabled = true;

    public int $hackernewsMinPoints = 50;

    public bool $lobstersEnabled = true;

    public int $lobstersMinEngagement = 25;

    // ── The feed being edited, if any ───────────────────────────────────────

    public ?int $editingFeedId = null;

    public string $feedLabel = '';

    public string $feedUrl = '';

    public float $feedAuthority = 0.7;

    public ?int $feedMaxAge = null;

    /** Filter for the outlet list, which is forty rows long. */
    public string $feedSearch = '';

    public function mount(): void
    {
        $settings = CurationSetting::current();

        $this->isEnabled = $settings->is_enabled;
        $this->timezone = $settings->timezone;
        $this->curateAtUtc = $settings->curate_at_utc;
        $this->weekendDays = $settings->weekend_days;
        $this->maxAgeHours = $settings->max_age_hours;
        $this->minSummaryLength = $settings->min_summary_length;
        $this->spareCandidates = $settings->spare_candidates;
        $this->hackernewsEnabled = $settings->hackernews_enabled;
        $this->hackernewsMinPoints = $settings->hackernews_min_points;
        $this->lobstersEnabled = $settings->lobsters_enabled;
        $this->lobstersMinEngagement = $settings->lobsters_min_engagement;
    }

    public function save(): void
    {
        $this->validate([
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'curateAtUtc' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'weekendDays' => ['nullable', 'regex:/^[1-7](,[1-7])*$/'],
            'maxAgeHours' => ['required', 'integer', 'min:1', 'max:720'],
            'minSummaryLength' => ['required', 'integer', 'min:0', 'max:2000'],
            'spareCandidates' => ['required', 'integer', 'min:0', 'max:10'],
            'hackernewsMinPoints' => ['required', 'integer', 'min:0', 'max:2000'],
            'lobstersMinEngagement' => ['required', 'integer', 'min:0', 'max:2000'],
        ], [
            'curateAtUtc.regex' => 'Write the time as HH:MM, in UTC.',
            'weekendDays.regex' => 'Weekend days are numbers 1 to 7 separated by commas — Monday is 1.',
        ]);

        // The one check that is about correctness rather than tidiness: choosing
        // the day's story after a window has closed means that network silently
        // never gets a post, and nothing anywhere says so.
        $earliest = $this->earliestWindowInUtc();

        if ($earliest !== null && $this->curateAtUtc >= $earliest) {
            $this->addError('curateAtUtc', 'This is after the earliest posting window opens ('
                .$earliest.' UTC), so that network would never be posted to. Choose an earlier time.');

            return;
        }

        CurationSetting::current()->update([
            'is_enabled' => $this->isEnabled,
            'timezone' => $this->timezone,
            'curate_at_utc' => $this->curateAtUtc,
            'weekend_days' => $this->weekendDays,
            'max_age_hours' => $this->maxAgeHours,
            'min_summary_length' => $this->minSummaryLength,
            'spare_candidates' => $this->spareCandidates,
            'hackernews_enabled' => $this->hackernewsEnabled,
            'hackernews_min_points' => $this->hackernewsMinPoints,
            'lobsters_enabled' => $this->lobstersEnabled,
            'lobsters_min_engagement' => $this->lobstersMinEngagement,
        ]);

        $this->toastSuccess('Curation settings saved.');
    }

    /**
     * The earliest moment any active window opens, expressed in UTC.
     *
     * Computed rather than assumed, because the operator can move any window and
     * change the timezone on this same page.
     */
    private function earliestWindowInUtc(): ?string
    {
        $timezone = in_array($this->timezone, timezone_identifiers_list(), true) ? $this->timezone : 'UTC';
        $earliest = null;

        foreach (CurationWindow::query()->usable()->get() as $window) {
            foreach ([$window->starts_at, $window->weekend_starts_at] as $time) {
                if (! is_string($time) || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
                    continue;
                }

                $utc = now($timezone)->startOfDay()
                    ->setTimeFromTimeString($time)
                    ->setTimezone('UTC')
                    ->format('H:i');

                if ($earliest === null || $utc < $earliest) {
                    $earliest = $utc;
                }
            }
        }

        return $earliest;
    }

    // ── Outlets ─────────────────────────────────────────────────────────────

    public function toggleFeed(int $id): void
    {
        $feed = CurationFeed::query()->find($id);

        if ($feed === null) {
            return;
        }

        $feed->update(['is_active' => ! $feed->is_active]);

        // Reported because a row scrolling in a list of forty is not something
        // the eye reliably catches, and this one changes what gets published.
        $this->toastSuccess($feed->label.($feed->is_active ? ' is back in the daily run.' : ' will not be read.'));
    }

    public function editFeed(int $id): void
    {
        $feed = CurationFeed::query()->findOrFail($id);

        $this->editingFeedId = $feed->id;
        $this->feedLabel = $feed->label;
        $this->feedUrl = $feed->url;
        $this->feedAuthority = $feed->authority;
        $this->feedMaxAge = $feed->max_age_hours;
    }

    public function newFeed(): void
    {
        $this->editingFeedId = 0;
        $this->feedLabel = '';
        $this->feedUrl = '';
        $this->feedAuthority = 0.7;
        $this->feedMaxAge = null;
    }

    public function cancelFeed(): void
    {
        // Closing a panel is visible in itself, so it says nothing.
        $this->editingFeedId = null;
        $this->resetValidation();
    }

    public function saveFeed(): void
    {
        $this->validate([
            'feedLabel' => [
                'required', 'string', 'max:120',
                // Unique because the ranker counts *independent* outlets by this
                // column: two rows sharing a label let one publisher count as two
                // agreeing, which is the one number this whole feature turns on.
                Rule::unique('curation_feeds', 'label')->ignore($this->editingFeedId ?: null),
            ],
            'feedUrl' => ['required', 'url', 'max:500'],
            'feedAuthority' => ['required', 'numeric', 'min:0.01', 'max:1'],
            'feedMaxAge' => ['nullable', 'integer', 'min:1', 'max:720'],
        ], [
            'feedLabel.unique' => 'Another outlet already uses this name, and two outlets with one name would be counted as one.',
        ]);

        $values = [
            'label' => $this->feedLabel,
            'url' => $this->feedUrl,
            'authority' => $this->feedAuthority,
            'max_age_hours' => $this->feedMaxAge,
        ];

        if ($this->editingFeedId) {
            CurationFeed::query()->whereKey($this->editingFeedId)->update($values);
        } else {
            CurationFeed::query()->create($values + ['is_active' => true, 'sort_order' => 9999]);
        }

        $this->editingFeedId = null;
        $this->toastSuccess('Outlet saved.');
    }

    public function deleteFeed(int $id): void
    {
        CurationFeed::query()->whereKey($id)->delete();

        $this->toastSuccess('Outlet removed. Re-running the Social seeder brings the shipped ones back.');
    }

    // ── Windows ─────────────────────────────────────────────────────────────

    public function saveWindow(int $id, string $field, string $value): void
    {
        $window = CurationWindow::query()->find($id);

        if ($window === null) {
            return;
        }

        if (in_array($field, ['starts_at', 'ends_at', 'weekend_starts_at', 'weekend_ends_at'], true)) {
            if (preg_match('/^\d{2}:\d{2}$/', $value) !== 1) {
                $this->toastError('Write the time as HH:MM.');

                return;
            }

            $window->update([$field => $value]);
        }

        if (in_array($field, ['hashtags_min', 'hashtags_max'], true)) {
            $window->update([$field => max(0, min(30, (int) $value))]);
        }

        $this->toastSuccess($window->network.' window updated.');
    }

    public function toggleWindow(int $id): void
    {
        $window = CurationWindow::query()->find($id);

        if ($window === null) {
            return;
        }

        $window->update(['is_active' => ! $window->is_active]);

        $this->toastSuccess(
            $window->network.($window->is_active ? ' is back in the daily post.' : ' will be skipped.'),
        );
    }

    public function with(): array
    {
        $accounts = SocialAccount::query()->active()->get()->groupBy('network');

        return [
            'feeds' => CurationFeed::query()
                ->when($this->feedSearch !== '', fn ($q) => $q->where('label', 'like', '%'.$this->feedSearch.'%'))
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get(),
            'activeFeeds' => CurationFeed::query()->where('is_active', true)->count(),
            'totalFeeds' => CurationFeed::query()->count(),
            'windows' => CurationWindow::query()->orderBy('starts_at')->get(),
            'accounts' => $accounts,
            'catalogue' => Networks::all(),
            // Whether the copy can be written at all. The single commonest reason
            // this feature silently does nothing, so it is stated at the top
            // rather than left to be discovered from cron output.
            'writerProblem' => app(Copywriter::class)->unavailableReason(),
        ];
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
                    <h2 class="text-lg font-semibold text-mono">Curation</h2>
                    <p class="text-sm text-secondary-foreground mt-1">
                        One story a day, read from the outlets below, written for each network and posted at the
                        hour that network is actually read.
                    </p>
                </div>
                <a href="{{ route('social.curated') }}" class="kt-btn kt-btn-outline gap-2">
                    <i class="ki-filled ki-book"></i> What went out
                </a>
            </div>

            @if ($writerProblem)
                <div class="kt-card border-warning/30 bg-warning/10">
                    <div class="kt-card-content p-4 flex items-start gap-3">
                        <i class="ki-filled ki-information-2 text-warning text-lg mt-0.5"></i>
                        <div class="text-sm text-secondary-foreground">
                            <span class="font-medium text-mono">No copy can be written yet.</span>
                            {{ $writerProblem }}
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── The switch, and when the day is chosen ───────────────────── --}}

            <div class="kt-card" id="general">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">The daily post</h3>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" class="kt-switch mt-0.5" wire:model.live="isEnabled">
                        <span>
                            <span class="block text-sm font-medium text-mono">Publish a story every day</span>
                            <span class="block text-xs text-secondary-foreground mt-0.5">
                                While this is off nothing is chosen and nothing is posted. It ships off, because
                                this publishes to live accounts with nobody watching.
                            </span>
                        </span>
                    </label>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="kt-form-label" for="timezone">Reader time zone</label>
                            <select id="timezone" class="kt-select @error('timezone') border-destructive @enderror"
                                    wire:model="timezone">
                                @foreach (['Asia/Tehran', 'Asia/Dubai', 'Europe/Istanbul', 'Europe/London', 'UTC'] as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                            <span class="block text-xs text-muted-foreground mt-1">
                                Every posting window below is written in this clock. Kargah stores the resulting
                                times in UTC.
                            </span>
                            @error('timezone')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div>
                            <label class="kt-form-label" for="curate-at">Choose the story at (UTC)</label>
                            <input id="curate-at" type="text" class="kt-input @error('curateAtUtc') border-destructive @enderror"
                                   placeholder="01:30" wire:model="curateAtUtc">
                            <span class="block text-xs text-muted-foreground mt-1">
                                Must be before the earliest window opens, or that network never gets a post.
                            </span>
                            @error('curateAtUtc')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div>
                            <label class="kt-form-label" for="weekend">Weekend days</label>
                            <input id="weekend" type="text" class="kt-input @error('weekendDays') border-destructive @enderror"
                                   placeholder="4,5" wire:model="weekendDays">
                            <span class="block text-xs text-muted-foreground mt-1">
                                Monday is 1. The default 4,5 is Thursday and Friday — the Iranian weekend, when
                                LinkedIn is barely read.
                            </span>
                            @error('weekendDays')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>

                        <div>
                            <label class="kt-form-label" for="spare">Backup stories</label>
                            <input id="spare" type="number" min="0" max="10" class="kt-input" wire:model="spareCandidates">
                            <span class="block text-xs text-muted-foreground mt-1">
                                How many further stories to try when the model judges the best one off-topic.
                                Without these, one awkward story costs the whole day.
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button class="kt-btn kt-btn-primary" wire:click="save" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="save">Save</span>
                            <span wire:loading wire:target="save" class="flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Saving…
                            </span>
                        </button>
                    </div>

                </div>
            </div>

            {{-- ── Where stories come from ──────────────────────────────────── --}}

            <div class="kt-card" id="sources">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Where stories come from</h3>
                    <span class="text-xs text-muted-foreground">{{ $activeFeeds }} of {{ $totalFeeds }} outlets on</span>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="kt-form-label" for="max-age">Story may be up to (hours) old</label>
                            <input id="max-age" type="number" min="1" max="720" class="kt-input" wire:model="maxAgeHours">
                            <span class="block text-xs text-muted-foreground mt-1">
                                72 by default. At 48 the pool ran dry at weekends, because outlets like Krebs and
                                MIT leave days between posts. Ranking sinks the older ones anyway.
                            </span>
                        </div>
                        <div>
                            <label class="kt-form-label" for="min-summary">Shortest usable summary</label>
                            <input id="min-summary" type="number" min="0" max="2000" class="kt-input" wire:model="minSummaryLength">
                            <span class="block text-xs text-muted-foreground mt-1">
                                Below this an item is a bare headline, and a summary of it can only be the headline
                                again.
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-lg border border-border p-4 flex flex-col gap-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="kt-switch" wire:model.live="hackernewsEnabled">
                                <span class="text-sm font-medium text-mono">Hacker News</span>
                            </label>
                            <div>
                                <label class="kt-form-label" for="hn-points">Minimum points</label>
                                <input id="hn-points" type="number" min="0" class="kt-input" wire:model="hackernewsMinPoints">
                                <span class="block text-xs text-muted-foreground mt-1">
                                    Applied in the search query, so raising it costs nothing.
                                </span>
                            </div>
                        </div>

                        <div class="rounded-lg border border-border p-4 flex flex-col gap-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="kt-switch" wire:model.live="lobstersEnabled">
                                <span class="text-sm font-medium text-mono">Lobsters</span>
                            </label>
                            <div>
                                <label class="kt-form-label" for="lob-engagement">Minimum engagement</label>
                                <input id="lob-engagement" type="number" min="0" class="kt-input" wire:model="lobstersMinEngagement">
                                <span class="block text-xs text-muted-foreground mt-1">
                                    Keep this. Lobsters scores are one and two digits, and without a floor a
                                    three-point story outranks a large Hacker News discussion.
                                </span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {{-- ── The outlets ──────────────────────────────────────────────── --}}

            <div class="kt-card" id="feeds">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Outlets</h3>
                    <button class="kt-btn kt-btn-sm kt-btn-outline gap-2" wire:click="newFeed">
                        <i class="ki-filled ki-plus"></i> Add outlet
                    </button>
                </div>

                @if ($editingFeedId !== null)
                    <div class="kt-card-content p-5 border-b border-border flex flex-col gap-4 bg-accent/40">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="kt-form-label" for="feed-label">Name</label>
                                <input id="feed-label" type="text" class="kt-input @error('feedLabel') border-destructive @enderror"
                                       wire:model="feedLabel" placeholder="Krebs on Security">
                                @error('feedLabel')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="kt-form-label" for="feed-url">Feed address</label>
                                <input id="feed-url" type="text" class="kt-input @error('feedUrl') border-destructive @enderror"
                                       wire:model="feedUrl" placeholder="https://example.com/feed/">
                                @error('feedUrl')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="kt-form-label" for="feed-authority">Trust (0.01–1)</label>
                                <input id="feed-authority" type="number" step="0.05" min="0.01" max="1"
                                       class="kt-input @error('feedAuthority') border-destructive @enderror"
                                       wire:model="feedAuthority">
                                <span class="block text-xs text-muted-foreground mt-1">
                                    How far to trust this outlet when nothing else separates two versions of a story.
                                </span>
                                @error('feedAuthority')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="kt-form-label" for="feed-age">Own age window (hours)</label>
                                <input id="feed-age" type="number" min="1" max="720" class="kt-input" wire:model="feedMaxAge"
                                       placeholder="leave blank to use the general one">
                                <span class="block text-xs text-muted-foreground mt-1">
                                    For outlets that publish every few days rather than every few hours.
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button class="kt-btn kt-btn-primary kt-btn-sm" wire:click="saveFeed">Save outlet</button>
                            <button class="kt-btn kt-btn-ghost kt-btn-sm" wire:click="cancelFeed">Cancel</button>
                        </div>
                    </div>
                @endif

                <div class="kt-card-content p-3 border-b border-border">
                    <input type="search" class="kt-input" placeholder="Search outlets"
                           aria-label="Search outlets" wire:model.live.debounce.250ms="feedSearch">
                </div>

                <div class="kt-scrollable-x-auto">
                    <table class="kt-table">
                        <thead>
                            <tr>
                                <th>Outlet</th>
                                <th class="w-24">Trust</th>
                                <th class="w-28">Window</th>
                                <th class="w-32 text-end">On</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($feeds as $feed)
                                <tr wire:key="feed-{{ $feed->id }}">
                                    <td>
                                        <span class="block text-sm font-medium text-mono">{{ $feed->label }}</span>
                                        <span class="block text-xs text-muted-foreground truncate max-w-[420px]">{{ $feed->url }}</span>
                                    </td>
                                    <td class="text-sm text-secondary-foreground">{{ number_format($feed->authority, 2) }}</td>
                                    <td class="text-sm text-secondary-foreground">
                                        {{ $feed->max_age_hours ? $feed->max_age_hours.' h' : '—' }}
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            <button class="kt-btn kt-btn-icon kt-btn-ghost kt-btn-sm"
                                                    wire:click="editFeed({{ $feed->id }})"
                                                    title="Edit {{ $feed->label }}" aria-label="Edit {{ $feed->label }}">
                                                <i class="ki-filled ki-setting-2"></i>
                                            </button>
                                            <button class="kt-btn kt-btn-icon kt-btn-ghost kt-btn-sm"
                                                    wire:click="toggleFeed({{ $feed->id }})"
                                                    title="{{ $feed->is_active ? 'Stop reading' : 'Start reading' }} {{ $feed->label }}"
                                                    aria-label="{{ $feed->is_active ? 'Stop reading' : 'Start reading' }} {{ $feed->label }}">
                                                <i class="ki-filled {{ $feed->is_active ? 'ki-toggle-on text-success' : 'ki-toggle-off text-muted-foreground' }}"></i>
                                            </button>
                                            <button class="kt-btn kt-btn-icon kt-btn-ghost kt-btn-sm"
                                                    wire:click="deleteFeed({{ $feed->id }})"
                                                    title="Remove {{ $feed->label }}" aria-label="Remove {{ $feed->label }}">
                                                <i class="ki-filled ki-trash text-destructive"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <div class="flex flex-col items-center text-center gap-2 py-10">
                                            <i class="ki-filled ki-satellite text-2xl text-muted-foreground"></i>
                                            <p class="text-sm text-secondary-foreground">
                                                @if ($feedSearch !== '')
                                                    No outlet matches "{{ $feedSearch }}".
                                                @else
                                                    No outlets yet. Add one, or run the Social seeder for the shipped forty.
                                                @endif
                                            </p>
                                            <button class="kt-btn kt-btn-sm kt-btn-primary" wire:click="newFeed">Add outlet</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ── Windows ──────────────────────────────────────────────────── --}}

            <div class="kt-card" id="windows">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">When each network is posted to</h3>
                </div>
                <div class="kt-card-content p-5 pb-0">
                    <p class="text-sm text-secondary-foreground">
                        Times are in {{ $timezone }}. The exact minute is random inside the window, fresh each day.
                        Instagram in Iran peaks in the evening and LinkedIn is read on weekday mornings, which is why
                        each network is scheduled separately rather than all at once.
                    </p>
                    <p class="text-sm text-secondary-foreground mt-2">
                        <span class="font-medium text-mono">The hashtag ceiling is not a matter of taste.</span>
                        Ten or more on LinkedIn risks a 30–50% loss of reach and its algorithm no longer reads them
                        for classification; Instagram allows thirty and dense tagging is normal there.
                    </p>
                </div>

                <div class="kt-scrollable-x-auto">
                    <table class="kt-table">
                        <thead>
                            <tr>
                                <th>Network</th>
                                <th class="w-40">Weekdays</th>
                                <th class="w-40">Weekend</th>
                                <th class="w-32">Hashtags</th>
                                <th class="w-20 text-end">On</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($windows as $window)
                                @php
                                    $meta = $catalogue[$window->network] ?? null;
                                    $connected = ($accounts[$window->network] ?? collect())->count();
                                @endphp
                                <tr wire:key="window-{{ $window->id }}">
                                    <td>
                                        <span class="flex items-center gap-2 text-sm font-medium text-mono">
                                            <i class="ki-filled {{ $meta['icon'] ?? 'ki-abstract-26' }} {{ $meta['tone'] ?? '' }}"></i>
                                            {{ $meta['label'] ?? $window->network }}
                                        </span>
                                        <span class="block text-xs {{ $connected ? 'text-muted-foreground' : 'text-warning' }} mt-0.5">
                                            {{ $connected
                                                ? $connected.' '.str('account')->plural($connected).' connected'
                                                : 'no account connected — nothing will be sent' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-1">
                                            <input type="text" class="kt-input kt-input-sm w-[68px]" value="{{ $window->starts_at }}"
                                                   aria-label="{{ $window->network }} weekday start"
                                                   wire:change="saveWindow({{ $window->id }}, 'starts_at', $event.target.value)">
                                            <span class="text-xs text-muted-foreground">–</span>
                                            <input type="text" class="kt-input kt-input-sm w-[68px]" value="{{ $window->ends_at }}"
                                                   aria-label="{{ $window->network }} weekday end"
                                                   wire:change="saveWindow({{ $window->id }}, 'ends_at', $event.target.value)">
                                        </div>
                                    </td>
                                    <td>
                                        @if ($window->weekend_starts_at)
                                            <div class="flex items-center gap-1">
                                                <input type="text" class="kt-input kt-input-sm w-[68px]" value="{{ $window->weekend_starts_at }}"
                                                       aria-label="{{ $window->network }} weekend start"
                                                       wire:change="saveWindow({{ $window->id }}, 'weekend_starts_at', $event.target.value)">
                                                <span class="text-xs text-muted-foreground">–</span>
                                                <input type="text" class="kt-input kt-input-sm w-[68px]" value="{{ $window->weekend_ends_at }}"
                                                       aria-label="{{ $window->network }} weekend end"
                                                       wire:change="saveWindow({{ $window->id }}, 'weekend_ends_at', $event.target.value)">
                                            </div>
                                        @else
                                            <span class="text-xs text-muted-foreground">same as weekdays</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-1">
                                            <input type="number" min="0" max="30" class="kt-input kt-input-sm w-[56px]"
                                                   value="{{ $window->hashtags_min }}"
                                                   aria-label="{{ $window->network }} minimum hashtags"
                                                   wire:change="saveWindow({{ $window->id }}, 'hashtags_min', $event.target.value)">
                                            <span class="text-xs text-muted-foreground">–</span>
                                            <input type="number" min="0" max="30" class="kt-input kt-input-sm w-[56px]"
                                                   value="{{ $window->hashtags_max }}"
                                                   aria-label="{{ $window->network }} maximum hashtags"
                                                   wire:change="saveWindow({{ $window->id }}, 'hashtags_max', $event.target.value)">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex justify-end">
                                            <button class="kt-btn kt-btn-icon kt-btn-ghost kt-btn-sm"
                                                    wire:click="toggleWindow({{ $window->id }})"
                                                    title="{{ $window->is_active ? 'Stop posting to' : 'Start posting to' }} {{ $window->network }}"
                                                    aria-label="{{ $window->is_active ? 'Stop posting to' : 'Start posting to' }} {{ $window->network }}">
                                                <i class="ki-filled {{ $window->is_active ? 'ki-toggle-on text-success' : 'ki-toggle-off text-muted-foreground' }}"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="flex flex-col items-center text-center gap-2 py-10">
                                            <i class="ki-filled ki-time text-2xl text-muted-foreground"></i>
                                            <p class="text-sm text-secondary-foreground">
                                                No windows configured. Networks without one are posted to in the
                                                evening by default.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>
