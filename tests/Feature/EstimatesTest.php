<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Models\Estimate;
use Modules\Accounting\Models\EstimateLine;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Customer;
use Tests\TestCase;

/**
 * Quoting, and the one step that makes quoting worth having.
 *
 * The feature is not "a second list of documents" — it is that an accepted quote
 * becomes an invoice saying *exactly* the same numbers, without anybody retyping
 * them. So what is asserted here is the seam: the money that crosses it, the
 * link that survives afterwards, and the two ways the seam can be abused —
 * converting twice, and letting an estimate eat an invoice number.
 */
class EstimatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Nima Fazlipour']));
    }

    /**
     * A quote with lines, totalled the way the application totals it.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $lines
     */
    private function quote(array $attributes = [], array $lines = []): Estimate
    {
        $estimate = Estimate::query()->create([
            'number' => $attributes['number'] ?? Estimate::nextNumber(),
            'company_id' => $attributes['company_id'] ?? null,
            'customer_id' => $attributes['customer_id'] ?? null,
            'status' => $attributes['status'] ?? 'draft',
            'currency' => $attributes['currency'] ?? Currencies::USD,
            'valid_until' => $attributes['valid_until'] ?? today()->addDays(30)->toDateString(),
            'notes' => $attributes['notes'] ?? null,
            'terms' => $attributes['terms'] ?? 'This quote is valid for 30 days.',
        ]);

        foreach ($lines === [] ? [['Discovery workshop', '2', '750.00'], ['Implementation', '8.5', '120.00']] : $lines as $i => [$description, $quantity, $price]) {
            EstimateLine::query()->create([
                'estimate_id' => $estimate->id,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => Money::toStorage(Money::lineTotal($quantity, $price, $estimate->currency)),
                'position' => (string) (($i + 1) * 1024),
            ]);
        }

        return $estimate->refresh()->recalculateTotal();
    }

    /* The conversion ---------------------------------------------------------- */

    /**
     * 🔴 The point of the whole feature.
     *
     * A draft invoice, never an issued one — issuing freezes rates and consumes
     * a sequential number for good, and that is a decision a person makes with
     * the invoice in front of them.
     */
    public function test_an_accepted_estimate_becomes_a_draft_invoice_with_the_same_lines_and_the_same_money(): void
    {
        $customer = Customer::factory()->create(['name' => 'Marta Sandoval']);

        $estimate = $this->quote([
            'status' => 'accepted',
            'customer_id' => $customer->id,
            'terms' => 'Half on acceptance, half on delivery.',
        ]);

        // 1500 + 1020.
        $this->assertSame('2520.000000', (string) $estimate->total);

        $invoice = $estimate->convertToInvoice();

        $this->assertNull($invoice->sent_at, 'Converting issued the invoice. It must only ever raise a draft.');
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->reporting_rate, 'A draft raised from a quote froze an exchange rate.');

        // The money, to the last decimal, and as a string on both sides.
        $this->assertSame((string) $estimate->total, (string) $invoice->total);
        $this->assertSame('2520.000000', (string) $invoice->total);
        $this->assertIsString($invoice->total);

        // The client, the currency and the terms travelled with it.
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame($estimate->currency, $invoice->currency);
        $this->assertSame('Half on acceptance, half on delivery.', $invoice->terms);

        // And the lines, in order, unchanged.
        $quoted = $estimate->lines()->get();
        $billed = $invoice->lines()->get();

        $this->assertCount(2, $billed);

        foreach ($quoted as $i => $line) {
            $this->assertSame((string) $line->description, (string) $billed[$i]->description);
            $this->assertSame((string) $line->quantity, (string) $billed[$i]->quantity);
            $this->assertSame((string) $line->unit_price, (string) $billed[$i]->unit_price);
            $this->assertSame((string) $line->amount, (string) $billed[$i]->amount);
        }
    }

    /**
     * 🔴 The failure the guard exists for: one accepted quote, two invoices.
     *
     * Asserted as a refusal rather than as a missing button — a missing button
     * is one `wire:click` away from being called anyway.
     */
    public function test_converting_a_second_time_is_refused_and_raises_no_second_invoice(): void
    {
        $estimate = $this->quote(['status' => 'accepted']);

        $first = $estimate->convertToInvoice();

        try {
            $estimate->fresh()->convertToInvoice();
            $this->fail('A second conversion was allowed. One accepted quote just became two invoices.');
        } catch (\DomainException $e) {
            // The sentence names the invoice, so the person reading it knows
            // where the work already went.
            $this->assertStringContainsString($first->number, $e->getMessage());
        }

        $this->assertSame(1, Invoice::withTrashed()->count(), 'A second invoice was raised for one quote.');
        $this->assertSame($first->id, $estimate->fresh()->converted_invoice_id);
    }

    public function test_an_estimate_that_has_not_been_accepted_refuses_to_convert(): void
    {
        foreach (['draft', 'sent', 'declined'] as $status) {
            $estimate = $this->quote(['status' => $status]);

            try {
                $estimate->convertToInvoice();
                $this->fail('A '.$status.' estimate was converted. Only an accepted quote may be billed.');
            } catch (\DomainException) {
                // Expected.
            }
        }

        $this->assertSame(0, Invoice::withTrashed()->count());
    }

    /* The link afterwards ------------------------------------------------------- */

    /**
     * 🔴 "This became invoice INV-0001" has to survive the invoice being deleted.
     *
     * A draft invoice is deletable, and a soft-deleted one keeps its number
     * reserved. The estimate still became it, so the estimate still says so —
     * through the relation while the row is there, and through the copied number
     * whatever happens to it.
     */
    public function test_the_estimate_names_the_invoice_it_became_even_after_that_invoice_is_deleted(): void
    {
        $estimate = $this->quote(['status' => 'accepted']);
        $invoice = $estimate->convertToInvoice();

        $estimate = $estimate->fresh();

        $this->assertTrue($estimate->isConverted());
        $this->assertSame($invoice->number, $estimate->converted_invoice_number);
        $this->assertNotNull($estimate->converted_at);
        $this->assertSame($invoice->id, $estimate->convertedInvoice->id);

        $invoice->delete();

        $reread = $estimate->fresh();

        $this->assertNull(Invoice::query()->find($invoice->id), 'The draft was not soft-deleted.');
        $this->assertSame($invoice->number, $reread->converted_invoice_number);
        $this->assertNotNull($reread->convertedInvoice, 'The estimate lost the invoice it became when it was deleted.');
        $this->assertNotNull($reread->convertedInvoice->deleted_at);
    }

    /**
     * A deleted invoice does not reopen the door.
     *
     * The number it took is still reserved, the row is still there to be
     * restored, and a second conversion would put the same accepted quote onto
     * two numbered documents.
     */
    public function test_a_deleted_invoice_does_not_reopen_the_conversion(): void
    {
        $estimate = $this->quote(['status' => 'accepted']);

        $estimate->convertToInvoice()->delete();

        $this->expectException(\DomainException::class);

        $estimate->fresh()->convertToInvoice();
    }

    /* Numbering ------------------------------------------------------------------ */

    /**
     * 🔴 An estimate must never consume an invoice number.
     *
     * A sequential invoice number is never reused and never left unexplained. A
     * quote that took one and was then declined would leave a hole in the
     * invoice book that no rule accounts for.
     */
    public function test_estimate_numbering_never_touches_the_invoice_sequence(): void
    {
        $this->quote();
        $this->quote();
        $this->quote();

        $this->assertSame(['EST-0001', 'EST-0002', 'EST-0003'], Estimate::query()->orderBy('id')->pluck('number')->all());
        $this->assertSame('EST-0004', Estimate::nextNumber());

        // Three quotes later, the invoice book has not moved: the next invoice
        // anybody raises is still the first one.
        Livewire::test('accounting::invoice-edit')->assertSet('number', 'INV-0001');
        $this->assertSame(0, Invoice::withTrashed()->count());
    }

    /** And the conversion draws from the invoice sequence, not from its own. */
    public function test_a_converted_estimate_takes_the_next_invoice_number(): void
    {
        Invoice::factory()->create(['number' => 'INV-0007']);

        $invoice = $this->quote(['status' => 'accepted'])->convertToInvoice();

        $this->assertSame('INV-0008', $invoice->number);

        // Deleted, and the number stays taken — the unique index holds it.
        $invoice->delete();

        $this->assertSame('INV-0009', Estimate::nextInvoiceNumber());
    }

    /**
     * A deleted estimate keeps its number too.
     *
     * The unique index still holds it, so a counter that read only the live rows
     * would hand back a number that collides on save.
     */
    public function test_a_deleted_estimate_keeps_its_number_out_of_the_sequence(): void
    {
        $this->quote()->delete();

        $this->assertSame('EST-0002', Estimate::nextNumber());

        $next = $this->quote();

        $this->assertSame('EST-0002', $next->number);
    }

    /* Expiry ---------------------------------------------------------------------- */

    /**
     * 🔴 Expiry is derived from the date, never written into the column.
     *
     * Nothing runs to expire an estimate, so a stored status would be right on
     * the page somebody happened to open and wrong in every filter and count.
     * The proof is that a quote whose date passed reads as expired with its
     * status column untouched — and that SQL can still find it.
     */
    public function test_expiry_is_derived_from_the_date_and_not_stored(): void
    {
        $stale = $this->quote(['status' => 'sent', 'valid_until' => today()->subDay()->toDateString()]);
        $live = $this->quote(['status' => 'sent', 'valid_until' => today()->addDays(7)->toDateString()]);
        $open = $this->quote(['status' => 'sent', 'valid_until' => null]);

        $this->assertTrue($stale->isExpired());
        $this->assertFalse($live->isExpired());
        $this->assertFalse($open->isExpired(), 'A quote with no stated expiry expired.');

        // The column says what a person chose, and nothing else.
        $this->assertSame('sent', $stale->fresh()->status);
        $this->assertSame('expired', $stale->state());

        // And it filters in SQL, exactly as an overdue invoice does.
        $this->assertSame([$stale->id], Estimate::query()->expired()->pluck('id')->all());
        $this->assertSame([$live->id, $open->id], Estimate::query()->awaiting()->orderBy('id')->pluck('id')->all());
    }

    /**
     * Only a sent quote can expire.
     *
     * An accepted or declined one has had its answer; a draft was never in front
     * of anybody. An accepted quote reading as expired would refuse to convert
     * work the client has already agreed to.
     */
    public function test_a_quote_that_has_had_its_answer_never_reads_as_expired(): void
    {
        $yesterday = today()->subDay()->toDateString();

        foreach (['draft', 'accepted', 'declined'] as $status) {
            $estimate = $this->quote(['status' => $status, 'valid_until' => $yesterday]);

            $this->assertFalse($estimate->isExpired(), 'A '.$status.' estimate read as expired.');
            $this->assertSame($status, $estimate->state());
        }

        // And an accepted one whose date has passed still converts.
        $accepted = $this->quote(['status' => 'accepted', 'valid_until' => $yesterday]);

        $this->assertNotNull($accepted->convertToInvoice()->id);
    }

    /* The pages --------------------------------------------------------------------- */

    public function test_the_estimate_pages_render_on_an_empty_database(): void
    {
        // A page that needs a row to exist before it can draw itself is broken
        // for the first person who ever opens it.
        $this->get('/accounting/estimates')->assertOk()->assertSee('No estimates yet', false);
        $this->get('/accounting/estimates/create')->assertOk()->assertSee('New estimate');

        // And a link to an estimate that is not there explains itself rather
        // than dead-ending on a 404.
        $this->get('/accounting/estimates/1/edit')->assertOk()->assertSee('That estimate is not here');
    }

    public function test_the_list_shows_the_quote_and_what_it_became(): void
    {
        $estimate = $this->quote(['status' => 'accepted']);
        $invoice = $estimate->convertToInvoice();

        $this->get('/accounting/estimates')
            ->assertOk()
            ->assertSee($estimate->number)
            ->assertSee($invoice->number)
            ->assertSee($estimate->fresh()->formattedTotal());
    }

    public function test_the_list_splits_sent_from_expired(): void
    {
        $stale = $this->quote(['status' => 'sent', 'valid_until' => today()->subDays(3)->toDateString()]);
        $live = $this->quote(['status' => 'sent', 'valid_until' => today()->addDays(3)->toDateString()]);

        $numbers = fn ($paginator): array => collect($paginator->items())->pluck('number')->all();

        Livewire::test('accounting::estimates')
            ->call('filterBy', 'sent')
            ->assertViewHas('estimates', fn ($rows): bool => $numbers($rows) === [$live->number])
            ->call('filterBy', 'expired')
            ->assertViewHas('estimates', fn ($rows): bool => $numbers($rows) === [$stale->number]);
    }

    /**
     * Both islands, every time.
     *
     * An island nobody names comes back as `mode=skip` and the browser keeps the
     * rows it already had — so a filter change would leave the old tab
     * highlighted over the new list.
     */
    public function test_a_filter_change_sends_both_islands_back(): void
    {
        $this->quote();

        $component = Livewire::test('accounting::estimates')->call('filterBy', 'draft');

        $this->assertNotEmpty(
            $component->effects['islandFragments'] ?? [],
            'filterBy() changed the table but never named an island.',
        );

        $source = file_get_contents(base_path('Modules/Accounting/resources/views/components/⚡estimates.blade.php'));

        $this->assertSame(2, substr_count($source, '@island('));
        $this->assertStringContainsString("renderIsland('tabs')", $source);
        $this->assertStringContainsString("renderIsland('rows')", $source);
    }

    public function test_a_quote_written_on_the_page_is_stored_and_convertible(): void
    {
        $customer = Customer::factory()->create(['name' => 'Helen Weiss']);

        Livewire::test('accounting::estimate-edit')
            ->assertSet('number', 'EST-0001')
            ->set('customerId', (string) $customer->id)
            ->set('items', [
                ['id' => null, 'description' => 'Discovery workshop', 'quantity' => '2', 'unit_price' => '750.00'],
                ['id' => null, 'description' => 'Implementation', 'quantity' => '8.5', 'unit_price' => '120.00'],
            ])
            ->set('status', 'accepted')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $estimate = Estimate::query()->where('number', 'EST-0001')->firstOrFail();

        $this->assertSame('2520.000000', (string) $estimate->total);
        $this->assertIsString($estimate->total);
        $this->assertCount(2, $estimate->lines);
    }

    /**
     * Converting from the page lands on the draft it made.
     *
     * The edit page, not the read-only one: the invoice still needs a tax rate
     * and a look at the dates before anybody issues it.
     */
    public function test_converting_from_the_edit_page_creates_the_draft_and_lands_on_it(): void
    {
        $estimate = $this->quote(['status' => 'accepted']);

        Livewire::test('accounting::estimate-edit', ['estimate' => (string) $estimate->id])
            ->call('convert')
            ->assertHasNoErrors();

        $invoice = Invoice::query()->firstOrFail();

        $this->assertSame('2520.000000', (string) $invoice->total);
        $this->assertNull($invoice->sent_at);
        $this->assertSame($invoice->id, $estimate->fresh()->converted_invoice_id);

        // And the page it leaves behind is read-only, saying what it became.
        $this->get('/accounting/estimates/'.$estimate->id.'/edit')
            ->assertOk()
            ->assertSee('This quote became invoice '.$invoice->number, false)
            ->assertDontSee('Convert to invoice');
    }

    /** A stale second tab cannot convert what the first one already converted. */
    public function test_a_stale_tab_cannot_convert_an_estimate_twice(): void
    {
        $estimate = $this->quote(['status' => 'accepted']);

        $page = Livewire::test('accounting::estimate-edit', ['estimate' => (string) $estimate->id]);

        // Converted in another tab while this one was open.
        $estimate->fresh()->convertToInvoice();

        $page->call('convert')->assertDispatched('toast');

        $this->assertSame(1, Invoice::withTrashed()->count(), 'An impatient second tab raised a second invoice.');
    }
}
