<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Contracts\NotificationPreferences;
use Modules\Core\Support\NotificationEvents;
use Modules\Platform\Support\ConnectionHealth;

/**
 * What reaches this person, and how — reads and writes
 * `notification_preferences` and `notification_settings` through
 * `Modules\Core\Contracts\NotificationPreferences`, never the models
 * directly.
 *
 * The event list itself lives in `Modules\Core\Support\NotificationEvents`,
 * not here, so this page and `Modules\Core\Services\Notifier` cannot drift
 * apart on what an event is called or what it defaults to.
 *
 * Nothing here writes until `save()` runs. Flipping a switch is a form edit,
 * not a write, so it says nothing; saving is a write, so it toasts.
 *
 * `$prefs` is keyed by a **slugged** event id (dots turned to underscores),
 * not the real event string. Livewire's `wire:model` binds through
 * `data_get()`/`data_set()`, which always splits a model path on `.` — an
 * event id like `invoice.overdue` used directly as an array key would make
 * `wire:model="prefs.invoice.overdue.in_app"` bind to
 * `$prefs['invoice']['overdue']['in_app']` instead of
 * `$prefs['invoice.overdue']['in_app']`. `slug()`, and `save()`'s own loop
 * back over the canonical event list, are the only place that translation
 * happens; `NotificationPreferences` never sees anything but the real dotted
 * event strings.
 *
 * ---
 *
 * **The tables are grouped by what somebody is trying to keep track of, not by
 * which module fires the event.** `NotificationEvents::grouped()` groups by
 * module — Projects, Mail, Accounting, Data, Social — which is the shape of the
 * codebase and not the shape of anybody's attention. "A backup fails" and "a
 * scheduled post fails" belong together because they are the two things that
 * break silently while you are not looking; they were four table rows apart,
 * under headings named after two unrelated modules. `GROUPS` below is that
 * regrouping, and it is a view concern: `NotificationEvents` is untouched, and
 * an event it grows that this file has not heard of falls into "Everything
 * else" rather than vanishing from the page — a switch that disappears is a
 * preference somebody silently loses.
 *
 * **Connection health sits above the switches that depend on it.** Two of these
 * columns promise something Kargah cannot always keep: the email column needs a
 * mail transport that is not `log`, and the Social rows need at least one
 * account whose token has not expired. Both are read through
 * `Modules\Platform\Support\ConnectionHealth`, which returns scalars rather than
 * models — putting a `SocialAccount` into this component's payload would
 * serialise a decrypting `credentials` cast straight into the page source.
 */
new
#[Title('Notifications — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The settings-nav search box. See `partials/settings-nav.blade.php`. */
    public string $settingsFilter = '';

    /** @var array<string, array{in_app: bool, email: bool}> */
    public array $prefs = [];

    #[Validate('required|in:instant,daily,weekly,off')]
    public string $digest = NotificationEvents::DEFAULT_DIGEST;

    public bool $quietHours = false;

    #[Validate('required|date_format:H:i')]
    public string $quietFrom = NotificationEvents::DEFAULT_QUIET_FROM;

    #[Validate('required|date_format:H:i')]
    public string $quietTo = NotificationEvents::DEFAULT_QUIET_TO;

    /**
     * The task-shaped grouping, and the one sentence each switch earns.
     *
     * `heading` is what somebody is trying to do; `intro` says what the whole
     * group's switches control; `changes` is per event, and every one of them
     * describes what stops or starts happening when that row is turned off,
     * rather than restating the label in different words. An event key not
     * listed here still renders — see `groups()`.
     */
    private const GROUPS = [
        [
            'heading' => 'Work that needs doing today',
            'intro' => 'The three that are about your own workload rather than somebody else changing something.',
            'events' => [
                'card.due_soon' => 'Off means a card due today passes with nothing in the bell feed to say so.',
                'card.overdue' => 'Off means a card that has slipped past its date stops chasing you about it.',
                'card.assigned' => 'Off means work handed to you appears on the board with no announcement.',
            ],
        ],
        [
            'heading' => 'Things other people change',
            'intro' => 'Everything here comes from watching a card, a list or a board — turn the watch off and these stop regardless.',
            'events' => [
                'card.commented' => 'Off means a comment on a card you watch arrives silently, and you find it next time you open the card.',
                'card.due_changed' => 'Off means somebody moving a deadline on a card you watch does not tell you.',
                'card.moved' => 'Off means a card you watch changing list is something you notice on the board or not at all.',
                'card.archived' => 'Off means a watched card disappearing from the board is not announced.',
                'card.new_in_list' => 'Off means new cards land in a list you watch without a line in the feed.',
            ],
        ],
        [
            'heading' => 'Money',
            'intro' => 'Both default to email as well as the feed, because both change what you are owed.',
            'events' => [
                'invoice.paid' => 'Off means an invoice being settled shows up only when you next open the invoice list.',
                'invoice.overdue' => 'Off means an unpaid invoice passing its due date chases nobody.',
            ],
        ],
        [
            'heading' => 'Mail going out and coming in',
            'intro' => 'The inbox and the campaign sender. Bounce and quota warnings are the ones with money behind them.',
            'events' => [
                'email.received' => 'Off means new mail appears in the inbox with no badge and no feed entry.',
                'campaign.completed' => 'Off means a campaign finishing sending tells you nothing; the report is still there to open.',
                'campaign.bounce_spike' => 'Off means a bounce rate crossing 2% is something you find in the report rather than being warned about.',
                'provider.quota_low' => 'Off means a sending provider approaching its quota goes unmentioned until sends start failing.',
            ],
        ],
        [
            'heading' => 'Things that break quietly',
            'intro' => 'Every row here is about something that stops working while nobody is watching, which is why all four default to email as well.',
            'events' => [
                'social.token_expiring' => 'Off removes the only warning before a connected account stops accepting posts.',
                'social.token_expired' => 'Off means publishing to that account fails from then on with nothing said.',
                'post.failed' => 'Off means a scheduled post that did not go out is only visible on the post itself.',
                'backup.failed' => 'Off means a failed backup is silent, which is the failure you find out about when you need the backup.',
            ],
        ],
    ];

    private function userId(): int
    {
        return (int) auth()->id();
    }

    /** See the class docblock: `wire:model` cannot bind through a dotted array key. */
    private function slug(string $event): string
    {
        return str_replace('.', '_', $event);
    }

    public function mount(): void
    {
        $service = app(NotificationPreferences::class);

        foreach ($service->forUser($this->userId()) as $event => $channels) {
            $this->prefs[$this->slug($event)] = $channels;
        }

        $this->digest = $service->digest($this->userId());

        $quiet = $service->quietHours($this->userId());
        $this->quietHours = $quiet['enabled'];
        $this->quietFrom = $quiet['from'];
        $this->quietTo = $quiet['to'];
    }

    /**
     * Sentences, not codes.
     *
     * `date_format:H:i` produces "The quiet from field must match the format
     * H:i", which names a PHP format string at somebody who typed a time into
     * a time field. These say what was wrong and what a right answer looks
     * like — the same standard `PostPublisher` writes failures to.
     */
    protected function messages(): array
    {
        return [
            'digest.required' => 'Choose how often email should arrive.',
            'digest.in' => 'That is not one of the four delivery choices: immediately, daily, weekly, or not at all.',
            'quietFrom.required' => 'Quiet hours need a start time, like 22:00.',
            'quietTo.required' => 'Quiet hours need an end time, like 08:00.',
            'quietFrom.date_format' => 'That is not a time Kargah can read. Quiet hours start at a 24-hour time like 22:00.',
            'quietTo.date_format' => 'That is not a time Kargah can read. Quiet hours end at a 24-hour time like 08:00.',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $events = [];

        foreach (array_keys(NotificationEvents::all()) as $event) {
            $slug = $this->slug($event);

            if (array_key_exists($slug, $this->prefs)) {
                $events[$event] = $this->prefs[$slug];
            }
        }

        app(NotificationPreferences::class)->save(
            $this->userId(),
            $events,
            $this->digest,
            $this->quietHours,
            $this->quietFrom,
            $this->quietTo,
        );

        $this->toastSuccess('Notification settings saved', 'These preferences now apply across Kargah.');
    }

    /**
     * The event rows, regrouped by task and carrying their explanations.
     *
     * ⚠️ The trailing "Everything else" group is not defensive padding. Core
     * owns the event list and this file does not; the day somebody adds a
     * nineteenth event there, it has to appear here without a second commit, or
     * a person has a preference they cannot see and cannot change. Only events
     * with no sentence written for them land in it, so the group is normally
     * absent entirely.
     *
     * @return list<array{heading: string, intro: string, rows: list<array{event: string, slug: string, label: string, changes: string}>}>
     */
    private function groups(): array
    {
        $catalogue = NotificationEvents::all();
        $placed = [];
        $groups = [];

        foreach (self::GROUPS as $group) {
            $rows = [];

            foreach ($group['events'] as $event => $changes) {
                if (! array_key_exists($event, $catalogue)) {
                    // An event this page describes but Core has dropped. Skip
                    // it rather than render a switch that writes to nothing.
                    continue;
                }

                $placed[$event] = true;

                $rows[] = [
                    'event' => $event,
                    'slug' => $this->slug($event),
                    'label' => $catalogue[$event]['label'],
                    'changes' => $changes,
                ];
            }

            if ($rows !== []) {
                $groups[] = ['heading' => $group['heading'], 'intro' => $group['intro'], 'rows' => $rows];
            }
        }

        $orphans = [];

        foreach ($catalogue as $event => $meta) {
            if (isset($placed[$event])) {
                continue;
            }

            $orphans[] = [
                'event' => $event,
                'slug' => $this->slug($event),
                'label' => $meta['label'],
                'changes' => 'Turning this off stops Kargah telling you about it, in the feed or by email.',
            ];
        }

        if ($orphans !== []) {
            $groups[] = [
                'heading' => 'Everything else',
                'intro' => 'Events added since this page was last grouped. They work exactly like the ones above.',
                'rows' => $orphans,
            ];
        }

        return $groups;
    }

    public function with(): array
    {
        return [
            'groups' => $this->groups(),
            'mail' => ConnectionHealth::mailDelivery(),
            'social' => ConnectionHealth::socialSummary(),
            'tokenSchedule' => ConnectionHealth::tokenWarningSchedule(),
            'emailAddress' => auth()->user()?->email ?? ConnectionHealth::UNKNOWN,
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

            <div>
                <h2 class="text-lg font-semibold text-mono">Notifications</h2>
                <p class="text-sm text-secondary-foreground mt-1">
                    Which of the things Kargah notices are worth interrupting you for, and whether that
                    interruption is a line in the bell feed or a message to {{ $emailAddress }}.
                </p>
            </div>

            {{-- Connection health, above the switches that depend on it. Both cards
                 answer a question the table underneath cannot: whether the column
                 headed "Email" reaches anybody, and whether any social account exists
                 for the social rows to be about. --}}
            <div class="kt-card {{ $mail['state'] === 'healthy' ? '' : 'border-warning/40 bg-warning/5' }}">
                <div class="kt-card-content p-4 flex items-start gap-3">
                    <i class="ki-filled {{ $mail['state'] === 'healthy' ? 'ki-check-circle text-success' : 'ki-information-2 text-warning' }} text-lg mt-0.5 shrink-0"></i>
                    <div class="min-w-0 flex flex-col gap-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <strong class="text-sm text-mono">{{ $mail['headline'] }}</strong>
                            <span class="{{ $mail['tone'] }}">{{ $mail['mailer'] }}</span>
                        </span>
                        <span class="text-sm text-secondary-foreground">{{ $mail['detail'] }}</span>
                    </div>
                </div>
            </div>

            @if ($social !== null)
                <div class="kt-card {{ $social['state'] === 'healthy' || $social['state'] === 'idle' ? '' : 'border-warning/40 bg-warning/5' }}">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Connected accounts</h3>
                        <span class="{{ $social['tone'] }}">{{ $social['summary'] }}</span>
                    </div>
                    <div class="kt-card-content p-4 flex flex-col gap-3">

                        @forelse ($social['accounts'] as $account)
                            <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg bg-muted px-3 py-2"
                                 wire:key="social-health-{{ $account['id'] }}">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-mono">
                                        {{ $account['label'] }} <span class="text-secondary-foreground">{{ $account['handle'] }}</span>
                                    </div>
                                    <div class="text-xs text-secondary-foreground">{{ $account['detail'] }}</div>
                                    <div class="text-[11px] text-muted-foreground mt-0.5">
                                        Connected {{ $account['connected'] }} · last checked {{ $account['checked'] }} · token expires {{ $account['expires'] }}
                                    </div>
                                </div>
                                <span class="{{ $account['tone'] }} shrink-0">{{ $account['headline'] }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-secondary-foreground">{{ $social['summary'] }}</p>
                        @endforelse

                        @if ($tokenSchedule !== null)
                            <p class="text-xs text-muted-foreground">{{ $tokenSchedule }}</p>
                        @endif

                    </div>
                </div>
            @endif

            <div id="events" class="flex flex-col gap-5">
                @foreach ($groups as $group)
                    <div class="kt-card" wire:key="group-{{ $loop->index }}">
                        <div class="kt-card-header">
                            <h3 class="kt-card-title">{{ $group['heading'] }}</h3>
                        </div>
                        <div class="kt-card-content px-5 pt-4 pb-0">
                            <p class="text-sm text-secondary-foreground">{{ $group['intro'] }}</p>
                        </div>
                        <div class="kt-card-table">
                            <div class="kt-scrollable-x-auto">
                                <table class="kt-table align-middle text-sm">
                                    <thead>
                                        <tr>
                                            <th class="min-w-[320px]">Event</th>
                                            <th class="w-[110px] text-center">In app</th>
                                            <th class="w-[110px] text-center">Email</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($group['rows'] as $row)
                                            <tr wire:key="event-{{ $row['event'] }}">
                                                <td>
                                                    <div class="text-mono">{{ $row['label'] }}</div>
                                                    <div class="text-xs text-muted-foreground">{{ $row['changes'] }}</div>
                                                </td>
                                                <td class="text-center">
                                                    <label class="kt-switch">
                                                        <input type="checkbox" wire:model="prefs.{{ $row['slug'] }}.in_app"
                                                               aria-label="{{ $row['label'] }} in the bell feed">
                                                    </label>
                                                </td>
                                                <td class="text-center">
                                                    <label class="kt-switch">
                                                        <input type="checkbox" wire:model="prefs.{{ $row['slug'] }}.email"
                                                               aria-label="{{ $row['label'] }} by email">
                                                    </label>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="kt-card" id="delivery">
                <div class="kt-card-header"><h3 class="kt-card-title">When email is allowed to arrive</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="flex flex-col gap-1 max-w-[320px]">
                        <label class="kt-form-label font-normal text-mono" for="digest">Email digest</label>
                        <select id="digest" class="kt-select @error('digest') border-destructive @enderror" wire:model="digest">
                            <option value="instant">Send each one immediately</option>
                            <option value="daily">Daily summary</option>
                            <option value="weekly">Weekly summary</option>
                            <option value="off">No email at all</option>
                        </select>
                        <span class="text-xs text-muted-foreground mt-1">
                            Changes whether email arrives one message at a time, once a day, once a week, or never.
                            The bell feed is unaffected either way.
                        </span>
                        @error('digest')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <label class="flex items-start justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium text-mono">Quiet hours</span>
                            <span class="block text-xs text-muted-foreground mt-1">
                                Holds email back between the two times below. Cards, invoices and mail still land in
                                the bell feed as they happen — only email waits.
                            </span>
                        </span>
                        <span class="kt-switch shrink-0"><input type="checkbox" wire:model.live="quietHours" aria-label="Quiet hours"></span>
                    </label>

                    @if ($quietHours)
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-3">
                                <input type="time" aria-label="Quiet hours start"
                                       class="kt-input max-w-[140px] @error('quietFrom') border-destructive @enderror" wire:model="quietFrom">
                                <span class="text-sm text-muted-foreground">to</span>
                                <input type="time" aria-label="Quiet hours end"
                                       class="kt-input max-w-[140px] @error('quietTo') border-destructive @enderror" wire:model="quietTo">
                            </div>
                            @error('quietFrom')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            @error('quietTo')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    @endif

                </div>
                <div class="kt-card-footer flex items-center justify-end">
                    <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            class="kt-btn kt-btn-primary gap-2">
                        <span wire:loading.remove wire:target="save">Save changes</span>
                        <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Saving…
                        </span>
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>
