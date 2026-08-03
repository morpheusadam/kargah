<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\Tools\ToolRegistry;

/**
 * One question, however many tool calls it takes, one answer.
 *
 * The loop `07-platform.md` describes without naming: ask the provider with the
 * catalogue attached, run whatever it asks for, hand the results back, ask
 * again, and stop when it answers in words instead. It lives here rather than
 * inside `KargahAsk` so the settings page's assistant panel — and anything else
 * that later wants an answer rather than a completion — does not have to
 * re-implement it, and so the loop can be exercised without a console.
 *
 * **The iteration cap is not a tuning knob, it is a safety belt.** A model that
 * misreads a tool result will cheerfully call the same tool with the same
 * arguments forever, and each turn is a billed request to a third party. So the
 * loop is bounded and the bound is small: running out of steps is reported as a
 * failure with the tools it did call, never as a shrug and an empty answer.
 *
 * **Every tool call is executed, then the whole batch is reported back in one
 * turn.** Providers may ask for several calls in a single response, and
 * answering only the first leaves the others dangling — Anthropic and OpenAI
 * both reject a follow-up that does not account for every call they made.
 *
 * Nothing here talks to the network itself; it goes through whichever
 * `AssistantDriver` the registry has for the row's driver, which is what lets
 * the whole thing run against `FakeAssistantDriver` with no CA bundle in sight.
 */
class AssistantConversation
{
    /**
     * How many times round the loop before giving up.
     *
     * Six is enough for a genuinely multi-step question — find the customer,
     * read their invoices, read one of them, check the totals, answer — and
     * short enough that a confused model costs five wasted requests rather
     * than an afternoon.
     */
    public const MAX_STEPS = 6;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * Ask, and keep answering tool calls until the model stops making them.
     *
     * @param  callable(ToolCall, array<string, mixed>): void|null  $onToolCall  Called after each tool runs, with the
     *                                                                           call and its result. This is how `-v`
     *                                                                           prints a trace without this class
     *                                                                           knowing what a console is.
     * @param  list<string>|null  $scopes  Offer only the tools a credential holding these scopes may use; null for all.
     *
     * @throws CompletionFailed when the provider cannot be reached or refuses
     * @throws \InvalidArgumentException when no driver is registered for the provider's driver string
     * @throws \RuntimeException when the model is still calling tools after `$maxSteps` turns
     */
    public function ask(
        AssistantProvider $provider,
        string $question,
        ?callable $onToolCall = null,
        ?int $maxSteps = null,
        ?array $scopes = null,
    ): string {
        $driver = $this->assistant->driverFor($provider->driver);

        $definitions = $this->tools->definitions($scopes);

        $messages = [
            new ChatMessage('system', $this->systemPrompt()),
            new ChatMessage('user', $question),
        ];

        $called = [];
        $steps = max(1, $maxSteps ?? self::MAX_STEPS);

        for ($step = 0; $step < $steps; $step++) {
            $response = $driver->complete($provider, new CompletionRequest(
                messages: $messages,
                tools: $definitions,
            ));

            if (! $response->isToolCall()) {
                return trim((string) $response->text);
            }

            // The assistant's own turn has to be in the transcript before its
            // results are, or a provider that validates the pairing sees tool
            // results answering nothing. `ChatMessage` carries the id and the
            // name; each driver decides what its provider wants that to look
            // like on the wire.
            foreach ($response->toolCalls as $call) {
                $messages[] = new ChatMessage(
                    role: 'assistant',
                    content: $this->encode($call->arguments),
                    toolCallId: $call->id,
                    name: $call->name,
                );
            }

            foreach ($response->toolCalls as $call) {
                $result = $this->tools->run($call->name, $call->arguments);

                $called[] = $call->name;

                if ($onToolCall !== null) {
                    $onToolCall($call, $result);
                }

                $messages[] = new ChatMessage(
                    role: 'tool',
                    content: $this->encode($result),
                    toolCallId: $call->id,
                    name: $call->name,
                );
            }
        }

        throw new \RuntimeException(
            'The assistant was still calling tools after '.$steps.' '.str('step')->plural($steps).' and was stopped. '
            .'It called: '.implode(', ', $called).'.',
        );
    }

    /**
     * What the model is told before the question.
     *
     * Three things, and they are all rules the rest of Kargah already enforces
     * somewhere it cannot reach a language model:
     *
     * - **Use the tools, do not guess.** A model with no tool result will
     *   invent an invoice number rather than say it does not know.
     * - **Money is a string.** `03-accounting.md` is why every amount crosses
     *   the contract boundary as `{amount: "1500.000000", …}`, and a model
     *   that reformats it to `1500.0` has undone that at the last possible
     *   moment.
     * - **Currencies do not add.** Same reason `InvoiceReader::totals()`
     *   answers per currency and refuses to produce one number.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You are the assistant inside Kargah, a single-person freelance workspace holding this owner\'s',
            'boards, cards, customers, invoices, expenses and email.',
            '',
            'Answer from the tools, never from memory or guesswork. If no tool can answer the question, say so',
            'plainly and say which information is missing — do not invent an id, a number or a date.',
            '',
            'Money always arrives as a string with its own currency, for example {"amount": "1500.000000",',
            '"currency": "USD", "formatted": "$1,500.00"}. Quote the formatted figure as given. Never add or',
            'convert between currencies: report each currency as its own separate figure.',
            '',
            'Be brief. This answer is printed in a terminal, so plain sentences, no headings and no markdown tables.',
        ]);
    }

    /**
     * A tool result on its way back to the provider.
     *
     * `JSON_PARTIAL_OUTPUT_ON_ERROR` rather than a bare `json_encode`: a single
     * malformed UTF-8 byte anywhere in a customer's name — which is exactly the
     * kind of thing arriving from an IMAP mailbox — otherwise turns the whole
     * result into `false` and the model receives the string "false" as the
     * answer to its question.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
