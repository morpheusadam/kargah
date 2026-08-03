<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Mailbox\Contracts\EmailReader;
use Modules\Platform\Support\Scopes;

/**
 * A customer's recent messages.
 *
 * The only email read in the catalogue, because `EmailReader` exposes exactly
 * one — the same limit the API ran into: there is no way through the contract
 * to fetch a single message, list the inbox generally, or read a thread. So
 * "summarise a thread", which `07-platform.md` names as a starting tool, is not
 * here; see the report for what `EmailReader` would have to grow.
 *
 * `preview` rather than a body is the contract's decision, not this tool's, and
 * it happens to be the right one for an assistant too: shipping `body_html` to
 * a third-party provider would send whatever a stranger put in an email
 * straight into the model's context.
 */
class CustomerEmails implements Tool
{
    use ReadsArguments;

    public const NAME = 'customer_emails';

    private const MAX_LIMIT = 50;

    public function __construct(private readonly EmailReader $emails) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'List a customer\'s recent email messages, newest first, with a one-line preview of each. '
            .'Needs a customer id — call search_customers first if you only have a name.';
    }

    public function scope(): string
    {
        return Scopes::MAILBOX_READ;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'integer', 'description' => 'The customer id, as returned by search_customers.'],
                'limit' => ['type' => 'integer', 'description' => 'How many messages to return, at most '.self::MAX_LIMIT.'.'],
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

        $limit = $this->intArgument($arguments, 'limit', 20, 1, self::MAX_LIMIT) ?? 20;

        return [
            'total' => $this->emails->countForCustomer($id),
            'emails' => $this->emails->forCustomer($id, $limit)->values()->all(),
        ];
    }
}
