<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;

/**
 * One AI provider Kargah can ask for a completion.
 *
 * Shaped exactly like `Modules\Mailbox\Services\Delivery\Mailer` and
 * `Modules\Accounting\Services\RateSources\RateSource`: a driver does not
 * write anywhere, it answers a question and the caller decides what to do
 * with the answer. That is what lets the same driver back a settings-page
 * "test this connection" button and, later, a real request — both just call
 * `complete()` and look at what comes back.
 *
 * As with the other two, there are two different kinds of not-working.
 * **Unavailable** means it was never going to answer: no key configured, no
 * base URL for a local endpoint. That is a state of the row, it will not fix
 * itself by retrying, and it must never reach the network — a driver
 * constructed with nothing to authenticate with must not spend a request
 * finding that out. **Failed** means the provider was asked and did not
 * answer usefully, which `complete()` reports by throwing
 * `CompletionFailed`.
 */
interface AssistantDriver
{
    /** The value stored in `assistant_providers.driver`, e.g. `gemini`. */
    public function driver(): string;

    /**
     * Why this provider cannot be asked anything, or null if it can.
     *
     * Checked before `complete()` is ever called — by the settings page's test
     * button and, later, by whatever dispatches a real request — so a row
     * with no key configured is reported as exactly that rather than as a
     * network failure one line later.
     */
    public function unavailableReason(AssistantProvider $provider): ?string;

    /**
     * Ask the provider for a completion.
     *
     * One HTTP call, a short timeout, no retry: retrying a paid completion
     * risks asking (and being billed) twice for one answer, which is not true
     * of a GET for a rate or an SMTP handoff.
     *
     * @throws CompletionFailed when the provider is unreachable, errors, or
     *                          answers with something this driver cannot map
     */
    public function complete(AssistantProvider $provider, CompletionRequest $request): CompletionResponse;
}
