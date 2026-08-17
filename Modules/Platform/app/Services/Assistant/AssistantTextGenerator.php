<?php

namespace Modules\Platform\Services\Assistant;

use Modules\Core\Contracts\TextGenerationFailed;
use Modules\Core\Contracts\TextGenerator;
use Modules\Platform\Models\AssistantProvider;

/**
 * `Modules\Core\Contracts\TextGenerator`, answered by whichever provider the
 * operator made the default on the assistant settings page.
 *
 * The whole of this class is the adapter between a one-prompt interface and the
 * conversation-shaped one underneath. It exists so that a feature module can ask
 * for generated text without depending on Platform, which nothing may — see the
 * interface's own docblock for why that rule is worth an adapter.
 *
 * **Provider resolution is copied from `KargahAsk::resolveProvider()` on
 * purpose**: active rows, the default first, then the oldest. Two places
 * answering "which provider" differently would be a support conversation nobody
 * could win, and a single-provider install — which is every install of this so
 * far — never notices the ordering at all.
 *
 * The provider is resolved per call rather than held. A settings page that
 * changes the default has to take effect on the next call, not on the next
 * deploy, and a queue worker here lives for fifty seconds and handles several
 * jobs.
 */
class AssistantTextGenerator implements TextGenerator
{
    public function __construct(private readonly Assistant $assistant) {}

    public function unavailableReason(): ?string
    {
        $provider = $this->provider();

        if ($provider === null) {
            return 'no AI provider is configured. Add one at Settings → Assistant '
                .'(/settings/assistant); Gemini, OpenRouter and a local Ollama endpoint all have a free option.';
        }

        try {
            $driver = $this->assistant->driverFor($provider->driver);
        } catch (\InvalidArgumentException $e) {
            // A `driver` string written by an older version of Kargah. Worth
            // naming rather than crashing a nightly command.
            return $e->getMessage();
        }

        $reason = $driver->unavailableReason($provider);

        return $reason === null ? null : $provider->name.' cannot be used: '.$reason;
    }

    public function generate(string $prompt, ?string $system = null, ?int $maxTokens = null): string
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            throw TextGenerationFailed::unavailable($reason);
        }

        // Not null: `unavailableReason()` returned null, which it only does with
        // a provider in hand.
        $provider = $this->provider();

        $messages = [];

        if ($system !== null && trim($system) !== '') {
            $messages[] = new ChatMessage('system', $system);
        }

        $messages[] = new ChatMessage('user', $prompt);

        try {
            $response = $this->assistant->driverFor($provider->driver)->complete(
                $provider,
                new CompletionRequest(
                    messages: $messages,
                    maxTokens: $maxTokens,
                    // Low, not zero. This writes the same kind of copy every day
                    // and a channel whose sentences are visibly stamped from one
                    // mould reads worse than one with a little variation — but
                    // the prompt asks for a strict shape, and a high temperature
                    // is how a model starts improvising around it.
                    temperature: 0.4,
                ),
            );
        } catch (CompletionFailed $e) {
            throw TextGenerationFailed::refused($provider->name, $e->getMessage());
        }

        $text = trim((string) $response->text);

        if ($text === '') {
            throw TextGenerationFailed::empty($provider->name);
        }

        return $text;
    }

    /** The default provider, or the oldest active one, or none. */
    private function provider(): ?AssistantProvider
    {
        return AssistantProvider::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
