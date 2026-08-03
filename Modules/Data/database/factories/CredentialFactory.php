<?php

namespace Modules\Data\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Data\Models\Credential;

class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement([
            'Hostinger hPanel', 'Brevo API', 'GitHub personal token',
            'Namecheap', 'Client SFTP', 'Bank portal',
        ]);

        return [
            'name' => $name,
            'username' => $this->faker->userName(),
            // Assigned through the virtual attribute, so the factory exercises
            // the same encryption path the application does. A test that wants
            // a known value passes ['secret' => '…'] and gets it back encrypted.
            'secret' => $this->faker->password(18, 24),
            'totp' => null,
            'notes' => null,
            'url' => 'https://'.$this->faker->domainName(),
            'category_id' => null,
            'company_id' => null,
            'last_revealed_at' => null,
            'rotated_at' => null,
            'created_by' => null,
        ];
    }

    /** Carry a TOTP seed, so the vault shows a rolling code beside the entry. */
    public function withTotp(string $seed = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn (): array => ['totp' => $seed]);
    }

    public function withNotes(string $notes): static
    {
        return $this->state(fn (): array => ['notes' => $notes]);
    }
}
