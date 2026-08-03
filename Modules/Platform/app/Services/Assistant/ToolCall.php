<?php

namespace Modules\Platform\Services\Assistant;

/**
 * A tool the model chose to call, decoded off the wire.
 *
 * `id` is the provider's own identifier for the call — Anthropic's
 * `tool_use.id`, OpenAI's `tool_calls[].id` — and it is what a `ChatMessage`
 * carrying the result must echo back as `toolCallId`, so the provider can
 * match the answer to the question it asked.
 *
 * All five drivers produce these now. Gemini is the one provider whose
 * function calls carry no identifier of their own, so `GeminiDriver`
 * synthesises one from the part's position — `id` is not nullable, because a
 * caller that cannot pair a result back to its call has nothing to send back.
 */
final readonly class ToolCall
{
    /** @param  array<string, mixed>  $arguments  Already decoded from the provider's JSON string. */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
