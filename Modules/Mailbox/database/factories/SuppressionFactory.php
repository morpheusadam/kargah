<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\Suppression;

class SuppressionFactory extends Factory
{
    protected $model = Suppression::class;

    public function definition(): array
    {
        return [
            // Unique because the column is — a shared suppression list with two
            // rows for one address would not be a suppression list.
            'email' => 'bounced+'.$this->faker->unique()->numberBetween(1, 999_999).'@northloop.example',
            'reason' => Suppression::HARD_BOUNCE,
            'source' => 'brevo',
            'detail' => '550 5.1.1 The email account that you tried to reach does not exist.',
            'suppressed_at' => now()->subDays($this->faker->numberBetween(0, 30)),
        ];
    }

    public function forEmail(string $email): static
    {
        return $this->state(fn (): array => ['email' => Suppression::normalise($email)]);
    }

    public function reason(string $reason): static
    {
        return $this->state(fn (): array => ['reason' => $reason]);
    }
}
