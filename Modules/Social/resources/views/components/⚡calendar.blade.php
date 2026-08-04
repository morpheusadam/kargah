<?php

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
 * Tuesday-morning post to a Tuesday that already has three.
 *
 * **One entry per target, not per post.** A post going to three networks is
 * three things on the calendar, because filtering by network is the reason
 * anybody opens this page and a single entry could not answer it. The colour is
 * the network's own.
 *
 * FullCalendar is 277 KB and the layout does not load it. It is pulled in from
 * `@script` on this page alone, and there is a plain list underneath that is
 * hidden only once the bundle has actually rendered — so a page served without
 * it still shows what is scheduled instead of an empty box.
 */
new
#[Title('Calendar — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** A network key, or 'all'. */
    #[Url]
    public string $network = 'all';

    /** Per-request memo; see the note on ⚡boards about why these are private. */
    private ?Collection $resolvedTargets = null;

    /**
     * Every target with a time, newest month first.
     *
     * Bounded rather than the whole history: the calendar draws one month and
     * the sidebar draws what is next, so a five-year archive would be loaded to
     * be thrown away.
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

    /** When a target sits on the calendar: the moment it went out, or the moment it is due. */
    private function momentOf(PostTarget $target): \Illuminate\Support\Carbon
    {
        return $target->published_at ?? $target->post->scheduled_for;
    }

    public function with(): array
    {
        $visible = $this->visible();

        $queued = $visible
            ->filter(fn (PostTarget $t): bool => ! $t->isPublished())
            ->sortBy(fn (PostTarget $t): string => $this->momentOf($t)->toDateTimeString())
            ->values();

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
            'events' => $visible->map(fn (PostTarget $t): array => [
                'id' => (string) $t->id,
                'title' => $t->account->label().' · '.$t->post->excerpt(60),
                'start' => $this->momentOf($t)->toIso8601String(),
                'url' => route('social.post-show', $t->post_id),
                'backgroundColor' => Networks::colour($t->account->network),
                'borderColor' => Networks::colour($t->account->network),
                'classNames' => [$t->isPublished() ? 'is-published' : 'is-scheduled'],
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
     */
    public function reschedule(int $postId, string $at): void
    {
        $post = Post::query()->with('targets')->find($postId);

        if ($post === null) {
            $this->toastError('That post is no longer here', 'Reload the page and try again.');

            return;
        }

        if ($post->targets->contains(fn (PostTarget $t): bool => $t->isPublished())) {
            $this->toastError(
                'That post has already gone out',
                'Moving it would not unsend what is already published. Write a new one instead.',
            );

            return;
        }

        try {
            $when = \Illuminate\Support\Carbon::parse($at);
        } catch (\Throwable) {
            $this->toastError('That is not a date Kargah can read', 'The post was not moved.');

            return;
        }

        if ($when->isPast()) {
            $this->toastError('That time has already passed', 'Pick a time in the future, or publish it now.');

            return;
        }

        $post->forceFill(['status' => Post::SCHEDULED, 'scheduled_for' => $when])->save();

        $this->resolvedTargets = null;

        $this->toastSuccess(
            'Moved to '.$when->format('D j M, H:i'),
            'The scheduler checks every minute, so it goes out within a minute of that time.',
        );
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

        {{-- Month view --}}
        <div class="col-span-12 xl:col-span-8">
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Month</h3>
                    <span class="text-xs text-muted-foreground">
                        {{ count($queued) }} queued · {{ $published }} published
                    </span>
                </div>
                <div class="kt-card-content p-4">
                    <div id="social-calendar"
                         class="kt-scrollable-x-auto"
                         data-social-calendar
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
                                    {{ ($target->published_at ?? $target->post->scheduled_for)?->format('D j M, H:i') }}
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
        </div>

        <div class="col-span-12 xl:col-span-4 flex flex-col gap-5">

            {{-- Next up --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Next up</h3>
                    <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Queue</a>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($queued as $target)
                        @php $when = $target->published_at ?? $target->post->scheduled_for; @endphp
                        <a href="{{ route('social.post-show', $target->post_id) }}" wire:navigate
                           wire:key="next-{{ $target->id }}"
                           class="flex items-start gap-3 px-4 py-3 hover:bg-accent/30 transition-colors">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                <i class="ki-filled {{ $target->account->icon() }} text-base text-muted-foreground"></i>
                            </span>
                            <div class="min-w-0 grow">
                                <div class="text-sm font-medium text-mono truncate">{{ $target->post->excerpt(60) }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ $target->account->label() }} · {{ $when?->format('D j M, H:i') }}
                                </div>
                            </div>
                            <span class="text-xs text-muted-foreground shrink-0">
                                {{ $when?->diffForHumans(['short' => true]) }}
                            </span>
                        </a>
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
@script
<script src="/assets/vendors/fullcalendar/index.global.min.js"></script>
<script>
(function () {
    function mount() {
        // A closure left behind by a wire:navigate must not touch the page that
        // replaced it.
        if (! $wire.$el || ! $wire.$el.isConnected) return;

        var el = $wire.$el.querySelector('[data-social-calendar]');

        if (! el) return;

        // No bundle, no calendar — leave the plain list in place rather than an
        // empty box.
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
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            buttonText: { today: 'Today', month: 'Month', list: 'List' },
            events: events,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            dayMaxEventRows: 3,
            eventDisplay: 'block'
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
