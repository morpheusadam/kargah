<?php

namespace Modules\Platform\Services\Assistant;

/**
 * One request for a completion, provider-agnostic.
 *
 * `$tools` was on the constructor before any caller filled it, which is what
 * made the tool layer cheap to add: a driver written against
 * `list<ToolDefinition> $tools = []` never had to change its `complete()`
 * signature, only its body. `AssistantConversation` fills it from
 * `Tools\ToolRegistry::definitions()`; it stays empty for a caller that wants
 * a plain completion, such as the settings page's "test this connection"
 * button, and the drivers omit the key from the wire entirely in that case.
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
