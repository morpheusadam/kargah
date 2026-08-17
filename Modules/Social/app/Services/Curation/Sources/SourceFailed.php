<?php

namespace Modules\Social\Services\Curation\Sources;

/**
 * A source was asked for stories and did not produce any usable ones.
 *
 * Thrown rather than returned so a half-parsed feed cannot be mistaken for an
 * empty one. That distinction is the point: a feed that legitimately has nothing
 * new returns `[]` and the day carries on, while a feed whose XML did not parse
 * has to be visible, because forty sources quietly returning nothing is how a
 * pipeline stops working without anybody noticing.
 *
 * The messages name the source and say what happened, because the reader is
 * whoever is looking at `social:curate-daily` output asking why the channel went
 * quiet.
 */
class SourceFailed extends \RuntimeException
{
    public static function unreachable(string $source, string $detail): self
    {
        return new self($source.' could not be reached: '.$detail);
    }

    public static function status(string $source, int $status): self
    {
        return new self($source.' answered HTTP '.$status.', so nothing was read from it.');
    }

    public static function malformed(string $source, string $detail): self
    {
        return new self($source.' answered with something that is not a feed: '.$detail);
    }
}
