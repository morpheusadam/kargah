<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Contracts\InvoiceReader as InvoiceReaderContract;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Tests\TestCase;

/**
 * `InvoiceReader::totals()` — the aggregate `InvoiceReader` could not
 * previously do at all.
 *
 * Every assertion here traces to a decimal string, never a float and never a
 * `SUM()` in SQL — see `InvoiceReader::totals()`'s own implementation, which
 * loads the outstanding book and its payments in two bounded queries and does
 * every addition through `brick/money`.
 */
class InvoiceTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): InvoiceReaderContract
    {
        return app(InvoiceReaderContract::class);
    }

    /** @return array{amount: string, currency: string, formatted: string} */
    private function forCurrency(array $entries, string $currency): array
    {
        return collect($entries)->firstWhere('currency', $currency);
    }

    public function test_a_total_across_several_invoices_in_one_currency_is_exact_to_six_decimal_places(): void
    {
        Invoice::factory()->sent()->create(['currency' => 'USD', 'total' => '10.100001', 'subtotal' => '10.100001']);
        Invoice::factory()->sent()->create(['currency' => 'USD', 'total' => '20.200002', 'subtotal' => '20.200002']);
        Invoice::factory()->sent()->create(['currency' => 'USD', 'total' => '5.300003', 'subtotal' => '5.300003']);

        $usd = $this->forCurrency($this->reader()->totals()['outstanding'], 'USD');

        // 10.100001 + 20.200002 + 5.300003, done by brick/money — not a
        // float sum, which would already have lost the sixth decimal.
        $this->assertSame('35.600006', $usd['amount']);
        $this->assertSame(Money::format('35.600006', 'USD'), $usd['formatted']);
    }

    /**
     * The decision this task exists to make, stated by the test: three
     * currencies produce three figures, never one combined number. Adding
     * 500 USD to 12,345.67 TRY to 75.123456 USDT is not a quantity — it is
     * the SQL-arithmetic mistake the money rule forbids, moved one layer up.
     */
    public function test_a_total_across_mixed_currencies_is_reported_per_currency_never_combined_into_one_figure(): void
    {
        Invoice::factory()->sent()->create(['currency' => 'USD', 'total' => '500.000000', 'subtotal' => '500.000000']);
        Invoice::factory()->sent()->create(['currency' => 'TRY', 'total' => '12345.670000', 'subtotal' => '12345.670000']);
        Invoice::factory()->sent()->create(['currency' => 'USDT', 'total' => '75.123456', 'subtotal' => '75.123456']);

        $totals = $this->reader()->totals()['outstanding'];

        // Exactly one entry per currency Kargah knows about — the decision is
        // "per currency", so the shape has to carry all three, not a list
        // that only happens to hold the ones seen this call.
        $this->assertCount(3, $totals);
        $this->assertSame(Currencies::supported(), array_column($totals, 'currency'));

        $this->assertSame('500.000000', $this->forCurrency($totals, 'USD')['amount']);
        $this->assertSame('12345.670000', $this->forCurrency($totals, 'TRY')['amount']);
        $this->assertSame('75.123456', $this->forCurrency($totals, 'USDT')['amount']);

        // Each entry stays in its own currency's own formatting — proof that
        // no conversion or combination happened on the way out.
        $this->assertSame(Money::format('500.000000', 'USD'), $this->forCurrency($totals, 'USD')['formatted']);
        $this->assertSame(Money::format('12345.670000', 'TRY'), $this->forCurrency($totals, 'TRY')['formatted']);
        $this->assertSame(Money::format('75.123456', 'USDT'), $this->forCurrency($totals, 'USDT')['formatted']);
    }

    /**
     * `invoices.status` can hold `overdue` as a value distinct from `sent`
     * and `part_paid`. A previous agent's version of this dashboard undercounted
     * because of exactly this — see DECISIONS.md.
     */
    public function test_an_invoice_with_the_stored_status_overdue_is_included_in_outstanding(): void
    {
        Invoice::factory()->overdue()->create(['currency' => 'USD', 'total' => '900.000000', 'subtotal' => '900.000000']);

        $usd = $this->forCurrency($this->reader()->totals()['outstanding'], 'USD');
        $usdOverdue = $this->forCurrency($this->reader()->totals()['overdue'], 'USD');

        $this->assertSame('900.000000', $usd['amount']);
        $this->assertSame('900.000000', $usdOverdue['amount']);
    }

    /** A part-paid invoice contributes what remains, never its face value. */
    public function test_a_part_paid_invoice_contributes_its_remaining_balance_not_its_face_value(): void
    {
        $invoice = Invoice::factory()->sent()->create([
            'currency' => 'USD', 'total' => '1000.000000', 'subtotal' => '1000.000000',
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'currency' => 'USD',
            'amount' => '400.000000',
            'applied_amount' => '400.000000',
        ]);

        $invoice->forceFill(['status' => 'part_paid'])->save();

        $usd = $this->forCurrency($this->reader()->totals()['outstanding'], 'USD');

        $this->assertSame('600.000000', $usd['amount']);
    }

    /**
     * An overpayment (wallets round differently — see 03-accounting.md on
     * crypto payments) must never make an invoice contribute a negative
     * figure to the book.
     */
    public function test_an_overpaid_invoice_contributes_zero_never_a_negative_amount(): void
    {
        $invoice = Invoice::factory()->sent()->create([
            'currency' => 'USD', 'total' => '100.000000', 'subtotal' => '100.000000',
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'currency' => 'USD',
            'amount' => '110.000000',
            'applied_amount' => '110.000000',
        ]);

        $usd = $this->forCurrency($this->reader()->totals()['outstanding'], 'USD');

        $this->assertSame('0.000000', $usd['amount']);
    }

    /** Draft, paid and void invoices carry no outstanding balance on the book. */
    public function test_draft_paid_and_void_invoices_are_excluded_from_outstanding(): void
    {
        Invoice::factory()->create(['currency' => 'USD', 'total' => '111.000000', 'subtotal' => '111.000000']); // draft
        Invoice::factory()->paid()->create(['currency' => 'USD', 'total' => '222.000000', 'subtotal' => '222.000000']);
        Invoice::factory()->voided()->create(['currency' => 'USD', 'total' => '333.000000', 'subtotal' => '333.000000']);

        $usd = $this->forCurrency($this->reader()->totals()['outstanding'], 'USD');

        $this->assertSame('0.000000', $usd['amount']);
    }

    /**
     * With no invoices at all, every currency Kargah knows about totals to a
     * real, formatted zero — never null, never an empty string, and never a
     * missing key a caller has to guard against.
     */
    public function test_a_book_with_no_invoices_totals_to_a_real_zero_in_every_supported_currency(): void
    {
        $totals = $this->reader()->totals();

        foreach (['outstanding', 'overdue'] as $bucket) {
            $this->assertCount(3, $totals[$bucket]);

            foreach (Currencies::supported() as $currency) {
                $entry = $this->forCurrency($totals[$bucket], $currency);

                $this->assertNotNull($entry);
                $this->assertSame('0.000000', $entry['amount']);
                $this->assertSame(Money::format('0.000000', $currency), $entry['formatted']);
                $this->assertNotSame('', $entry['formatted']);
            }
        }
    }

    /**
     * The cost that matters at scale: query count, not wall-clock time — the
     * suite is not a quiet machine. Two queries regardless of how many
     * invoices are outstanding: the book itself, and its payments in one
     * bulk `whereIn`.
     */
    public function test_totals_runs_a_bounded_number_of_queries_regardless_of_book_size(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $invoice = Invoice::factory()->sent()->create([
                'currency' => $i % 3 === 0 ? 'TRY' : 'USD',
                'total' => '100.000000',
                'subtotal' => '100.000000',
            ]);

            if ($i % 5 === 0) {
                Payment::factory()->create([
                    'invoice_id' => $invoice->id,
                    'currency' => $invoice->currency,
                    'amount' => '40.000000',
                    'applied_amount' => '40.000000',
                ]);
            }
        }

        DB::enableQueryLog();
        $this->reader()->totals();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $count);
    }

    /** No float anywhere along the way, including a currency that stores six decimals. */
    public function test_no_float_reaches_the_totals_path(): void
    {
        Invoice::factory()->sent()->create([
            'currency' => 'USDT', 'total' => '1234.567891', 'subtotal' => '1234.567891',
        ]);

        $usdt = $this->forCurrency($this->reader()->totals()['outstanding'], 'USDT');

        $this->assertIsString($usdt['amount']);
        $this->assertSame('1234.567891', $usdt['amount']);
    }
}
