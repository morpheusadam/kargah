<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\EmailThread;

class EmailThreadFactory extends Factory
{
    protected $model = EmailThread::class;

    public function definition(): array
    {
        return [
            'subject' => $this->faker->randomElement([
                'Retainer renewal — September onwards',
                'Analytics dashboard scope',
                'Booking widget without a build step',
                'Invoice 2026-0114 — lira equivalent',
                'Provider credentials screen',
                'Q2 self-assessment — documents needed',
            ]),
            'participants' => [],
            'last_message_at' => null,
            'message_count' => 0,
        ];
    }
}
