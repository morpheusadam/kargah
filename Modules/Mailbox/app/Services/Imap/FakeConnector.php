<?php

namespace Modules\Mailbox\Services\Imap;

use Modules\Mailbox\Models\MailAccount;

/**
 * A mailbox made of an array.
 *
 * There are no IMAP credentials on a developer's machine and there must be none
 * in CI, so every test of the sync runs against this. It is app code rather
 * than test code for the same reason Laravel ships its own fakes: the moment a
 * fake lives in `tests/`, the next module that needs one writes a second,
 * subtly different one.
 *
 * Two behaviours exist purely so failure paths can be exercised, and both
 * matter because neither can be produced on a machine with no mail server:
 *
 * - `fail()` makes `open()` throw `MailboxUnavailable`, which is what a refused
 *   connection or a rejected password looks like from the outside.
 * - `killAfter()` makes a fetch die partway through the window with an ordinary
 *   runtime error — deliberately *not* a `MailboxUnavailable`, because a killed
 *   process is not a reported failure. It is the closest a test can get to the
 *   plug being pulled: rows already committed stay committed, and the cursor is
 *   never advanced.
 */
class FakeConnector implements MailboxConnector
{
    /** @var array<int, list<RemoteMessage>> keyed by account id, then folder */
    private array $folders = [];

    /** @var array<string, int> */
    private array $uidValidity = [];

    /** @var array<int, MailboxUnavailable> */
    private array $failures = [];

    /**
     * Every window that was actually asked for, in order.
     *
     * The test for chunking asserts on this rather than on row counts, because
     * the property under test is that the command never asks for more than a
     * chunk — a row count would still pass if the command asked for all two
     * thousand and stored a hundred.
     *
     * @var list<array{account: int, folder: string, from: int, to: int}>
     */
    public array $windows = [];

    /** How many messages have been handed over across every fetch. */
    public int $delivered = 0;

    /** How many times `open()` has been called; a connection is not free. */
    public int $opened = 0;

    private ?int $killAfter = null;

    /**
     * Put messages in a folder. Replaces whatever was there.
     *
     * @param  list<RemoteMessage>  $messages
     */
    public function seed(MailAccount $account, array $messages, string $folder = 'INBOX'): static
    {
        usort($messages, fn (RemoteMessage $a, RemoteMessage $b) => $a->uid <=> $b->uid);

        $this->folders[$account->getKey()][$folder] = array_values($messages);
        $this->uidValidity[$this->key($account, $folder)] ??= 1;

        return $this;
    }

    /**
     * Change the folder's UIDVALIDITY, as a server does when it is rebuilt.
     */
    public function uidValidity(MailAccount $account, int $value, string $folder = 'INBOX'): static
    {
        $this->uidValidity[$this->key($account, $folder)] = $value;

        return $this;
    }

    /** Make this account unopenable until `heal()` is called. */
    public function fail(MailAccount $account, MailboxUnavailable|string $reason): static
    {
        $this->failures[$account->getKey()] = is_string($reason)
            ? MailboxUnavailable::unreachable($account->email, $reason)
            : $reason;

        return $this;
    }

    public function heal(MailAccount $account): static
    {
        unset($this->failures[$account->getKey()]);

        return $this;
    }

    /**
     * Die after handing over this many messages in total, or never again if null.
     */
    public function killAfter(?int $messages): static
    {
        $this->killAfter = $messages;

        return $this;
    }

    public function open(MailAccount $account, string $folder): MailboxConnection
    {
        $this->opened++;

        if (isset($this->failures[$account->getKey()])) {
            throw $this->failures[$account->getKey()];
        }

        return new FakeMailbox(
            $this,
            $account->getKey(),
            $folder,
            $this->folders[$account->getKey()][$folder] ?? [],
            $this->uidValidity[$this->key($account, $folder)] ?? 1,
        );
    }

    /**
     * Called by the open folder as it hands each message over.
     *
     * @throws \RuntimeException when the configured kill point is reached
     */
    public function delivering(): void
    {
        if ($this->killAfter !== null && $this->delivered >= $this->killAfter) {
            throw new \RuntimeException('The fake mailbox was killed mid-chunk.');
        }

        $this->delivered++;
    }

    public function windowOpened(int $account, string $folder, int $from, int $to): void
    {
        $this->windows[] = ['account' => $account, 'folder' => $folder, 'from' => $from, 'to' => $to];
    }

    /** The widest window asked for in any single fetch, in messages handed over. */
    public function largestWindow(): int
    {
        return collect($this->windows)->max(fn (array $w) => $w['to'] - $w['from'] + 1) ?? 0;
    }

    private function key(MailAccount $account, string $folder): string
    {
        return $account->getKey().':'.$folder;
    }
}
