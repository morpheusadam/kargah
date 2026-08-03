<?php

namespace Modules\Project\Support;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One `VEVENT`, as a value object. Inert, immutable, and free of the app.
 *
 * This knows nothing about cards, checklist items, Eloquent, the container or
 * the clock. It is the whole contract between whatever collects due dates and
 * `IcsCalendar`, which turns a list of these into bytes.
 *
 * ## The UID must be stable, and that is the caller's job
 *
 * 🔴 **Never generate the UID here, and never generate it randomly there.** A
 * calendar client keys an event by its UID: it *updates* the event it already
 * holds when the UID matches and *creates a second one* when it does not. A feed
 * that mints a fresh UID on every regeneration therefore does not publish a
 * calendar, it publishes an ever-growing pile of duplicates, and the user's only
 * remedy is to unsubscribe. The UID must be derived from what the event *is* —
 * `card-{ulid}@{host}` or `checklist-item-{ulid}@{host}` — so that the same card
 * yields the same string next week, next deploy, and after a database restore.
 * It is required for exactly this reason: an empty one is refused rather than
 * defaulted.
 *
 * The domain part is a convention rather than a rule (RFC 5545 §3.8.4.7 asks
 * only for a globally unique string), but it is what keeps two Kargah instances
 * subscribed to by the same person from colliding.
 *
 * ## All-day events are dates, not instants
 *
 * When `allDay` is true the *calendar date* is read off the value in its own
 * timezone and no conversion happens — a date has no timezone, and converting
 * midnight in Istanbul to UTC would move a card due on 31 July to the 30th.
 * Pass the date as the user sees it.
 *
 * `end` is **inclusive** and means "the last day the event covers", because that
 * is what a card's date range means to the person who typed it. The exclusive
 * `DTEND` the RFC wants is computed by `exclusiveEnd()`; see the note there.
 *
 * When `allDay` is false, `start` and `end` are instants and are written to the
 * wire in UTC whatever timezone they arrive in.
 *
 * ## Carbon
 *
 * Any `DateTimeInterface` is accepted, including a mutable `Carbon` straight off
 * an Eloquent cast, and is normalised to `DateTimeImmutable` on the way in — so
 * mutating the Carbon afterwards cannot reach back into an event already built.
 */
final class IcsEvent
{
    /** The only `STATUS` values RFC 5545 §3.8.1.11 defines for a `VEVENT`. */
    public const STATUSES = ['TENTATIVE', 'CONFIRMED', 'CANCELLED'];

    /** The start. A date when `allDay`, an instant otherwise. */
    public readonly DateTimeImmutable $start;

    /** The end. Inclusive and a date when `allDay`, an exclusive instant otherwise. */
    public readonly ?DateTimeImmutable $end;

    public readonly ?DateTimeImmutable $lastModified;

    /** One of `STATUSES`, upper-cased, or null. */
    public readonly ?string $status;

    /**
     * @param  string  $uid  stable across regenerations — read the class note
     * @param  string  $summary  the line the client shows; the card title
     * @param  bool  $allDay  a date-valued event rather than a timed one
     * @param  ?string  $description  free text; newlines survive as `\n`
     * @param  ?string  $url  a link back into the app, written as a URI
     *
     * @throws InvalidArgumentException on an empty UID, an end before the start,
     *                                  or a status outside `STATUSES`
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $summary,
        DateTimeInterface $start,
        ?DateTimeInterface $end = null,
        public readonly bool $allDay = false,
        public readonly ?string $description = null,
        public readonly ?string $url = null,
        ?DateTimeInterface $lastModified = null,
        ?string $status = null,
    ) {
        if (trim($uid) === '') {
            throw new InvalidArgumentException(
                'An ICS event needs a UID that is stable across regenerations. '
                .'Derive it from the record — card-{id}@{host} — never from random().',
            );
        }

        $this->start = DateTimeImmutable::createFromInterface($start);
        $this->end = $end === null ? null : DateTimeImmutable::createFromInterface($end);
        $this->lastModified = $lastModified === null ? null : DateTimeImmutable::createFromInterface($lastModified);

        if ($this->end !== null && $this->endsBeforeItStarts()) {
            throw new InvalidArgumentException("ICS event {$uid} ends before it starts.");
        }

        if ($status === null) {
            $this->status = null;
        } else {
            $normalised = strtoupper(trim($status));

            if (! in_array($normalised, self::STATUSES, true)) {
                throw new InvalidArgumentException(
                    'STATUS must be one of '.implode(', ', self::STATUSES).", got {$status}.",
                );
            }

            $this->status = $normalised;
        }
    }

    /**
     * The `DTEND` to write, which RFC 5545 §3.6.1 defines as **exclusive**.
     *
     * 🔴 This is the classic off-by-one in an ICS feed, and it is silent: get it
     * wrong and every all-day event shows up in the client one day short, which
     * for a single-day card means it does not show up at all. A card due on
     * 31 July is `DTSTART;VALUE=DATE:20260731` with `DTEND;VALUE=DATE:20260801`
     * — the end is the first day the event no longer covers.
     *
     * So for an all-day event the inclusive `end` (or `start`, for a one-day
     * event) gains a day here. `modify()` does calendar arithmetic in the value's
     * own timezone, so a day boundary that crosses a DST change still lands on
     * the next date rather than 23 or 25 hours later.
     *
     * A timed event's `end` is already the instant it stops, so it is returned
     * untouched — and null stays null, which is a legitimate `VEVENT` with a
     * start and no duration.
     */
    public function exclusiveEnd(): ?DateTimeImmutable
    {
        if (! $this->allDay) {
            return $this->end;
        }

        return ($this->end ?? $this->start)->modify('+1 day');
    }

    /**
     * An all-day event is compared by date, because two values on the same day
     * in different timezones are the same day and neither is "before" the other.
     * A timed event is compared as an instant, which is timezone-safe already.
     */
    private function endsBeforeItStarts(): bool
    {
        if ($this->allDay) {
            return $this->end->format('Ymd') < $this->start->format('Ymd');
        }

        return $this->end < $this->start;
    }
}
