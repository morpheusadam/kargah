<?php

namespace Modules\Platform\Services\Assistant\Tools;

/**
 * One thing the assistant can actually do inside Kargah.
 *
 * `07-platform.md` is explicit about what a tool is allowed to reach: "the
 * assistant reaches Kargah through the same contracts the API does, with the
 * same scopes. It does not get privileged access." So every implementation in
 * this directory takes a `Modules\<X>\Contracts\…` interface in its constructor
 * and holds nothing else. **No tool may import another module's `Models`** —
 * that is Platform's whole boundary rule, and a tool is the most tempting place
 * in the module to break it, because a model would be so much quicker to reach
 * for than a contract that does not quite have the method you wanted. When a
 * contract does not have the method, the answer is to say so in the report, not
 * to reach past it.
 *
 * The four members map one-for-one onto what every provider's tool-use API
 * wants — `ToolRegistry::definitions()` turns a `Tool` into the
 * `ToolDefinition` a driver already knows how to carry, so adding a tool never
 * touches a driver.
 *
 * `scope()` is the fifth, and it is Kargah's rather than the provider's. A tool
 * names the `Modules\Platform\Support\Scopes` constant it needs, so a caller
 * holding an application password can offer the model only the tools that
 * credential is allowed to use — see `ToolRegistry::definitions()`'s `$scopes`
 * argument. A tool that is never offered cannot be called, which is a better
 * guarantee than one that is offered and refused at execution time: a model
 * that can see a tool will keep trying to use it.
 */
interface Tool
{
    /**
     * The name the model calls it by — `snake_case`, and stable.
     *
     * Every implementation also exposes it as a `NAME` constant, so
     * `PlatformServiceProvider` can register a factory under the right key
     * without constructing the tool (and therefore without resolving the
     * contract behind it) just to ask its name.
     */
    public function name(): string;

    /**
     * One sentence saying what it does, written for the model rather than for
     * a developer. This is the only documentation the model gets, and a vague
     * one is why an assistant calls the wrong tool.
     */
    public function description(): string;

    /**
     * A JSON Schema object describing the arguments.
     *
     * `['type' => 'object', 'properties' => [...], 'required' => [...]]`. A
     * tool that takes no arguments still returns `type: object` with an empty
     * *object* of properties — `(object) []`, not `[]`, because `json_encode`
     * turns an empty PHP array into `[]` and every provider rejects that as a
     * schema.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /** The `Modules\Platform\Support\Scopes` constant a credential needs to use this tool. */
    public function scope(): string;

    /**
     * Run it, and answer with something that survives `json_encode`.
     *
     * **Plain arrays, scalars and nulls only** — never a model, never a
     * Carbon, never a Collection of objects. The result goes back to the
     * provider as a JSON string, so anything that does not encode cleanly
     * either throws or arrives as `{}`.
     *
     * A tool that was asked for something that does not exist returns
     * `['error' => '…']` rather than throwing: "no customer with that id" is
     * an answer the model can act on, and one it frequently causes itself by
     * guessing an id. Throwing is for a tool that could not run at all —
     * `ToolRegistry::run()` catches that and turns it into the same shape.
     *
     * @param  array<string, mixed>  $arguments  As the model sent them, already JSON-decoded. Untrusted: a model
     *                                           will send a string where the schema said integer, omit a required
     *                                           key, and invent one that is not in the schema at all.
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array;
}
