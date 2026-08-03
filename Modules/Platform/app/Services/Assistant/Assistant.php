<?php

namespace Modules\Platform\Services\Assistant;

/**
 * Which driver handles which provider.
 *
 * Factories, not instances — the same shape as
 * `Modules\Mailbox\Services\Delivery\Delivery`, for the same reason. A real
 * driver is built only the moment something actually asks for it, so a test
 * that never asks for `gemini` never constructs `GeminiDriver`, and a test
 * that swaps a driver for `FakeAssistantDriver` never constructs the real one
 * at all. On a machine with no CA bundle configured, that is the difference
 * between a clean test run and every one of them tripping `cURL error 60`.
 *
 * Registered as a singleton so a driver swapped in a test's `setUp` is the
 * same registry the settings page, the (future) CLI and the (future) tool
 * layer all resolve through. Nothing here is static, for the reason
 * `Delivery`'s docblock gives: this application must not assume a fresh
 * process per request, and static state is how that assumption leaks under a
 * long-lived worker.
 *
 * **Deviation from `Delivery::driverFor()`:** that method returns `null` for
 * an unrecognised driver, because a stale `delivery_providers.driver` value
 * must not crash the rest of a campaign chunk — one bad row degrades to "no
 * driver" and the job moves on. Here, `driverFor()` throws instead. There is
 * no batch of rows to protect: the settings page and the CLI each ask for one
 * provider's driver to act on right now, and a driver string in the database
 * that this registry does not recognise is a configuration problem worth
 * surfacing immediately and specifically, not one to paper over as "nothing
 * to do here".
 */
class Assistant
{
    /** @var array<string, callable(): AssistantDriver> */
    private array $factories = [];

    /** @var array<string, AssistantDriver> */
    private array $resolved = [];

    /**
     * Bind a provider driver to a factory.
     *
     * Called once per driver by the service provider, and again by a test to
     * replace one. Replacing drops any instance already built from the old
     * factory, so the swap takes effect even mid-request.
     *
     * @param  callable(): AssistantDriver  $factory
     */
    public function extend(string $driver, callable $factory): void
    {
        $this->factories[$driver] = $factory;

        unset($this->resolved[$driver]);
    }

    /** Swap in a ready-made driver. The convenience a test wants over `extend`. */
    public function swap(AssistantDriver $driver): void
    {
        $this->extend($driver->driver(), fn (): AssistantDriver => $driver);
    }

    /**
     * The driver for a provider name.
     *
     * @throws \InvalidArgumentException when no driver is registered for the name
     */
    public function driverFor(string $driver): AssistantDriver
    {
        if (isset($this->resolved[$driver])) {
            return $this->resolved[$driver];
        }

        $factory = $this->factories[$driver] ?? null;

        if ($factory === null) {
            throw new \InvalidArgumentException(
                'No assistant driver is registered for "'.$driver.'". Registered: '.implode(', ', $this->drivers()).'.',
            );
        }

        return $this->resolved[$driver] = $factory();
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
