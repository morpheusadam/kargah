<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;

/**
 * A driver that does not leave the process.
 *
 * The same role `Modules\Mailbox\Services\Delivery\FakeMailer` plays: it lets
 * a test assert exactly what was asked, without a real driver — or a real
 * HTTP call — ever being constructed. Registered through `Assistant::swap()`,
 * which replaces the factory for this driver's name, so the real one is never
 * built even by accident.
 */
class FakeAssistantDriver implements AssistantDriver
{
    /** @var list<array{provider: int, request: CompletionRequest}> Every completion asked for, in order. */
    public array $requests = [];

    private ?CompletionResponse $nextResponse = null;

    private ?string $failWith = null;

    private ?string $unavailableBecause = null;

    public function __construct(private readonly string $driver) {}

    public function driver(): string
    {
        return $this->driver;
    }

    /** Answer the next `complete()` call with this response. */
    public function willRespond(CompletionResponse $response): static
    {
        $this->nextResponse = $response;

        return $this;
    }

    /** The common case: answer with plain text. */
    public function willReply(string $text): static
    {
        return $this->willRespond(new CompletionResponse(text: $text));
    }

    /** Make the next `complete()` call throw, as a provider that refuses does. */
    public function failWith(string $message): static
    {
        $this->failWith = $message;

        return $this;
    }

    /** Report as unconfigured without being asked to complete, as a provider with no key does. */
    public function unavailable(?string $reason): static
    {
        $this->unavailableBecause = $reason;

        return $this;
    }

    public function unavailableReason(AssistantProvider $provider): ?string
    {
        return $this->unavailableBecause;
    }

    public function complete(AssistantProvider $provider, CompletionRequest $request): CompletionResponse
    {
        $this->requests[] = ['provider' => (int) $provider->getKey(), 'request' => $request];

        if ($this->failWith !== null) {
            throw CompletionFailed::providerError($this->driver, $this->failWith);
        }

        return $this->nextResponse ?? new CompletionResponse(text: 'This is a fake reply, from the '.$this->driver.' fake.');
    }

    /** How many times this driver was actually asked for a completion. */
    public function requestCount(): int
    {
        return count($this->requests);
    }
}
