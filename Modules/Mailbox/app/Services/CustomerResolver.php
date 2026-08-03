<?php

namespace Modules\Mailbox\Services;

use Modules\Core\Models\Customer;
use Modules\Mailbox\Models\Email;

/**
 * The join that turns an inbox into a CRM: a sender address becomes a customer.
 *
 * The sync calls this once per message, and a mailbox is overwhelmingly one
 * person writing several times — twelve messages in a thread are twelve
 * identical lookups. So the answers are memoised per address for the life of
 * the instance, and the provider binds it as a singleton, which makes that the
 * life of the request or of the sync command. Misses are memoised too: a
 * stranger who sends forty newsletters should cost one query, not forty.
 *
 * Matching is case-insensitive because addresses are, in the half that matters:
 * `Sam@Northwind.example` and `sam@northwind.example` are the same mailbox to
 * every mail server anyone uses, and a customer typed in with a capital letter
 * must not go unrecognised. `Customer::byEmail()` does the lowering on both
 * sides of the comparison.
 */
class CustomerResolver
{
    /**
     * Lowercased address => customer id, or null when nothing matched.
     *
     * `array_key_exists` rather than `isset` throughout, because a memoised
     * miss is stored as null and `isset` cannot tell it from an address that
     * has never been looked up.
     *
     * @var array<string, int|null>
     */
    private array $resolved = [];

    public function resolve(string $fromEmail): ?int
    {
        $address = mb_strtolower(trim($fromEmail));

        if ($address === '') {
            return null;
        }

        if (array_key_exists($address, $this->resolved)) {
            return $this->resolved[$address];
        }

        $id = Customer::query()->byEmail($address)->value('id');

        return $this->resolved[$address] = $id === null ? null : (int) $id;
    }

    /**
     * Resolve a message's sender and write the result onto the message.
     *
     * A miss never clears an existing `customer_id`. The sender address is one
     * way of arriving at a customer and a person clicking 'attach to client' in
     * the inbox is another; the second must not be undone by the next re-sync
     * of the same message.
     */
    public function resolveAndAttach(Email $email): ?int
    {
        $customerId = $this->resolve((string) $email->from_email);

        if ($customerId === null || $customerId === $email->customer_id) {
            return $email->customer_id;
        }

        $email->customer_id = $customerId;

        // An unsaved message is being built by the sync and will be inserted
        // with this attribute already on it. Saving here would insert it early,
        // half-populated.
        if ($email->exists) {
            $email->save();
        }

        return $customerId;
    }

    /**
     * Drop the memo.
     *
     * A long-running sync that adds a customer mid-flight would otherwise keep
     * answering null for them until the process ends.
     */
    public function forget(): void
    {
        $this->resolved = [];
    }
}
