<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Support\Carbon;

/**
 * What one run of the curator did, and what went wrong on the way.
 *
 * Returned rather than logged, so that the command can print it, a dry run can
 * show it without anything having been written, and the settings page can one day
 * show the last one. A method that logged its own progress would give the dry run
 * nothing to display and the command nothing to summarise.
 */
class CurationReport
{
    /** @var list<string> */
    public array $problems = [];

    /** @var list<RankedStory> */
    public array $considered = [];

    /** @var list<array{title: string, reason: string}> */
    public array $refused = [];

    public ?RankedStory $chosen = null;

    /** @var array<string, Copy> */
    public array $copy = [];

    /** @var array<string, Carbon> */
    public array $slots = [];

    /** @var array<string, int> network => post id */
    public array $posts = [];

    public bool $hasCover = false;

    public int $storiesRead = 0;

    public int $clustersFound = 0;

    public ?string $stoppedBecause = null;

    public function problem(string $message): void
    {
        $this->problems[] = $message;
    }

    /** Nothing was published, and this says whether that was a fault or a quiet day. */
    public function publishedAnything(): bool
    {
        return $this->posts !== [];
    }
}
