<?php

namespace Modules\Social\Services\Curation;

/**
 * One story with its score and the arithmetic that produced it.
 *
 * The breakdown is carried rather than recomputed because the reason a story won
 * is the thing an operator actually needs when the channel posts something odd,
 * and `social:curate-daily --explain` is where they will look for it. A score on
 * its own says which story won and nothing about why, and "why" here is four
 * numbers that each mean something: how many outlets, how old, how fast it
 * spread, how much the outlets are trusted.
 */
final readonly class RankedStory
{
    public function __construct(
        public Story $story,
        public Cluster $cluster,
        public float $score,
        public int $sources,
        public float $ageHours,
        public float $spanHours,
        public float $corroboration,
        public float $pickup,
    ) {}

    /** One line for the explain table. */
    public function explain(): string
    {
        return sprintf(
            'sources=%d  age=%.1fh  span=%.1fh  corrob=%.2f  pickup=%.2f',
            $this->sources,
            $this->ageHours,
            $this->spanHours,
            $this->corroboration,
            $this->pickup,
        );
    }
}
