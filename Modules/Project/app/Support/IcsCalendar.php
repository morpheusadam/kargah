<?php

namespace Modules\Project\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * An RFC 5545 iCalendar (`.ics`) document, built out of strings.
 *
 * A pure function of its input: no models, no Eloquent, no container, no clock
 * unless you decline to pass one. It takes a list of `IcsEvent` and returns the
 * bytes a calendar client will accept. Publishing them on a signed URL, and
 * deciding which cards become events, belong to whatever calls this.
 *
 * ## Why this is hand-rolled and stays hand-rolled
 *
 * `01-architecture.md` targets shared hosting: no daemon, no binary, no shell.
 * Every mature ICS library either drags in a tree of dependencies or shells out
 * for the parsing half we do not need. This is a few hundred lines of string
 * building against a specification that has not moved since 2009 — a composer
 * dependency here would buy nothing and cost a deploy constraint.
 *
 * ## The four things that decide whether a client accepts the file
 *
 * **Line endings are CRLF, everywhere, including after the last line.** RFC 5545
 * §3.1. A file ending in a bare LF is rejected outright by some clients and
 * silently truncated by others.
 *
 * **A content line is at most 75 octets** — octets, not characters — and folds
 * onto a continuation line beginning with a single space. Counting characters
 * instead of octets is the bug that matters here: it does not misbehave on
 * ASCII, so it survives every test written in English and then splits a UTF-8
 * codepoint down the middle the first time somebody titles a card in Turkish.
 * The client shows mojibake and there is nothing in the file to explain it. See
 * `fold()`.
 *
 * **Text values escape backslash, semicolon, comma and newline — and nothing
 * else.** A colon is *not* escaped inside a text value, however much it looks
 * like a separator: escaping it is the common over-correction and it puts a
 * literal backslash in front of every "10:30" the user reads. See `escapeText()`.
 *
 * **All-day events are dates with an exclusive end.** See `IcsEvent::exclusiveEnd()`.
 *
 * ## Determinism
 *
 * The same events in the same order with the same `$stamp` produce **byte-
 * identical** output. Nothing here sorts, hashes, randomises or reads a clock on
 * a path the caller has not chosen. That is this project's "runs twice, changes
 * nothing" rule applied to a serialiser, and it is what lets an HTTP caller hash
 * the body into an `ETag` and answer a polling client with `304 Not Modified`
 * instead of regenerating and re-sending an unchanged feed every few minutes.
 *
 * `DTSTAMP` is the one field that legitimately moves between generations, which
 * is exactly why it is a parameter: pass the feed's own last-modified time and
 * the output stops changing when the data does not.
 */
final class IcsCalendar
{
    /**
     * RFC 5545 §3.7.3 wants a product identifier that is unique to the software.
     * The `-//` prefix marks it as a privately-registered identifier, which is
     * correct for an application that is not in the IANA registry.
     */
    public const PRODID = '-//Kargah//Project Calendar//EN';

    /** RFC 5545 §3.1: octets in a content line, before folding. */
    public const MAX_OCTETS = 75;

    public const CRLF = "\r\n";

    /**
     * The whole `VCALENDAR` document, ready to be served as `text/calendar`.
     *
     * @param  list<IcsEvent>  $events  emitted in the order given; nothing is sorted
     * @param  ?string  $name  `X-WR-CALNAME`, the label most clients show in the
     *                         sidebar. Not in RFC 5545 — an Apple extension that
     *                         Google, Apple and Thunderbird all honour, and that
     *                         anything else ignores harmlessly.
     * @param  ?DateTimeInterface  $stamp  `DTSTAMP` for every event. Defaults to now,
     *                                     which is correct for a live feed and useless
     *                                     for a test — pass it and the output is fixed.
     */
    public static function build(
        array $events,
        ?string $name = null,
        ?DateTimeInterface $stamp = null,
        string $prodId = self::PRODID,
    ): string {
        $stamp = $stamp === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($stamp);

        $lines = [
            'BEGIN:VCALENDAR',
            // VERSION first after BEGIN is convention rather than rule, but a
            // handful of readers give up on a component whose version they have
            // not seen yet.
            'VERSION:2.0',
            self::text('PRODID', $prodId),
            'CALSCALE:GREGORIAN',
            // A read-only published feed, as opposed to an invitation exchange.
            'METHOD:PUBLISH',
        ];

        if ($name !== null && $name !== '') {
            $lines[] = self::text('X-WR-CALNAME', $name);
        }

        foreach ($events as $event) {
            foreach (self::eventLines($event, $stamp) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'END:VCALENDAR';

        $out = '';

        foreach ($lines as $line) {
            $out .= self::fold($line).self::CRLF;
        }

        return $out;
    }

    /**
     * Escape a value of type TEXT — RFC 5545 §3.3.11.
     *
     * Four escapes and no others: `\` becomes `\\`, `;` becomes `\;`, `,`
     * becomes `\,`, and any line break becomes a literal `\n`. The backslash
     * goes first or the escapes escape each other.
     *
     * 🔴 A colon is **not** escaped. The RFC lists `\:` as something a parser
     * must *tolerate*, not something a writer may produce, and escaping it means
     * every time and every `https://` in a description reaches the user with a
     * backslash in front of it. Under-escaping breaks the parse; over-escaping
     * breaks the display. Both are wrong and only one of them is obvious.
     *
     * Remaining control characters are dropped rather than escaped, because
     * RFC 5545 has no representation for them and a raw `\x01` in a card title —
     * pasted from somewhere, as these things are — would make the whole feed
     * unparseable. The pattern is deliberately byte-wise and not `/u`: every byte
     * it matches is ASCII, and a UTF-8 continuation byte is always ≥ `\x80`, so
     * it cannot touch a multi-byte character. A `/u` pattern would instead return
     * null on the first malformed byte and silently blank the value.
     */
    public static function escapeText(string $value): string
    {
        $value = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
    }

    /**
     * Fold one content line to 75 octets per RFC 5545 §3.1.
     *
     * The continuation is CRLF followed by a single space, and that space counts
     * against the 75 — so the first line carries 75 octets and every line after
     * it carries 74. Unfolding, which every reader does before parsing anything,
     * strips the CRLF and the one space that follows it.
     *
     * Two things this does that a naïve `chunk_split()` does not:
     *
     * 🔴 **It never splits a UTF-8 codepoint.** `strlen` is the right measure —
     * the limit is octets — but the cut has to land on a character boundary, so
     * a cut that would fall on a continuation byte (`10xxxxxx`) walks backwards
     * to the lead byte. Without this, a Turkish `ş` or an em dash straddling the
     * 75th octet arrives at the client as two replacement characters, and the
     * file still parses, so nothing anywhere reports a problem.
     *
     * **It never splits a `\` from what it escapes.** Unfolding happens before
     * unescaping, so `\` + CRLF + space + `n` is legal and round-trips through a
     * conformant reader — but enough readers in the wild get that order wrong
     * that the one-octet retreat is worth more than the octet it costs. The
     * retreat always flips the parity of the trailing backslash run, so it is
     * needed at most once per fold, and a backslash is never a continuation byte
     * so it cannot undo the boundary correction above.
     */
    public static function fold(string $line): string
    {
        $length = strlen($line);

        if ($length <= self::MAX_OCTETS) {
            return $line;
        }

        $out = '';
        $offset = 0;
        $limit = self::MAX_OCTETS;

        while (true) {
            if ($length - $offset <= $limit) {
                $out .= substr($line, $offset);

                break;
            }

            $cut = $offset + $limit;

            // Walk back off a continuation byte onto the lead byte of the
            // character it belongs to. At most three steps for valid UTF-8; the
            // offset guard keeps malformed input from stalling.
            while ($cut > $offset + 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            if ($cut > $offset + 1 && self::endsInOddBackslashRun($line, $offset, $cut)) {
                $cut--;
            }

            $out .= substr($line, $offset, $cut - $offset).self::CRLF.' ';
            $offset = $cut;
            $limit = self::MAX_OCTETS - 1;
        }

        return $out;
    }

    /**
     * The lines of one `VEVENT`, unfolded.
     *
     * @return list<string>
     */
    private static function eventLines(IcsEvent $event, DateTimeImmutable $stamp): array
    {
        $lines = [
            'BEGIN:VEVENT',
            // UID is a TEXT value, so it is escaped like one. A UID built the way
            // the class note asks — id plus host — contains nothing escapable and
            // therefore comes out byte-for-byte as it went in.
            self::text('UID', $event->uid),
            'DTSTAMP:'.self::utc($stamp),
        ];

        if ($event->allDay) {
            // VALUE=DATE overrides the default DATE-TIME for this property only.
            // The date is read in the value's own zone — see IcsEvent.
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->exclusiveEnd()->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.self::utc($event->start);

            if ($event->end !== null) {
                $lines[] = 'DTEND:'.self::utc($event->end);
            }
        }

        $lines[] = self::text('SUMMARY', $event->summary);

        if ($event->description !== null && $event->description !== '') {
            $lines[] = self::text('DESCRIPTION', $event->description);
        }

        if ($event->url !== null && $event->url !== '') {
            $lines[] = 'URL:'.self::uri($event->url);
        }

        if ($event->status !== null) {
            $lines[] = 'STATUS:'.$event->status;
        }

        if ($event->lastModified !== null) {
            $lines[] = 'LAST-MODIFIED:'.self::utc($event->lastModified);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** `NAME:escaped-value` for a property whose value type is TEXT. */
    private static function text(string $name, string $value): string
    {
        return $name.':'.self::escapeText($value);
    }

    /**
     * A DATE-TIME in UTC — `YYYYMMDDTHHMMSSZ`, RFC 5545 §3.3.5 form two.
     *
     * The project stores timestamps in UTC and renders them in the user's
     * timezone; a feed read by a client we do not control is exactly the case
     * where UTC on the wire is right, because the client applies its own zone.
     * The conversion is real: a value arriving in Europe/Istanbul is moved, not
     * relabelled, so 15:30 +03 is written as 12:30:00Z.
     */
    private static function utc(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * A value of type URI — RFC 5545 §3.3.13 — which is *not* escaped as text.
     *
     * A URI's commas and semicolons are part of the address, and backslashing
     * them hands the client a link that 404s. What must not survive is anything
     * that would end the content line early or smuggle in a property, so control
     * characters and whitespace are dropped; a URL that needs a space in it needs
     * it percent-encoded before it gets here.
     */
    private static function uri(string $uri): string
    {
        return preg_replace('/[\x00-\x20\x7F]/', '', $uri) ?? '';
    }

    /** Whether the bytes ending at `$cut` finish with an odd number of backslashes. */
    private static function endsInOddBackslashRun(string $line, int $offset, int $cut): bool
    {
        $count = 0;

        for ($i = $cut - 1; $i >= $offset && $line[$i] === '\\'; $i--) {
            $count++;
        }

        return $count % 2 === 1;
    }
}
