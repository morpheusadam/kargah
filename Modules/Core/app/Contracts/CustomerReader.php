<?php

namespace Modules\Core\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Models\Customer;

/**
 * What other modules are allowed to know about customers.
 *
 * A feature module depends on this interface, never on the Eloquent model. That
 * is the whole boundary: a foreign key in a migration is a schema fact and is
 * fine; importing `Modules\Core\Models\Customer` into Accounting is a code fact
 * and is not.
 */
interface CustomerReader
{
    public function find(int $id): ?Customer;

    /** Resolve an inbound email address to a customer, if one matches. */
    public function findByEmail(string $email): ?Customer;

    /** @return Collection<int, Customer> */
    public function search(string $term, int $limit = 20): Collection;

    /** @return Collection<int, Customer> */
    public function forCompany(int $companyId): Collection;

    /** id => display name, for select boxes. */
    public function options(): array;
}
