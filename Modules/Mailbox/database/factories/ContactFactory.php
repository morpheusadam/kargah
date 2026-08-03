<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\Contact;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $company = $this->faker->randomElement([
            ['Studio Nord', 'studio-nord'],
            ['Pixelforge', 'pixelforge'],
            ['Northloop', 'northloop'],
            ['Brightlab', 'brightlab'],
            ['Harbourside', 'harbourside'],
            ['Quiet Fox', 'quietfox'],
            ["Makers' Lane", 'makers-lane'],
            ['Orbit Studio', 'orbitstudio'],
        ]);

        return [
            // Unique because the column is, and a factory that collides here
            // fails with a constraint violation rather than a useful message.
            'email' => 'hello+'.$this->faker->unique()->numberBetween(1, 999_999).'@'.$company[1].'.example',
            'name' => $this->faker->randomElement(['Sam Okafor', 'Helen Vasquez', 'Joris Bakker', 'Priya Nandakumar', 'Marta Lindqvist']),
            'company_name' => $company[0],
            'customer_id' => null,
            'tags' => ['agencies-uk'],
            'meta' => null,
            // Deterministic, not random. A test that counts subscribed contacts
            // has to be able to make three and know the answer is three.
            'is_subscribed' => true,
            'source' => Contact::IMPORT,
        ];
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (): array => ['is_subscribed' => false]);
    }

    /** @param  list<string>  $tags */
    public function tagged(array $tags): static
    {
        return $this->state(fn (): array => ['tags' => $tags]);
    }

    public function withEmail(string $email): static
    {
        return $this->state(fn (): array => ['email' => $email]);
    }

    public function fromInbox(): static
    {
        return $this->state(fn (): array => ['source' => Contact::INBOX]);
    }
}
