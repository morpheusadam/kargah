<?php

namespace Modules\Platform\Support;

/**
 * The AI providers Kargah can talk to, and everything the settings page needs
 * to draw one — the same role `Modules\Mailbox\Support\Senders` plays for
 * delivery providers.
 *
 * A code constant rather than a config entry, for the same reason `Senders`
 * is one: none of it is an install decision. The driver name is what
 * `Modules\Platform\Services\Assistant\Assistant` registers it under, the
 * default model is whichever one the provider's free or cheapest tier
 * currently favours, and the icon has to be a name that actually exists in
 * the keenicons bundle — checked against
 * `public/assets/vendors/keenicons/styles.bundle.css` before use, per
 * `docs/frontend-conventions.md`.
 *
 * `requiresKey` is what makes Ollama/LM Studio provable as a real case rather
 * than a special one: it is `false` and `requiresBaseUrl` is `true`, and
 * nothing else in the settings page or the model's validation needs a branch
 * for "the local one" — they just read these two flags.
 *
 * Every class string is written out whole, never assembled from a driver
 * name: Tailwind's scanner reads source as text, so `'text-'.$driver` would
 * be invisible to it.
 */
final class AssistantDrivers
{
    public const GEMINI = 'gemini';

    public const OPENROUTER = 'openrouter';

    public const ANTHROPIC = 'anthropic';

    public const OPENAI = 'openai';

    public const OLLAMA = 'ollama';

    /**
     * @return array<string, array{
     *     label: string,
     *     icon: string,
     *     tone: string,
     *     summary: string,
     *     requiresKey: bool,
     *     requiresBaseUrl: bool,
     *     defaultModel: string,
     *     modelPlaceholder: string,
     *     keyPlaceholder: string|null,
     *     keyHint: string|null,
     *     baseUrlPlaceholder: string|null,
     *     baseUrlHint: string|null,
     * }>
     */
    public static function all(): array
    {
        return [
            self::GEMINI => [
                'label' => 'Google Gemini',
                'icon' => 'ki-abstract-26',
                'tone' => 'text-info',
                'summary' => 'A genuinely usable free tier. Good default.',
                'requiresKey' => true,
                'requiresBaseUrl' => false,
                'defaultModel' => 'gemini-1.5-flash',
                'modelPlaceholder' => 'gemini-1.5-flash',
                'keyPlaceholder' => 'AIza…',
                'keyHint' => 'From Google AI Studio (aistudio.google.com/apikey).',
                'baseUrlPlaceholder' => null,
                'baseUrlHint' => null,
            ],
            self::OPENROUTER => [
                'label' => 'OpenRouter',
                'icon' => 'ki-technology-4',
                'tone' => 'text-primary',
                'summary' => 'One key, many models, several free.',
                'requiresKey' => true,
                'requiresBaseUrl' => false,
                'defaultModel' => 'meta-llama/llama-3.1-8b-instruct:free',
                'modelPlaceholder' => 'meta-llama/llama-3.1-8b-instruct:free',
                'keyPlaceholder' => 'sk-or-…',
                'keyHint' => 'From openrouter.ai/keys. Model names ending in :free cost nothing.',
                'baseUrlPlaceholder' => null,
                'baseUrlHint' => null,
            ],
            self::ANTHROPIC => [
                'label' => 'Anthropic Claude',
                'icon' => 'ki-flash-circle',
                'tone' => 'text-warning',
                'summary' => 'Paid; the best at tool use.',
                'requiresKey' => true,
                'requiresBaseUrl' => false,
                'defaultModel' => 'claude-3-5-haiku-latest',
                'modelPlaceholder' => 'claude-3-5-haiku-latest',
                'keyPlaceholder' => 'sk-ant-…',
                'keyHint' => 'From console.anthropic.com. Billed per token; there is no free tier.',
                'baseUrlPlaceholder' => null,
                'baseUrlHint' => null,
            ],
            self::OPENAI => [
                'label' => 'OpenAI',
                'icon' => 'ki-cloud',
                'tone' => 'text-success',
                'summary' => 'Paid.',
                'requiresKey' => true,
                'requiresBaseUrl' => false,
                'defaultModel' => 'gpt-4o-mini',
                'modelPlaceholder' => 'gpt-4o-mini',
                'keyPlaceholder' => 'sk-…',
                'keyHint' => 'From platform.openai.com/api-keys. Billed per token; there is no free tier.',
                'baseUrlPlaceholder' => null,
                'baseUrlHint' => null,
            ],
            self::OLLAMA => [
                'label' => 'Ollama / LM Studio',
                'icon' => 'ki-laptop',
                'tone' => 'text-secondary-foreground',
                'summary' => 'A local endpoint. No key, no cost, works offline.',
                'requiresKey' => false,
                'requiresBaseUrl' => true,
                'defaultModel' => 'llama3.1',
                'modelPlaceholder' => 'llama3.1',
                'keyPlaceholder' => null,
                'keyHint' => null,
                'baseUrlPlaceholder' => 'http://127.0.0.1:11434',
                'baseUrlHint' => 'Ollama\'s default port is 11434; LM Studio\'s is usually 1234. Reachable from this server, not from your browser.',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $driver): bool
    {
        return array_key_exists($driver, self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $driver): ?array
    {
        return self::all()[$driver] ?? null;
    }

    public static function label(string $driver): string
    {
        return self::all()[$driver]['label'] ?? ucfirst($driver);
    }

    public static function icon(string $driver): string
    {
        return self::all()[$driver]['icon'] ?? 'ki-abstract-26';
    }

    public static function tone(string $driver): string
    {
        return self::all()[$driver]['tone'] ?? 'text-secondary-foreground';
    }

    public static function summary(string $driver): string
    {
        return self::all()[$driver]['summary'] ?? '';
    }

    public static function requiresKey(string $driver): bool
    {
        return self::all()[$driver]['requiresKey'] ?? true;
    }

    public static function requiresBaseUrl(string $driver): bool
    {
        return self::all()[$driver]['requiresBaseUrl'] ?? false;
    }

    public static function defaultModel(string $driver): string
    {
        return self::all()[$driver]['defaultModel'] ?? '';
    }
}
