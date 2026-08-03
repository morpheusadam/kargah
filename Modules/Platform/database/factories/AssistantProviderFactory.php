<?php

namespace Modules\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Support\AssistantDrivers;

/**
 * An assistant provider, configured or not.
 *
 * The default is configured — a key for a driver that needs one, a base URL
 * for the one that does not — because most tests want a row that is actually
 * usable, unlike `DeliveryProviderFactory`'s default. `unconfigured()` is the
 * one that gives the ordinary state of a fresh install.
 */
class AssistantProviderFactory extends Factory
{
    protected $model = AssistantProvider::class;

    public function definition(): array
    {
        $driver = $this->faker->randomElement(AssistantDrivers::keys());

        return [
            'name' => AssistantDrivers::label($driver),
            'driver' => $driver,
            'model' => AssistantDrivers::defaultModel($driver),
            'api_key' => AssistantDrivers::requiresKey($driver) ? 'test-key-'.$this->faker->unique()->numberBetween(1, 99_999) : null,
            'base_url' => AssistantDrivers::requiresBaseUrl($driver) ? 'http://127.0.0.1:11434' : null,
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function driver(string $driver): static
    {
        return $this->state(fn (): array => [
            'driver' => $driver,
            'name' => AssistantDrivers::label($driver),
            'model' => AssistantDrivers::defaultModel($driver),
            'api_key' => AssistantDrivers::requiresKey($driver) ? 'test-key' : null,
            'base_url' => AssistantDrivers::requiresBaseUrl($driver) ? 'http://127.0.0.1:11434' : null,
        ]);
    }

    /** No key and no base URL — the state a freshly added row is in before anyone fills in credentials. */
    public function unconfigured(): static
    {
        return $this->state(fn (): array => [
            'api_key' => null,
            'base_url' => null,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
