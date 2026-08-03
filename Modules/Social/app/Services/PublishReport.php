<?php

namespace Modules\Social\Services;

/**
 * What one publish run actually did.
 *
 * Exists so the pages can tell the truth. 'Published' is not enough when a post
 * went to three networks and reached two of them, and neither is 'failed' — the
 * toast has to name what happened to each, because the person's next action
 * (retry, reconnect, or nothing) depends on which.
 *
 * `untouched` is the count that proves the retry design works: on a second run
 * of the same job every already-published target lands here and nothing is
 * sent.
 */
final class PublishReport
{
    public int $published = 0;

    public int $failed = 0;

    public int $untouched = 0;

    /** @var list<string> One per failed target, in the order they were attempted. */
    public array $errors = [];

    public function recordPublished(): void
    {
        $this->published++;
    }

    public function recordFailed(string $error): void
    {
        $this->failed++;
        $this->errors[] = $error;
    }

    public function recordUntouched(): void
    {
        $this->untouched++;
    }

    /** Whether this run sent anything at all. */
    public function didAnything(): bool
    {
        return $this->published > 0 || $this->failed > 0;
    }

    /** A sentence for a toast, saying what happened rather than what was asked for. */
    public function summary(): string
    {
        if (! $this->didAnything()) {
            return $this->untouched === 0
                ? 'There was nothing to publish.'
                : 'Nothing was sent — every target had already been published.';
        }

        $parts = [];

        if ($this->published > 0) {
            $parts[] = 'published to '.$this->published.' '.($this->published === 1 ? 'network' : 'networks');
        }

        if ($this->failed > 0) {
            $parts[] = $this->failed.' failed';
        }

        if ($this->untouched > 0) {
            $parts[] = $this->untouched.' left alone as already published';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    /** The first error, which is what a toast has room for. */
    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
