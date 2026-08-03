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

    /** @var list<CompletionResponse> Answers for the next calls, in order, the last one repeating. */
    private array $queue = [];

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

    /**
     * Answer successive calls with successive responses.
     *
     * `willRespond()` sets one answer and repeats it forever, which is right
     * for a settings-page test and wrong for a tool-calling one: a fake that
     * answers "call read_board" every single time drives
     * `AssistantConversation` straight into its iteration cap, so the loop can
     * only ever be observed failing. This is how a test spells the real
     * sequence — ask for a tool, then answer in words.
     *
     * The last response repeats once the queue is empty, so a fake set up with
     * one tool call and one answer never runs out mid-conversation.
     */
    public function willRespondInOrder(CompletionResponse ...$responses): static
    {
        $this->queue = array_values($responses);

        return $this;
    }

    /** A tool call, as a model asking for one arrives. */
    public function willCallTool(string $name, array $arguments = [], string $id = 'call_1'): static
    {
        return $this->willRespond(new CompletionResponse(text: null, toolCalls: [new ToolCall($id, $name, $arguments)]));
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

        if ($this->queue !== []) {
            // The last one is kept rather than consumed, so a conversation that
            // takes one more turn than the test expected gets the final answer
            // again instead of falling through to the placeholder text.
            return count($this->queue) === 1 ? $this->queue[0] : array_shift($this->queue);
        }

        return $this->nextResponse ?? new CompletionResponse(text: 'This is a fake reply, from the '.$this->driver.' fake.');
    }

    /** How many times this driver was actually asked for a completion. */
    public function requestCount(): int
    {
        return count($this->requests);
    }
}
