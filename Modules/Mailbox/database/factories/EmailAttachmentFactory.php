<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailAttachment;

class EmailAttachmentFactory extends Factory
{
    protected $model = EmailAttachment::class;

    public function definition(): array
    {
        $file = $this->faker->randomElement([
            ['proposal-northwind-retainer.pdf', 'application/pdf'],
            ['invoice-2026-0114.pdf', 'application/pdf'],
            ['booking-widget-mockup.png', 'image/png'],
            ['bank-statement-q2.csv', 'text/csv'],
            ['hand-over-notes.md', 'text/markdown'],
        ]);

        return [
            'email_id' => Email::factory()->withAttachments(),
            'filename' => $file[0],
            'mime' => $file[1],
            'size_bytes' => $this->faker->numberBetween(4_000, 4_000_000),
            'content_id' => null,
            'part_number' => (string) $this->faker->numberBetween(2, 5),
            // Null until phase 6 stores the bytes through Data.
            'attachment_id' => null,
        ];
    }

    /** An image the HTML body references rather than a real attachment. */
    public function inline(): static
    {
        return $this->state(fn () => [
            'filename' => 'signature.png',
            'mime' => 'image/png',
            'content_id' => '<'.$this->faker->unique()->uuid().'>',
            'size_bytes' => $this->faker->numberBetween(2_000, 40_000),
        ]);
    }

    /** The bytes exist, so a download button may be drawn. */
    public function stored(): static
    {
        return $this->state(fn () => ['attachment_id' => $this->faker->unique()->numberBetween(1, 10_000)]);
    }
}
