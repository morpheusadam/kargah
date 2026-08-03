<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Core\Contracts\CustomerReader;
use Modules\Platform\Http\Resources\CustomerResource;
use Modules\Platform\Support\Scopes;

/**
 * Find a customer by name or email.
 *
 * The entry point for most questions the assistant is asked — "what does Acme
 * owe", "what did we last say to Bob" — because every other reader keys off a
 * customer id and the person asking has a name.
 *
 * `CustomerReader` is the one contract in this set that hands back an Eloquent
 * model rather than an array, which is the single place "read the contracts,
 * not the models" does not fully hold for Platform. `CustomerResource` is the
 * existing containment for that — it names every field it takes off the model
 * instead of calling `toArray()` — so this tool reuses it rather than reaching
 * into the model itself and inventing a second, looser list of what a customer
 * is allowed to expose to a third-party provider.
 */
class SearchCustomers implements Tool
{
    use ReadsArguments;

    public const NAME = 'search_customers';

    private const MAX_LIMIT = 50;

    public function __construct(private readonly CustomerReader $customers) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Search customers by name or email address and return the matches with their ids. '
            .'Use this first when the question names a person or a company but not an id.';
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
                'q' => [
                    'type' => 'string',
                    'description' => 'Part of a name or email address. Leave empty to list customers.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many to return, at most '.self::MAX_LIMIT.'.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $results = $this->customers->search(
            $this->stringArgument($arguments, 'q'),
            $this->intArgument($arguments, 'limit', 20, 1, self::MAX_LIMIT) ?? 20,
        );

        $customers = $results->map(fn ($customer): array => (new CustomerResource($customer))->resolve())->values()->all();

        return ['count' => count($customers), 'customers' => $customers];
    }
}
