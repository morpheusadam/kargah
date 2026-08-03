<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Support\AssistantDrivers;

/**
 * Anthropic Claude, over the Messages API.
 *
 * Named in `07-platform.md` as "the best at tool use, which is what this
 * feature is" — which is exactly why `CompletionRequest`/`CompletionResponse`
 * are shaped the way they are: Anthropic's tool-use blocks
 * (`{"type": "tool_use", "id", "name", "input"}` inside `content`) are what
 * `ToolCall` mirrors most closely of the four providers' shapes.
 *
 * Two things Anthropic does differently from the OpenAI-compatible three:
 * the key goes in `x-api-key`, not `Authorization`, and needs a versioned
 * `anthropic-version` header alongside it; and `system` is a top-level string
 * rather than a message with `role: "system"` mixed into the array.
 * `max_tokens` is required by Anthropic's API (the other three default it),
 * so this driver is the one place a request without an explicit
 * `CompletionRequest::$maxTokens` gets one supplied.
 *
 * **Not exercised against the real API.** No Anthropic key exists on this
 * machine; mapped here from Anthropic's published shape and checked with
 * `Http::fake()` only.
 */
class AnthropicDriver extends HttpAssistantDriver
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    private const DEFAULT_MAX_TOKENS = 1024;

    public function driver(): string
    {
        return AssistantDrivers::ANTHROPIC;
    }

    public function unavailableReason(AssistantProvider $provider): ?string
    {
        return $provider->api_key === null ? 'no API key is configured' : null;
    }

    public function complete(AssistantProvider $provider, CompletionRequest $request): CompletionResponse
    {
        if ($provider->api_key === null) {
            throw CompletionFailed::noKeyConfigured($this->driver());
        }

        [$system, $messages] = $this->toAnthropicMessages($request->messages);

        $body = array_filter([
            'model' => $provider->effectiveModel(),
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'system' => $system,
            'messages' => $messages,
        ], fn (mixed $value): bool => $value !== null);

        $raw = $this->post(self::ENDPOINT, [
            'x-api-key' => $provider->api_key,
            'anthropic-version' => self::API_VERSION,
        ], $body);

        $block = $raw['content'][0] ?? null;
        $text = is_array($block) && ($block['type'] ?? null) === 'text' ? ($block['text'] ?? null) : null;

        if (! is_string($text)) {
            throw CompletionFailed::malformed($this->driver(), 'no text content block in the response');
        }

        $usage = $raw['usage'] ?? [];

        return new CompletionResponse(
            text: $text,
            stopReason: $raw['stop_reason'] ?? null,
            promptTokens: is_array($usage) ? ($usage['input_tokens'] ?? null) : null,
            completionTokens: is_array($usage) ? ($usage['output_tokens'] ?? null) : null,
        );
    }

    /**
     * @param  list<ChatMessage>  $messages
     * @return array{0: string|null, 1: list<array{role: string, content: string}>}
     */
    private function toAnthropicMessages(array $messages): array
    {
        $system = null;
        $out = [];

        foreach ($messages as $message) {
            if ($message->role === 'system') {
                $system = $message->content;

                continue;
            }

            $out[] = ['role' => $message->role, 'content' => $message->content];
        }

        return [$system, $out];
    }
}
