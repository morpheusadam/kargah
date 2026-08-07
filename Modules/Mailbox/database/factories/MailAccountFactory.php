<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\MailAccount;

class MailAccountFactory extends Factory
{
    protected $model = MailAccount::class;

    public function definition(): array
    {
        $email = $this->faker->unique()->userName().'@kargah.local';

        return [
            'name' => $this->faker->randomElement(['Studio inbox', 'Invoices', 'Support', 'Personal']),
            'email' => $email,
            'imap_host' => 'imap.'.$this->faker->randomElement(['fastmail.com', 'migadu.com', 'mailbox.org']),
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_validate_cert' => true,
            'imap_username' => $email,
            // Goes through the mutator, so the factory stores ciphertext like
            // everything else does. A test that wants the plaintext back reads
            // `$account->password`.
            'password' => $this->faker->password(12, 20),
            'default_folder' => 'INBOX',
            'sync_cursor' => null,
            'uid_validity' => null,
            'last_synced_at' => null,
            'last_error' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * An account messages are pushed to by the Email Worker.
     *
     * Every IMAP column is nulled rather than left at the definition's default.
     * A row that names a host it is never going to connect to is a row someone
     * will eventually try to connect to, and the point of `kind` is that there
     * is nothing there to connect to at all.
     */
    public function inbound(): static
    {
        return $this->state(fn () => [
            'kind' => MailAccount::KIND_INBOUND,
            'imap_host' => null,
            'imap_username' => null,
            'password' => null,
            'sync_cursor' => null,
            'uid_validity' => null,
        ]);
    }

    /** An account the sync has already run against and can resume. */
    public function synced(): static
    {
        return $this->state(fn () => [
            'sync_cursor' => $this->faker->numberBetween(100, 9_000),
            'uid_validity' => $this->faker->numberBetween(1, 1_000_000),
            'last_synced_at' => now()->subMinutes(15),
            'last_error' => null,
        ]);
    }

    /** An account whose last run ended badly — what the settings page warns on. */
    public function failing(): static
    {
        return $this->state(fn () => [
            'last_synced_at' => now()->subHours(6),
            'last_error' => 'IMAP login failed: authentication credentials rejected.',
        ]);
    }
}
