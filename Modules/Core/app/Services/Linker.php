<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Core\Contracts\Linker as LinkerContract;
use Modules\Core\Models\Link;

class Linker implements LinkerContract
{
    public function link(Model $source, Model $target, string $relation, ?array $meta = null): Link
    {
        return Link::query()->updateOrCreate(
            [
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'relation' => $relation,
            ],
            [
                'meta' => $meta,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ],
        );
    }

    public function unlink(Model $source, Model $target, ?string $relation = null): int
    {
        return Link::query()
            ->where(function ($q) use ($source, $target) {
                $q->where(fn ($s) => $s
                    ->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
                    ->where('target_type', $target->getMorphClass())->where('target_id', $target->getKey()))
                    ->orWhere(fn ($s) => $s
                        ->where('source_type', $target->getMorphClass())->where('source_id', $target->getKey())
                        ->where('target_type', $source->getMorphClass())->where('target_id', $source->getKey()));
            })
            ->when($relation, fn ($q) => $q->where('relation', $relation))
            ->delete();
    }

    public function related(Model $model, string $morphAlias, ?string $relation = null): Collection
    {
        $type = $model->getMorphClass();
        $id = $model->getKey();

        $rows = Link::query()
            ->touching($model)
            ->when($relation, fn ($q) => $q->where('relation', $relation))
            ->get();

        $ids = $rows->map(function (Link $link) use ($type, $id, $morphAlias) {
            $atSource = $link->source_type === $type && (int) $link->source_id === (int) $id;
            $atTarget = $link->target_type === $type && (int) $link->target_id === (int) $id;

            if ($atSource && $link->target_type === $morphAlias) {
                return $link->target_id;
            }

            if ($atTarget && $link->source_type === $morphAlias) {
                return $link->source_id;
            }

            return null;
        })->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $class = Model::getActualClassNameForMorph($morphAlias);
        $instance = new $class;

        return $class::query()->whereIn($instance->getKeyName(), $ids)->get();
    }

    public function isLinked(Model $a, Model $b, ?string $relation = null): bool
    {
        return Link::query()
            ->touching($a)
            ->when($relation, fn ($q) => $q->where('relation', $relation))
            ->get()
            ->contains(fn (Link $link) => ($link->source_type === $b->getMorphClass() && (int) $link->source_id === (int) $b->getKey())
                || ($link->target_type === $b->getMorphClass() && (int) $link->target_id === (int) $b->getKey()));
    }
}
