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
            // Google nests every declaration inside a single `tools` entry
            // rather than listing them at the top level, and calls the schema
            // `parameters` — the same JSON Schema object, one wrapper deeper.
            'tools' => $request->tools === [] ? null : [[
                'functionDeclarations' => array_map(
                    static fn (ToolDefinition $tool): array => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'parameters' => $tool->parameters,
                    ],
                    $request->tools,
                ),
            ]],
        ], fn (mixed $value): bool => $value !== null);

        $url = self::ENDPOINT.$provider->effectiveModel().':generateContent?key='.$provider->api_key;

        $raw = $this->post($url, [], $body);

        [$text, $toolCalls] = $this->readParts($raw['candidates'][0]['content']['parts'] ?? []);

        // As with Anthropic, a turn that calls a function usually carries no
        // text at all, so only a candidate with neither is malformed.
        if ($text === null && $toolCalls === []) {
            throw CompletionFailed::malformed($this->driver(), 'no candidate text or function call in the response');
        }

        $usage = $raw['usageMetadata'] ?? [];

        return new CompletionResponse(
            text: $text,
            toolCalls: $toolCalls,
            stopReason: $raw['candidates'][0]['finishReason'] ?? null,
            promptTokens: is_array($usage) ? ($usage['promptTokenCount'] ?? null) : null,
            completionTokens: is_array($usage) ? ($usage['candidatesTokenCount'] ?? null) : null,
        );
    }

    /**
     * Text and function calls out of a candidate's `parts` array.
     *
     * **Gemini's `functionCall` has no id of its own**, unlike Anthropic's
     * `tool_use.id` and OpenAI's `tool_calls[].id`. `ToolCall::$id` is not
     * optional — a caller has to be able to pair a result back to its call —
     * so one is synthesised from the part's position. It is only ever echoed
     * back to this driver, which pairs a `functionResponse` by *name* the way
     * Google's own API does, so a synthetic id costs nothing and keeps
     * `ToolCall` one shape across all five providers.
     *
     * @return array{0: string|null, 1: list<ToolCall>}
     */
    private function readParts(mixed $parts): array
    {
        if (! is_array($parts)) {
            return [null, []];
        }

        $text = [];
        $calls = [];

        foreach ($parts as $index => $part) {
            if (! is_array($part)) {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                $text[] = $part['text'];

                continue;
            }

            $call = $part['functionCall'] ?? null;

            if (is_array($call) && is_string($call['name'] ?? null)) {
                $arguments = $call['args'] ?? [];

                $calls[] = new ToolCall(
                    id: 'call_'.$index,
                    name: $call['name'],
                    arguments: is_array($arguments) ? $arguments : [],
                );
            }
        }

        return [$text === [] ? null : implode("\n", $text), $calls];
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

            if ($message->role === 'assistant' && $message->toolCallId !== null) {
                // The model's own request to call something, played back:
                // a `functionCall` part on a `model` turn, with the arguments
                // as a real object rather than the JSON string OpenAI wants.
                $decoded = json_decode($message->content, true);

                $contents[] = ['role' => 'model', 'parts' => [[
                    'functionCall' => [
                        'name' => $message->name,
                        'args' => is_array($decoded) ? $decoded : [],
                    ],
                ]]];

                continue;
            }

            if ($message->role === 'tool') {
                // Gemini has no `tool` role either: a result is a
                // `functionResponse` part on a user turn, paired by name, and
                // `response` must be an object — a bare string is rejected, so
                // a result that is not one is wrapped rather than sent as is.
                $decoded = json_decode($message->content, true);

                $contents[] = ['role' => 'user', 'parts' => [[
                    'functionResponse' => [
                        'name' => $message->name,
                        'response' => is_array($decoded) ? $decoded : ['result' => $message->content],
                    ],
                ]]];

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
