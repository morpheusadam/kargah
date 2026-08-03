<?php

namespace Modules\Core\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\NotificationSetting;
use Modules\Core\Support\NotificationEvents;

class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'digest' => NotificationEvents::DEFAULT_DIGEST,
            'quiet_hours_enabled' => false,
            'quiet_hours_from' => NotificationEvents::DEFAULT_QUIET_FROM,
            'quiet_hours_to' => NotificationEvents::DEFAULT_QUIET_TO,
        ];
    }

    public function quietHours(string $from = '22:00', string $to = '08:00'): static
    {
        return $this->state(fn () => [
            'quiet_hours_enabled' => true,
            'quiet_hours_from' => $from,
            'quiet_hours_to' => $to,
        ]);
    }
}
