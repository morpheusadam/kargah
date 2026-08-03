<?php

namespace Tests\Feature;

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\ExchangeRate;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Money;
use Tests\TestCase;

/**
 * "No float appears anywhere in the money path — enforced by a test that greps
 * for it."
 *
 * That is an acceptance criterion for the Accounting phase, and this is the
 * test. It works four ways, because a float can get in at four different
 * layers and catching it at only one of them is catching it nowhere:
 *
 *   1. the column type in the database
 *   2. the Eloquent cast that reads the column
 *   3. a float literal written into source — a factory, a seeder, a component
 *   4. the value actually handed back at runtime
 *
 * A failure here is not a style complaint. A float cannot hold 0.1, so an
 * invoice built on one is wrong by an amount too small to notice until a year
 * of them is added up.
 */
class NoFloatsInMoneyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columns that hold money, a rate, or a quantity that multiplies money.
     *
     * @return array<string, list<string>>
     */
    private function monetaryColumns(): array
    {
        return [
            'invoices' => ['subtotal', 'tax_percent', 'tax_amount', 'total', 'reporting_rate', 'reporting_amount', 'issue_rate_to_try', 'try_equivalent'],
            'invoice_lines' => ['quantity', 'unit_price', 'amount'],
            'payments' => ['amount', 'settlement_rate', 'applied_amount', 'fx_gain_loss'],
            'crypto_payments' => ['amount', 'network_fee'],
            'expenses' => ['amount', 'reporting_rate', 'reporting_amount'],
            'ledger_entries' => ['amount', 'reporting_amount'],
            'exchange_rates' => ['rate'],
        ];
    }

    /** Every PHP file in the Accounting module, plus its Blade components. */
    private function accountingSources(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('Modules/Accounting'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[$file->getPathname()] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * Source with comments stripped, line numbers preserved.
     *
     * A docblock explaining *why* `number_format()` is banned would otherwise
     * trip the ban on `number_format()`, and a rule that punishes its own
     * documentation is a rule people delete.
     */
    private function withoutComments(string $source): array
    {
        $lines = explode("\n", $source);

        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')
                || str_starts_with($trimmed, '{{--')) {
                $lines[$i] = '';
            }
        }

        return $lines;
    }

    /* 1 — the columns ------------------------------------------------------- */

    public function test_every_monetary_column_is_a_decimal_in_the_database(): void
    {
        foreach ($this->monetaryColumns() as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table.' is missing.');

            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), $table.'.'.$column.' is missing.');

                $type = strtolower(Schema::getColumnType($table, $column));

                // SQLite has no DECIMAL storage class; it gives the column
                // NUMERIC affinity and reports it as such. Both spellings mean
                // "declared decimal"; `real`, `float` and `double` do not.
                $this->assertTrue(
                    str_contains($type, 'decimal') || str_contains($type, 'numeric'),
                    $table.'.'.$column.' is '.$type.'. Money is decimal, never float, double or real.',
                );
            }
        }
    }

    /**
     * What SQLite actually keeps.
     *
     * The spec says `decimal(20,6)` holds up to 99,999,999,999,999.999999.
     * That is true of MySQL and MariaDB, which is the primary target. It is
     * **not** true of SQLite: NUMERIC affinity stores a non-integer as an IEEE
     * double, so the top of that range is silently rounded — the maximum the
     * spec quotes comes back as the integer 100000000000000.
     *
     * This is documented rather than worked around, because the alternative
     * (integer minor units, or money in a varchar) costs `SUM()` and `ORDER BY`
     * on every report to buy headroom no freelance invoice will ever use. What
     * matters is knowing where the edge is, so this test pins it.
     */
    public function test_sqlite_keeps_every_figure_a_freelancer_will_ever_invoice(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The precision ceiling being pinned here is SQLite-specific.');
        }

        DB::statement('CREATE TEMPORARY TABLE money_probe (v decimal(20,6))');

        $exact = [
            '0.100000',              // the value binary cannot hold
            '1500.490000',
            '249.999871',            // six decimals, as USDT needs
            '12345678.123456',       // fourteen significant digits — the edge
        ];

        foreach ($exact as $value) {
            DB::table('money_probe')->insert(['v' => $value]);
        }

        $stored = collect(DB::table('money_probe')->pluck('v'))
            ->map(fn ($v): string => (string) BigDecimal::of((string) $v)->toScale(6))
            ->all();

        $this->assertSame($exact, $stored, 'SQLite lost precision inside the range Kargah actually uses.');
    }

    public function test_the_documented_sqlite_ceiling_is_still_where_we_think_it_is(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific.');
        }

        DB::statement('CREATE TEMPORARY TABLE ceiling_probe (v decimal(20,6))');

        // Fifteen significant digits is the first thing to go, and PHP's
        // `precision` ini (14 by default) is what decides it — not the double
        // itself, which holds nearly sixteen.
        DB::table('ceiling_probe')->insert(['v' => '123456789.123456']);

        $this->assertNotSame(
            '123456789.123456',
            (string) BigDecimal::of((string) DB::table('ceiling_probe')->value('v'))->toScale(6),
            'SQLite now keeps fifteen significant digits — update the ceiling in 03-accounting.md.',
        );

        $this->assertSame('14', ini_get('precision'), 'The ceiling measured in the spec assumes precision=14.');
    }

    /* 2 — the casts ---------------------------------------------------------- */

    public function test_no_accounting_model_casts_a_column_to_a_float(): void
    {
        $offenders = [];

        foreach ($this->accountingSources() as $path => $source) {
            if (! str_contains($path, DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (preg_match_all("/=>\s*'(float|double|real)'/", $source, $matches)) {
                $offenders[] = basename($path).' casts to '.implode(', ', $matches[1]);
            }
        }

        $this->assertSame([], $offenders, "A model is handing back floats:\n".implode("\n", $offenders));
    }

    public function test_every_monetary_attribute_is_cast_to_a_decimal_string(): void
    {
        $models = [
            Invoice::class => ['subtotal', 'tax_amount', 'total'],
            InvoiceLine::class => ['quantity', 'unit_price', 'amount'],
            Payment::class => ['amount', 'settlement_rate', 'applied_amount', 'fx_gain_loss'],
            Expense::class => ['amount'],
            LedgerEntry::class => ['amount'],
            ExchangeRate::class => ['rate'],
        ];

        foreach ($models as $class => $attributes) {
            $casts = (new $class)->getCasts();

            foreach ($attributes as $attribute) {
                $this->assertArrayHasKey($attribute, $casts, $class.' does not cast '.$attribute.' at all.');
                $this->assertStringStartsWith(
                    'decimal:',
                    $casts[$attribute],
                    $class.'::$'.$attribute.' is cast as "'.$casts[$attribute].'". It must be decimal:N, which returns a string.',
                );
            }
        }
    }

    /* 3 — the source ---------------------------------------------------------- */

    /**
     * The grep the spec asks for.
     *
     * These functions all take or return a float, so any of them on a monetary
     * value throws away precision on the last step before it reaches a human —
     * which is the worst place to lose it, because the number now looks final.
     */
    public function test_no_float_producing_function_appears_in_the_accounting_module(): void
    {
        $banned = ['(float)', '(double)', 'floatval(', 'doubleval(', 'number_format(', 'round(', 'floor(', 'ceil('];

        $offenders = [];

        foreach ($this->accountingSources() as $path => $source) {
            foreach ($this->withoutComments($source) as $number => $line) {
                if (str_contains($line, 'no-float-ok')) {
                    continue;
                }

                foreach ($banned as $needle) {
                    if (str_contains($line, $needle)) {
                        $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                            .':'.($number + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A float-producing call is in the money path:\n".implode("\n", $offenders)
            ."\n\nUse Modules\\Accounting\\Support\\Money. If a line is genuinely not money, mark it // no-float-ok.",
        );
    }

    /**
     * A float *literal* assigned to a monetary key.
     *
     * `'amount' => 1500.00` in a factory is a float, and it will round-trip
     * through the database looking fine right up until the value is one that
     * binary cannot represent. It has to be `'1500.00'`.
     */
    public function test_no_monetary_value_is_written_as_a_float_literal(): void
    {
        $keys = 'amount|total|subtotal|unit_price|price|rate|tax_amount|tax_percent|fx_gain_loss'
            .'|applied_amount|settlement_rate|reporting_amount|reporting_rate|try_equivalent'
            .'|issue_rate_to_try|network_fee|quantity';

        $offenders = [];

        foreach ($this->accountingSources() as $path => $source) {
            // 'amount' => 1500.00     — a float literal, unquoted
            $stripped = implode("\n", $this->withoutComments($source));

            if (preg_match_all("/'(?:{$keys})'\s*=>\s*-?\d+\.\d+/", $stripped, $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).'  '.$match;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A monetary value is a float literal — quote it:\n".implode("\n", $offenders),
        );
    }

    public function test_faker_is_never_asked_for_a_float_amount(): void
    {
        $offenders = [];

        foreach ($this->accountingSources() as $path => $source) {
            $stripped = implode("\n", $this->withoutComments($source));

            if (preg_match('/randomFloat|randomNumber\(\)\s*\/\s*100/', $stripped, $m)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).'  '.$m[0];
            }
        }

        $this->assertSame([], $offenders, "A random float is being used as money:\n".implode("\n", $offenders));
    }

    /* 4 — runtime -------------------------------------------------------------- */

    public function test_a_money_attribute_read_off_a_model_is_a_string(): void
    {
        $invoice = Invoice::factory()->create();

        foreach (['subtotal', 'tax_amount', 'total'] as $attribute) {
            $this->assertIsString(
                $invoice->{$attribute},
                'Invoice::$'.$attribute.' came back as '.get_debug_type($invoice->{$attribute}).', not a string.',
            );
        }

        // And it survives a round trip through the database unchanged.
        $reloaded = Invoice::query()->find($invoice->id);

        $this->assertSame($invoice->total, $reloaded->total);
    }

    public function test_the_money_layer_itself_refuses_a_float(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A float reached the money path');

        Money::of(1500.00, 'USD');
    }
}
