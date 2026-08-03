<?php

namespace Modules\Platform\Services\Assistant;

/**
 * One tool a driver may offer the model, described the way every major
 * provider's tool-use API wants it: a name, a sentence saying what it does,
 * and a JSON Schema for its arguments.
 *
 * Nothing constructs one of these yet — `07-platform.md`'s tool layer (search,
 * read a board, draft an invoice, …) is later work, built against the
 * `Modules\*\Contracts` the API already reads. This type exists now so
 * `CompletionRequest::$tools` has something to be typed as, and so the day the
 * tool layer arrives it hands the driver a `list<ToolDefinition>` rather than
 * forcing every driver's `complete()` signature to change under it.
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  A JSON Schema object, e.g.
     *                                             `['type' => 'object', 'properties' => [...], 'required' => [...]]`.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
