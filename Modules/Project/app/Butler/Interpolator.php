<?php

namespace Modules\Project\Butler;

use Modules\Project\Models\Card;

/**
 * `{card name}` and friends, resolved against the card an action is running on.
 *
 * Trello writes its variables with spaces inside the braces, so this does too —
 * `{card name}`, not `{card_name}`. A person writing a comment template should
 * not have to remember a second spelling for something they can already read.
 *
 * An unknown variable is left exactly as it was typed. Replacing it with an
 * empty string would silently eat a literal brace somebody meant to keep, and
 * leaving it visible is how they find out they misspelled it.
 */
final class Interpolator
{
    /**
     * The variables the builder advertises, with a one-line description each.
     *
     * @var array<string, string>
     */
    public const VARIABLES = [
        '{card name}' => "the card's title",
        '{card number}' => 'its per-board number, e.g. 42',
        '{list name}' => 'the list the card lives in',
        '{board name}' => 'the board it lives on',
        '{due date}' => 'its due date, or "no due date"',
        '{start date}' => 'its start date, or "no start date"',
        '{member}' => 'the first member on the card',
        '{members}' => 'every member on the card',
        '{user}' => 'whoever caused this to run',
        '{date}' => "today's date",
        '{time}' => 'the time right now',
    ];

    public static function render(string $template, Card $card): string
    {
        if (! str_contains($template, '{')) {
            return $template;
        }

        $values = self::values($card);

        return (string) preg_replace_callback(
            '/\{([a-z][a-z ]*)\}/i',
            function (array $match) use ($values): string {
                $key = '{'.mb_strtolower(trim($match[1])).'}';

                return $values[$key] ?? $match[0];
            },
            $template,
        );
    }

    /** @return array<string, string> */
    private static function values(Card $card): array
    {
        $list = $card->list;
        $members = $card->relationLoaded('members') ? $card->members : $card->members()->get();

        return [
            '{card name}' => (string) $card->title,
            '{card number}' => $card->number === null ? '' : (string) $card->number,
            '{list name}' => $list?->name ?? '',
            '{board name}' => $list?->board?->name ?? '',
            '{due date}' => $card->due_on?->format('j M Y') ?? 'no due date',
            '{start date}' => $card->start_on?->format('j M Y') ?? 'no start date',
            '{member}' => (string) ($members->first()?->name ?? 'nobody'),
            '{members}' => $members->isEmpty() ? 'nobody' : $members->pluck('name')->join(', ', ' and '),
            '{user}' => auth()->user()?->name ?? 'Butler',
            '{date}' => now()->format('j M Y'),
            '{time}' => now()->format('H:i'),
        ];
    }
}
