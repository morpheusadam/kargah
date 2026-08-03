<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Support\AssistantDrivers;

/**
 * Google Gemini, over the Generative Language API.
 *
 * The free tier is the reason this is the suggested default in
 * `Support\AssistantDrivers` — see `07-platform.md`. The key travels as a
 * `?key=` query parameter, which is Google's own documented shape for this
 * API rather than a bearer header, and is the one real deviation from the
 * other three cloud drivers here.
 *
 * Gemini's request body is `contents` (an array of turns) plus an optional
 * top-level `systemInstruction`, not a `system` role mixed into the array the
 * way Anthropic and the OpenAI-compatible shape both do it — so mapping a
 * `system` message means pulling it out rather than passing it through.
 *
 * **Not exercised against the real API.** Mapped here from Google's published
 * request/response shape and checked in `AssistantProviderTest` with
 * `Http::fake()` only; no key exists on this machine to call it for real.
 */
class GeminiDriver extends HttpAssistantDriver
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function driver(): string
    {
        return AssistantDrivers::GEMINI;
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

        [$system, $contents] = $this->toGeminiContents($request->messages);

        $body = array_filter([
            'contents' => $contents,
            'systemInstruction' => $system,
        ], fn (mixed $value): bool => $value !== null);

        $url = self::ENDPOINT.$provider->effectiveModel().':generateContent?key='.$provider->api_key;

        $raw = $this->post($url, [], $body);

        $text = $raw['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! is_string($text)) {
            throw CompletionFailed::malformed($this->driver(), 'no candidate text in the response');
        }

        $usage = $raw['usageMetadata'] ?? [];

        return new CompletionResponse(
            text: $text,
            stopReason: $raw['candidates'][0]['finishReason'] ?? null,
            promptTokens: is_array($usage) ? ($usage['promptTokenCount'] ?? null) : null,
            completionTokens: is_array($usage) ? ($usage['candidatesTokenCount'] ?? null) : null,
        );
    }

    /**
     * @param  list<ChatMessage>  $messages
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>}
     */
    private function toGeminiContents(array $messages): array
    {
        $system = null;
        $contents = [];

        foreach ($messages as $message) {
            if ($message->role === 'system') {
                // Gemini has no system turn in `contents`; it is a separate
                // top-level field. The last system message wins, matching how
                // every other driver here treats a conversation with more
                // than one.
                $system = ['parts' => [['text' => $message->content]]];

                continue;
            }

            $contents[] = [
                // Gemini calls the assistant's own turns "model", not "assistant".
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }

        return [$system, $contents];
    }
}
