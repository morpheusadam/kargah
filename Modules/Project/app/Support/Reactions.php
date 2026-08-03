<?php

namespace Modules\Project\Support;

/**
 * The emoji a comment can be reacted with.
 *
 * GitHub's eight, and deliberately only those. A free-text emoji picker turns
 * a tally into a long tail of one-offs — the point of a reaction is that
 * several people can land on the same one — and it also turns
 * `comment_reactions.emoji` into a column nothing can validate.
 *
 * A class with a constant rather than an enum: an enum's cases have to be
 * valid PHP identifiers, so every emoji would need a name invented for it
 * (`ThumbsUp`, `Tada`) and every read site would go through `->value`. Nothing
 * here needs behaviour per emoji, only the list and a membership test, so the
 * list is the class.
 *
 * The order is the order they are drawn in, both in the picker and in the
 * chips under a comment — a stable order is what stops the same three
 * reactions rearranging themselves every time somebody adds a fourth.
 */
final class Reactions
{
    /** @var list<string> */
    public const SET = ['👍', '👎', '😄', '🎉', '😕', '❤️', '🚀', '👀'];

    /** Whether this is one of the eight, and therefore storable. */
    public static function has(string $emoji): bool
    {
        return in_array($emoji, self::SET, true);
    }

    /**
     * What the picker shows as a tooltip, and what a screen reader reads
     * instead of the character itself.
     */
    public static function name(string $emoji): string
    {
        return match ($emoji) {
            '👍' => 'Thumbs up',
            '👎' => 'Thumbs down',
            '😄' => 'Laugh',
            '🎉' => 'Celebrate',
            '😕' => 'Confused',
            '❤️' => 'Heart',
            '🚀' => 'Rocket',
            '👀' => 'Eyes',
            default => 'Reaction',
        };
    }

    /**
     * The set's own order, as a sort key. Anything not in the set sorts past
     * the end of it, so a row left behind by an older set still draws — last
     * — rather than throwing.
     */
    public static function order(string $emoji): int
    {
        $index = array_search($emoji, self::SET, true);

        return $index === false ? count(self::SET) : $index;
    }
}
