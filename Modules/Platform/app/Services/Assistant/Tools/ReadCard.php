<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Platform\Support\Scopes;
use Modules\Project\Contracts\BoardReader;

/**
 * One card in full — description, labels, members, checklist, where it lives.
 *
 * `BoardReader::findCard()` reads through the card's *origin* placement, so a
 * card mirrored onto three lists still answers with the one board it belongs
 * to, and names the others under `mirrored_onto`. That distinction is the
 * contract's, not this tool's, and it is why this reads through `findCard()`
 * rather than hunting the card down through `cardsForList()`.
 */
class ReadCard implements Tool
{
    use ReadsArguments;

    public const NAME = 'read_card';

    public function __construct(private readonly BoardReader $boards) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Read one card by its numeric id: description, due date, labels, members, checklist progress and which board and list it is on.';
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
                'card_id' => ['type' => 'integer', 'description' => 'The card id, as returned by read_board or cards_due_soon.'],
            ],
            'required' => ['card_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $id = $this->intArgument($arguments, 'card_id', null, 1);

        if ($id === null) {
            return ['error' => 'card_id is required and must be a number.'];
        }

        $card = $this->boards->findCard($id);

        if ($card === null) {
            return ['error' => 'There is no card with id '.$id.'.'];
        }

        return ['card' => $card];
    }
}
