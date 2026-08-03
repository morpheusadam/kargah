<?php

namespace Modules\Project\Support;

use League\CommonMark\CommonMarkConverter;

/**
 * Markdown to HTML, safe to echo unescaped.
 *
 * A card description, a card comment and a board description are all user
 * input rendered back as HTML — the one place in the front end that needs a
 * `{!! !!}`. Everything that would make that dangerous is turned off here
 * rather than trusted to whoever calls it: raw HTML in the source is
 * stripped rather than passed through, and a link whose scheme is not
 * http(s)/mailto — `javascript:` chief among them — is refused.
 *
 * `league/commonmark` is already installed transitively via `laravel/framework`
 * (2.8.3); nothing new was added to build this.
 */
final class Markdown
{
    private static ?CommonMarkConverter $converter = null;

    /** Render markdown to HTML. Blank or null input renders nothing. */
    public static function toHtml(?string $source): string
    {
        $source = trim((string) $source);

        if ($source === '') {
            return '';
        }

        return (string) self::converter()->convert($source);
    }

    private static function converter(): CommonMarkConverter
    {
        return self::$converter ??= new CommonMarkConverter([
            // Raw HTML in the source — a pasted <script>, an <img onerror>
            // — is dropped rather than escaped into view. Escaping would
            // still be safe, but stripping is the less surprising result of
            // pasting stray angle brackets into a description.
            'html_input' => 'strip',

            // A link whose scheme is not http(s)/mailto is rendered with no
            // href — refuses `javascript:` and similar without needing a
            // second pass over the output.
            'allow_unsafe_links' => false,
        ]);
    }
}
