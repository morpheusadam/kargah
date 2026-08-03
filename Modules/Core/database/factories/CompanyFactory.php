<?php

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Company;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' '.$this->faker->randomElement(['Ltd', 'GmbH', 'A.Ş.', 'LLC']),
            'tax_number' => (string) $this->faker->numberBetween(1000000000, 9999999999),
            'tax_office' => $this->faker->optional()->city(),
            'country' => $this->faker->randomElement(['GB', 'DE', 'US', 'TR', 'NL']),
            'address' => $this->faker->address(),
            'website' => 'https://'.$this->faker->domainName(),
            'default_currency' => $this->faker->randomElement(['USD', 'TRY', 'USDT']),
            'is_domestic' => false,
            'notes' => null,
        ];
    }

    /** A Turkish company, which changes what its invoices must carry. */
    public function domestic(): static
    {
        return $this->state(fn () => [
            'country' => 'TR',
            'is_domestic' => true,
            'default_currency' => 'TRY',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()->subDays(30)]);
    }
}
