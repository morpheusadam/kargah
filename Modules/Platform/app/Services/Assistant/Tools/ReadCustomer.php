<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Core\Contracts\CustomerReader;
use Modules\Platform\Http\Resources\CustomerResource;
use Modules\Platform\Support\Scopes;

/**
 * One customer, by id.
 *
 * Separate from `SearchCustomers` rather than folded into it as a filter,
 * because a model that has an id from an earlier tool result should not have
 * to phrase a search that might match two rows. A missing id is an answer, not
 * a failure — see `Tool::execute()` on why that is an `error` key rather than
 * an exception.
 */
class ReadCustomer implements Tool
{
    use ReadsArguments;

    public const NAME = 'read_customer';

    public function __construct(private readonly CustomerReader $customers) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Read one customer by their numeric id, including their email, phone and company.';
    }

    public function scope(): string
    {
        return Scopes::CORE_READ;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'integer', 'description' => 'The customer id, as returned by search_customers.'],
            ],
            'required' => ['customer_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $id = $this->intArgument($arguments, 'customer_id', null, 1);

        if ($id === null) {
            return ['error' => 'customer_id is required and must be a number.'];
        }

        $customer = $this->customers->find($id);

        if ($customer === null) {
            return ['error' => 'There is no customer with id '.$id.'.'];
        }

        return ['customer' => (new CustomerResource($customer))->resolve()];
    }
}
