<?php

namespace Modules\Project\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Modules\Project\Models\Card;
use Modules\Project\Models\Watcher;

class WatcherFactory extends Factory
{
    protected $model = Watcher::class;

    /** Defaults to watching a card; `->for($list, 'watchable')` etc. overrides it, same as any morph factory. */
    public function definition(): array
    {
        $card = Card::factory()->create();

        return [
            'watchable_type' => $card->getMorphClass(),
            'watchable_id' => $card->id,
            'user_id' => User::factory(),
        ];
    }

    /** The common case in tests: attach the watch to a specific model without touching the morph columns by hand. */
    public function watching(Model $watchable): static
    {
        return $this->state(fn (): array => [
            'watchable_type' => $watchable->getMorphClass(),
            'watchable_id' => $watchable->getKey(),
        ]);
    }
}
