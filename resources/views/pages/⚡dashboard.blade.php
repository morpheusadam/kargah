<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Contracts\InvoiceReader as InvoiceReaderContract;
use Modules\Core\Contracts\Notifier as NotifierContract;
use Modules\Mailbox\Contracts\EmailReader as EmailReaderContract;
use Modules\Project\Contracts\BoardReader as BoardReaderContract;
use Spatie\Activitylog\Models\Activity;

/**
 * The home screen.
 *
 * It answers one question: what needs me today. Everything on it traces to a
 * row, through a module's `Contracts` namespace — this file lives outside
 * every module and must never import a module's `Models` namespace. See
 * `Modules\Data\Contracts\AttachmentService`'s docblock for why.
 *
 * **Money is never summed here — it is summed inside Accounting and read
 * back already summed.** `InvoiceReader::totals()` does the arithmetic
 * through `brick/money`, one figure per currency, because Kargah invoices in
 * USD, TRY and USDT and a single cross-currency total is not a number
 * anyone could defend to an accountant — see the contract's own docblock for
 * why per-currency was chosen over converting through a frozen rate. This
 * page only ever receives the finished `{amount, currency, formatted}`
 * arrays and joins their `formatted` strings for display; it still never
 * touches a raw amount or a currency symbol table, which is what keeps this
 * file outside Accounting's `Support` namespace exactly as before.
 *
 * **"Unread mail" now has a source.** `Modules\Mailbox\Contracts\EmailReader
 * ::unreadCount()` is inbox-wide — every folder, matching
 * `⚡inbox.blade.php`'s own `unreadTotal()` exactly — rather than a proxy
 * built by summing `countForCustomer()` over every customer, which would
 * both miss mail from an unknown sender and cost one query per customer.
 *
 * **`Spatie\Activitylog\Models\Activity` is read directly, not through a
 * contract.** It is shared package infrastructure — the same table every
 * module already writes to via the `activity()` helper — not a module's
 * private domain model, so this does not reach into `Modules\X\Models`. Only
 * `description` (already rendered, human text — the same reasoning
 * `Modules\Core\Contracts\Notifier` gives for `title`/`body`) and `causer`
 * (`App\Models\User`, an application model) are read; `subject` is never
 * touched, because resolving it would mean knowing what a card or an invoice
 * is.
 */
new
#[Title('Dashboard — Kargah')]
class extends Component
{
    /**
     * Cache keys, published as constants because the `database` cache store
     * has no tags — invalidation has to be by explicit key, and a key nobody
     * can find is a key nobody can invalidate. Nothing on this page writes
     * the figures it reads, so there is no write path here to bust them from;
     * the `flexible()` fresh window is what keeps them from ever being more
     * than a few seconds stale.
     */
    public const CACHE_INVOICE_STATS = 'dashboard.invoices.v1';

    public const CACHE_INVOICE_TOTALS = 'dashboard.invoice-totals.v1';

    public const CACHE_CARD_STATS = 'dashboard.cards.v1';

    public const CACHE_DUE_CARDS = 'dashboard.due-cards.v1';

    public const CACHE_MAIL_STATS = 'dashboard.mail.v1';

    public const CACHE_NOTIFICATIONS = 'dashboard.notifications.v1';

    public const CACHE_ACTIVITY = 'dashboard.activity.v1';

    private const RANGE_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

    #[Url]
    public string $range = '30d';

    public function mount(): void
    {
        // #[Url] is whatever the address bar says.
        if (! array_key_exists($this->range, self::RANGE_DAYS)) {
            $this->range = '30d';
        }
    }

    /**
     * The range only changes how far ahead "cards due" and the agenda look;
     * invoices, mail and notifications are not windowed by it. Both islands
     * are named so the change actually reaches the browser — an island
     * nobody names comes back `mode=skip` and the morph walks straight past
     * it.
     */
    public function updatedRange(): void
    {
        if (! array_key_exists($this->range, self::RANGE_DAYS)) {
            $this->range = '30d';
        }

        $this->renderIsland('stats');
        $this->renderIsland('due-cards');
        $this->renderIsland('agenda');
    }

    private function days(): int
    {
        return self::RANGE_DAYS[$this->range] ?? 30;
    }

    /** @return array{open_count: int, overdue_count: int, nearest_open: ?array, most_overdue: ?array, upcoming: list<array>} */
    private function invoiceStats(): array
    {
        return Cache::flexible(self::CACHE_INVOICE_STATS, [15, 180], function (): array {
            // Two calls, not one. The migration's own comment allows a stored
            // status of 'overdue' distinct from 'sent' — `paginate('sent')`
            // alone silently drops those rows, which is exactly the kind of
            // wrong number this page exists to stop showing. Merged by id so
            // an invoice that happens to satisfy both scopes is not counted
            // twice.
            $reader = app(InvoiceReaderContract::class);
            $items = collect($reader->paginate('sent', perPage: 100)['items'])
                ->concat($reader->paginate('overdue', perPage: 100)['items'])
                ->unique('id')
                ->values()
                ->all();

            usort($items, fn (array $a, array $b): int => ($a['due_on'] ?? '9999-12-31') <=> ($b['due_on'] ?? '9999-12-31'));

            $overdue = array_values(array_filter($items, fn (array $i): bool => $i['is_overdue']));
            $upcoming = array_values(array_filter($items, fn (array $i): bool => $i['due_on'] !== null && ! $i['is_overdue']));

            return [
                'open_count' => count($items),
                'overdue_count' => count($overdue),
                'nearest_open' => $upcoming[0] ?? ($items[0] ?? null),
                'most_overdue' => $overdue[0] ?? null,
                // Bounded and already sorted, so the agenda can merge this
                // with cards due without a second call to Accounting.
                'upcoming' => array_slice($upcoming, 0, 8),
            ];
        });
    }

    /**
     * The book's real money, per currency — see `InvoiceReader::totals()`'s
     * docblock for why this is never one cross-currency number. Every entry
     * is a plain `{amount, currency, formatted}` array of strings, so this
     * is safe inside `Cache::flexible()` on the `database` store: nothing
     * here is an object that could come back `__PHP_Incomplete_Class` on the
     * stale-serve path.
     *
     * @return array{outstanding: list<array{amount: string, currency: string, formatted: string}>, overdue: list<array{amount: string, currency: string, formatted: string}>}
     */
    private function invoiceTotals(): array
    {
        return Cache::flexible(self::CACHE_INVOICE_TOTALS, [15, 180], fn (): array => app(InvoiceReaderContract::class)->totals());
    }

    /** @return array{due_soon_count: int, overdue_count: int} */
    private function cardStats(): array
    {
        $days = $this->days();

        return Cache::flexible(self::CACHE_CARD_STATS.'.'.$days, [15, 180], fn (): array => [
            'due_soon_count' => app(BoardReaderContract::class)->countDueSoon($days),
            'overdue_count' => app(BoardReaderContract::class)->countOverdue(),
        ]);
    }

    /**
     * Overdue first (most urgent), then due soon — both already sorted by
     * `BoardReader`, and an overdue card's due date always sorts before a
     * due-soon one, so concatenating rather than re-sorting is exact, not an
     * approximation.
     *
     * @return list<array>
     */
    private function dueCards(): array
    {
        $days = $this->days();

        return Cache::flexible(self::CACHE_DUE_CARDS.'.'.$days, [15, 180], function () use ($days): array {
            $reader = app(BoardReaderContract::class);

            return array_slice(array_merge($reader->cardsOverdue(5), $reader->cardsDueSoon($days, 5)), 0, 8);
        });
    }

    /** @return array{unread_count: int} */
    private function mailStats(): array
    {
        return Cache::flexible(self::CACHE_MAIL_STATS, [15, 180], fn (): array => [
            'unread_count' => app(EmailReaderContract::class)->unreadCount(),
        ]);
    }

    /** @return array{unread_count: int, latest_title: ?string} */
    private function notificationStats(): array
    {
        $userId = (int) auth()->id();

        return Cache::flexible(self::CACHE_NOTIFICATIONS.'.'.$userId, [15, 180], function () use ($userId): array {
            $notifier = app(NotifierContract::class);

            return [
                'unread_count' => $notifier->unreadCount($userId),
                'latest_title' => $notifier->recent($userId, 1, unreadOnly: true)->first()['title'] ?? null,
            ];
        });
    }

    /**
     * `Cache::flexible()`'s stale branch reads the value back through the
     * `database` store's own `unserialize()`, and a `Carbon` instance nested
     * inside that value comes back as `__PHP_Incomplete_Class` — reproduced
     * in isolation, nothing to do with this page's own code otherwise.
     * Every cached array on this page is therefore primitives only; a
     * timestamp is turned into its human string *before* it goes in, not
     * after it comes out.
     *
     * @return list<array{description: string, actor: string, when: string}>
     */
    private function recentActivity(): array
    {
        return Cache::flexible(self::CACHE_ACTIVITY, [15, 180], fn (): array => Activity::query()
            ->latest('id')
            ->with('causer')
            ->limit(8)
            ->get(['id', 'description', 'causer_id', 'causer_type', 'created_at'])
            ->map(fn (Activity $activity): array => [
                'description' => $activity->description,
                'actor' => $activity->causer?->name ?? 'System',
                'when' => $activity->created_at?->diffForHumans() ?? '—',
            ])
            ->all());
    }

    /**
     * The soonest-first timeline: cards and invoices merged by due date, real
     * throughout. A card's row links into its board; an invoice's names its
     * own already-formatted amount rather than computing anything new.
     *
     * @return list<array{due_on: ?string, title: string, kind: string, tone: string, url: string}>
     */
    private function agenda(): array
    {
        $invoices = $this->invoiceStats();
        $cards = $this->dueCards();

        $items = [];

        foreach (array_slice($cards, 0, 5) as $card) {
            $items[] = [
                'due_on' => $card['due_on'],
                'title' => $card['title'],
                'kind' => 'card',
                'tone' => $card['due_state'] === 'overdue' ? 'bg-destructive' : 'bg-primary',
                'url' => $card['url'],
            ];
        }

        $invoiceItems = $invoices['most_overdue'] === null
            ? []
            : [$invoices['most_overdue']];
        $invoiceItems = array_merge($invoiceItems, array_slice($invoices['upcoming'], 0, 5));

        foreach ($invoiceItems as $invoice) {
            $items[] = [
                'due_on' => $invoice['due_on'],
                'title' => 'Invoice '.$invoice['number'].' — '.$invoice['outstanding']['formatted'].' outstanding',
                'kind' => 'invoice',
                'tone' => 'bg-warning',
                'url' => route('accounting.invoice-show', ['invoice' => $invoice['id']]),
            ];
        }

        usort($items, fn (array $a, array $b): int => ($a['due_on'] ?? '9999-12-31') <=> ($b['due_on'] ?? '9999-12-31'));

        return array_slice($items, 0, 6);
    }

    /**
     * The non-zero currencies in a `totals()` list, joined for one line of
     * text — `"$575.00"`, or `"$575.00 · ₺12,000.00"` for a book genuinely
     * open in two currencies at once. Never combines them into one number;
     * see `InvoiceReader::totals()`'s docblock for why.
     *
     * @param  list<array{amount: string, currency: string, formatted: string}>  $entries
     */
    private function moneyLine(array $entries): string
    {
        $nonZero = array_values(array_filter($entries, fn (array $money): bool => $money['amount'] !== '0.000000'));

        return implode(' · ', array_map(fn (array $money): string => $money['formatted'], $nonZero));
    }

    /** "Overdue by 2 days" / "Due today" / "Due tomorrow" / "Due in 5 days" — never a clock time no `due_on` column carries. */
    private function dueLabel(?string $dueOn): string
    {
        if ($dueOn === null) {
            return '—';
        }

        $diff = (int) now()->startOfDay()->diffInDays(Carbon::parse($dueOn)->startOfDay(), false);

        return match (true) {
            $diff < 0 => 'Overdue by '.abs($diff).' '.Str::plural('day', abs($diff)),
            $diff === 0 => 'Due today',
            $diff === 1 => 'Due tomorrow',
            default => 'Due in '.$diff.' days',
        };
    }

    public function with(): array
    {
        $invoices = $this->invoiceStats();
        $invoiceTotals = $this->invoiceTotals();
        $cards = $this->cardStats();
        $mail = $this->mailStats();
        $notifications = $this->notificationStats();

        return [
            'ranges' => ['7d' => 'Next 7 days', '30d' => 'Next 30 days', '90d' => 'Next quarter'],

            'stats' => [
                [
                    'label' => 'Unpaid invoices',
                    'value' => (string) $invoices['open_count'],
                    'sub' => $invoices['open_count'] === 0
                        ? 'Nothing outstanding'
                        : ($invoices['overdue_count'] > 0
                            ? $invoices['overdue_count'].' overdue — '.$this->moneyLine($invoiceTotals['overdue']).' past due'
                            : 'You are owed '.$this->moneyLine($invoiceTotals['outstanding'])),
                    'icon' => 'ki-dollar',
                    'tone' => $invoices['overdue_count'] > 0 ? 'text-destructive' : 'text-warning',
                    'bg' => $invoices['overdue_count'] > 0 ? 'bg-destructive/10' : 'bg-warning/10',
                    'route' => 'accounting.invoices',
                ],
                [
                    'label' => 'Cards due',
                    'value' => (string) $cards['due_soon_count'],
                    'sub' => $cards['overdue_count'] > 0
                        ? $cards['overdue_count'].' overdue'
                        : 'None overdue',
                    'icon' => 'ki-abstract-26',
                    'tone' => $cards['overdue_count'] > 0 ? 'text-destructive' : 'text-primary',
                    'bg' => $cards['overdue_count'] > 0 ? 'bg-destructive/10' : 'bg-primary/10',
                    'route' => 'projects.boards',
                ],
                [
                    'label' => 'Unread mail',
                    'value' => (string) $mail['unread_count'],
                    'sub' => $mail['unread_count'] === 0
                        ? 'Inbox zero'
                        : $mail['unread_count'].' '.Str::plural('message', $mail['unread_count']).' waiting',
                    'icon' => 'ki-sms',
                    'tone' => $mail['unread_count'] > 0 ? 'text-info' : 'text-muted-foreground',
                    'bg' => $mail['unread_count'] > 0 ? 'bg-info/10' : 'bg-muted',
                    'route' => 'mail.inbox',
                ],
                [
                    'label' => 'Notifications',
                    'value' => (string) $notifications['unread_count'],
                    'sub' => $notifications['unread_count'] === 0
                        ? 'You are caught up'
                        : Str::limit((string) $notifications['latest_title'], 34),
                    'icon' => 'ki-notification-status',
                    'tone' => $notifications['unread_count'] > 0 ? 'text-info' : 'text-success',
                    'bg' => $notifications['unread_count'] > 0 ? 'bg-info/10' : 'bg-success/10',
                    'route' => 'core.notifications',
                ],
            ],

            'agenda' => array_map(fn (array $item): array => [
                'label' => $this->dueLabel($item['due_on']),
                'title' => $item['title'],
                'tone' => $item['tone'],
                'url' => $item['url'],
            ], $this->agenda()),

            'dueCards' => array_map(fn (array $card): array => [
                'title' => $card['title'],
                'board' => $card['board'],
                'due' => $this->dueLabel($card['due_on']),
                'late' => $card['due_state'] === 'overdue',
                'url' => $card['url'],
            ], $this->dueCards()),

            // Already shaped by recentActivity() — 'when' is a string there
            // on purpose; see that method's docblock.
            'recentActivity' => $this->recentActivity(),

            'quickActions' => [
                ['label' => 'New board', 'icon' => 'ki-abstract-26', 'route' => 'projects.boards'],
                ['label' => 'New campaign', 'icon' => 'ki-paper-plane', 'route' => 'mail.campaign-create'],
                ['label' => 'New invoice', 'icon' => 'ki-dollar', 'route' => 'accounting.invoice-create'],
                ['label' => 'Save a link', 'icon' => 'ki-arrow-up-right', 'route' => 'data.link-create'],
                ['label' => 'Add credential', 'icon' => 'ki-lock', 'route' => 'data.credential-create'],
                ['label' => 'Publish post', 'icon' => 'ki-share', 'route' => 'social.publish'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5 lg:gap-7.5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">
                {{ now()->hour < 12 ? 'Good morning' : (now()->hour < 18 ? 'Good afternoon' : 'Good evening') }}{{ auth()->user()?->name ? ', ' . str(auth()->user()->name)->before(' ') : '' }}
            </h1>
            <p class="text-sm text-secondary-foreground mt-1">Here is what needs you today.</p>
        </div>
        <select class="kt-select max-w-[170px]" wire:model.live="range" aria-label="Look-ahead window">
            @foreach ($ranges as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- Headline numbers. Lazy so a slow query never blocks first paint. --}}
    @island(name: 'stats', lazy: true)
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
        @placeholder
        @for ($i = 0; $i < 4; $i++)
            <div class="kt-card">
                <div class="kt-card-content flex items-start gap-4 p-5">
                    <span class="size-11 rounded-lg bg-muted animate-pulse shrink-0"></span>
                    <div class="min-w-0 grow flex flex-col gap-2">
                        <span class="block h-6 w-12 rounded bg-muted animate-pulse"></span>
                        <span class="block h-3 w-24 rounded bg-muted animate-pulse"></span>
                    </div>
                </div>
            </div>
        @endfor
        @endplaceholder
        @foreach ($stats as $stat)
            <a href="{{ route($stat['route']) }}" wire:navigate wire:key="stat-{{ $stat['label'] }}"
               class="kt-card hover:border-primary/40 transition-colors">
                <div class="kt-card-content flex items-start gap-4 p-5">
                    <span class="inline-flex items-center justify-center size-11 rounded-lg {{ $stat['bg'] }} {{ $stat['tone'] }} shrink-0">
                        <i class="ki-filled {{ $stat['icon'] }} text-xl"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="text-2xl font-semibold text-mono leading-none">{{ $stat['value'] }}</div>
                        <div class="text-sm font-medium text-secondary-foreground mt-1.5">{{ $stat['label'] }}</div>
                        <div class="text-xs text-muted-foreground truncate">{{ $stat['sub'] }}</div>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
    @endisland

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Left column --}}
        <div class="col-span-12 xl:col-span-8 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Revenue and expenses</h3>
                    <a href="{{ route('accounting.reports') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                        Reports <i class="ki-filled ki-black-right text-xs"></i>
                    </a>
                </div>
                <div class="kt-card-content p-5">
                    <div class="min-h-[200px] flex items-center justify-center">
                        <div class="flex flex-col items-center text-center">
                            <i class="ki-filled ki-chart-line-up text-4xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Revenue and expense trends live in Reports, not here yet.</p>
                        </div>
                    </div>
                </div>
            </div>

            @island(name: 'due-cards', lazy: true)
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Cards that need attention</h3>
                    <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                        Boards <i class="ki-filled ki-black-right text-xs"></i>
                    </a>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @placeholder
                    <div class="p-5 flex flex-col gap-3">
                        @for ($i = 0; $i < 3; $i++)
                            <span class="block h-4 w-full rounded bg-muted animate-pulse"></span>
                        @endfor
                    </div>
                    @endplaceholder
                    @forelse ($dueCards as $card)
                        <a href="{{ $card['url'] }}" wire:navigate wire:key="due-card-{{ $loop->index }}"
                           class="flex items-center gap-3 px-5 py-3.5 hover:bg-accent/30 transition-colors">
                            <span class="size-2 rounded-full shrink-0 {{ $card['late'] ? 'bg-destructive' : 'bg-primary' }}"></span>
                            <span class="min-w-0 grow">
                                <span class="block text-sm text-mono truncate">{{ $card['title'] }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $card['board'] }}</span>
                            </span>
                            <span class="text-xs shrink-0 {{ $card['late'] ? 'text-destructive font-medium' : 'text-muted-foreground' }}">
                                {{ $card['due'] }}
                            </span>
                        </a>
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-check-circle text-4xl text-success mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Nothing is due. Enjoy it.</p>
                        </div>
                    @endforelse
                </div>
            </div>
            @endisland

        </div>

        {{-- Right column --}}
        <div class="col-span-12 xl:col-span-4 flex flex-col gap-5">

            @island(name: 'agenda', lazy: true)
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Coming up</h3></div>
                <div class="kt-card-content p-5">
                    @placeholder
                    <div class="flex flex-col gap-3">
                        @for ($i = 0; $i < 3; $i++)
                            <span class="block h-4 w-full rounded bg-muted animate-pulse"></span>
                        @endfor
                    </div>
                    @endplaceholder
                    @forelse ($agenda as $item)
                        <div class="flex gap-3 pb-4 last:pb-0 relative" wire:key="agenda-{{ $loop->index }}">
                            <div class="flex flex-col items-center shrink-0">
                                <span class="size-2.5 rounded-full {{ $item['tone'] }} mt-1.5"></span>
                                @unless ($loop->last)
                                    <span class="w-px grow bg-border mt-1"></span>
                                @endunless
                            </div>
                            <div class="min-w-0 pb-1">
                                <div class="text-xs text-muted-foreground">{{ $item['label'] }}</div>
                                <a href="{{ $item['url'] }}" wire:navigate class="block text-sm text-mono mt-0.5 hover:text-primary truncate">
                                    {{ $item['title'] }}
                                </a>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-secondary-foreground text-center py-6">Nothing scheduled.</p>
                    @endforelse
                </div>
            </div>
            @endisland

            @island(name: 'activity', lazy: true)
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Recent activity</h3></div>
                <div class="kt-card-content p-5">
                    @placeholder
                    <div class="flex flex-col gap-3">
                        @for ($i = 0; $i < 4; $i++)
                            <span class="block h-4 w-full rounded bg-muted animate-pulse"></span>
                        @endfor
                    </div>
                    @endplaceholder
                    @forelse ($recentActivity as $entry)
                        <div class="flex gap-3 pb-3.5 last:pb-0" wire:key="activity-{{ $loop->index }}">
                            <span class="size-1.5 rounded-full bg-muted-foreground mt-2 shrink-0"></span>
                            <div class="min-w-0">
                                <p class="text-sm text-secondary-foreground leading-snug">
                                    <span class="text-mono font-medium">{{ $entry['actor'] }}</span>
                                    {{ $entry['description'] }}
                                </p>
                                <p class="text-xs text-muted-foreground mt-0.5">{{ $entry['when'] }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center py-8 text-center">
                            <i class="ki-filled ki-time text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Nothing has happened yet — it will show up here.</p>
                        </div>
                    @endforelse
                </div>
            </div>
            @endisland

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Quick actions</h3></div>
                <div class="kt-card-content grid grid-cols-2 gap-2 p-4">
                    @foreach ($quickActions as $action)
                        <a href="{{ route($action['route']) }}" wire:navigate
                           class="flex flex-col items-center justify-center gap-2 py-4 rounded-lg border border-border hover:border-primary/40 hover:bg-accent/30 transition-colors text-center">
                            <i class="ki-filled {{ $action['icon'] }} text-lg text-primary"></i>
                            <span class="text-xs text-mono leading-tight">{{ $action['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

        </div>
    </div>
</div>
