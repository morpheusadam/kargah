<?php

namespace Modules\Platform\Services\Assistant;

/**
 * One request for a completion, provider-agnostic.
 *
 * `$tools` is empty for every caller today — the tool layer in `07-platform.md`
 * is not built yet — and is on the constructor from the start anyway. The
 * point of building the interface now rather than when the tools arrive is
 * exactly this: a driver written against `list<ToolDefinition> $tools = []`
 * never has to change its `complete()` signature later, only its body, and
 * every driver already in place keeps working unmodified.
 */
final readonly class CompletionRequest
{
    /**
     * @param  list<ChatMessage>  $messages
     * @param  list<ToolDefinition>  $tools
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public ?int $maxTokens = null,
        public ?float $temperature = null,
    ) {}
}
