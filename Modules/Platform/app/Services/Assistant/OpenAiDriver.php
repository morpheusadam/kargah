<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Support\AssistantDrivers;

/**
 * OpenAI, over the Chat Completions endpoint.
 *
 * The plain Chat Completions shape rather than the newer Responses API: it is
 * the shape `OpenRouterDriver` and `OllamaDriver`'s compatibility endpoint
 * both already speak, which is what `HttpAssistantDriver::toOpenAiMessages()`
 * and `::mapChatCompletionsResponse()` are named after.
 *
 * **Not exercised against the real API.** No OpenAI key exists on this
 * machine; mapped here from OpenAI's published shape and checked with
 * `Http::fake()` only.
 */
class OpenAiDriver extends HttpAssistantDriver
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function driver(): string
    {
        return AssistantDrivers::OPENAI;
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

        $body = [
            'model' => $provider->effectiveModel(),
            'messages' => $this->toOpenAiMessages($request->messages),
        ];

        // Only when there are some. An empty `tools: []` is not the same
        // request as no `tools` key at all — some OpenAI-compatible endpoints
        // reject it outright, and it is one more thing on the wire for every
        // caller that has no tool layer attached.
        if ($request->tools !== []) {
            $body['tools'] = $this->toOpenAiTools($request->tools);
        }

        $raw = $this->post(self::ENDPOINT, [
            'Authorization' => 'Bearer '.$provider->api_key,
        ], $body);

        return $this->mapChatCompletionsResponse($raw);
    }
}
