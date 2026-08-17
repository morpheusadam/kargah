<?php

namespace Modules\Core\Contracts;

/**
 * A language model was asked for text and did not produce any.
 *
 * Lives beside the interface rather than in an exceptions namespace of its own,
 * for the reason `Modules\Social\Services\Publishers\PublishFailed` does: it is
 * part of the contract's signature, and a caller that has the interface should
 * have the exception it declares without going looking.
 *
 * The messages are written for whoever is reading cron output at the point the
 * channel went quiet, so they name what was asked and what came back.
 */
class TextGenerationFailed extends \RuntimeException
{
    public static function unavailable(string $reason): self
    {
        return new self('No text could be generated: '.$reason);
    }

    public static function refused(string $provider, string $detail): self
    {
        return new self($provider.' refused the request: '.$detail);
    }

    public static function empty(string $provider): self
    {
        return new self($provider.' answered without any text in it.');
    }
}
