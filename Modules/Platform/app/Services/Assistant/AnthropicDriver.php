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
            // Anthropic calls the schema `input_schema`, not `parameters`; it
            // is the same JSON Schema object under a different key.
            'tools' => $request->tools === [] ? null : array_map(
                static fn (ToolDefinition $tool): array => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'input_schema' => $tool->parameters,
                ],
                $request->tools,
            ),
        ], fn (mixed $value): bool => $value !== null);

        $raw = $this->post(self::ENDPOINT, [
            'x-api-key' => $provider->api_key,
            'anthropic-version' => self::API_VERSION,
        ], $body);

        [$text, $toolCalls] = $this->readContentBlocks($raw['content'] ?? []);

        // A turn that calls a tool often has no text block at all, and one that
        // thinks out loud first has text *and* tool blocks. Only a response
        // with neither is malformed.
        if ($text === null && $toolCalls === []) {
            throw CompletionFailed::malformed($this->driver(), 'no text or tool_use content block in the response');
        }

        $usage = $raw['usage'] ?? [];

        return new CompletionResponse(
            text: $text,
            toolCalls: $toolCalls,
            stopReason: $raw['stop_reason'] ?? null,
            promptTokens: is_array($usage) ? ($usage['input_tokens'] ?? null) : null,
            completionTokens: is_array($usage) ? ($usage['output_tokens'] ?? null) : null,
        );
    }

    /**
     * Text and tool calls out of Anthropic's `content` block array.
     *
     * Text blocks are joined rather than only the first being taken: a model
     * that says a sentence, calls a tool and says another sentence produces
     * three blocks, and reading `content[0]` alone would silently drop the
     * second half of what it said.
     *
     * @return array{0: string|null, 1: list<ToolCall>}
     */
    private function readContentBlocks(mixed $content): array
    {
        if (! is_array($content)) {
            return [null, []];
        }

        $text = [];
        $calls = [];

        foreach ($content as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text[] = $block['text'];

                continue;
            }

            if (($block['type'] ?? null) === 'tool_use' && is_string($block['name'] ?? null)) {
                $input = $block['input'] ?? [];

                $calls[] = new ToolCall(
                    id: is_string($block['id'] ?? null) ? $block['id'] : 'call_'.$index,
                    name: $block['name'],
                    arguments: is_array($input) ? $input : [],
                );
            }
        }

        return [$text === [] ? null : implode("\n", $text), $calls];
    }

    /**
     * Anthropic's `messages`, with the system prompt pulled out.
     *
     * Two of the four roles are not messages with a string in them:
     *
     * - an **assistant** turn carrying a `toolCallId` is the model's own
     *   request, and becomes a `tool_use` content block whose `input` is a
     *   real object — Anthropic wants the arguments decoded, unlike OpenAI,
     *   which wants them as a JSON string;
     * - a **tool** result is not a `tool` role at all. Anthropic has no such
     *   role: a result is a `tool_result` block inside a **user** turn.
     *
     * Consecutive blocks of the same kind are merged into one turn, because
     * Anthropic requires strictly alternating user and assistant turns and
     * rejects two of either in a row.
     *
     * @param  list<ChatMessage>  $messages
     * @return array{0: string|null, 1: list<array<string, mixed>>}
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

            if ($message->role === 'assistant' && $message->toolCallId !== null) {
                $decoded = json_decode($message->content, true);

                $this->appendBlock($out, 'assistant', [
                    'type' => 'tool_use',
                    'id' => $message->toolCallId,
                    'name' => $message->name,
                    'input' => is_array($decoded) ? $decoded : [],
                ]);

                continue;
            }

            if ($message->role === 'tool') {
                $this->appendBlock($out, 'user', [
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolCallId,
                    'content' => $message->content,
                ]);

                continue;
            }

            $out[] = ['role' => $message->role, 'content' => $message->content];
        }

        return [$system, $out];
    }

    /**
     * Add a content block, merging into the previous turn when it is the same
     * role and already block-shaped.
     *
     * @param  list<array<string, mixed>>  $out
     * @param  array<string, mixed>  $block
     */
    private function appendBlock(array &$out, string $role, array $block): void
    {
        $last = array_key_last($out);

        if ($last !== null && ($out[$last]['role'] ?? null) === $role && is_array($out[$last]['content'] ?? null)) {
            $out[$last]['content'][] = $block;

            return;
        }

        $out[] = ['role' => $role, 'content' => [$block]];
    }
}
