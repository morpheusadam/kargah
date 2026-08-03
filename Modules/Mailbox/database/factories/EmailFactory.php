<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\MailAccount;

class EmailFactory extends Factory
{
    protected $model = Email::class;

    public function definition(): array
    {
        $sender = $this->faker->randomElement([
            ['Sam Okafor', 'sam@northwind.example'],
            ['Helen Vasquez', 'helen@northwind.example'],
            ['Joris Bakker', 'joris@acmestudio.example'],
            ['Priya Nandakumar', 'priya@bluepeak.example'],
            ['Deniz Aydın', 'deniz@harbourfinch.example'],
            ['Marta Lindqvist', 'marta@orbitstudio.example'],
        ]);

        return [
            'mail_account_id' => MailAccount::factory(),
            'email_thread_id' => null,
            // Unique because the column is, and a factory that collides here
            // fails with a constraint violation rather than a useful message.
            'message_id' => '<'.$this->faker->unique()->uuid().'@kargah.local>',
            'in_reply_to' => null,
            'uid' => $this->faker->unique()->numberBetween(1, 100_000),
            'subject' => $this->faker->randomElement([
                'Retainer renewal — September onwards',
                'Analytics dashboard scope',
                'Booking widget without a build step',
                'Invoice 2026-0114 — lira equivalent',
                'Provider credentials screen',
                'Re: hand-over notes',
            ]),
            'from_name' => $sender[0],
            'from_email' => $sender[1],
            'to' => [['name' => 'Nima Fazlipour', 'email' => 'admin@kargah.local']],
            'cc' => null,
            'body_text' => $this->faker->paragraph(3),
            'body_html' => null,
            'has_attachments' => false,
            'customer_id' => null,
            // Deterministic, not random. A test that counts starred messages
            // has to be able to make three emails and know the answer is zero;
            // the states below are how a test asks for the other conditions.
            'is_read' => true,
            'is_starred' => false,
            'folder' => 'INBOX',
            'received_at' => now()->subMinutes($this->faker->numberBetween(5, 60 * 24 * 30)),
        ];
    }

    public function unread(): static
    {
        return $this->state(fn () => ['is_read' => false]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['is_read' => true]);
    }

    public function starred(): static
    {
        return $this->state(fn () => ['is_starred' => true]);
    }

    public function inFolder(string $folder): static
    {
        return $this->state(fn () => ['folder' => $folder]);
    }

    public function from(string $name, string $email): static
    {
        return $this->state(fn () => ['from_name' => $name, 'from_email' => $email]);
    }

    /**
     * A message whose body arrived as HTML only.
     *
     * The state exists so `preview()` can be tested against the fallback path
     * rather than only against `body_text`.
     */
    public function htmlOnly(): static
    {
        return $this->state(fn () => [
            'body_text' => null,
            'body_html' => '<html><head><style>p { margin: 0 }</style></head><body>'
                .'<p>The proposal is attached.</p><p>Let me know before Friday.</p></body></html>',
        ]);
    }

    public function withAttachments(): static
    {
        return $this->state(fn () => ['has_attachments' => true]);
    }
}
