<?php

namespace Modules\Accounting\Database\Seeders;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\CryptoPayment;
use Modules\Accounting\Models\Currency;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * A plausible year-to-date for the customers `CoreDatabaseSeeder` creates.
 *
 * Idempotent, like every seeder here: it runs from the deploy script, and a
 * deploy that duplicates the invoice book is worse than a bad afternoon. Every
 * write is keyed on something a person would recognise and an accountant would
 * accept as the identity of the thing — an invoice's number, a line's wording
 * within its invoice, an expense's vendor and date, a rate's business key, a
 * transaction's hash. None of them is a surrogate id.
 *
 * Two deliberate departures:
 *
 * **Nothing here goes through `InvoiceIssuer`.** The service can only issue at
 * today's date — `issue()` refuses an invoice that already has an `issued_on`,
 * which is exactly right for the application and makes a back-dated fixture
 * impossible. The frozen figures are therefore computed here the same way the
 * service computes them, from the same `ExchangeRates` reader.
 *
 * **Nothing goes through `PaymentRecorder` either**, for the same reason in
 * reverse: it creates rather than matches, so a second run would take every
 * invoice twice.
 *
 * Dates are day offsets from today rather than fixed calendar dates. A fixed
 * date is 'overdue' by next month and the fixture stops demonstrating anything;
 * anchoring to `startOfDay()` keeps a second run writing the same values.
 */
class AccountingDatabaseSeeder extends Seeder
{
    /** How far back the rate history goes. */
    private const RATE_DAYS = 40;

    private ExchangeRates $rates;

    public function run(): void
    {
        $this->rates = new ExchangeRates;

        $user = User::query()->first();

        $this->seedCurrencies();
        $this->seedRates();
        $this->seedInvoices($user);
        $this->seedExpenses($user);
    }

    // ------------------------------------------------------------ currencies

    /**
     * The three codes this install deals in.
     *
     * USDT is the one `brick/money` has never heard of; its six decimals are
     * defined in `Currencies`, and this row exists so the interface has
     * something to list, not so the money layer can look it up.
     */
    private function seedCurrencies(): void
    {
        $currencies = [
            ['code' => Currencies::USD, 'name' => 'US Dollar', 'symbol' => '$', 'minor_unit' => 2, 'is_crypto' => false, 'position' => 0],
            ['code' => Currencies::TRY, 'name' => 'Turkish Lira', 'symbol' => '₺', 'minor_unit' => 2, 'is_crypto' => false, 'position' => 1],
            ['code' => Currencies::USDT, 'name' => 'Tether', 'symbol' => '₮', 'minor_unit' => 6, 'is_crypto' => true, 'position' => 2],
        ];

        foreach ($currencies as $attributes) {
            Currency::query()->updateOrCreate(
                ['code' => $attributes['code']],
                $attributes + ['is_active' => true],
            );
        }
    }

    // ----------------------------------------------------------------- rates

    /**
     * Forty days of history, in the three series the module actually reads.
     *
     * USD/TRY twice over, because the market rate and the central bank's buying
     * rate are different numbers and which one an invoice must use is a legal
     * question rather than a preference. USDT/USD is stored even though it sits
     * at one, because a stablecoin that has depegged is something the owner
     * wants to know before invoicing in it.
     *
     * The values are arithmetic rather than random: a seeder that writes a
     * different rate on every run cannot be run twice and compared.
     */
    private function seedRates(): void
    {
        for ($daysAgo = self::RATE_DAYS; $daysAgo >= 0; $daysAgo--) {
            $asOf = $this->dayAt($daysAgo)->toDateString();

            // The lira drifts one way over time. 40.28 forty days ago, 41.00
            // today — slow depreciation, which is what makes an invoice issued
            // in March worth arguing about in August.
            $market = BigDecimal::of('41.000000')
                ->minus(BigDecimal::of('0.018')->multipliedBy($daysAgo))
                ->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp);

            // The buying rate sits just under the market rate, as it does.
            $buying = $market
                ->multipliedBy('0.9985')
                ->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp);

            // Tether wobbles either side of the peg by a couple of basis points.
            $tether = BigDecimal::of('1.000000')
                ->plus(BigDecimal::of('0.00008')->multipliedBy(($daysAgo % 5) - 2))
                ->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp);

            $this->rates->record(Currencies::USD, Currencies::TRY, (string) $market, 'frankfurter', $asOf, ExchangeRates::MARKET);
            $this->rates->record(Currencies::USD, Currencies::TRY, (string) $buying, 'tcmb_evds', $asOf, ExchangeRates::TCMB_BUY);
            $this->rates->record(Currencies::USDT, Currencies::USD, (string) $tether, 'coingecko', $asOf, ExchangeRates::MARKET);
        }
    }

    // -------------------------------------------------------------- invoices

    private function seedInvoices(?User $user): void
    {
        foreach ($this->invoices() as $data) {
            $this->seedInvoice($data, $user);
        }
    }

    private function seedInvoice(array $data, ?User $user): void
    {
        $company = $data['company'] === null
            ? null
            : Company::query()->where('name', $data['company'])->first();

        $customer = $data['customer'] === null
            ? null
            : Customer::query()->where('email', $data['customer'])->first();

        $currency = $data['currency'];
        $taxPercent = $data['tax_percent'];

        $subtotal = Money::sum(
            array_map(
                fn (array $line): string => Money::toStorage(
                    Money::lineTotal($line['quantity'], $line['unit_price'], $currency),
                ),
                $data['lines'],
            ),
            $currency,
        );

        $tax = Money::percentageOf($subtotal, $taxPercent);
        $total = $subtotal->plus($tax, Money::ROUNDING);

        $issuedOn = $data['issued'] === null ? null : $this->dayAt($data['issued'])->toDateString();
        $dueOn = $issuedOn === null ? null : $this->dayAt($data['issued'] - 30)->toDateString();

        $attributes = [
            'company_id' => $company?->id,
            'customer_id' => $customer?->id,
            'status' => $data['status'],
            'currency' => $currency,
            'subtotal' => Money::toStorage($subtotal),
            'tax_percent' => $taxPercent,
            'tax_amount' => Money::toStorage($tax),
            'total' => Money::toStorage($total),
            'issued_on' => $issuedOn,
            'due_on' => $dueOn,
            'sent_at' => $issuedOn === null ? null : $this->dayAt($data['issued']),
            'paid_at' => $data['status'] === 'paid' ? $this->dayAt($data['paid_days_ago'] ?? 0) : null,
            'notes' => $data['notes'] ?? null,
            'terms' => 'Payment due within 30 days of the invoice date.',
            'created_by' => $user?->id,
        ];

        if ($issuedOn !== null) {
            $attributes += $this->frozenFigures($company, $currency, Money::toStorage($total), $issuedOn);
        }

        $invoice = Invoice::query()->updateOrCreate(['number' => $data['number']], $attributes);

        $this->seedLines($invoice, $data['lines']);

        if (isset($data['payment'])) {
            $this->seedPayment($invoice, $data['payment'], $user);
        }
    }

    /**
     * Everything an invoice freezes at the moment it is issued.
     *
     * This mirrors `InvoiceIssuer` rather than calling it — see the class
     * docblock. The rules it mirrors: the reporting figure is the owner's own
     * P&L currency and is a rate of exactly one when the invoice is already in
     * it; the lira equivalent exists only for a domestic Turkish buyer being
     * billed in something other than lira, and is taken at the TCMB *buying*
     * rate for the invoice date, because that is what Turkish tax procedure
     * specifies and the liability sits with the issuer.
     */
    private function frozenFigures(?Company $company, string $currency, string $total, string $issuedOn): array
    {
        $figures = ['reporting_currency' => Currencies::USD];

        if ($currency === Currencies::USD) {
            $figures['reporting_rate'] = '1.000000';
            $figures['reporting_amount'] = $total;
        } else {
            $rate = $this->rates->rateFor($currency, Currencies::USD, $issuedOn);

            $figures['reporting_rate'] = $rate;
            $figures['reporting_amount'] = $rate === null
                ? null
                : Money::toStorage(Money::convert(Money::fromStorage($total, $currency), $rate, Currencies::USD));
        }

        if ($company === null || ! $company->is_domestic || $currency === Currencies::TRY) {
            return $figures;
        }

        // USDT has no authoritative Turkish ruling, so USD is the intermediate
        // and the note says so out loud. An accountant can see exactly what was
        // done and override it.
        $bridge = '1.000000';
        $source = $currency;
        $note = null;

        if ($currency === Currencies::USDT) {
            $bridge = $this->rates->rateFor(Currencies::USDT, Currencies::USD, $issuedOn);

            if ($bridge === null) {
                return $figures;
            }

            $source = Currencies::USD;
            $note = 'Lira equivalent computed through USD: USDT/USD '.$bridge.' then the TCMB buying rate. '
                .'No Turkish ruling covers a stablecoin invoice directly — have this confirmed by a muhasebeci.';
        }

        $tcmb = $this->rates->on($source, Currencies::TRY, $issuedOn, ExchangeRates::TCMB_BUY);

        if ($tcmb === null) {
            return $figures;
        }

        $inSource = Money::convert(Money::fromStorage($total, $currency), $bridge, $source);

        return $figures + [
            'issue_rate_to_try' => (string) $tcmb->rate,
            'issue_rate_source' => $tcmb->source,
            'issue_rate_date' => $tcmb->as_of->toDateString(),
            'try_equivalent' => Money::toStorage(Money::convert($inSource, (string) $tcmb->rate, Currencies::TRY)),
            'rate_note' => $note,
        ];
    }

    /**
     * @param  list<array{description: string, quantity: string, unit_price: string}>  $lines
     */
    private function seedLines(Invoice $invoice, array $lines): void
    {
        foreach ($lines as $index => $line) {
            InvoiceLine::query()->updateOrCreate(
                ['invoice_id' => $invoice->id, 'description' => $line['description']],
                [
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => Money::toStorage(
                        Money::lineTotal($line['quantity'], $line['unit_price'], $invoice->currency),
                    ),
                    // The same fractional ordering the boards use, spaced so a
                    // line can be dropped between two others without renumbering.
                    'position' => (string) BigDecimal::of('1024')
                        ->multipliedBy($index + 1)
                        ->toScale(10, RoundingMode::Down),
                ],
            );
        }
    }

    private function seedPayment(Invoice $invoice, array $data, ?User $user): void
    {
        $paidAt = $this->dayAt($data['days_ago']);
        $rate = $data['settlement_rate'] ?? '1.000000';

        $applied = Money::convert(
            Money::of($data['amount'], $data['currency']),
            $rate,
            $invoice->currency,
        );

        $payment = Payment::query()->updateOrCreate(
            ['invoice_id' => $invoice->id, 'paid_at' => $paidAt],
            [
                'currency' => $data['currency'],
                'amount' => Money::toStorage(Money::of($data['amount'], $data['currency'])),
                'settlement_rate' => $rate,
                'applied_amount' => Money::toStorage($applied),
                // Paid in the invoice's own currency realises nothing, by
                // definition. Nothing seeded here settles across a rate move.
                'fx_gain_loss' => '0.000000',
                'method' => $data['method'],
                'note' => $data['note'] ?? null,
                'created_by' => $user?->id,
            ],
        );

        if (isset($data['chain'])) {
            $this->seedChainDetail($payment, $data);
        }

        $this->seedLedgerEntry(
            LedgerEntry::TYPE_INVOICE_PAYMENT,
            $invoice,
            $invoice->currency,
            Money::toStorage($applied),
            'Payment on '.$invoice->number,
            $paidAt,
            $user,
            $invoice->reporting_currency,
            $this->inReporting($invoice, $applied, $paidAt),
        );
    }

    /**
     * The on-chain half, keyed on the transaction hash.
     *
     * The hash is the natural key of a transfer everywhere else in the world,
     * so it is the natural key here too — and the column is unique, which makes
     * a second run of this seeder land on the same row rather than a conflict.
     */
    private function seedChainDetail(Payment $payment, array $data): void
    {
        CryptoPayment::query()->updateOrCreate(
            ['tx_hash' => $data['tx_hash']],
            [
                'payment_id' => $payment->id,
                'chain' => $data['chain'],
                'token_standard' => $data['chain'] === CryptoPayment::CHAIN_TRON ? 'TRC-20' : 'ERC-20',
                'from_address' => $data['from_address'],
                'to_address' => $data['to_address'],
                // What the chain says arrived, which is not assumed to equal
                // what the invoice asked for.
                'amount' => $data['chain_amount'],
                'network_fee' => $data['network_fee'],
                'block_number' => $data['block_number'],
                'confirmations' => $data['confirmations'],
                'status' => 'confirmed',
                'verified_at' => $this->dayAt($data['days_ago']),
            ],
        );
    }

    private function inReporting(Invoice $invoice, \Brick\Money\Money $applied, Carbon $paidAt): ?string
    {
        $reporting = $invoice->reporting_currency;

        if ($reporting === null) {
            return null;
        }

        if ($reporting === $invoice->currency) {
            return Money::toStorage($applied);
        }

        $rate = $this->rates->rateFor($invoice->currency, $reporting, $paidAt);

        return $rate === null ? null : Money::toStorage(Money::convert($applied, $rate, $reporting));
    }

    // -------------------------------------------------------------- expenses

    private function seedExpenses(?User $user): void
    {
        $rebill = Invoice::query()->where('number', 'INV-0039')->first();

        foreach ($this->expenses() as $data) {
            $spentOn = $this->dayAt($data['days_ago']);
            $amount = Money::of($data['amount'], Currencies::USD);

            // The Carbon, not `->toDateString()`. Eloquent writes a `date` cast
            // through the connection's own format, so the column holds
            // '2026-07-28 00:00:00'; a bare 'Y-m-d' in the key would match
            // nothing and the second run would seed every expense again.
            $expense = Expense::query()->updateOrCreate(
                ['vendor' => $data['vendor'], 'spent_on' => $spentOn],
                [
                    'company_id' => null,
                    'category' => $data['category'],
                    'description' => $data['description'] ?? null,
                    'currency' => Currencies::USD,
                    'amount' => Money::toStorage($amount),
                    'reporting_currency' => Currencies::USD,
                    'reporting_rate' => '1.000000',
                    'reporting_amount' => Money::toStorage($amount),
                    'is_billable' => $data['billable'] ?? false,
                    'rebilled_on_invoice_id' => ($data['rebilled'] ?? false) ? $rebill?->id : null,
                    'receipt_reference' => $data['receipt'] ?? null,
                    'created_by' => $user?->id,
                ],
            );

            // Money out is a negative entry, so a balance is a plain sum rather
            // than a sum with a sign rule bolted onto it.
            $this->seedLedgerEntry(
                LedgerEntry::TYPE_EXPENSE,
                $expense,
                Currencies::USD,
                (string) BigDecimal::of(Money::toStorage($amount))->negated(),
                $data['vendor'].' — '.$data['category'],
                $spentOn,
                $user,
                Currencies::USD,
                (string) BigDecimal::of(Money::toStorage($amount))->negated(),
            );
        }
    }

    // ---------------------------------------------------------------- ledger

    /**
     * `firstOrCreate`, never `updateOrCreate`.
     *
     * The ledger is append only and the model refuses an update outright, so
     * the only idempotent write available is one that matches or inserts. The
     * key is what makes an entry that entry: what it records, what it records
     * it against, and when it happened.
     */
    private function seedLedgerEntry(
        string $type,
        Model $reference,
        string $currency,
        string $amount,
        string $description,
        Carbon $occurredAt,
        ?User $user,
        ?string $reportingCurrency = null,
        ?string $reportingAmount = null,
    ): void {
        LedgerEntry::query()->firstOrCreate(
            [
                'entry_type' => $type,
                'reference_type' => $reference->getMorphClass(),
                'reference_id' => $reference->getKey(),
                'occurred_at' => $occurredAt->toDateTimeString(),
            ],
            [
                'currency' => $currency,
                'amount' => $amount,
                'reporting_currency' => $reportingCurrency,
                'reporting_amount' => $reportingAmount,
                'description' => $description,
                'created_by' => $user?->id,
            ],
        );
    }

    // ------------------------------------------------------------------ data

    /** Midnight, `$daysAgo` days back. Midnight so a second run matches exactly. */
    private function dayAt(int $daysAgo): Carbon
    {
        return now()->startOfDay()->subDays($daysAgo);
    }

    /**
     * The invoice book.
     *
     * Amounts are decimal strings throughout. `1500.00` written as a PHP
     * literal is a float the moment the parser sees it, and the money layer
     * refuses floats at the door — which is the behaviour that stops a fixture
     * quietly seeding a wrong number.
     *
     * The four USD invoices reproduce what `⚡invoices.blade.php` has been
     * drawing from its fixture, so the page reads the database and looks no
     * different. The last three are what the fixture never covered: a lira
     * invoice with KDV on it, a stablecoin invoice settled on chain, and a
     * draft that has not been issued and therefore has no frozen rate at all.
     */
    private function invoices(): array
    {
        return [
            [
                'number' => 'INV-0038',
                'company' => 'Northwind Ltd',
                'customer' => 'helen@northwind.example',
                'currency' => Currencies::USD,
                'tax_percent' => '0',
                'status' => 'paid',
                'issued' => 63,
                'paid_days_ago' => 38,
                'notes' => 'Retainer, June. Procurement pay on the 15th and the last working day only.',
                'lines' => [
                    ['description' => 'Development retainer — four days', 'quantity' => '4', 'unit_price' => '300.00'],
                ],
                'payment' => [
                    'days_ago' => 38, 'currency' => Currencies::USD, 'amount' => '1200.00',
                    'method' => 'bank', 'note' => 'Received by transfer, reference NW-0621.',
                ],
            ],
            [
                'number' => 'INV-0039',
                'company' => 'Bluepeak',
                'customer' => 'priya@bluepeak.example',
                'currency' => Currencies::USD,
                'tax_percent' => '0',
                'status' => 'paid',
                'issued' => 46,
                'paid_days_ago' => 21,
                'notes' => 'Booking widget, first phase. Hosting for the staging box is rebilled on this invoice.',
                'lines' => [
                    ['description' => 'Booking widget — build', 'quantity' => '1', 'unit_price' => '4500.00'],
                    ['description' => 'Staging environment and hand-over', 'quantity' => '1', 'unit_price' => '650.00'],
                ],
                'payment' => [
                    'days_ago' => 21, 'currency' => Currencies::USD, 'amount' => '5150.00',
                    'method' => 'wise', 'note' => 'Paid in full by Wise.',
                ],
            ],
            [
                'number' => 'INV-0040',
                'company' => 'Acme Studio',
                'customer' => 'joris@acmestudio.example',
                'currency' => Currencies::USD,
                'tax_percent' => '0',
                'status' => 'overdue',
                'issued' => 32,
                'notes' => 'Mail module, milestone one. Chased twice.',
                'lines' => [
                    ['description' => 'Mail module: inbox and reading pane', 'quantity' => '1', 'unit_price' => '980.00'],
                ],
            ],
            [
                'number' => 'INV-0041',
                'company' => 'Northwind Ltd',
                'customer' => 'sam@northwind.example',
                'currency' => Currencies::USD,
                'tax_percent' => '0',
                'status' => 'sent',
                'issued' => 14,
                'notes' => 'Analytics dashboard scoping, eight days at the 2026 rate.',
                'lines' => [
                    ['description' => 'Analytics dashboard scoping', 'quantity' => '8', 'unit_price' => '300.00'],
                ],
            ],
            [
                'number' => 'INV-0042',
                'company' => 'Harbour & Finch',
                'customer' => 'deniz@harbourfinch.example',
                'currency' => Currencies::TRY,
                // KDV at twenty per cent. A lira invoice to a domestic buyer
                // carries it; an export invoice does not, which is why the
                // other five are at zero.
                'tax_percent' => '20',
                'status' => 'sent',
                'issued' => 12,
                'notes' => 'Yerel fatura. KDV %20 dahil.',
                'lines' => [
                    ['description' => 'Danışmanlık — sistem entegrasyonu', 'quantity' => '3', 'unit_price' => '18000.00'],
                ],
            ],
            [
                'number' => 'INV-0043',
                'company' => 'Harbour & Finch',
                'customer' => 'deniz@harbourfinch.example',
                'currency' => Currencies::USDT,
                'tax_percent' => '0',
                'status' => 'paid',
                'issued' => 21,
                'paid_days_ago' => 18,
                'notes' => 'Settled on Tron. Their accountant checks the rate date, so the lira equivalent and its source are on the invoice.',
                'lines' => [
                    ['description' => 'Migration off shared hosting', 'quantity' => '1', 'unit_price' => '2400.00'],
                    ['description' => 'Certificate renewal and DNS cutover', 'quantity' => '1', 'unit_price' => '350.00'],
                ],
                'payment' => [
                    'days_ago' => 18, 'currency' => Currencies::USDT, 'amount' => '2750.00',
                    'method' => 'crypto', 'note' => 'TRC-20 transfer, verified on chain.',
                    'chain' => CryptoPayment::CHAIN_TRON,
                    'tx_hash' => 'b31c0a7e4f5d2c8916a04be7d3f1c25a8e69b0d47c3a15fe82d90bc47a6e1f38',
                    'from_address' => 'TQ5NMqJjW8hVv1c7bYh4nD3gLpR2sXeK9u',
                    'to_address' => 'TJmVb7Ld4pQ9xN2wR6sYc1HkA8fE5uZ3gT',
                    // A shade under the invoice: the wallet rounded down. The
                    // delta is a business decision, not something to paper over.
                    'chain_amount' => '2749.981200',
                    'network_fee' => '13.400000',
                    'block_number' => 66_142_907,
                    'confirmations' => 4_213,
                ],
            ],
            [
                'number' => 'INV-0044',
                'company' => null,
                'customer' => 'marta@orbitstudio.example',
                'currency' => Currencies::USD,
                'tax_percent' => '0',
                // Never issued, so no frozen rate, no reporting amount and no
                // due date. Everything about a draft is still editable.
                'status' => 'draft',
                'issued' => null,
                'notes' => 'Draft. Marta has no company yet, so this goes to her personally.',
                'lines' => [
                    ['description' => 'Hand-over documentation', 'quantity' => '2', 'unit_price' => '300.00'],
                ],
            ],
        ];
    }

    /**
     * What the business costs to run.
     *
     * The first four reproduce `⚡expenses.blade.php` row for row. The rest are
     * what the fixture had no way to show: a cost the client agreed to cover
     * and that has landed on an invoice, one that is billable and still has
     * not, and one large enough to matter to a quarter.
     */
    private function expenses(): array
    {
        return [
            ['vendor' => 'Hostinger', 'category' => 'Hosting', 'amount' => '71.88', 'days_ago' => 6, 'receipt' => 'HG-2026-07-981'],
            ['vendor' => 'KeenThemes', 'category' => 'Software', 'amount' => '49.00', 'days_ago' => 9, 'receipt' => 'KT-114420'],
            ['vendor' => 'Amazon SES', 'category' => 'Email', 'amount' => '12.40', 'days_ago' => 20, 'receipt' => 'AWS-0726'],
            ['vendor' => 'Namecheap', 'category' => 'Domains', 'amount' => '28.00', 'days_ago' => 32, 'receipt' => 'NC-88213'],
            [
                'vendor' => 'DigitalOcean', 'category' => 'Hosting', 'amount' => '120.00', 'days_ago' => 48,
                'description' => 'Staging box for the Bluepeak booking widget.',
                'billable' => true, 'rebilled' => true, 'receipt' => 'DO-2026-06-402',
            ],
            [
                'vendor' => 'Figma', 'category' => 'Software', 'amount' => '45.00', 'days_ago' => 15,
                'description' => 'Seat for the Acme Studio hand-over files. Agreed as recoverable, not yet invoiced.',
                'billable' => true, 'receipt' => 'FG-70233',
            ],
            ['vendor' => 'Apple Store', 'category' => 'Hardware', 'amount' => '1299.00', 'days_ago' => 71, 'receipt' => 'AP-4471209'],
            ['vendor' => 'Accountant', 'category' => 'Other', 'amount' => '250.00', 'days_ago' => 27, 'receipt' => 'MB-2026-Q2'],
        ];
    }
}
