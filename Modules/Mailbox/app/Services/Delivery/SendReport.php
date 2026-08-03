<?php

namespace Modules\Mailbox\Services\Delivery;

/**
 * What one chunk actually did.
 *
 * Exists so the pages and the console can tell the truth. 'Sent' is not enough
 * when a chunk of fifty reached forty, skipped seven that were on the
 * suppression list and failed three, because the person's next action differs
 * for each.
 *
 * `untouched` is the count that proves the idempotency design works: on a
 * second run of the same chunk every recipient lands here and nothing is sent.
 */
final class SendReport
{
    public int $sent = 0;

    public int $failed = 0;

    public int $suppressed = 0;

    /** Rows another worker had already claimed, or that were no longer `pending`. */
    public int $untouched = 0;

    /** Claims abandoned by a worker that stopped, moved to `failed` rather than repeated. */
    public int $abandoned = 0;

    /** @var list<string> One per failed recipient, in the order they were attempted. */
    public array $errors = [];

    public function recordSent(): void
    {
        $this->sent++;
    }

    public function recordFailed(string $error): void
    {
        $this->failed++;
        $this->errors[] = $error;
    }

    public function recordSuppressed(): void
    {
        $this->suppressed++;
    }

    public function recordUntouched(): void
    {
        $this->untouched++;
    }

    public function recordAbandoned(): void
    {
        $this->abandoned++;
    }

    public function didAnything(): bool
    {
        return $this->sent > 0 || $this->failed > 0 || $this->suppressed > 0 || $this->abandoned > 0;
    }

    /** A sentence saying what happened, rather than what was asked for. */
    public function summary(): string
    {
        if (! $this->didAnything()) {
            return $this->untouched === 0
                ? 'There was nothing left to send.'
                : 'Nothing was sent — every recipient in this batch had already been taken.';
        }

        $parts = [];

        if ($this->sent > 0) {
            $parts[] = 'sent '.$this->sent.' '.($this->sent === 1 ? 'message' : 'messages');
        }

        if ($this->suppressed > 0) {
            $parts[] = $this->suppressed.' skipped as suppressed';
        }

        if ($this->failed > 0) {
            $parts[] = $this->failed.' failed';
        }

        if ($this->abandoned > 0) {
            $parts[] = $this->abandoned.' left over from a stopped worker and marked failed rather than re-sent';
        }

        if ($this->untouched > 0) {
            $parts[] = $this->untouched.' already taken';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    /** The first error, which is what a toast has room for. */
    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
