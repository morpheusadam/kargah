<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\Assistant;
use Modules\Platform\Services\Assistant\AssistantConversation;
use Modules\Platform\Services\Assistant\ToolCall;
use Modules\Platform\Services\Assistant\Tools\ToolRegistry;
use Modules\Platform\Support\AssistantDrivers;

/**
 * `php artisan kargah:ask "what is overdue?"`
 *
 * The assistant from a terminal, and the reason `07-platform.md` says the CLI
 * "costs almost nothing once the tool layer exists": everything here is
 * argument parsing, provider selection and printing. The loop itself is
 * `AssistantConversation`, and the tools are the same catalogue the settings
 * page and the API surface can use.
 *
 * **Every failure is a sentence, never a stack trace.** This command is the
 * first thing anyone runs after configuring a provider, so the four ways it
 * can fail before the model says a word — no provider configured, a named
 * provider that does not exist, a provider with no key, a driver string
 * nothing implements — each print what to do about it and exit non-zero.
 * `--verbose` turns on the tool trace and nothing else; without it the output
 * is the answer alone, so `kargah:ask … | pbcopy` is useful.
 *
 * **It makes no network request against `FakeAssistantDriver`**, which is the
 * only way it can be exercised on the development machine: `php.ini` there
 * sets no CA bundle, so every real provider fails with cURL error 60 before it
 * is asked anything. Swap the driver through `Assistant::swap()` and the whole
 * command — selection, loop, tool execution, printing — runs in-process.
 */
class KargahAsk extends Command
{
    protected $signature = 'kargah:ask
        {question : What to ask, in quotes}
        {--provider= : Which configured provider to use, by name or by driver. Defaults to the default one.}
        {--steps= : How many tool-calling rounds to allow before giving up. Defaults to '.AssistantConversation::MAX_STEPS.'.}';

    protected $description = 'Ask the assistant a question, letting it read Kargah through the tool catalogue';

    public function handle(Assistant $assistant, AssistantConversation $conversation, ToolRegistry $tools): int
    {
        $question = trim((string) $this->argument('question'));

        if ($question === '') {
            $this->components->error('Ask something: php artisan kargah:ask "what is overdue?"');

            return self::FAILURE;
        }

        $provider = $this->resolveProvider();

        if ($provider === null) {
            return self::FAILURE;
        }

        // Asked before anything is sent, exactly as the settings page's test
        // button does: a row with no key is that, and reporting it as a
        // network failure one line later sends whoever is reading hunting for
        // the wrong problem.
        try {
            $driver = $assistant->driverFor($provider->driver);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($reason = $driver->unavailableReason($provider)) {
            $this->components->error(
                $provider->label().' cannot be asked anything: '.$reason.'. Fix it at Settings → Assistant.',
            );

            return self::FAILURE;
        }

        if ($this->output->isVerbose()) {
            $this->components->twoColumnDetail('Provider', $provider->label().' ('.$provider->effectiveModel().')');
            $this->components->twoColumnDetail('Tools', implode(', ', $tools->names()));
            $this->newLine();
        }

        $steps = $this->option('steps') === null ? null : max(1, (int) $this->option('steps'));

        try {
            $answer = $conversation->ask($provider, $question, $this->traceCallback(), $steps);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            // CompletionFailed is a RuntimeException and already reads as a
            // sentence naming an actionable cause — a missing key, a rejected
            // key, a certificate this machine cannot verify. So is the
            // out-of-steps failure. Neither is worth a trace.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($answer === '') {
            $this->components->warn($provider->label().' answered with nothing at all.');

            return self::FAILURE;
        }

        $this->line($answer);

        return self::SUCCESS;
    }

    /**
     * Which provider to ask.
     *
     * Prints its own error and returns null rather than throwing, so the two
     * "nothing to ask" cases — none configured at all, and a `--provider` that
     * matches nothing — each get the sentence that fits them. The default
     * ordering is the settings page's own: the default row first, then the
     * oldest active one, so a single-provider install never needs the flag.
     */
    private function resolveProvider(): ?AssistantProvider
    {
        $wanted = $this->option('provider');

        $query = AssistantProvider::query()->active();

        if ($wanted !== null && $wanted !== '') {
            $provider = (clone $query)
                ->where(fn ($q) => $q->where('name', $wanted)->orWhere('driver', $wanted))
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if ($provider === null) {
                $configured = AssistantProvider::query()->active()->pluck('name')->all();

                $this->components->error(
                    'No active assistant provider is called "'.$wanted.'".'
                    .($configured === []
                        ? ' None is configured at all.'
                        : ' The configured ones are: '.implode(', ', $configured).'.'),
                );

                return null;
            }

            return $provider;
        }

        $provider = $query->orderByDesc('is_default')->orderBy('id')->first();

        if ($provider === null) {
            $this->components->error(
                'No assistant provider is configured, so there is nothing to ask. '
                .'Add one at Settings → Assistant (/settings/assistant) — '
                .implode(', ', array_map(AssistantDrivers::label(...), AssistantDrivers::keys()))
                .' are supported, and Gemini, OpenRouter and a local Ollama endpoint all have a free option.',
            );

            return null;
        }

        return $provider;
    }

    /**
     * The `-v` trace: what was called, with what, and what came back.
     *
     * Null when not verbose, so the ordinary run prints the answer and nothing
     * else — a command whose output is piped somewhere should not narrate.
     * Results are truncated because a board with forty cards is not something
     * anyone wants scrolled past on the way to the answer; the full result
     * still goes to the model.
     *
     * @return callable(ToolCall, array<string, mixed>): void|null
     */
    private function traceCallback(): ?callable
    {
        if (! $this->output->isVerbose()) {
            return null;
        }

        return function (ToolCall $call, array $result): void {
            $arguments = json_encode($call->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            $this->components->twoColumnDetail(
                '<fg=cyan>'.$call->name.'</>',
                $arguments === '[]' || $arguments === '{}' ? 'no arguments' : (string) $arguments,
            );

            $this->line('  '.str((string) $encoded)->limit(500)->toString());
            $this->newLine();
        };
    }
}
