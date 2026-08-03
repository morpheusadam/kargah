<?php

namespace Modules\Platform\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The network policy every HTTP-backed driver shares.
 *
 * No retry, unlike `Modules\Accounting\Services\RateSources\HttpRateSource`.
 * A GET for a rate is free to repeat; a chat completion is usually billed per
 * call, so retrying automatically risks paying for — and returning — two
 * answers to one question. A caller that wants a retry can ask again.
 *
 * `post()` is also where the three distinguishable failure states from
 * `CompletionFailed`'s docblock actually get told apart: a `ConnectionException`
 * whose message mentions a certificate is this machine's known `cURL error 60`
 * (no CA bundle configured — see `project-guaid/DECISIONS.md`, "Environment"),
 * a 401 or 403 is the provider naming a credentials problem, and anything else
 * that failed is a provider error with whatever detail it gave.
 *
 * Two OpenAI-compatible providers (`OpenAiDriver`, `OpenRouterDriver`) and one
 * that offers an OpenAI-compatible endpoint alongside its own
 * (`OllamaDriver`) share the same request and response shape, so
 * `toOpenAiMessages()` and `mapChatCompletionsResponse()` live here rather
 * than being copied three times.
 */
abstract class HttpAssistantDriver implements AssistantDriver
{
    /** Chat completions run longer than a rate fetch; a small model can still take several seconds. */
    protected const TIMEOUT = 25;

    /** Deliberately short — a host that refuses the connection outright is not going to answer if we wait longer. */
    protected const CONNECT_TIMEOUT = 6;

    protected function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(static::TIMEOUT)
            ->connectTimeout(static::CONNECT_TIMEOUT);
    }

    /**
     * One POST, decoded, with every way it can go wrong named.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     *
     * @throws CompletionFailed
     */
    protected function post(string $url, array $headers, array $body): array
    {
        try {
            $response = $this->request()->withHeaders($headers)->post($url, $body);
        } catch (ConnectionException $e) {
            if (str_contains($e->getMessage(), 'certificate')) {
                throw CompletionFailed::tlsUnverified($this->driver(), $e->getMessage());
            }

            throw CompletionFailed::unreachable($this->driver(), $e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw CompletionFailed::credentialsRejected($this->driver(), $this->errorDetail($response));
        }

        if ($response->failed()) {
            throw CompletionFailed::providerError($this->driver(), $response->status().' '.$this->errorDetail($response));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw CompletionFailed::malformed($this->driver(), 'the body did not decode as JSON');
        }

        return $decoded;
    }

    /** Whatever detail the provider's own error body offers, kept short enough for a toast. */
    private function errorDetail(Response $response): string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return str($response->body())->limit(200)->toString();
    }

    /**
     * Messages in the shape every OpenAI-compatible chat endpoint wants.
     *
     * Three roles pass straight through. The two that do not are the halves of
     * a tool round trip:
     *
     * - an **assistant** message carrying a `toolCallId` is not a turn with
     *   text in it, it is the model's own request to call something, and
     *   OpenAI spells that as `content: null` plus a `tool_calls` array whose
     *   `arguments` is a **JSON string**, not an object;
     * - a **tool** message is the result, and pairs back to the request by
     *   `tool_call_id`.
     *
     * Consecutive assistant tool-call messages are merged into one turn rather
     * than emitted as several. A model that asks for three tools in one
     * response made *one* assistant turn, and sending three — each answering
     * nothing — is rejected by the API as an unmatched call.
     *
     * @param  list<ChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    protected function toOpenAiMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message->toolCallId,
                    'content' => $message->content,
                ];

                continue;
            }

            if ($message->role === 'assistant' && $message->toolCallId !== null) {
                $call = [
                    'id' => $message->toolCallId,
                    'type' => 'function',
                    'function' => ['name' => $message->name, 'arguments' => $message->content],
                ];

                $last = array_key_last($out);

                if ($last !== null && ($out[$last]['role'] ?? null) === 'assistant' && isset($out[$last]['tool_calls'])) {
                    $out[$last]['tool_calls'][] = $call;

                    continue;
                }

                $out[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]];

                continue;
            }

            $out[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $out;
    }

    /**
     * The tool catalogue in the OpenAI function-calling shape.
     *
     * `ToolDefinition::$parameters` is already a JSON Schema object, which is
     * exactly what `function.parameters` wants — the tool layer was built to
     * this shape on purpose, so nothing is translated here beyond the wrapper.
     *
     * @param  list<ToolDefinition>  $tools
     * @return list<array<string, mixed>>
     */
    protected function toOpenAiTools(array $tools): array
    {
        return array_map(
            static fn (ToolDefinition $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters,
                ],
            ],
            $tools,
        );
    }

    /**
     * Tool calls off an OpenAI-compatible response, if the model made any.
     *
     * `arguments` arrives as a JSON *string*, and a model does occasionally
     * emit one that does not parse. That is decoded to an empty argument list
     * rather than thrown on: the tool will answer "x is required", which the
     * model can correct on the next turn, where an exception would end the
     * conversation over one malformed field.
     *
     * @param  array<array-key, mixed>  $rawCalls
     * @return list<ToolCall>
     */
    protected function mapOpenAiToolCalls(array $rawCalls): array
    {
        $calls = [];

        foreach ($rawCalls as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $name = $raw['function']['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $arguments = $raw['function']['arguments'] ?? null;
            $decoded = is_array($arguments)
                ? $arguments
                : json_decode(is_string($arguments) ? $arguments : '', true);

            $calls[] = new ToolCall(
                id: is_string($raw['id'] ?? null) ? $raw['id'] : 'call_'.$index,
                name: $name,
                arguments: is_array($decoded) ? $decoded : [],
            );
        }

        return $calls;
    }

    /**
     * A Chat Completions-shaped response, mapped to ours.
     *
     * @param  array<array-key, mixed>  $raw
     *
     * @throws CompletionFailed
     */
    protected function mapChatCompletionsResponse(array $raw): CompletionResponse
    {
        $choice = $raw['choices'][0] ?? null;

        if (! is_array($choice)) {
            throw CompletionFailed::malformed($this->driver(), 'no choices in the response');
        }

        $text = $choice['message']['content'] ?? null;

        if ($text !== null && ! is_string($text)) {
            throw CompletionFailed::malformed($this->driver(), 'the message content was not text');
        }

        // A model calling a tool answers with `content: null` and a
        // `tool_calls` array, so the absence of text is not a malformed
        // response here — it is the other half of the shape.
        $rawCalls = $choice['message']['tool_calls'] ?? [];
        $toolCalls = is_array($rawCalls) ? $this->mapOpenAiToolCalls($rawCalls) : [];

        $usage = $raw['usage'] ?? [];

        return new CompletionResponse(
            text: $text,
            toolCalls: $toolCalls,
            stopReason: $choice['finish_reason'] ?? null,
            promptTokens: is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null,
            completionTokens: is_array($usage) ? ($usage['completion_tokens'] ?? null) : null,
        );
    }
}
