<?php

namespace Modules\Core\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Link;

/**
 * Gives a model the ability to be joined to any other model.
 *
 * A link is undirected in practice — asking a card what it is linked to should
 * return an invoice whether the card or the invoice created the row — so every
 * read here looks at both ends.
 */
trait Linkable
{
    /** Every link row this model appears in, on either end. */
    public function links(): Collection
    {
        return Link::query()->touching($this)->get();
    }

    /**
     * Create a link. Idempotent: linking the same pair twice with the same
     * relation updates the existing row rather than creating a second one.
     */
    public function linkTo(Model $target, string $relation, ?array $meta = null): Link
    {
        return Link::query()->updateOrCreate(
            [
                'source_type' => $this->getMorphClass(),
                'source_id' => $this->getKey(),
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

    public function unlinkFrom(Model $target, ?string $relation = null): int
    {
        return Link::query()
            ->where(function ($q) use ($target) {
                $q->where(fn ($s) => $s
                    ->where('source_type', $this->getMorphClass())->where('source_id', $this->getKey())
                    ->where('target_type', $target->getMorphClass())->where('target_id', $target->getKey()))
                    ->orWhere(fn ($s) => $s
                        ->where('source_type', $target->getMorphClass())->where('source_id', $target->getKey())
                        ->where('target_type', $this->getMorphClass())->where('target_id', $this->getKey()));
            })
            ->when($relation, fn ($q) => $q->where('relation', $relation))
            ->delete();
    }

    /**
     * Models of one morph alias linked to this one, regardless of which end of
     * the link they sit on.
     *
     * @return \Illuminate\Support\Collection<int, Model>
     */
    public function linked(string $morphAlias, ?string $relation = null): \Illuminate\Support\Collection
    {
        $rows = Link::query()
            ->touching($this)
            ->when($relation, fn ($q) => $q->where('relation', $relation))
            ->get();

        $ids = $rows
            ->map(function (Link $link) use ($morphAlias) {
                if ($link->source_type === $morphAlias && $this->isOtherEnd($link, 'target')) {
                    return $link->source_id;
                }

                if ($link->target_type === $morphAlias && $this->isOtherEnd($link, 'source')) {
                    return $link->target_id;
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $class = Model::getActualClassNameForMorph($morphAlias);

        return $class::query()->whereIn((new $class)->getKeyName(), $ids)->get();
    }

    public function isLinkedTo(Model $target, ?string $relation = null): bool
    {
        return Link::query()
            ->touching($this)
            ->when($relation, fn ($q) => $q->where('relation', $relation))
            ->get()
            ->contains(function (Link $link) use ($target) {
                return ($link->source_type === $target->getMorphClass() && $link->source_id === $target->getKey())
                    || ($link->target_type === $target->getMorphClass() && $link->target_id === $target->getKey());
            });
    }

    /** True when this model occupies the given end of the link. */
    private function isOtherEnd(Link $link, string $end): bool
    {
        return $link->{$end.'_type'} === $this->getMorphClass()
            && (int) $link->{$end.'_id'} === (int) $this->getKey();
    }
}
