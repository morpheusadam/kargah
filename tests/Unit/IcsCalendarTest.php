<?php

namespace Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Modules\Project\Support\IcsCalendar;
use Modules\Project\Support\IcsEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ICS feed.
 *
 * Everything asserted here is something a calendar client checks and nothing
 * else does. There is no compiler warning for a `DTEND` that is off by a day, no
 * type error for a UTF-8 character split across a fold, and no exception for a
 * file that ends in a bare LF — the feed simply arrives wrong at somebody else's
 * software, where we cannot see it.
 *
 * Nothing here installs an ICS library to check the output. Unfolding on CRLF
 * plus a space and splitting is four lines of test, and a test that asserts the
 * bytes is more honest about what went over the wire than one that asserts a
 * third party could read them.
 */
class IcsCalendarTest extends TestCase
{
    /** A fixed DTSTAMP, so every expectation below is about the events. */
    private const STAMP = '20260701T090000Z';

    private function stamp(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-01 09:00:00', new DateTimeZone('UTC'));
    }

    private function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    /**
     * @param  list<IcsEvent>  $events
     */
    private function build(array $events, ?string $name = null): string
    {
        return IcsCalendar::build($events, $name, $this->stamp());
    }

    /** The physical lines, exactly as written, without the trailing empty one. */
    private function physicalLines(string $ics): array
    {
        $this->assertStringEndsWith("\r\n", $ics, 'the document does not end in CRLF');

        return explode("\r\n", substr($ics, 0, -2));
    }

    /** The logical lines: unfolded, which is what a reader parses. */
    private function lines(string $ics): array
    {
        return $this->physicalLines(str_replace("\r\n ", '', $ics));
    }

    /** The first logical line starting with `$prefix`, or null. */
    private function line(string $ics, string $prefix): ?string
    {
        foreach ($this->lines($ics) as $line) {
            if (str_starts_with($line, $prefix)) {
                return $line;
            }
        }

        return null;
    }

    private function event(string $summary = 'Ship the invoice', ...$overrides): IcsEvent
    {
        return new IcsEvent(...array_merge([
            'uid' => 'card-01jqx@kargah.local',
            'summary' => $summary,
            'start' => $this->utc('2026-07-31 12:00:00'),
        ], $overrides));
    }

    // ---------------------------------------------------------------- shape --

    public function test_a_minimal_calendar_carries_the_properties_a_client_requires(): void
    {
        $ics = $this->build([]);
        $lines = $this->lines($ics);

        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertSame('END:VCALENDAR', $lines[count($lines) - 1]);
        $this->assertContains('VERSION:2.0', $lines);
        $this->assertContains('PRODID:'.IcsCalendar::PRODID, $lines);
        $this->assertContains('METHOD:PUBLISH', $lines);
    }

    /**
     * CRLF is not cosmetic. A file with bare LF endings is rejected outright by
     * some clients and silently truncated by others, and the last line needs its
     * terminator as much as any other.
     */
    public function test_every_line_ends_in_crlf_including_the_last(): void
    {
        $ics = $this->build([$this->event()], 'Kargah');

        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $ics), 'a bare LF is present');
        $this->assertSame(0, preg_match('/\r(?!\n)/', $ics), 'a bare CR is present');
    }

    public function test_the_feed_name_is_published_for_the_clients_that_read_it(): void
    {
        $ics = $this->build([], 'Kargah — Due dates');

        $this->assertSame('X-WR-CALNAME:Kargah — Due dates', $this->line($ics, 'X-WR-CALNAME'));
    }

    /**
     * An empty feed is the normal state of a new board, and it must still be a
     * document rather than a truncated one.
     */
    public function test_an_empty_event_list_still_produces_a_parseable_calendar(): void
    {
        $ics = $this->build([]);

        $this->assertParses($ics);
        $this->assertNull($this->line($ics, 'BEGIN:VEVENT'));
    }

    public function test_a_calendar_of_events_parses(): void
    {
        $ics = $this->build([
            $this->event('One'),
            $this->event('Two', allDay: true),
            $this->event('Three', description: "line one\nline two", url: 'https://kargah.local/c/1', status: 'confirmed'),
        ]);

        $this->assertParses($ics);
        $this->assertCount(3, array_filter($this->lines($ics), fn ($l) => $l === 'BEGIN:VEVENT'));
    }

    // ------------------------------------------------------------- all-day --

    /**
     * 🔴 The classic bug. `DTEND` is exclusive, so a card due on 31 July ends on
     * 1 August. Written as 31 July it lands in the client a day early — which for
     * a one-day event means it does not appear at all.
     */
    public function test_an_all_day_event_ends_the_day_after_it_starts(): void
    {
        $ics = $this->build([$this->event(start: $this->utc('2026-07-31 00:00:00'), allDay: true)]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART;VALUE=DATE:20260731', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20260801', $lines);
    }

    public function test_a_multi_day_all_day_event_spans_from_its_first_day_to_past_its_last(): void
    {
        $ics = $this->build([$this->event(
            start: $this->utc('2026-07-30 00:00:00'),
            end: $this->utc('2026-08-02 00:00:00'),
            allDay: true,
        )]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART;VALUE=DATE:20260730', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20260803', $lines);
    }

    /**
     * A date has no timezone. Converting midnight in Istanbul to UTC would move
     * a card due on the 31st to the 30th — the same off-by-one arriving by a
     * different road.
     */
    public function test_an_all_day_date_is_read_in_its_own_timezone_rather_than_converted(): void
    {
        $ics = $this->build([$this->event(
            start: new DateTimeImmutable('2026-07-31 00:00:00', new DateTimeZone('Europe/Istanbul')),
            allDay: true,
        )]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART;VALUE=DATE:20260731', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20260801', $lines);
    }

    public function test_an_all_day_event_carries_no_time_of_day(): void
    {
        $ics = $this->build([$this->event(start: $this->utc('2026-07-31 16:45:00'), allDay: true)]);

        $this->assertContains('DTSTART;VALUE=DATE:20260731', $this->lines($ics));
        $this->assertStringNotContainsString('DTSTART:', $ics);
    }

    // --------------------------------------------------------------- timed --

    public function test_a_timed_event_is_stamped_in_utc(): void
    {
        $ics = $this->build([$this->event(
            start: $this->utc('2026-07-31 12:00:00'),
            end: $this->utc('2026-07-31 13:30:00'),
        )]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART:20260731T120000Z', $lines);
        $this->assertContains('DTEND:20260731T133000Z', $lines);
        $this->assertContains('DTSTAMP:'.self::STAMP, $lines);
    }

    /**
     * Converted, not relabelled: 15:30 in Istanbul is 12:30Z, and writing
     * `20260731T153000Z` would move every appointment three hours.
     */
    public function test_a_non_utc_time_is_converted_rather_than_relabelled(): void
    {
        $ics = $this->build([$this->event(
            start: new DateTimeImmutable('2026-07-31 15:30:00', new DateTimeZone('Europe/Istanbul')),
        )]);

        $this->assertContains('DTSTART:20260731T123000Z', $this->lines($ics));
        $this->assertStringNotContainsString('20260731T153000Z', $ics);
    }

    public function test_a_timed_event_without_an_end_emits_no_dtend(): void
    {
        $ics = $this->build([$this->event()]);

        $this->assertNull($this->line($ics, 'DTEND'));
    }

    // ------------------------------------------------------------ escaping --

    /**
     * The four escapes RFC 5545 defines, and nothing beyond them. Under-escaping
     * breaks the parse; over-escaping breaks the display; only the first of those
     * is loud.
     */
    #[DataProvider('textValues')]
    public function test_a_text_value_escapes_exactly_what_the_rfc_asks(string $raw, string $expected): void
    {
        $this->assertSame($expected, IcsCalendar::escapeText($raw));
    }

    public static function textValues(): array
    {
        return [
            'the four together' => ['a, b; c \\ d', 'a\\, b\\; c \\\\ d'],
            'a newline becomes a literal backslash-n' => ["first\nsecond", 'first\\nsecond'],
            'a CRLF is one escape, not two' => ["first\r\nsecond", 'first\\nsecond'],
            'a lone CR is a line break too' => ["first\rsecond", 'first\\nsecond'],
            'a colon is left alone' => ['Standup at 10:30', 'Standup at 10:30'],
            'a URL inside text keeps its slashes' => ['see https://kargah.local/x', 'see https://kargah.local/x'],
            'a quote is not special' => ['the "big" one', 'the "big" one'],
            'a backslash is doubled once, not twice' => ['C:\\path', 'C:\\\\path'],
            'nothing to do' => ['plain', 'plain'],
            // RFC 5545 §3.1: CONTROL is %x00-08 / %x0A-1F / %x7F — every control
            // except HTAB, which is legal inside a text value and is kept.
            'a control character is dropped, a tab survives' => ["kept\there\x01", "kept\there"],
            'multi-byte text is untouched' => ['İstanbul — şube', 'İstanbul — şube'],
        ];
    }

    public function test_a_summary_reaches_the_line_with_its_escapes_and_no_others(): void
    {
        $ics = $this->build([$this->event("a, b; c \\ d\nsecond line")]);

        $this->assertSame('SUMMARY:a\\, b\\; c \\\\ d\\nsecond line', $this->line($ics, 'SUMMARY'));
    }

    /**
     * A URL is a URI value, not a text value. Backslashing its commas hands the
     * client a link that 404s.
     */
    public function test_a_url_is_not_escaped_as_text(): void
    {
        $ics = $this->build([$this->event(url: 'https://kargah.local/cards?ids=1,2;view=cal')]);

        $this->assertSame('URL:https://kargah.local/cards?ids=1,2;view=cal', $this->line($ics, 'URL'));
    }

    // ------------------------------------------------------------- folding --

    public function test_a_long_ascii_line_folds_at_seventy_five_octets(): void
    {
        $ics = $this->build([$this->event(str_repeat('a', 200))]);
        $physical = $this->physicalLines($ics);

        $folded = array_values(array_filter($physical, fn ($l) => str_starts_with($l, 'SUMMARY:') || str_starts_with($l, ' a')));

        $this->assertGreaterThan(1, count($folded), 'the line did not fold at all');
        $this->assertSame(75, strlen($folded[0]), 'the first line is not a full 75 octets');

        foreach (array_slice($folded, 1) as $continuation) {
            $this->assertStringStartsWith(' ', $continuation, 'a continuation line does not begin with a space');
        }

        $this->assertSame('SUMMARY:'.str_repeat('a', 200), $this->line($ics, 'SUMMARY'));
    }

    /**
     * 🔴 The bug that survives every test written in English. Folding counts
     * octets, so the cut can land inside a multi-byte character — and the file
     * still parses, so the only symptom is mojibake in somebody else's client.
     *
     * The padding sweeps the boundary across the whole title so that at some
     * width a Turkish letter or an em dash straddles octet 75 exactly.
     */
    #[DataProvider('paddingWidths')]
    public function test_folding_never_splits_a_multi_byte_character(int $padding): void
    {
        $title = str_repeat('x', $padding)
            .'İstanbul şubesi — ödeme güncelleme raporu ğ ş İ ö ü ç — devam eden çok uzun bir kart başlığı';

        $ics = $this->build([$this->event($title)]);

        $this->assertTrue(mb_check_encoding($ics, 'UTF-8'), "padding {$padding} produced invalid UTF-8");
        $this->assertStringNotContainsString("\u{FFFD}", $ics);
        $this->assertSame('SUMMARY:'.$title, $this->line($ics, 'SUMMARY'), "padding {$padding} did not survive unfolding");

        foreach ($this->physicalLines($ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "padding {$padding} left a line of ".strlen($line).' octets');
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "padding {$padding} split a character");
        }
    }

    public static function paddingWidths(): array
    {
        return array_map(fn (int $i) => [$i], range(0, 8));
    }

    public function test_no_line_in_a_realistic_feed_exceeds_seventy_five_octets(): void
    {
        $ics = $this->build([
            $this->event(
                summary: str_repeat('Uzun başlık — ', 12),
                description: str_repeat("Açıklama; virgül, ters bölü \\ ve satır sonu\n", 6),
                url: 'https://kargah.local/boards/01jqx/cards/01jqy?from=calendar&highlight=due-date',
            ),
        ], str_repeat('Kargah — takvim ', 8));

        foreach ($this->physicalLines($ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "over-long line: {$line}");
        }
    }

    /**
     * Unfolding happens before unescaping, so a fold between a backslash and what
     * it escapes is legal — and enough readers get that order wrong that it is
     * worth one octet to avoid.
     */
    public function test_a_fold_never_separates_a_backslash_from_what_it_escapes(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $ics = $this->build([$this->event(str_repeat('b', $i).str_repeat('\\', 40).str_repeat('c', 60))]);

            foreach ($this->physicalLines($ics) as $line) {
                $this->assertFalse(
                    $this->endsInOddBackslashRun($line),
                    "offset {$i} folded in the middle of an escape: {$line}",
                );
            }
        }
    }

    // -------------------------------------------------------------- the UID --

    /**
     * A client keys an event by its UID: same UID updates, new UID duplicates. A
     * feed that regenerates its identifiers publishes a growing pile of copies.
     */
    public function test_a_uid_is_emitted_verbatim(): void
    {
        $ics = $this->build([new IcsEvent(
            uid: 'card-01jqx3v9e0000000000000000@kargah.local',
            summary: 'Ship it',
            start: $this->utc('2026-07-31 12:00:00'),
        )]);

        $this->assertSame('UID:card-01jqx3v9e0000000000000000@kargah.local', $this->line($ics, 'UID'));
    }

    public function test_an_empty_uid_is_refused_rather_than_invented(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IcsEvent(uid: '', summary: 'Ship it', start: $this->utc('2026-07-31 12:00:00'));
    }

    public function test_a_whitespace_uid_is_refused_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IcsEvent(uid: '   ', summary: 'Ship it', start: $this->utc('2026-07-31 12:00:00'));
    }

    // -------------------------------------------------------- determinism --

    /**
     * "Runs twice, changes nothing", applied to a serialiser. It is what lets the
     * HTTP layer hash the body into an ETag and answer a polling client with 304
     * instead of resending an unchanged feed every few minutes.
     */
    public function test_two_generations_with_the_same_stamp_are_byte_identical(): void
    {
        $events = [
            $this->event('One', allDay: true),
            $this->event('İki — ödeme', description: "a, b\nc", url: 'https://kargah.local/x', status: 'CONFIRMED'),
            $this->event('Three', end: $this->utc('2026-07-31 13:00:00'), lastModified: $this->utc('2026-06-01 08:00:00')),
        ];

        $this->assertSame($this->build($events, 'Kargah'), $this->build($events, 'Kargah'));
    }

    public function test_the_stamp_is_a_parameter_so_the_output_is_fixed(): void
    {
        $ics = $this->build([$this->event()]);

        $this->assertContains('DTSTAMP:'.self::STAMP, $this->lines($ics));
    }

    public function test_events_keep_the_order_they_were_given(): void
    {
        $summaries = array_values(array_filter(
            $this->lines($this->build([$this->event('Bravo'), $this->event('Alfa'), $this->event('Charlie')])),
            fn ($l) => str_starts_with($l, 'SUMMARY:'),
        ));

        $this->assertSame(['SUMMARY:Bravo', 'SUMMARY:Alfa', 'SUMMARY:Charlie'], $summaries);
    }

    // ----------------------------------------------------------- optionals --

    public function test_optional_properties_are_absent_rather_than_empty(): void
    {
        $ics = $this->build([$this->event()]);

        $this->assertNull($this->line($ics, 'DESCRIPTION'));
        $this->assertNull($this->line($ics, 'URL'));
        $this->assertNull($this->line($ics, 'STATUS'));
        $this->assertNull($this->line($ics, 'LAST-MODIFIED'));
        $this->assertNull($this->line($ics, 'X-WR-CALNAME'));
    }

    public function test_optional_properties_are_written_when_given(): void
    {
        $ics = $this->build([$this->event(
            description: 'Scope agreed',
            url: 'https://kargah.local/c/1',
            lastModified: $this->utc('2026-06-01 08:00:00'),
            status: 'cancelled',
        )]);
        $lines = $this->lines($ics);

        $this->assertContains('DESCRIPTION:Scope agreed', $lines);
        $this->assertContains('URL:https://kargah.local/c/1', $lines);
        $this->assertContains('LAST-MODIFIED:20260601T080000Z', $lines);
        $this->assertContains('STATUS:CANCELLED', $lines);
    }

    public function test_a_status_outside_the_rfc_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event(status: 'done');
    }

    public function test_an_end_before_its_start_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event(start: $this->utc('2026-07-31 12:00:00'), end: $this->utc('2026-07-31 11:00:00'));
    }

    public function test_an_all_day_event_may_start_and_end_on_the_same_day(): void
    {
        $ics = $this->build([$this->event(
            start: $this->utc('2026-07-31 00:00:00'),
            end: $this->utc('2026-07-31 00:00:00'),
            allDay: true,
        )]);

        $this->assertContains('DTEND;VALUE=DATE:20260801', $this->lines($ics));
    }

    // ------------------------------------------------------------- helpers --

    /**
     * A reader's job, done here rather than by a dependency: unfold, split, and
     * check that every component opens and closes in the right order.
     */
    private function assertParses(string $ics): void
    {
        $stack = [];

        foreach ($this->lines($ics) as $line) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9-]+[;:]/',
                $line,
                "not a content line: {$line}",
            );

            if (str_starts_with($line, 'BEGIN:')) {
                $stack[] = substr($line, 6);
            }

            if (str_starts_with($line, 'END:')) {
                $this->assertSame(array_pop($stack), substr($line, 4), 'components are not properly nested');
            }
        }

        $this->assertSame([], $stack, 'a component was never closed');
    }

    private function endsInOddBackslashRun(string $line): bool
    {
        $count = 0;

        for ($i = strlen($line) - 1; $i >= 0 && $line[$i] === '\\'; $i--) {
            $count++;
        }

        return $count % 2 === 1;
    }
}
