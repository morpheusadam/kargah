<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Contracts\CustomerReader;
use Modules\Mailbox\Contracts\EmailReader;
use Modules\Platform\Http\Resources\EmailMessageResource;
use Modules\Platform\Http\Resources\EmailResource;
use Modules\Platform\Support\ApiResponse;

/**
 * `GET /api/v1/emails`, `GET /api/v1/emails/{email}`,
 * `GET /api/v1/threads/{thread}`, `GET /api/v1/customers/{customer}/emails`
 * — all `mailbox:read`.
 *
 * `Modules\Mailbox\Contracts\EmailReader` used to expose exactly one read — a
 * customer's messages, capped at a limit — with no way to fetch a single
 * message, read a conversation, or list the mailbox at all. It has `find()`,
 * `thread()` and `paginate()` now, and this is what they carry.
 *
 * Two shapes, on purpose. `EmailResource` is the listing shape: a preview, no
 * body. `EmailMessageResource` is the message shape: the body, the recipients,
 * the attachment count. A page of twenty bodies is not a list, and collapsing
 * the two would make every listing pay for the one case that wants the text.
 *
 * **Reading does not mark read.** The contract's `find()` deliberately does not
 * call `markRead()`, so an integration polling the mailbox cannot silently
 * empty the owner's unread badge. Marking read is a write, `mailbox:write` is
 * not one of the twelve scopes, and there is no endpoint for it.
 *
 * **There is no send endpoint.** `mailbox:send` exists as a scope and
 * `07-platform.md` asks for it, but sending goes through
 * `Modules\Mailbox\Services\Delivery\Delivery` — a concrete registry with no
 * interface in front of it, in a namespace Platform may not import. See the
 * report for what an `EmailSender` contract would need.
 */
class EmailController
{
    public function __construct(
        private readonly CustomerReader $customers,
        private readonly EmailReader $emails,
    ) {}

    /** A page of the mailbox, newest first. */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            // Not validated against a known set of folders: Mailbox takes its
            // folder names from whatever IMAP hands over, and an allowlist here
            // would be Platform inventing a rule Mailbox has not stated.
            'folder' => ['sometimes', 'string', 'max:100'],
            'unread' => ['sometimes', 'boolean'],
            'customer_id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:200'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $customerId = $request->query('customer_id');

        $page = $this->emails->paginate(
            $request->query('folder'),
            $request->has('unread') ? $request->boolean('unread') : null,
            $customerId === null ? null : (int) $customerId,
            trim((string) $request->query('q', '')),
            $request->query('cursor'),
            (int) $request->query('per_page', 20),
        );

        return ApiResponse::page($page, EmailResource::class);
    }

    public function show(int $email): JsonResponse
    {
        $found = $this->emails->find($email);

        if ($found === null) {
            return ApiResponse::notFound("Email {$email} does not exist.");
        }

        return response()->json(['data' => new EmailMessageResource($found)]);
    }

    /**
     * A conversation, oldest first — the order a thread is read in, which is
     * the opposite of the order an inbox is.
     *
     * `message_count` is the thread's own stored total, not the number of rows
     * in `messages`: the rows are capped by `limit`, and a client told a
     * conversation has 20 messages when it has 60 would stop reading.
     */
    public function thread(Request $request, int $thread): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $found = $this->emails->thread($thread, (int) $request->query('limit', 50));

        if ($found === null) {
            return ApiResponse::notFound("Thread {$thread} does not exist.");
        }

        return response()->json([
            'data' => [
                'id' => $found['id'],
                'subject' => $found['subject'],
                'participants' => $found['participants'],
                'message_count' => $found['message_count'],
                'last_message_at' => $found['last_message_at'],
                'messages' => EmailMessageResource::collection($found['messages']),
            ],
        ]);
    }

    /** A customer's correspondence — the original endpoint, unchanged. */
    public function forCustomer(Request $request, int $customer): JsonResponse
    {
        if ($this->customers->find($customer) === null) {
            return ApiResponse::notFound("Customer {$customer} does not exist.");
        }

        $validator = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'data' => EmailResource::collection($this->emails->forCustomer($customer, $limit)),
            'total' => $this->emails->countForCustomer($customer),
        ]);
    }
}
