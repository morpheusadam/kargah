<?php

namespace Modules\Platform\Services\Assistant;

/**
 * What a provider handed back, mapped to one shape regardless of which
 * provider it came from.
 *
 * `$text` and `$toolCalls` are both here, and a driver returns one or the
 * other depending on what the model actually did — a plain answer sets
 * `$text` and leaves `$toolCalls` empty; a model asking to call a tool sets
 * `$toolCalls` and leaves `$text` null. `isToolCall()` is the one place that
 * distinction is spelled out, so a caller does not re-derive it from
 * `$text === null` at every call site.
 *
 * All five drivers populate `$toolCalls` now, and it cost each of them a
 * change of body and none of them a change of signature — which is the whole
 * reason the field was part of the type before anything produced one.
 *
 * A model may do both at once: Anthropic and Gemini both allow a turn that
 * says a sentence *and* calls a tool, so `$text` being set does not mean
 * `$toolCalls` is empty. `isToolCall()` is what a caller should branch on —
 * `AssistantConversation` treats any response with tool calls as a step to be
 * answered rather than as the final word, whatever text came with it.
 */
final readonly class CompletionResponse
{
    /** @param  list<ToolCall>  $toolCalls */
    public function __construct(
        public ?string $text,
        public array $toolCalls = [],
        public ?string $stopReason = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {}

    public function isToolCall(): bool
    {
        return $this->toolCalls !== [];
    }
}
