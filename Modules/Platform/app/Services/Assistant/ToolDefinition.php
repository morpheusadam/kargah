<?php

namespace Modules\Platform\Services\Assistant;

/**
 * One tool a driver may offer the model, described the way every major
 * provider's tool-use API wants it: a name, a sentence saying what it does,
 * and a JSON Schema for its arguments.
 *
 * **This type was written before anything constructed one**, so that
 * `CompletionRequest::$tools` had something to be typed as and the tool layer,
 * when it arrived, would be a new caller rather than a new interface. It
 * arrived: `Modules\Platform\Services\Assistant\Tools\ToolRegistry::definitions()`
 * is the only thing that builds these, one per `Tools\Tool`, and not one
 * driver's `complete()` signature changed to accept them — only their bodies,
 * to translate `parameters` into whatever their provider calls the schema
 * (`function.parameters`, `input_schema`, `functionDeclarations[].parameters`).
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  A JSON Schema object, e.g.
     *                                            `['type' => 'object', 'properties' => [...], 'required' => [...]]`.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
