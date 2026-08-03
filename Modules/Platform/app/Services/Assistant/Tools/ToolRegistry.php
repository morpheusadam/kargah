<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Platform\Services\Assistant\ToolDefinition;

/**
 * Which tools the assistant may be offered, and how to run one.
 *
 * Factories, not instances — the same shape as
 * `Modules\Platform\Services\Assistant\Assistant` and
 * `Modules\Mailbox\Services\Delivery\Delivery`, for a reason that applies more
 * strongly here than it does to either: every tool holds a contract resolved
 * out of the container, so constructing all of them eagerly would resolve
 * every reader in five modules at the moment Platform's service provider
 * registers, on every request, including the ones that never mention the
 * assistant. Registered under the name the model calls the tool by, which each
 * implementation also publishes as a `NAME` constant, so the factory can be
 * bound without asking an instance for its own name.
 *
 * Registered as a singleton so a tool swapped in a test's `setUp` is the same
 * registry the CLI resolves through. Nothing here is static, for the reason
 * `Delivery`'s docblock gives: this application must not assume a fresh
 * process per request.
 *
 * **`run()` never throws.** A tool that fails — a bad argument, a contract
 * that raised, a name the model invented — comes back as
 * `['error' => '…']`, because that string is going straight back to the model
 * as the result of its call, and a model told "there is no tool called
 * read_boards, the tools are: …" corrects itself on the next turn. An
 * exception at that point would end the conversation over a typo. The one
 * thing it does not do is swallow the detail: the message says what went
 * wrong.
 */
class ToolRegistry
{
    /** @var array<string, callable(): Tool> */
    private array $factories = [];

    /** @var array<string, Tool> */
    private array $resolved = [];

    /**
     * Bind a tool name to a factory.
     *
     * Called once per tool by the service provider, and again by a test to
     * replace one. Replacing drops any instance already built from the old
     * factory, so the swap takes effect even mid-request.
     *
     * @param  callable(): Tool  $factory
     */
    public function extend(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;

        unset($this->resolved[$name]);
    }

    /** Swap in a ready-made tool. The convenience a test wants over `extend`. */
    public function register(Tool $tool): void
    {
        $this->extend($tool->name(), fn (): Tool => $tool);
    }

    /** Forget a tool entirely — the only way to offer the model a catalogue without one. */
    public function forget(string $name): void
    {
        unset($this->factories[$name], $this->resolved[$name]);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->factories);
    }

    /**
     * The tool for a name.
     *
     * @throws \InvalidArgumentException when no tool is registered under it
     */
    public function get(string $name): Tool
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $factory = $this->factories[$name] ?? null;

        if ($factory === null) {
            throw new \InvalidArgumentException(
                'There is no tool called "'.$name.'". The tools are: '.implode(', ', $this->names()).'.',
            );
        }

        return $this->resolved[$name] = $factory();
    }

    /** @return list<string> Every registered name, in registration order. */
    public function names(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Every tool, constructed.
     *
     * @param  list<string>|null  $scopes  Only tools whose `scope()` is in this list; null for all of them.
     * @return list<Tool>
     */
    public function all(?array $scopes = null): array
    {
        $tools = array_map(fn (string $name): Tool => $this->get($name), $this->names());

        if ($scopes === null) {
            return array_values($tools);
        }

        return array_values(array_filter(
            $tools,
            static fn (Tool $tool): bool => in_array($tool->scope(), $scopes, true),
        ));
    }

    /**
     * The whole catalogue, in the shape a driver already expects.
     *
     * This is the join between the tool layer and the provider layer, and it is
     * deliberately the only one: `CompletionRequest::$tools` has been typed
     * `list<ToolDefinition>` since the drivers were written, so handing over
     * the catalogue costs no change to any driver's signature — exactly what
     * `ToolDefinition`'s own docblock said building the type early would buy.
     *
     * @param  list<string>|null  $scopes  Offer only the tools a credential holding these scopes may use.
     * @return list<ToolDefinition>
     */
    public function definitions(?array $scopes = null): array
    {
        return array_map(
            static fn (Tool $tool): ToolDefinition => new ToolDefinition(
                name: $tool->name(),
                description: $tool->description(),
                parameters: $tool->parameters(),
            ),
            $this->all($scopes),
        );
    }

    /**
     * Run one tool call and answer with something that always encodes as JSON.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(string $name, array $arguments): array
    {
        try {
            $result = $this->get($name)->execute($arguments);
        } catch (\Throwable $e) {
            // Deliberately the message and not the trace. This string is sent
            // to a third-party provider as the result of a tool call; a stack
            // trace would ship absolute paths off this machine, and would tell
            // the model nothing it could act on.
            return ['error' => $name.' failed: '.$e->getMessage()];
        }

        return $result;
    }
}
