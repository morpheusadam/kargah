<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Support\AssistantDrivers;

/**
 * OpenRouter — one key, many models, several genuinely free.
 *
 * OpenRouter is a proxy in front of dozens of providers but publishes one
 * OpenAI-compatible endpoint for all of them, so this driver is the thinnest
 * of the five: pick the model string (a `:free` suffix is how OpenRouter
 * marks its no-cost models — see `Support\AssistantDrivers::OPENROUTER`'s
 * default), send it, and map the same Chat Completions shape `OpenAiDriver`
 * and `OllamaDriver` also use.
 *
 * **Not exercised against the real API.** No OpenRouter key exists on this
 * machine; mapped here from its published (OpenAI-compatible) shape and
 * checked with `Http::fake()` only.
 */
class OpenRouterDriver extends HttpAssistantDriver
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    public function driver(): string
    {
        return AssistantDrivers::OPENROUTER;
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

        $raw = $this->post(self::ENDPOINT, [
            'Authorization' => 'Bearer '.$provider->api_key,
        ], [
            'model' => $provider->effectiveModel(),
            'messages' => $this->toOpenAiMessages($request->messages),
        ]);

        return $this->mapChatCompletionsResponse($raw);
    }
}
