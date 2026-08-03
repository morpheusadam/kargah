<?php

namespace Modules\Mailbox\Services\Delivery;

/**
 * Which driver handles which provider.
 *
 * Factories, not instances. Two reasons, and the second is the one that
 * matters: a driver nobody sends through is never built, and a test that swaps
 * a provider for a fake never constructs the real driver for it at all — so no
 * Symfony transport for a live provider can be created from a test even by
 * accident, which on a machine with no credentials is the difference between
 * an empty configuration and somebody's actual mailing list.
 *
 * Registered as a singleton so a swap made in a test's `setUp` is the same
 * registry the command, the job, the webhook controller and the Livewire pages
 * resolve. Nothing here is static: this application must not assume a fresh
 * process per request *or* a persistent one, and static state is how a
 * Livewire-heavy app leaks under a long-lived worker. See
 * project-guaid/spec/01-architecture.md.
 */
class Delivery
{
    /** @var array<string, callable(): Mailer> */
    private array $factories = [];

    /** @var array<string, Mailer> */
    private array $resolved = [];

    /**
     * Bind a provider driver to a factory.
     *
     * Called once per driver by the service provider, and again by a test to
     * replace one. Replacing drops any instance already built from the old
     * factory, so the swap takes effect even mid-request.
     *
     * @param  callable(): Mailer  $factory
     */
    public function extend(string $driver, callable $factory): void
    {
        $this->factories[$driver] = $factory;

        unset($this->resolved[$driver]);
    }

    /** Swap in a ready-made driver. The convenience a test wants over `extend`. */
    public function swap(Mailer $driver): void
    {
        $this->extend($driver->driver(), fn (): Mailer => $driver);
    }

    /**
     * The driver for a provider row, or null when Kargah has none.
     *
     * Null is a real answer rather than an exception: a row can carry a driver
     * string written by an older version of Kargah, and that should show up as
     * a recorded error on one recipient, not as a job that dies and strands the
     * rest of the chunk.
     */
    public function driverFor(string $driver): ?Mailer
    {
        if (isset($this->resolved[$driver])) {
            return $this->resolved[$driver];
        }

        $factory = $this->factories[$driver] ?? null;

        return $factory === null ? null : $this->resolved[$driver] = $factory();
    }

    /** The driver for a provider, but only if it can read callbacks back. */
    public function webhookHandlerFor(string $driver): ?HandlesWebhooks
    {
        $mailer = $this->driverFor($driver);

        return $mailer instanceof HandlesWebhooks ? $mailer : null;
    }

    /** @return list<string> Drivers with an implementation registered. */
    public function drivers(): array
    {
        return array_keys($this->factories);
    }

    public function handles(string $driver): bool
    {
        return array_key_exists($driver, $this->factories);
    }
}
