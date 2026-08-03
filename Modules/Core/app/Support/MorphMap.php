<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The stable aliases stored in `links.source_type`, `activities.subject_type`
 * and `searchables.subject_type`.
 *
 * These rows outlive refactors. A fully-qualified class name in that column
 * becomes an orphan the moment a model is renamed or moved between modules, so
 * every polymorphic type is an alias registered here instead.
 *
 * Each module registers its own aliases from its service provider:
 *
 *     MorphMap::register(['card' => Card::class]);
 *
 * Core then calls `enforce()`, after which an unregistered model throws rather
 * than silently writing a class name.
 */
final class MorphMap
{
    /** @param array<string, class-string> $aliases */
    public static function register(array $aliases): void
    {
        Relation::morphMap($aliases);
    }

    /** @return array<string, class-string> */
    public static function all(): array
    {
        return Relation::morphMap();
    }

    public static function aliasFor(string $class): ?string
    {
        $alias = array_search($class, self::all(), true);

        return $alias === false ? null : $alias;
    }

    /**
     * After this, any model used polymorphically without an alias throws a
     * ClassMorphViolationException. That is the point: the failure should be
     * loud at development time, not silent in a column five months later.
     */
    public static function enforce(): void
    {
        Relation::requireMorphMap();
    }
}
