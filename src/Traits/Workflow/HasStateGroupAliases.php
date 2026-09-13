<?php

namespace Flowra\Traits\Workflow;

use UnitEnum;

/**
 * The phase helpers under the names they had before "state group" became "phase".
 *
 * Each one forwards to its phase-named replacement and exists only so code written against
 * v0.2.0 keeps running. Delete this trait, its `use` in HasPhases, src/DTOs/StateGroup.php and
 * the groups() fallback in HasPhases::compilePhases() at the next breaking release.
 *
 * @deprecated Use the phase-named helpers on HasPhases.
 */
trait HasStateGroupAliases
{
    /** @deprecated Use phases(). */
    public static function stateGroups(): array
    {
        return static::phases();
    }

    /** @deprecated Use phase(). */
    public static function stateGroupFor(UnitEnum|string|null $state): ?array
    {
        return static::phase($state);
    }

    /** @deprecated Use phaseChildren(). */
    public static function stateGroupChildren(UnitEnum|string $state): array
    {
        return static::phaseChildren($state);
    }

    /** @deprecated Use stateParentPhase(). */
    public static function stateParentGroup(UnitEnum|string $state): ?array
    {
        return static::stateParentPhase($state);
    }

    /** @deprecated Use isPhase(). */
    public static function isGroupedState(UnitEnum|string $state): bool
    {
        return static::isPhase($state);
    }

    /** @deprecated Use hasParentPhase(). */
    public static function hasParentGroup(UnitEnum|string $state): bool
    {
        return static::hasParentPhase($state);
    }
}
