<?php

namespace Modules\Accounting\Services\RateSources;

/**
 * A source was asked for a rate and did not produce one.
 *
 * Thrown rather than returned so that a half-parsed response cannot be
 * mistaken for a rate. The messages name the source and say what actually
 * happened, because the only reader is whoever is looking at cron output
 * asking why an invoice has no lira figure.
 */
class RateSourceFailed extends \RuntimeException
{
    public static function unreachable(string $source, string $detail): self
    {
        return new self($source.' could not be reached: '.$detail);
    }

    public static function status(string $source, int $status): self
    {
        return new self($source.' answered HTTP '.$status.', so no rate was recorded from it.');
    }

    public static function malformed(string $source, string $detail): self
    {
        return new self($source.' answered with something that is not a rate: '.$detail);
    }
}
