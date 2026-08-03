<?php

namespace Modules\Core\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Core\Models\Link;

/**
 * How a module joins one of its records to a record it does not own.
 */
interface Linker
{
    public function link(Model $source, Model $target, string $relation, ?array $meta = null): Link;

    public function unlink(Model $source, Model $target, ?string $relation = null): int;

    /**
     * Records of one morph alias joined to the given model.
     *
     * @return Collection<int, Model>
     */
    public function related(Model $model, string $morphAlias, ?string $relation = null): Collection;

    public function isLinked(Model $a, Model $b, ?string $relation = null): bool;
}
