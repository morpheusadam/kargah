<?php

use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Publishing calendar.
 *
 * A month at a glance so you can see where the gaps are before adding another
 * Tuesday-morning post to a Tuesday that already has three.
 */
new
#[Title('Calendar — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $network = 'all';

    /** @return array<string, array{label: string, icon: string, tone: string, colour: string}> */
    public function networks(): array
    {
        return [
            'telegram'  => ['label' => 'Telegram',  'icon' => 'ki-paper-plane',         'tone' => 'bg-info',        'colour' => '#0088cc'],
            'linkedin'  => ['label' => 'LinkedIn',  'icon' => 'ki-abstract-41',  'tone' => 'bg-primary',     'colour' => '#0a66c2'],
            'x'         => ['label' => 'X',         'icon' => 'ki-abstract-39',  'tone' => 'bg-foreground',  'colour' => '#3f4254'],
            'instagram' => ['label' => 'Instagram', 'icon' => 'ki-instagram',    'tone' => 'bg-destructive', 'colour' => '#e1306c'],
        ];
    }

    public function with(): array
    {
        $today = Carbon::today();

        $posts = [
            ['id' => 1, 'title' => 'Drag-and-drop board write-up', 'network' => 'linkedin',  'status' => 'scheduled', 'at' => $today->copy()->addDay()->setTime(9, 30)],
            ['id' => 2, 'title' => 'Ordering persists after refresh', 'network' => 'x',        'status' => 'scheduled', 'at' => $today->copy()->addDay()->setTime(9, 30)],
            ['id' => 3, 'title' => 'Build log: invoice PDF templates', 'network' => 'telegram', 'status' => 'scheduled', 'at' => $today->copy()->addDays(2)->setTime(18, 0)],
            ['id' => 4, 'title' => 'Desk setup for the new contract', 'network' => 'instagram','status' => 'scheduled', 'at' => $today->copy()->addDays(4)->setTime(12, 15)],
            ['id' => 5, 'title' => 'Why I stopped using an SPA', 'network' => 'linkedin',  'status' => 'scheduled', 'at' => $today->copy()->addDays(6)->setTime(9, 0)],
            ['id' => 6, 'title' => 'Shared hosting benchmark numbers', 'network' => 'x',        'status' => 'published', 'at' => $today->copy()->subDays(2)->setTime(10, 5)],
            ['id' => 7, 'title' => 'Client onboarding checklist', 'network' => 'linkedin',  'status' => 'published', 'at' => $today->copy()->subDays(5)->setTime(9, 40)],
            ['id' => 8, 'title' => 'Kargah changelog for July', 'network' => 'telegram', 'status' => 'published', 'at' => $today->copy()->subDays(9)->setTime(17, 20)],
            ['id' => 9, 'title' => 'Time-tracking screenshot', 'network' => 'instagram','status' => 'published', 'at' => $today->copy()->subDays(12)->setTime(13, 0)],
        ];

        $networks = $this->networks();

        $visible = array_values(array_filter(
            $posts,
            fn (array $p): bool => $this->network === 'all' || $p['network'] === $this->network,
        ));

        $queued = array_values(array_filter($visible, fn (array $p): bool => $p['status'] === 'scheduled'));
        usort($queued, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $events = array_map(fn (array $p): array => [
            'id' => (string) $p['id'],
            'title' => $p['title'],
            'start' => $p['at']->toIso8601String(),
            'url' => route('social.post-show', $p['id']),
            'backgroundColor' => $networks[$p['network']]['colour'],
            'borderColor' => $networks[$p['network']]['colour'],
            'classNames' => $p['status'] === 'published' ? ['is-published'] : ['is-scheduled'],
        ], $visible);

        return [
            'networks' => $networks,
            'events' => $events,
            'queued' => $queued,
            'published' => count(array_filter($visible, fn (array $p): bool => $p['status'] === 'published')),
            'bestTimes' => [
                ['network' => 'linkedin',  'window' => 'Tue–Thu, 08:45–10:00', 'note' => 'Highest reply rate on posts about the build'],
                ['network' => 'x',         'window' => 'Weekdays, 13:00–14:30', 'note' => 'Reposts cluster around lunch'],
                ['network' => 'telegram',  'window' => 'Weekdays, 18:00–19:30', 'note' => 'Channel opens peak after work'],
                ['network' => 'instagram', 'window' => 'Sat–Sun, 11:00–12:30', 'note' => 'Weekend saves run ahead of weekdays'],
            ],
        ];
    }

    public function reschedule(int $post, string $at): void
    {
        // Moving an entry on the calendar rewrites the queued job. Backend work.

        $this->toastInfo('Rescheduling is not wired up yet', 'The post is still queued for its original time.');
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
        <button wire:click="$set('network', 'all')"
                class="kt-btn kt-btn-sm gap-2 {{ $network === 'all' ? 'kt-btn-primary' : 'kt-btn-outline' }}">
            <i class="ki-filled ki-element-11 text-sm"></i> All networks
        </button>
        @foreach ($networks as $key => $n)
            <button wire:click="$set('network', '{{ $key }}')"
                    class="kt-btn kt-btn-sm gap-2 {{ $network === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <span class="size-2 rounded-full {{ $n['tone'] }}"></span> {{ $n['label'] }}
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
                        @forelse ($events as $e)
                            <div class="flex items-center gap-3 py-2.5">
                                <span class="size-2 rounded-full shrink-0" style="background-color: {{ $e['backgroundColor'] }}"></span>
                                <span class="text-sm text-mono grow min-w-0 truncate">{{ $e['title'] }}</span>
                                <span class="text-xs text-muted-foreground shrink-0">
                                    {{ \Illuminate\Support\Carbon::parse($e['start'])->format('D j M, H:i') }}
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
                    @forelse ($queued as $q)
                        <a href="{{ route('social.post-show', $q['id']) }}" wire:navigate
                           class="flex items-start gap-3 px-4 py-3 hover:bg-accent/30 transition-colors">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-muted shrink-0">
                                <i class="ki-filled {{ $networks[$q['network']]['icon'] }} text-base text-muted-foreground"></i>
                            </span>
                            <div class="min-w-0 grow">
                                <div class="text-sm font-medium text-mono truncate">{{ $q['title'] }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ $networks[$q['network']]['label'] }} · {{ $q['at']->format('D j M, H:i') }}
                                </div>
                            </div>
                            <span class="text-xs text-muted-foreground shrink-0">{{ $q['at']->diffForHumans(['short' => true]) }}</span>
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

            {{-- Best time to post --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Best time to post</h3>
                </div>
                <div class="kt-card-content p-4 flex flex-col gap-3">
                    <p class="text-xs text-muted-foreground">
                        Windows are worked out from engagement on your own posts over the last 90 days, not from a
                        published industry average.
                    </p>
                    @foreach ($bestTimes as $b)
                        <div class="flex items-start gap-3 rounded-lg border border-border p-3">
                            <span class="size-2 rounded-full mt-1.5 shrink-0 {{ $networks[$b['network']]['tone'] }}"></span>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-mono">
                                    {{ $networks[$b['network']]['label'] }}
                                    <span class="text-secondary-foreground font-normal">· {{ $b['window'] }}</span>
                                </div>
                                <div class="text-xs text-muted-foreground mt-0.5">{{ $b['note'] }}</div>
                            </div>
                        </div>
                    @endforeach
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
        var el = document.querySelector('[data-social-calendar]');

        if (! el) {
            return;
        }

        var fallback = document.querySelector('[data-social-calendar-fallback]');

        // No bundle, no calendar — leave the plain list in place rather than an empty box.
        if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar !== 'function') {
            return;
        }

        // Livewire re-renders the container; tear the old instance down before rebuilding.
        if (el.dataset.calendarMounted === '1' && el._socialCalendar) {
            el._socialCalendar.destroy();
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
        el.dataset.calendarMounted = '1';

        if (fallback) {
            fallback.classList.add('hidden');
        }
    }

    document.addEventListener('DOMContentLoaded', mount);
    if (window.Livewire) Livewire.hook('morph.updated', mount);
    mount();
})();
</script>
@endscript
</div>
