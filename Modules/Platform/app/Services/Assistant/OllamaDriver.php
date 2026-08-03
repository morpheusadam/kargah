<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Support\AssistantDrivers;

/**
 * Ollama or LM Studio, on a local endpoint the owner runs themselves.
 *
 * The one driver in this module with no key at all — only a base URL, which
 * is exactly the shape `07-platform.md` asks the interface to prove it can
 * express: "no key at all, and a base URL instead". `unavailableReason()`
 * checks `$provider->base_url` rather than `$provider->api_key`, and
 * `AssistantDrivers::requiresKey()` returns `false` for this driver so the
 * settings page never asks for one.
 *
 * Both Ollama (recent versions) and LM Studio serve an OpenAI-compatible
 * `/v1/chat/completions` endpoint alongside their own native ones, so this
 * driver targets that rather than Ollama's native `/api/chat` — one mapping
 * shared with `OpenAiDriver` and `OpenRouterDriver` instead of a second,
 * Ollama-specific one that would only ever be exercised by this driver.
 *
 * **Not exercised against a real local endpoint.** No Ollama or LM Studio
 * instance runs on this machine; mapped here from the OpenAI-compatible
 * shape both projects publish, and checked with `Http::fake()` only.
 */
class OllamaDriver extends HttpAssistantDriver
{
    public function driver(): string
    {
        return AssistantDrivers::OLLAMA;
    }

    public function unavailableReason(AssistantProvider $provider): ?string
    {
        return $provider->base_url === null || $provider->base_url === '' ? 'no base URL is configured' : null;
    }

    public function complete(AssistantProvider $provider, CompletionRequest $request): CompletionResponse
    {
        if ($provider->base_url === null || $provider->base_url === '') {
            throw CompletionFailed::misconfigured($this->driver(), 'no base URL is configured');
        }

        $url = rtrim($provider->base_url, '/').'/v1/chat/completions';

        $body = [
            'model' => $provider->effectiveModel(),
            'messages' => $this->toOpenAiMessages($request->messages),
        ];

        // Sent when there are tools, even though a small local model is the
        // one most likely to ignore them: whether the model can call a tool is
        // a property of the model the owner chose, and silently withholding
        // the catalogue would make a capable local model look incapable.
        if ($request->tools !== []) {
            $body['tools'] = $this->toOpenAiTools($request->tools);
        }

        // No Authorization header: a local endpoint with no key is the whole
        // point of this driver.
        $raw = $this->post($url, [], $body);

        return $this->mapChatCompletionsResponse($raw);
    }
}
