<?php

namespace Modules\Platform\Services\Assistant;

/**
 * One turn in a conversation, on its way to a provider.
 *
 * `role` is one of `system`, `user`, `assistant` or `tool`.
 * `AssistantConversation` uses all four: `tool` carries the result of a call,
 * and an `assistant` message carrying a `toolCallId` is not a turn with text
 * in it but the model's own *request* to call something, played back into the
 * transcript so that the result following it is answering something.
 *
 * `toolCallId` and `name` answer the question every provider's tool round trip
 * has to answer: which call is this, and which tool produced it. **No two
 * providers spell that the same way**, and translating it is the driver's job,
 * not the caller's — OpenAI wants `tool_calls[].function.arguments` as a JSON
 * *string* and has a `tool` role; Anthropic wants a decoded object in a
 * `tool_use` block and has no `tool` role at all, putting results in a `user`
 * turn; Gemini wants `functionCall`/`functionResponse` parts and pairs them by
 * name rather than by id.
 */
final readonly class ChatMessage
{
    public function __construct(
        public string $role,
        public string $content,
        public ?string $toolCallId = null,
        public ?string $name = null,
    ) {}
}
