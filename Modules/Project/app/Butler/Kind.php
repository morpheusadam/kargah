<?php

namespace Modules\Project\Butler;

/**
 * The three synchronous Butler command types.
 *
 * Trello has five. The other two — calendar commands and due-date commands —
 * are deliberately absent: both are "at some clock time, sweep the board", and
 * a sweep needs the cron job that spec 06 lists as separate infrastructure.
 * Nothing here reaches for a scheduler, which is what makes all three of these
 * run inside the request that caused them.
 */
final class Kind
{
    public const RULE = 'rule';

    public const CARD_BUTTON = 'card_button';

    public const BOARD_BUTTON = 'board_button';

    /** @var array<string, string> */
    public const LABELS = [
        self::RULE => 'Rule',
        self::CARD_BUTTON => 'Card button',
        self::BOARD_BUTTON => 'Board button',
    ];

    /** @var array<string, string> */
    public const DESCRIPTIONS = [
        self::RULE => 'Runs on its own the moment its trigger happens.',
        self::CARD_BUTTON => 'A button on the card back. Runs on that card.',
        self::BOARD_BUTTON => 'A button in the board sidebar. Runs on every card that matches its conditions.',
    ];

    public static function isValid(string $kind): bool
    {
        return array_key_exists($kind, self::LABELS);
    }

    public static function isButton(string $kind): bool
    {
        return $kind === self::CARD_BUTTON || $kind === self::BOARD_BUTTON;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }
}
