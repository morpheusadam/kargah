<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Platform\Support\Scopes;
use Modules\Project\Contracts\BoardReader;

/**
 * Every board, so the model can find a slug.
 *
 * `ReadBoard` keys off a slug, and nothing else in the catalogue produces one;
 * without this the model would guess `"acme"` from the word "Acme" in the
 * question and be wrong about half the time.
 */
class ListBoards implements Tool
{
    use ReadsArguments;

    public const NAME = 'list_boards';

    public function __construct(private readonly BoardReader $boards) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'List every project board with its name and slug. Call this to find the slug read_board needs.';
    }

    public function scope(): string
    {
        return Scopes::PROJECT_READ;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_archived' => ['type' => 'boolean', 'description' => 'Include archived boards. Defaults to false.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $boards = $this->boards->boards($this->boolArgument($arguments, 'include_archived'));

        return ['count' => count($boards), 'boards' => $boards];
    }
}
