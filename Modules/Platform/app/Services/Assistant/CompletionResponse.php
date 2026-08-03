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
 * No driver populates `$toolCalls` yet, because nothing offers a
 * `CompletionRequest::$tools` list yet. It is part of the type from the start
 * for the same reason `$tools` is: the day the tool layer exists, it is a new
 * caller and a new driver body, not a new interface.
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
