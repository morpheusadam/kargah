<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Contracts\CustomerReader as CustomerReaderContract;
use Modules\Core\Contracts\Linker as LinkerContract;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Core\Models\Link;
use Modules\Core\Support\MorphMap;
use Tests\TestCase;

/**
 * Phase 1 acceptance criteria from project-guaid/spec/05-build-order.md.
 *
 * These are the tests that decide whether the spine actually holds, not whether
 * the code merely runs.
 */
class CoreSpineTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- entities

    public function test_a_customer_can_be_created_linked_to_a_company_archived_and_restored(): void
    {
        $company = Company::factory()->create(['name' => 'Northwind Ltd']);
        $customer = Customer::factory()->create(['company_id' => $company->id, 'name' => 'Sam Okafor']);

        $this->assertTrue($customer->company->is($company));
        $this->assertTrue($company->customers->contains($customer));

        $customer->update(['archived_at' => now()]);
        $this->assertTrue($customer->fresh()->isArchived());
        $this->assertCount(0, Customer::active()->get());
        $this->assertCount(1, Customer::archived()->get());

        $customer->update(['archived_at' => null]);
        $this->assertFalse($customer->fresh()->isArchived());
        $this->assertCount(1, Customer::active()->get());
    }

    public function test_deleting_a_company_does_not_delete_its_customers(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        $company->forceDelete();

        $this->assertNotNull($customer->fresh());
        $this->assertNull($customer->fresh()->company_id);
    }

    public function test_soft_deleting_keeps_the_row(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->assertNull(Customer::find($customer->id));
        $this->assertNotNull(Customer::withTrashed()->find($customer->id));
    }

    // ------------------------------------------------------------------- links

    public function test_two_records_can_be_linked_and_read_back_from_either_end(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();

        $customer->linkTo($company, 'references');

        $this->assertTrue($customer->isLinkedTo($company));
        $this->assertTrue($company->isLinkedTo($customer));

        $this->assertTrue($customer->linked('company')->contains->is($company));
        $this->assertTrue($company->linked('customer')->contains->is($customer));
    }

    public function test_linking_the_same_pair_twice_does_not_create_a_second_row(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();

        $customer->linkTo($company, 'references');
        $customer->linkTo($company, 'references', ['note' => 'updated']);

        $this->assertSame(1, Link::count());
        $this->assertSame('updated', Link::first()->meta['note']);
    }

    public function test_a_link_can_be_removed_from_either_end(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();

        $customer->linkTo($company, 'references');
        $company->unlinkFrom($customer);

        $this->assertSame(0, Link::count());
    }

    public function test_the_linker_contract_behaves_the_same_as_the_trait(): void
    {
        /** @var LinkerContract $linker */
        $linker = app(LinkerContract::class);

        $company = Company::factory()->create();
        $customer = Customer::factory()->create();

        $linker->link($company, $customer, 'references');

        $this->assertTrue($linker->isLinked($customer, $company));
        $this->assertTrue($linker->related($company, 'customer')->contains->is($customer));

        $linker->unlink($company, $customer);
        $this->assertFalse($linker->isLinked($company, $customer));
    }

    // --------------------------------------------------------------- morph map

    public function test_links_store_a_short_alias_not_a_class_name(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();

        $customer->linkTo($company, 'references');

        $link = Link::first();

        $this->assertSame('customer', $link->source_type);
        $this->assertSame('company', $link->target_type);
        $this->assertStringNotContainsString('\\', $link->source_type);
        $this->assertStringNotContainsString('\\', $link->target_type);
    }

    public function test_the_morph_map_is_enforced_so_an_unregistered_model_throws(): void
    {
        $this->assertTrue(Relation::requiresMorphMap());

        $this->assertSame('company', MorphMap::aliasFor(Company::class));
        $this->assertSame('customer', MorphMap::aliasFor(Customer::class));
    }

    public function test_renaming_a_model_class_does_not_orphan_existing_links(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $customer->linkTo($company, 'references');

        // Simulate the model moving to a different namespace: only the map changes.
        MorphMap::register(['company' => Company::class]);

        $this->assertSame(1, Link::where('target_type', 'company')->count());
        $this->assertTrue($customer->fresh()->linked('company')->contains->is($company));
    }

    // ------------------------------------------------------------------ reader

    public function test_an_email_address_resolves_to_a_customer(): void
    {
        /** @var CustomerReaderContract $reader */
        $reader = app(CustomerReaderContract::class);

        $customer = Customer::factory()->create(['email' => 'Sam@Northwind.Example']);

        $this->assertTrue($reader->findByEmail('sam@northwind.example')?->is($customer));
        $this->assertTrue($reader->findByEmail('  SAM@NORTHWIND.EXAMPLE  ')?->is($customer));
        $this->assertNull($reader->findByEmail('nobody@example.com'));
        $this->assertNull($reader->findByEmail(''));
    }

    public function test_search_finds_a_customer_by_partial_name(): void
    {
        /** @var CustomerReaderContract $reader */
        $reader = app(CustomerReaderContract::class);

        $customer = Customer::factory()->create(['name' => 'Jonas Reyes']);
        Customer::factory()->create(['name' => 'Rita Vance']);

        $found = $reader->search('reye');

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($customer));
    }

    public function test_archived_customers_are_excluded_from_search_and_options(): void
    {
        /** @var CustomerReaderContract $reader */
        $reader = app(CustomerReaderContract::class);

        Customer::factory()->archived()->create(['name' => 'Old Contact']);

        $this->assertCount(0, $reader->search('Old'));
        $this->assertCount(0, $reader->options());
    }

    // ----------------------------------------------------------------- created

    public function test_created_by_is_recorded_on_a_link(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $customer->linkTo($company, 'references');

        $this->assertSame($user->id, Link::first()->created_by);
    }
}
