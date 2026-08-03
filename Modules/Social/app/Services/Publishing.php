<?php

namespace Modules\Social\Services;

use Modules\Social\Services\Publishers\IngestsNotifications;
use Modules\Social\Services\Publishers\Publisher;

/**
 * Which driver handles which network.
 *
 * Factories, not instances. Two reasons, and the second is the one that
 * matters: a driver nobody publishes to is never built, and a test that swaps a
 * network for a fake never constructs the real driver for it at all — so
 * `MastodonPublisher` cannot reach the network from a test even by accident.
 *
 * Registered as a singleton so that a swap made in a test's `setUp` is the same
 * registry the command, the job and the Livewire page resolve. Nothing here is
 * static: this application must not assume a fresh process per request *or* a
 * persistent one, and static state is how a Livewire-heavy app leaks under a
 * long-lived worker. See project-guaid/spec/01-architecture.md.
 */
class Publishing
{
    /** @var array<string, callable(): Publisher> */
    private array $factories = [];

    /** @var array<string, Publisher> */
    private array $resolved = [];

    /**
     * Bind a network to a driver factory.
     *
     * Called once per network by the service provider, and again by a test to
     * replace one. Replacing drops any instance already built from the old
     * factory, so the swap takes effect even mid-request.
     *
     * @param  callable(): Publisher  $factory
     */
    public function extend(string $network, callable $factory): void
    {
        $this->factories[$network] = $factory;

        unset($this->resolved[$network]);
    }

    /** Swap in a ready-made driver. The convenience a test wants over `extend`. */
    public function swap(Publisher $driver): void
    {
        $this->extend($driver->network(), fn (): Publisher => $driver);
    }

    /**
     * The driver for a network, or null when Kargah has none.
     *
     * Null is a real answer rather than an exception: a row can carry a network
     * string written by an older version of Kargah, and that should show up as
     * a recorded error on the target, not as a job that dies and takes the
     * post's other targets with it.
     */
    public function driverFor(string $network): ?Publisher
    {
        if (isset($this->resolved[$network])) {
            return $this->resolved[$network];
        }

        $factory = $this->factories[$network] ?? null;

        return $factory === null ? null : $this->resolved[$network] = $factory();
    }

    /** The driver for a network, but only if it can read notifications back. */
    public function ingesterFor(string $network): ?IngestsNotifications
    {
        $driver = $this->driverFor($network);

        return $driver instanceof IngestsNotifications ? $driver : null;
    }

    /** @return list<string> Networks with a driver registered. */
    public function networks(): array
    {
        return array_keys($this->factories);
    }

    public function handles(string $network): bool
    {
        return array_key_exists($network, $this->factories);
    }
}
