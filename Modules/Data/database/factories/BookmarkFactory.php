<?php

namespace Modules\Data\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Data\Models\Bookmark;

class BookmarkFactory extends Factory
{
    protected $model = Bookmark::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'url' => 'https://'.$this->faker->domainName(),
            'kind' => $this->faker->randomElement(Bookmark::KINDS),
            'notes' => null,
            'tags' => $this->faker->randomElements(['laravel', 'hosting', 'client', 'telegram', 'tool', 'docs'], 2),
            'company_id' => null,
            'last_checked_at' => null,
            'last_status' => null,
            'created_by' => null,
        ];
    }

    public function ofKind(string $kind): static
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    /** Answered a reachability check, so the list can show a live badge. */
    public function checked(int $status = 200): static
    {
        return $this->state(fn (): array => [
            'last_checked_at' => now()->subHours(2),
            'last_status' => $status,
        ]);
    }
}
