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
     * @param  list<ChatMessage>  $messages
     * @return list<array{role: string, content: string}>
     */
    protected function toOpenAiMessages(array $messages): array
    {
        return array_map(
            fn (ChatMessage $message): array => ['role' => $message->role, 'content' => $message->content],
            $messages,
        );
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

        $usage = $raw['usage'] ?? [];

        return new CompletionResponse(
            text: $text,
            stopReason: $choice['finish_reason'] ?? null,
            promptTokens: is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null,
            completionTokens: is_array($usage) ? ($usage['completion_tokens'] ?? null) : null,
        );
    }
}
