<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Support\Networks;

/**
 * Publishing calendar.
 *
 * A month at a glance, so you can see where the gaps are before adding another
 * Tuesday-morning post to a Tuesday that already has three. A week view sits
 * beside it — FullCalendar's own toolbar button, `dayGridMonth`/`timeGridWeek`
 * — for the days where "the 14th" is not specific enough.
 *
 * **One entry per target, not per post.** A post going to three networks is
 * three things on the calendar, because filtering by network is the reason
 * anybody opens this page and a single entry could not answer it. The colour is
 * the network's own.
 *
 * FullCalendar is 277 KB and the layout does not load it. It is pulled in from
 * `@assets` on this page alone, and there is a plain list underneath that is
 * hidden only once the bundle has actually rendered — so a page served without
 * it still shows what is scheduled instead of an empty box.
 *
 * 🔴 The `src` tag used to sit inside `@script`, and Livewire drops such a tag
 * silently — it evaluates inline code, and a tag whose whole content is an
 * external `src` has none. **This calendar had therefore never rendered once**;
 * the plain list underneath is the only reason the page looked fine. Corrected
 * 4 August 2026 after loading it in a real browser. See
 * docs/frontend-conventions.md, which named this file as the pattern to copy.
 *
 * ## Timezone — the whole page reads and writes in one zone: the person's own
 *
 * `displayTimezone()` is `users.timezone` (defaulted to `Europe/London` by the
 * `2026_08_03_100000_…` migration, and already how
 * `Modules\Core\Services\NotificationPreferences::timezoneFor()` decides quiet
 * hours), falling back to `config('app.timezone')` — UTC — for the same reason
 * that fallback exists there: a bad or missing preference must not turn a
 * calendar render into a 500. Every clock on this page — the month grid, the
 * week grid, the plain list, "Next up", the reschedule form — goes through
 * `displayed()` and is labelled with the zone name in the page header, so
 * nothing on screen is a guess about which clock it is reading.
 *
 * `posts.scheduled_for` itself is stored and compared in UTC — `now()` inside
 * `PublishDue::handle()` (Modules/Social/app/Console/PublishDue.php:35) and
 * `Post::scopeDue()` (Modules/Social/app/Models/Post.php:126) both run in the
 * application's UTC clock, because `config('app.timezone')` is `'UTC'`. So
 * `reschedule()` parses whatever wall-clock string it is given **as a time in
 * `displayTimezone()`** and converts it to UTC with `->utc()` before saving —
 * see that method's own docblock for why skipping the conversion would be a bug
 * that only shows up when the clocks change.
 */
new
#[Title('Calendar — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** A network key, or 'all'. */
    #[Url]
    public string $network = 'all';

    /** The target being moved through the explicit (non-drag) editor, or null. */
    public ?int $editingTargetId = null;

    /** `datetime-local` value for the row currently being edited. */
    public string $editValue = '';

    /** `datetime-local` value for the "new post at…" quick-add control. */
    public string $quickAddAt = '';

    /** Per-request memo; see the note on ⚡boards about why these are private. */
    private ?Collection $resolvedTargets = null;

    /**
     * Every target with a time, newest month first.
     *
     * Bounded rather than the whole history: the calendar draws whatever month
     * or week it is asked for and the sidebar draws what is next, so a
     * five-year archive would be loaded to be thrown away. There is no upper
     * bound on the future side — FullCalendar switches between month and week
     * and pages months forward entirely client-side against this one payload,
     * so a post scheduled eight months out still has to be in it.
     *
     * @return Collection<int, PostTarget>
     */
    private function targets(): Collection
    {
        return $this->resolvedTargets ??= PostTarget::query()
            ->with(['post', 'account'])
            ->whereHas('post', fn ($query) => $query
                ->whereIn('status', [Post::SCHEDULED, Post::PUBLISHING, Post::PUBLISHED, Post::PARTLY_FAILED])
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '>=', now()->subMonths(3)))
            ->get()
            ->filter(fn (PostTarget $target): bool => $target->post !== null && $target->account !== null)
            ->values();
    }

    /** @return Collection<int, PostTarget> */
    private function visible(): Collection
    {
        return $this->targets()
            ->filter(fn (PostTarget $t): bool => $this->network === 'all' || $t->account->network === $this->network)
            ->values();
    }

    /** When a target sits on the calendar: the moment it went out, or the moment it is due. Still UTC — see `displayed()`. */
    private function momentOf(PostTarget $target): Carbon
    {
        return $target->published_at ?? $target->post->scheduled_for;
    }

    /**
     * The zone every clock on this page reads and writes through.
     *
     * See the class docblock's Timezone section for why this is the user's own
     * preference rather than the server's, and why it falls back rather than
     * throws.
     */
    private function displayTimezone(): string
    {
        $timezone = auth()->user()?->timezone;

        if (! is_string($timezone) || $timezone === '') {
            return (string) config('app.timezone', 'UTC');
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            return (string) config('app.timezone', 'UTC');
        }

        return $timezone;
    }

    /** A UTC moment, converted for the screen. Never used for a database write — `reschedule()` converts the other way. */
    private function displayed(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone($this->displayTimezone());
    }

    public function with(): array
    {
        $visible = $this->visible();
        $tz = $this->displayTimezone();

        $queued = $visible
            ->filter(fn (PostTarget $t): bool => ! $t->isPublished())
            ->sortBy(fn (PostTarget $t): string => $this->momentOf($t)->toDateTimeString())
            ->values();

        // The signal that tells "nothing is scheduled" apart from "something is
        // scheduled and cron has stopped reading it". A post is only counted
        // once it is more than two minutes past its time and still sitting on
        // `scheduled` — one minute is the cron's own promise, and the second is
        // slack for a slow tick, so a post at :30 seconds past is not a false
        // alarm. There is no heartbeat to read instead of this: `PublishDue`
        // (Modules/Social/app/Console/PublishDue.php) is outside this
        // component's ownership, so it writes nothing this page could read
        // directly — see the final report for the one-line addition that would
        // replace this inference with a real one.
        $overdue = $queued->filter(fn (PostTarget $t): bool => $t->post->status === Post::SCHEDULED
            && $t->post->scheduled_for !== null
            && $t->post->scheduled_for->lt(now()->subMinutes(2)));

        $oldestOverdueAt = $overdue->min(fn (PostTarget $t): int => $t->post->scheduled_for->timestamp);

        return [
            // `all()` and deliberately not `available()`. Everything on this
            // page is a target that already exists, and a post that went out to
            // DEV.to last month keeps its dot, its colour and its label after
            // the Blog module is switched off — history is not an offer, and
            // filtering it would leave grey unlabelled rows in a month nobody
            // can change. See the class docblock on Modules\Social\Support\Networks.
            'catalogue' => Networks::all(),
            // Only networks with something on the calendar; a filter that can
            // only ever return nothing is not a filter.
            'filters' => $this->targets()->pluck('account.network')->unique()->values(),
            'queued' => $queued,
            'published' => $visible->filter(fn (PostTarget $t): bool => $t->isPublished())->count(),
            'tz' => $tz,
            // The current offset alongside the name — 'Europe/London' alone
            // does not say GMT or BST, and that is exactly the ambiguity DST
            // creates. Both `T` (abbreviation) and `P` (offset) are read off
            // the zone's own table via `now()`, never typed out, so this label
            // is right on both sides of a DST change without a second branch.
            'tzLabel' => $tz.' ('.now()->setTimezone($tz)->format('T').', UTC'.now()->setTimezone($tz)->format('P').')',
            'overdueMinutes' => $oldestOverdueAt === null ? null : (int) floor((now()->timestamp - $oldestOverdueAt) / 60),
            'events' => $visible->map(fn (PostTarget $t): array => [
                'id' => (string) $t->id,
                'title' => $t->account->label().' · '.$t->post->excerpt(60),
                // An offset-bearing ISO string, so FullCalendar's own `timeZone`
                // option — set to `displayTimezone()` in the script below —
                // re-renders it correctly regardless of which zone the visiting
                // browser's OS happens to be set to. See the class docblock.
                'start' => $this->momentOf($t)->toIso8601String(),
                'url' => route('social.post-show', $t->post_id),
                'backgroundColor' => Networks::colour($t->account->network),
                'borderColor' => Networks::colour($t->account->network),
                'classNames' => [$t->isPublished() ? 'is-published' : 'is-scheduled'],
                // A published target is history, not a plan — dragging it would
                // ask `reschedule()` to un-send something already sent, which it
                // already refuses server-side. Refusing the drag itself here is
                // the same rule stated where the person's cursor is.
                'editable' => ! $t->isPublished(),
                'extendedProps' => ['postId' => $t->post_id],
            ])->values()->all(),
            'listRows' => $visible
                ->sortByDesc(fn (PostTarget $t): string => $this->momentOf($t)->toDateTimeString())
                ->values(),
        ];
    }

    public function setNetwork(string $network): void
    {
        $this->network = $network === 'all' || Networks::has($network) ? $network : 'all';

        $this->resolvedTargets = null;
    }

    /**
     * Move a scheduled post to a new time.
     *
     * The whole post moves, not one target: `scheduled_for` lives on the post,
     * and a per-network time would need a column the schema does not have. A
     * post that has already gone out to one network is refused rather than
     * silently moving only the rest.
     *
     * `$at` is a wall-clock string with no offset of its own — a
     * `datetime-local` input and FullCalendar's `startStr` (once the script's
     * `timeZone` is set to `displayTimezone()`) both hand over exactly that
     * shape. It is parsed **in** the display timezone and converted with
     * `->utc()` before it is saved, because `posts.scheduled_for` is read back
     * by `PublishDue::handle()` (Modules/Social/app/Console/PublishDue.php:35)
     * through `Post::scopeDue()` (Modules/Social/app/Models/Post.php:126),
     * both of which compare against `now()` in the application's UTC clock.
     * Saving a Carbon still carrying `Europe/London` would write local
     * wall-clock digits straight into a column cron reads as UTC — right on
     * the day this ran, wrong by an hour the day the clocks change, because
     * the column itself carries no zone of its own to catch the mismatch.
     *
     * Returns whether the move actually happened, so the calendar's drag
     * handler (`@script` below) can tell a rejected drop from a saved one —
     * the full-payload rebuild after every server round trip already snaps a
     * rejected drop back to where it started, but the caller still needs to
     * know which one occurred.
     */
    public function reschedule(int $postId, string $at): bool
    {
        $post = Post::query()->with('targets')->find($postId);

        if ($post === null) {
            $this->toastError('That post is no longer here', 'Reload the page and try again.');

            return false;
        }

        if ($post->targets->contains(fn (PostTarget $t): bool => $t->isPublished())) {
            $this->toastError(
                'That post has already gone out',
                'Moving it would not unsend what is already published. Write a new one instead.',
            );

            return false;
        }

        try {
            $when = Carbon::parse($at, $this->displayTimezone())->utc();
        } catch (\Throwable) {
            $this->toastError('That is not a date Kargah can read', 'The post was not moved.');

            return false;
        }

        if ($when->isPast()) {
            $this->toastError('That time has already passed', 'Pick a time in the future, or publish it now.');

            return false;
        }

        $post->forceFill(['status' => Post::SCHEDULED, 'scheduled_for' => $when])->save();

        $this->resolvedTargets = null;

        $this->toastSuccess(
            'Moved to '.$this->displayed($when)->format('D j M, H:i').' ('.$this->displayTimezone().')',
            'The scheduler checks every minute, so it goes out within a minute of that time.',
        );

        return true;
    }

    /**
     * The keyboard- and screen-reader-reachable alternative to dragging.
     *
     * Lives in the "Next up" list rather than inside the FullCalendar grid, on
     * purpose: that list is always in the DOM — it is only ever hidden by
     * `[data-social-calendar-fallback]`'s own sibling, never itself — so the
     * explicit editor works identically whether or not the 277 KB bundle ever
     * loaded.
     */
    public function startEdit(int $targetId): void
    {
        $target = $this->targets()->firstWhere('id', $targetId);

        if ($target === null || $target->isPublished()) {
            return;
        }

        $this->editingTargetId = $targetId;
        $this->editValue = $this->displayed($this->momentOf($target))->format('Y-m-d\TH:i');
    }

    public function cancelEdit(): void
    {
        $this->editingTargetId = null;
        $this->editValue = '';
    }

    public function saveEdit(): void
    {
        if ($this->editingTargetId === null) {
            return;
        }

        $target = PostTarget::query()->find($this->editingTargetId);

        if ($target === null) {
            $this->toastError('That post is no longer here', 'Reload the page and try again.');
            $this->cancelEdit();

            return;
        }

        $this->reschedule($target->post_id, $this->editValue);
        $this->cancelEdit();
    }

    /**
     * Land in the composer with this slot's time pre-filled.
     *
     * 🔴 `⚡publish.blade.php` does not read `schedule` or `scheduled_at` from
     * the query string today — neither property carries `#[Url]`, and its
     * `mount()` only ever sets `$targets`. This method still resolves the
     * correct UTC instant and builds the link that would prefill it, because
     * that is this component's whole share of the work; the two-line addition
     * on the composer's side is outside this file's ownership and is called out
     * in the final report rather than made here.
     */
    public function startNewPost(string $at, bool $allDay = false): void
    {
        try {
            $when = Carbon::parse($allDay ? $at.' 09:00' : $at, $this->displayTimezone())->utc();
        } catch (\Throwable) {
            $this->toastError('That is not a date Kargah can read', 'Pick a day on the calendar and try again.');

            return;
        }

        if ($when->isPast()) {
            $this->toastError('That time has already passed', 'Pick a time in the future.');

            return;
        }

        $this->redirectRoute('social.publish', [
            'schedule' => 'later',
            'scheduled_at' => $when->format('Y-m-d\TH:i'),
        ], navigate: true);
    }

    /** The no-JS, no-drag path to (d): a plain field that reaches the same method a calendar click does. */
    public function quickAdd(): void
    {
        if (trim($this->quickAddAt) === '') {
            $this->toastError('Pick a day and time first', 'Nothing was opened.');

            return;
        }

        $this->startNewPost($this->quickAddAt);
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Calendar</h1>
            <p class="text-sm text-secondary-foreground mt-1">See the month before you commit to another Tuesday morning.</p>
        </div>
        <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> New post
        </a>
    </div>

    {{-- The timezone label the whole class docblock argues for. Every time on
         this page — the grid, the list, "Next up", the reschedule form — reads
         through this one zone, so this line is the key to every clock below it. --}}
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <i class="ki-filled ki-time text-sm"></i>
        <span>Times shown in {{ $tzLabel }}</span>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button wire:click="setNetwork('all')"
                class="kt-btn kt-btn-sm gap-2 {{ $network === 'all' ? 'kt-btn-primary' : 'kt-btn-outline' }}">
            <i class="ki-filled ki-element-11 text-sm"></i> All networks
        </button>
        @foreach ($filters as $key)
            <button wire:click="setNetwork('{{ $key }}')" wire:key="cal-filter-{{ $key }}"
                    class="kt-btn kt-btn-sm gap-2 {{ $network === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <span class="size-2 rounded-full {{ $catalogue[$key]['dot'] ?? 'bg-muted' }}"></span>
                {{ $catalogue[$key]['label'] ?? $key }}
            </button>
        @endforeach
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Month / week view --}}
        <div class="col-span-12 xl:col-span-8">
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Month / week</h3>
                    <span class="text-xs text-muted-foreground">
                        {{ count($queued) }} queued · {{ $published }} published
                    </span>
                </div>
                <div class="kt-card-content p-4">
                    <div id="social-calendar"
                         class="kt-scrollable-x-auto"
                         data-social-calendar
                         data-tz="{{ $tz }}"
                         data-events="{{ json_encode($events) }}"></div>

                    {{-- Shown until the calendar bundle takes over, and if it never loads --}}
                    <div data-social-calendar-fallback class="flex flex-col divide-y divide-border">
                        @forelse ($listRows as $target)
                            <div class="flex items-center gap-3 py-2.5" wire:key="row-{{ $target->id }}">
                                <span class="size-2 rounded-full shrink-0"
                                      style="background-color: {{ $catalogue[$target->account->network]['colour'] ?? '#78829d' }}"></span>
                                <a href="{{ route('social.post-show', $target->post_id) }}" wire:navigate
                                   class="text-sm text-mono grow min-w-0 truncate hover:text-primary">
                                    {{ $target->account->label() }} · {{ $target->post->excerpt(60) }}
                                </a>
                                <span class="text-xs text-muted-foreground shrink-0">
                                    {{ $this->displayed($target->published_at ?? $target->post->scheduled_for)->format('D j M, H:i') }}
                                </span>
                            </div>
                        @empty
                            <div class="flex flex-col items-center py-14 text-center">
                                <i class="ki-filled ki-calendar text-4xl text-muted-foreground mb-3"></i>
                                <p class="text-sm text-secondary-foreground">Nothing on the calendar for this network.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Quick add: the no-JS, no-drag path to landing in the composer at a
                 chosen time — see `quickAdd()`. Always visible, unlike the grid
                 above, which needs the FullCalendar bundle to draw at all. --}}
            <div class="kt-card mt-5">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">New post at a time</h3>
                </div>
                <div class="kt-card-content p-4 flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label text-xs" for="cal-quick-add">Date and time ({{ $tz }})</label>
                        <input id="cal-quick-add" type="datetime-local" class="kt-input max-w-[220px]" wire:model="quickAddAt">
                    </div>
                    <button wire:click="quickAdd" wire:loading.attr="disabled" class="kt-btn kt-btn-outline gap-2">
                        <i class="ki-filled ki-plus"></i> Open in composer
                    </button>
                </div>
            </div>
        </div>

        <div class="col-span-12 xl:col-span-4 flex flex-col gap-5">

            {{-- Scheduler health: (e) — what is due next, in order, and enough
                 signal to tell an empty calendar apart from a stopped cron. --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Next up</h3>
                    <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Queue</a>
                </div>

                @if ($overdueMinutes !== null)
                    <div class="mx-4 mt-4 flex items-start gap-2.5 rounded-lg border border-destructive/40 bg-destructive/5 p-3">
                        <i class="ki-filled ki-shield-cross text-destructive text-base mt-0.5 shrink-0"></i>
                        <span class="text-sm text-secondary-foreground">
                            A post has been waiting {{ $overdueMinutes }} {{ $overdueMinutes === 1 ? 'minute' : 'minutes' }}
                            past its time and is still marked scheduled. Cron runs `social:publish-due` every minute — if
                            it were running, this post would already be `publishing`. Check the crontab entry runs
                            <code class="text-xs">php artisan schedule:run</code> once a minute.
                        </span>
                    </div>
                @elseif (count($queued) > 0)
                    <div class="mx-4 mt-4 flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/10 p-3">
                        <i class="ki-filled ki-check-circle text-success text-base mt-0.5 shrink-0"></i>
                        <span class="text-sm text-secondary-foreground">Nothing is overdue — the scheduler is keeping up.</span>
                    </div>
                @endif

                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($queued as $target)
                        @php $when = $this->displayed($target->published_at ?? $target->post->scheduled_for); @endphp
                        <div class="flex items-start gap-3 px-4 py-3" wire:key="next-{{ $target->id }}">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                <i class="ki-filled {{ $target->account->icon() }} text-base text-muted-foreground"></i>
                            </span>
                            <a href="{{ route('social.post-show', $target->post_id) }}" wire:navigate class="min-w-0 grow hover:text-primary">
                                <div class="text-sm font-medium text-mono truncate">{{ $loop->iteration }}. {{ $target->post->excerpt(60) }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ $target->account->label() }} · {{ $when->format('D j M, H:i') }} · {{ $when->diffForHumans(['short' => true]) }}
                                </div>
                            </a>
                            <button type="button" wire:click="startEdit({{ $target->id }})"
                                    aria-label="Move {{ $target->account->label() }} · {{ $target->post->excerpt(30) }}"
                                    class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost shrink-0">
                                <i class="ki-filled ki-calendar-edit"></i>
                            </button>
                        </div>

                        @if ($editingTargetId === $target->id)
                            <div class="px-4 pb-3 flex flex-wrap items-end gap-2 bg-accent/30" wire:key="edit-{{ $target->id }}">
                                <div class="flex flex-col gap-1">
                                    <label class="kt-form-label text-xs" for="cal-edit-{{ $target->id }}">New time ({{ $tz }})</label>
                                    <input id="cal-edit-{{ $target->id }}" type="datetime-local" class="kt-input max-w-[200px]" wire:model="editValue">
                                </div>
                                <button wire:click="saveEdit" wire:loading.attr="disabled" class="kt-btn kt-btn-sm kt-btn-primary">
                                    <span wire:loading.remove wire:target="saveEdit">Save</span>
                                    <span wire:loading wire:target="saveEdit"><i class="ki-filled ki-loading animate-spin"></i></span>
                                </button>
                                <button wire:click="cancelEdit" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                            </div>
                        @endif
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-time text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Nothing queued.</p>
                            <a href="{{ route('social.publish') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3">Write one</a>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- What the schedule actually promises --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">How the timing works</h3>
                </div>
                <div class="kt-card-content p-4 flex flex-col gap-3">
                    <p class="text-xs text-muted-foreground">
                        There is no daemon behind this. Cron runs the scheduler every minute, the scheduler hands each
                        due post to a small job, and a worker sends it — so a post goes out within a minute of its time
                        rather than exactly on it.
                    </p>
                    <div class="flex items-start gap-2.5 rounded-lg border border-border p-3">
                        <i class="ki-filled ki-check-circle text-success text-base mt-0.5 shrink-0"></i>
                        <span class="text-sm text-secondary-foreground">
                            A network that fails does not hold up the others, and retrying sends only what did not go.
                        </span>
                    </div>
                    <div class="flex items-start gap-2.5 rounded-lg border border-border p-3">
                        <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                        <span class="text-sm text-secondary-foreground">
                            An account whose credentials are not configured records that on the post rather than sending it.
                        </span>
                    </div>
                </div>
            </div>

        </div>

    </div>
{{--
    Kept inside the component's root element on purpose. Livewire renders one
    root node and discards everything after it, so a @push below the closing tag
    never reaches the page.
--}}
@assets
<script src="{{ asset('assets/vendors/fullcalendar/index.global.min.js') }}"></script>
@endassets
@script
<script>
(function () {
    function mount() {
        // A closure left behind by a wire:navigate must not touch the page that
        // replaced it.
        if (! $wire.$el || ! $wire.$el.isConnected) return;

        var el = $wire.$el.querySelector('[data-social-calendar]');

        if (! el) return;

        // No bundle, no calendar — leave the plain list and the always-visible
        // "Next up" editor in place rather than an empty box.
        if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar !== 'function') return;

        // Ask the library, never a data-* flag: Livewire's morph removes any
        // attribute the incoming HTML does not carry, so a flag would clear
        // itself on every render and leave a second instance on the same node.
        if (el._socialCalendar) {
            el._socialCalendar.destroy();
            el._socialCalendar = null;
        }

        var events = [];

        try {
            events = JSON.parse(el.dataset.events || '[]');
        } catch (e) {
            events = [];
        }

        var calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            height: 620,
            firstDay: 1,
            // Named rather than 'local': the events carry a UTC offset, and
            // parsing that against a fixed IANA zone is what makes the grid
            // agree with the "Times shown in …" label above regardless of which
            // zone the visiting browser's OS happens to be set to. See the
            // class docblock's Timezone section.
            timeZone: el.dataset.tz || 'UTC',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' },
            buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'List' },
            events: events,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            dayMaxEventRows: 3,
            eventDisplay: 'block',
            // (b) Drag to reschedule. `editable` is also set per-event — a
            // published target answers false there and cannot be picked up —
            // this is only the switch that lets a scheduled one move at all.
            editable: true,
            eventDurationEditable: false,
            eventStartEditable: true,
            eventDrop: function (info) {
                var postId = info.event.extendedProps.postId;

                // `startStr` is wall-clock text in the zone set above — with no
                // offset of its own, because the zone is named rather than
                // 'local' — which is exactly the shape `reschedule()` expects.
                // The server round trip this triggers re-renders the whole
                // component, which rebuilds this calendar from fresh data
                // through `morphed` below; a rejected move therefore snaps back
                // to where it started without any revert logic needed here.
                $wire.reschedule(postId, info.event.startStr);
            },
            // (d) Create a post from a slot. `allDay` tells the server whether
            // this was a day cell (month view) or a real time (week view), so
            // it can default the day-cell case to a sensible hour rather than
            // midnight.
            dateClick: function (info) {
                $wire.startNewPost(info.dateStr, info.allDay);
            }
        });

        calendar.render();

        el._socialCalendar = calendar;

        var fallback = $wire.$el.querySelector('[data-social-calendar-fallback]');

        // Hidden only now, once the calendar has genuinely drawn.
        if (fallback) fallback.classList.add('hidden');
    }

    // Once per component, not once per DOM node touched.
    Livewire.hook('morphed', mount);

    mount();
})();
</script>
@endscript
</div>
