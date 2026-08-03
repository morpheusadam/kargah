<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Contracts\NotificationPreferences;
use Modules\Core\Support\NotificationEvents;

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
 */
new
#[Title('Notifications — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** @var array<string, array{in_app: bool, email: bool}> */
    public array $prefs = [];

    #[Validate('required|in:instant,daily,weekly,off')]
    public string $digest = NotificationEvents::DEFAULT_DIGEST;

    public bool $quietHours = false;

    #[Validate('required|date_format:H:i')]
    public string $quietFrom = NotificationEvents::DEFAULT_QUIET_FROM;

    #[Validate('required|date_format:H:i')]
    public string $quietTo = NotificationEvents::DEFAULT_QUIET_TO;

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

    public function with(): array
    {
        return ['groups' => NotificationEvents::grouped()];
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

            @foreach ($groups as $groupName => $rows)
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">{{ $groupName }}</h3></div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[280px]">Event</th>
                                        <th class="w-[110px] text-center">In app</th>
                                        <th class="w-[110px] text-center">Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php($slug = str_replace('.', '_', $row['event']))
                                        <tr wire:key="event-{{ $row['event'] }}">
                                            <td class="text-mono">{{ $row['label'] }}</td>
                                            <td class="text-center">
                                                <label class="kt-switch">
                                                    <input type="checkbox" wire:model="prefs.{{ $slug }}.in_app">
                                                </label>
                                            </td>
                                            <td class="text-center">
                                                <label class="kt-switch">
                                                    <input type="checkbox" wire:model="prefs.{{ $slug }}.email">
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

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Delivery</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="flex flex-col gap-1 max-w-[280px]">
                        <label class="kt-form-label font-normal text-mono">Email digest</label>
                        <select class="kt-select @error('digest') border-destructive @enderror" wire:model="digest">
                            <option value="instant">Send each one immediately</option>
                            <option value="daily">Daily summary</option>
                            <option value="weekly">Weekly summary</option>
                            <option value="off">No email at all</option>
                        </select>
                        @error('digest')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <label class="flex items-center justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium text-mono">Quiet hours</span>
                            <span class="block text-xs text-muted-foreground">Do not send email overnight — cards, invoices and mail still land in the feed, only email waits.</span>
                        </span>
                        <span class="kt-switch shrink-0"><input type="checkbox" wire:model.live="quietHours"></span>
                    </label>

                    @if ($quietHours)
                        <div class="flex items-center gap-3">
                            <input type="time" class="kt-input max-w-[140px] @error('quietFrom') border-destructive @enderror" wire:model="quietFrom">
                            <span class="text-sm text-muted-foreground">to</span>
                            <input type="time" class="kt-input max-w-[140px] @error('quietTo') border-destructive @enderror" wire:model="quietTo">
                        </div>
                        @error('quietFrom')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
                        @error('quietTo')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
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
