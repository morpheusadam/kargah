<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Platform\Support\Scopes;
use Modules\Project\Contracts\BoardReader;

/**
 * One board with its lists, and optionally the cards on them.
 *
 * "Read a board" is the first tool `07-platform.md` names, and a board is three
 * contract calls rather than one — `findBoard()`, `listsForBoard()`, then
 * `cardsForList()` per column. Doing all three here rather than making them
 * three tools is the one place in this set a tool is not a single contract
 * call, and it is deliberate: a model asked "what is on the Acme board" would
 * otherwise need four round trips through the provider to answer, and each one
 * costs a whole completion.
 *
 * `include_cards` exists because the cheap version of this question — "what
 * columns does this board have" — should not drag every card on the board
 * through a third-party provider's context window. Archived lists are filtered
 * out here rather than by the contract, which returns them on purpose so a
 * settings page can show them; an assistant summarising a board wants what the
 * board draws.
 */
class ReadBoard implements Tool
{
    use ReadsArguments;

    public const NAME = 'read_board';

    public function __construct(private readonly BoardReader $boards) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Read one board by slug: its lists in column order, and the cards on each list. '
            .'Call list_boards first if you do not already have the slug.';
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
                'board_slug' => ['type' => 'string', 'description' => 'The board slug, as returned by list_boards.'],
                'include_cards' => ['type' => 'boolean', 'description' => 'Include the cards on each list. Defaults to true.'],
            ],
            'required' => ['board_slug'],
        ];
    }

    public function execute(array $arguments): array
    {
        $slug = $this->stringArgument($arguments, 'board_slug');

        if ($slug === '') {
            return ['error' => 'board_slug is required. Call list_boards to find one.'];
        }

        $board = $this->boards->findBoard($slug);

        if ($board === null) {
            return ['error' => 'There is no board with the slug "'.$slug.'". Call list_boards to see the real ones.'];
        }

        $includeCards = ! array_key_exists('include_cards', $arguments) || $this->boolArgument($arguments, 'include_cards', true);

        $lists = [];

        foreach ($this->boards->listsForBoard($slug) as $list) {
            if ($list['is_archived']) {
                continue;
            }

            $row = ['id' => $list['id'], 'name' => $list['name']];

            if ($includeCards) {
                $row['cards'] = $this->boards->cardsForList($list['id']);
            }

            $lists[] = $row;
        }

        return ['board' => $board, 'lists' => $lists];
    }
}
