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
 * Nothing produces one of these yet; see `ToolDefinition`'s docblock for why
 * the type exists ahead of the feature.
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
