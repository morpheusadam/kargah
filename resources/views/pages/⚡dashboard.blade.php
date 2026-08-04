<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Contracts\ExpenseReader as ExpenseReaderContract;
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
 *
 * **This is the first page in Kargah to load ApexCharts, and it is loaded
 * here rather than in the layout.** `layouts/app.blade.php` says why: the
 * bundle is 563 KB, and having it and FullCalendar in the layout once added
 * 854 KB to every page and made the single-threaded dev server queue requests
 * until they timed out. It stays out of the layout; this page pulls it from
 * inside `@script`, exactly as `Modules\Social`'s calendar pulls FullCalendar.
 *
 * `⚡client-show.blade.php` chose twelve plain divs over this same library and
 * that decision was right and stands. The difference is not taste, it is what
 * the mark has to say: a sparkline says "bigger than last month" and a bar
 * per month says that perfectly, whereas these two want a shared y axis
 * across twelve months and two series, a tooltip carrying a formatted lira
 * figure per point, and a proportional split across clients — none of which a
 * div can do without becoming a chart library written badly. Where a div
 * still suffices, keep the div.
 *
 * **Nothing here is drawn without also being written down.** Every chart has
 * a server-rendered table of the same figures beneath it, hidden by the
 * script only once the chart has actually rendered, so a page served without
 * the bundle shows the numbers rather than an empty box.
 *
 * **The charts follow the same money rule as the tiles.** The series are
 * summed inside Accounting — `InvoiceReader::revenueByMonth()`,
 * `revenueByClient()`, `agedReceivables()` and `ExpenseReader
 * ::expensesByMonth()` — and arrive already added and already formatted.
 * Nothing on this page sums money, and the JavaScript below does no
 * arithmetic on it: it plots the amounts and prints the server's own
 * formatted strings. Documents that carry no lira figure are **excluded and
 * counted**, and the count is printed under the chart, because a series that
 * silently drops four invoices reads as a bad quarter.
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

    public const CACHE_TREND = 'dashboard.trend.v1';

    public const CACHE_REVENUE_BY_CLIENT = 'dashboard.revenue-by-client.v1';

    public const CACHE_RECEIVABLES = 'dashboard.receivables.v1';

    private const RANGE_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * How each aging bucket reads. Whole class strings in a map, never built
     * by concatenation — the Tailwind scanner reads source text, so
     * `"text-{$tone}"` produces a class that exists nowhere in the stylesheet.
     *
     * @var array<string, array{tone: string, dot: string}>
     */
    private const RECEIVABLE_TONES = [
        'not_due' => ['tone' => 'text-mono', 'dot' => 'bg-primary'],
        '1_30' => ['tone' => 'text-warning', 'dot' => 'bg-warning'],
        '31_60' => ['tone' => 'text-warning', 'dot' => 'bg-warning'],
        'over_60' => ['tone' => 'text-destructive', 'dot' => 'bg-destructive'],
    ];

    /** How many months the trend covers, and how many clients get their own slice. */
    private const TREND_MONTHS = 12;

    private const CLIENT_SLICES = 6;

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

    /**
     * Revenue against expenses, twelve months, in lira.
     *
     * Two readers, joined on the `YYYY-MM` key both of them emit — which is
     * why `ExpenseReader::expensesByMonth()` calls `InvoiceReader
     * ::monthWindow()` rather than building its own window. Joining is not
     * arithmetic on money: every amount here is a string this page received
     * already summed, and a month with no matching entry renders as an em
     * dash rather than a zero this page invented.
     *
     * @return array{rows: list<array<string, string>>, series: array<string, mixed>, note: ?string}
     */
    private function trend(): array
    {
        return Cache::flexible(self::CACHE_TREND, [15, 180], function (): array {
            $revenue = app(InvoiceReaderContract::class)->revenueByMonth(self::TREND_MONTHS);
            $expenses = app(ExpenseReaderContract::class)->expensesByMonth(self::TREND_MONTHS);

            $spentByMonth = [];

            foreach ($expenses['months'] as $month) {
                $spentByMonth[$month['month']] = $month;
            }

            $rows = array_map(function (array $month) use ($spentByMonth): array {
                $spent = $spentByMonth[$month['month']] ?? null;

                return [
                    'label' => $month['label'],
                    'revenue' => $month['amount'],
                    'revenue_formatted' => $month['formatted'],
                    'expenses' => $spent === null ? '0' : $spent['amount'],
                    'expenses_formatted' => $spent === null ? '—' : $spent['formatted'],
                ];
            }, $revenue['months']);

            return [
                'rows' => $rows,
                'series' => [
                    'symbol' => $revenue['symbol'],
                    'labels' => array_column($rows, 'label'),
                    'revenue' => array_column($rows, 'revenue'),
                    'expenses' => array_column($rows, 'expenses'),
                    'revenueFormatted' => array_column($rows, 'revenue_formatted'),
                    'expensesFormatted' => array_column($rows, 'expenses_formatted'),
                ],
                'note' => $this->excludedNote([
                    'invoice' => $revenue['excluded'],
                    'expense' => $expenses['excluded'],
                ]),
            ];
        });
    }

    /**
     * Who the practice actually rests on, twelve months, in lira.
     *
     * @return array{rows: list<array<string, string>>, series: array<string, mixed>, note: ?string}
     */
    private function revenueByClient(): array
    {
        return Cache::flexible(self::CACHE_REVENUE_BY_CLIENT, [15, 180], function (): array {
            $revenue = app(InvoiceReaderContract::class)->revenueByClient(self::TREND_MONTHS, self::CLIENT_SLICES);

            $rows = array_values(array_filter(
                $revenue['clients'],
                // A client at zero is a client with nothing to show on a
                // donut, and a zero slice draws as a label with no wedge.
                fn (array $client): bool => $client['amount'] !== '0.000000',
            ));

            return [
                'rows' => $rows,
                'series' => [
                    'labels' => array_column($rows, 'name'),
                    'values' => array_column($rows, 'amount'),
                    'formatted' => array_column($rows, 'formatted'),
                ],
                'note' => $this->excludedNote(['invoice' => $revenue['excluded']]),
            ];
        });
    }

    /**
     * What is owed, split by how late it is.
     *
     * Per currency inside each bucket, never added across them — see
     * `InvoiceReader::agedReceivables()`. `moneyLine()` is the same joiner the
     * unpaid-invoices tile already uses, so "$980.00 · ₺64,800.00" reads the
     * same way in both places.
     *
     * @return array{buckets: list<array<string, string|int>>, count: int}
     */
    private function receivables(): array
    {
        return Cache::flexible(self::CACHE_RECEIVABLES, [15, 180], function (): array {
            $aged = app(InvoiceReaderContract::class)->agedReceivables();

            return [
                'buckets' => array_map(fn (array $bucket): array => [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'money' => $this->moneyLine($bucket['totals']) ?: '—',
                    'count' => $bucket['count'],
                    'sub' => $bucket['count'].' '.Str::plural('invoice', $bucket['count']),
                    'tone' => self::RECEIVABLE_TONES[$bucket['key']]['tone'],
                    'dot' => self::RECEIVABLE_TONES[$bucket['key']]['dot'],
                ], $aged['buckets']),
                'count' => $aged['count'],
            ];
        });
    }

    /**
     * "4 invoices and 7 expenses are not on this chart…" — the sentence
     * `ACCOUNTING-BRIEF.md` insists a chart must be able to say. A row that
     * never got a rate is counted out loud rather than converted at today's
     * rate, which would make last March move every time the lira does.
     *
     * @param  array<string, int>  $counts  noun => how many
     */
    private function excludedNote(array $counts): ?string
    {
        $parts = [];
        $total = 0;

        foreach ($counts as $noun => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.Str::plural($noun, $count);
                $total += $count;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' and ', $parts).' '.($total === 1 ? 'is' : 'are').' not on this chart. '
            .'Nothing on those documents says what they were worth in lira, and converting them at '
            .'today’s rate would invent a figure nobody could defend.';
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

            // Already summed and already formatted inside Accounting. This
            // page joins and labels; it never adds.
            'receivables' => $this->receivables(),
            'trend' => $this->trend(),
            'clientRevenue' => $this->revenueByClient(),

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

    {{--
        What is owed and how late it is. Not an island: the figures come from
        one reader call the page already caches, and a person deciding whether
        to chase an invoice should not watch it fade in.
    --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Outstanding receivables</h3>
            <a href="{{ route('accounting.invoices') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                Invoices <i class="ki-filled ki-black-right text-xs"></i>
            </a>
        </div>
        <div class="kt-card-content grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 p-5">
            @foreach ($receivables['buckets'] as $bucket)
                <div class="rounded-lg border border-border p-4" wire:key="receivable-{{ $bucket['key'] }}">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full shrink-0 {{ $bucket['dot'] }}"></span>
                        <span class="text-xs text-muted-foreground">{{ $bucket['label'] }}</span>
                    </div>
                    <div class="text-lg font-semibold mt-2 {{ $bucket['tone'] }}">{{ $bucket['money'] }}</div>
                    <div class="text-xs text-muted-foreground mt-0.5">{{ $bucket['sub'] }}</div>
                </div>
            @endforeach
        </div>
        <div class="kt-card-footer">
            <span class="text-xs text-muted-foreground">
                One figure per currency, never added across them — a client billed in lira and in dollars owes two amounts.
            </span>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Left column --}}
        <div class="col-span-12 xl:col-span-8 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <div>
                        <h3 class="kt-card-title">Revenue and expenses</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">Twelve months, in lira, at the rate each document froze.</p>
                    </div>
                    <a href="{{ route('accounting.reports') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                        Reports <i class="ki-filled ki-black-right text-xs"></i>
                    </a>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <div data-trend-chart
                         class="min-h-[280px]"
                         data-series="{{ json_encode($trend['series']) }}"></div>

                    {{--
                        The same twelve figures as a table. Shown until the chart
                        genuinely draws, and for good if the bundle never arrives —
                        a page without JavaScript shows the numbers, not a hole.
                    --}}
                    <div data-trend-fallback class="kt-scrollable-x-auto">
                        <table class="kt-table">
                            <thead>
                                <tr>
                                    <th class="text-start">Month</th>
                                    <th class="text-end">Invoiced</th>
                                    <th class="text-end">Spent</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($trend['rows'] as $row)
                                    <tr wire:key="trend-{{ $loop->index }}">
                                        <td class="text-sm text-secondary-foreground">{{ $row['label'] }}</td>
                                        <td class="text-sm text-mono text-end">{{ $row['revenue_formatted'] }}</td>
                                        <td class="text-sm text-mono text-end">{{ $row['expenses_formatted'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($trend['note'])
                        <p class="text-xs text-muted-foreground">{{ $trend['note'] }}</p>
                    @endif
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <div>
                        <h3 class="kt-card-title">Revenue by client</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">Twelve months, in lira — how much of the practice rests on one client.</p>
                    </div>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    @if (count($clientRevenue['rows']) > 0)
                        <div data-client-chart
                             class="min-h-[280px]"
                             data-series="{{ json_encode($clientRevenue['series']) }}"></div>

                        <div data-client-fallback class="kt-scrollable-x-auto">
                            <table class="kt-table">
                                <thead>
                                    <tr>
                                        <th class="text-start">Client</th>
                                        <th class="text-end">Invoiced</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($clientRevenue['rows'] as $row)
                                        <tr wire:key="client-revenue-{{ $loop->index }}">
                                            <td class="text-sm text-secondary-foreground">{{ $row['name'] }}</td>
                                            <td class="text-sm text-mono text-end">{{ $row['formatted'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="flex flex-col items-center py-10 text-center">
                            <i class="ki-filled ki-chart-pie-simple text-4xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">No invoice in the last twelve months carries a lira figure yet.</p>
                        </div>
                    @endif

                    @if ($clientRevenue['note'])
                        <p class="text-xs text-muted-foreground">{{ $clientRevenue['note'] }}</p>
                    @endif
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
{{--
    Kept inside the component's root element on purpose. Livewire renders one
    root node and discards everything after it, and it carries neither a pushed
    stack nor @assets through to the layout — a @push('scripts') here would be
    dropped silently, with no error and no script.

    ApexCharts is fetched here rather than from the layout because it is 563 KB
    and only two pages will ever want it. See the class docblock.
--}}
@script
<script src="/assets/vendors/apexcharts/apexcharts.min.js"></script>
<script>
(function () {
    // 🔴 Series colours are hex literals rather than the theme's own CSS
    // variables, and that is not laziness. `--color-primary` and friends
    // compute to `oklch(…)` in this theme, and ApexCharts' `hexToRgba()` —
    // which every solid fill is passed through — replaces any colour string
    // not starting with `#` by the grey `#999999`. Both series would come out
    // the same grey, on a chart that still rendered, so nothing would look
    // broken. Verified by reading the shipped bundle, not assumed.
    //
    // Everything ApexCharts picks for itself — label colour, tooltip skin — is
    // steered by `theme.mode` below instead, which is what keeps the chart
    // legible in both themes without a second colour table here.
    var REVENUE = '#1b84ff';
    var EXPENSE = '#f8285a';
    var SLICES = ['#1b84ff', '#17c653', '#f6b100', '#7239ea', '#f8285a', '#26a8d3', '#78829d'];

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function grid() {
        return isDark() ? '#26272f' : '#f1f1f4';
    }

    function read(el) {
        try {
            return JSON.parse(el.dataset.series || 'null');
        } catch (e) {
            return null;
        }
    }

    // The mount key, not a data-* flag. Livewire's morph strips any attribute
    // the incoming HTML does not carry, so a flag would clear itself on every
    // render and leave a second chart bound to the same node; a property on
    // the element is invisible to the morph. Comparing the payload as well
    // means an unrelated re-render does not tear a drawn chart down and
    // rebuild it, and a theme flip does.
    function unchanged(el, payload) {
        return el._chart && el._chartKey === payload + '|' + (isDark() ? 'dark' : 'light');
    }

    function drawn(el, chart, payload, fallback) {
        el._chart = chart;
        el._chartKey = payload + '|' + (isDark() ? 'dark' : 'light');

        hide(fallback);
    }

    // Hidden only once the chart has genuinely drawn — and re-hidden on every
    // pass, because the morph strips the class again each time: the class was
    // put there by JavaScript, so the incoming HTML never carries it.
    function hide(fallback) {
        if (fallback) fallback.classList.add('hidden');
    }

    function destroy(el) {
        if (el._chart) {
            el._chart.destroy();
            el._chart = null;
        }
    }

    function trend(root) {
        var el = root.querySelector('[data-trend-chart]');
        if (! el) return;

        var payload = el.dataset.series;
        var fallback = root.querySelector('[data-trend-fallback]');

        if (unchanged(el, payload)) {
            hide(fallback);

            return;
        }

        var data = read(el);
        if (! data) return;

        destroy(el);

        // The one place a money figure becomes a double is here, where it
        // becomes a pixel height — never to be added, compared or rounded.
        // Every figure a person actually reads on this chart is the string the
        // server formatted through brick/money: the tooltips below index into
        // it, and the table underneath prints it.
        var revenue = data.revenue.map(Number);
        var expenses = data.expenses.map(Number);
        var symbol = data.symbol;

        var chart = new ApexCharts(el, {
            chart: {
                type: 'line',
                height: 280,
                background: 'transparent',
                toolbar: { show: false },
                animations: { enabled: false },
                fontFamily: 'inherit'
            },
            theme: { mode: isDark() ? 'dark' : 'light' },
            colors: [REVENUE, EXPENSE],
            series: [
                { name: 'Invoiced', data: revenue },
                { name: 'Spent', data: expenses }
            ],
            stroke: { curve: 'straight', width: 2 },
            markers: { size: 3, strokeWidth: 0 },
            dataLabels: { enabled: false },
            legend: { position: 'top', horizontalAlign: 'left' },
            xaxis: { categories: data.labels, axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: {
                labels: {
                    // An axis tick is a position ApexCharts chose in order to
                    // draw a scale, not a figure out of the book. Grouping it
                    // for legibility is presentation; no figure the person is
                    // asked to trust is produced here.
                    formatter: function (value) {
                        return symbol + Math.round(value).toLocaleString('en');
                    }
                }
            },
            grid: { borderColor: grid(), strokeDashArray: 4 },
            tooltip: {
                y: {
                    formatter: function (value, opts) {
                        var formatted = opts.seriesIndex === 0 ? data.revenueFormatted : data.expensesFormatted;

                        return formatted[opts.dataPointIndex];
                    }
                }
            }
        });

        chart.render();
        drawn(el, chart, payload, fallback);
    }

    function clients(root) {
        var el = root.querySelector('[data-client-chart]');
        if (! el) return;

        var payload = el.dataset.series;
        var fallback = root.querySelector('[data-client-fallback]');

        if (unchanged(el, payload)) {
            hide(fallback);

            return;
        }

        var data = read(el);
        if (! data || ! data.values.length) return;

        destroy(el);

        var chart = new ApexCharts(el, {
            chart: { type: 'donut', height: 280, background: 'transparent', fontFamily: 'inherit' },
            theme: { mode: isDark() ? 'dark' : 'light' },
            colors: SLICES,
            series: data.values.map(Number),
            labels: data.labels,
            // A share of the whole is a percentage, which is not money — the
            // same reasoning ⚡client-show gives for its twelve div heights.
            dataLabels: { enabled: true, formatter: function (percent) { return Math.round(percent) + '%'; } },
            legend: { position: 'bottom' },
            stroke: { width: 0 },
            tooltip: {
                y: {
                    formatter: function (value, opts) {
                        return data.formatted[opts.seriesIndex];
                    }
                }
            }
        });

        chart.render();
        drawn(el, chart, payload, fallback);
    }

    function mount() {
        // A closure left behind by a wire:navigate must not touch the page
        // that replaced it.
        if (! $wire.$el || ! $wire.$el.isConnected) return;

        // No bundle, no chart — leave the tables in place rather than an empty
        // box. This is the whole of the progressive-enhancement promise.
        if (typeof ApexCharts === 'undefined') return;

        trend($wire.$el);
        clients($wire.$el);
    }

    // Once per component, not once per DOM node touched.
    Livewire.hook('morphed', mount);

    // The theme toggle flips a class on <html> and dispatches nothing, so a
    // chart drawn in the dark would keep dark axis labels on a white card
    // until the next render. Watching the class is the only signal there is.
    var watcher = new MutationObserver(function () {
        if (! $wire.$el || ! $wire.$el.isConnected) {
            watcher.disconnect();

            return;
        }

        mount();
    });

    watcher.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    mount();
})();
</script>
@endscript
</div>
