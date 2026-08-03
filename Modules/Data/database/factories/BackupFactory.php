<?php

namespace Modules\Data\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Data\Models\Backup;

class BackupFactory extends Factory
{
    protected $model = Backup::class;

    public function definition(): array
    {
        $started = now()->subDays($this->faker->numberBetween(0, 20))->setTime(3, 0);
        $size = $this->faker->numberBetween(200_000, 90_000_000);

        return [
            'target' => Backup::TARGET_DATABASE,
            'disk' => 'backups',
            'path' => 'kargah-'.$started->format('Y-m-d-Hi').'.sql',
            'size_bytes' => $size,
            'checksum' => hash('sha256', (string) $size.$started->toIso8601String()),
            'status' => Backup::STATUS_COMPLETE,
            'error' => null,
            'started_at' => $started,
            'completed_at' => $started->copy()->addSeconds($this->faker->numberBetween(5, 240)),
        ];
    }

    public function failed(string $error = 'mysqldump is not installed on this host.'): static
    {
        return $this->state(fn (): array => [
            'status' => Backup::STATUS_FAILED,
            'error' => $error,
            // No artefact and no checksum: a failed run must not look
            // downloadable, and a size would imply something was written.
            'path' => null,
            'size_bytes' => null,
            'checksum' => null,
            'completed_at' => now(),
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => Backup::STATUS_RUNNING,
            'completed_at' => null,
            'size_bytes' => null,
            'checksum' => null,
        ]);
    }
}
