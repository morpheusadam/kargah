<?php

namespace Modules\Platform\Services\Assistant;

/**
 * One turn in a conversation, on its way to a provider.
 *
 * `role` is one of `system`, `user`, `assistant` or `tool`. The first three are
 * used from the day this ships; `tool` is not populated yet but is spelled out
 * now so a tool-calling layer can hand a driver the *result* of a call without
 * a new type — see `CompletionResponse::$toolCalls` for the other half of that
 * round trip.
 *
 * `toolCallId` and `name` are both null until then. When they are used, they
 * answer the question every provider's tool-result message has to answer:
 * which call is this the result of, and which tool produced it. Anthropic and
 * OpenAI spell the pairing differently on the wire — a driver's job is to
 * translate this into whichever shape its provider wants, not the other way
 * round.
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
