<?php

namespace Flowra\Support;

use Closure;
use Flowra\Contracts\HasWorkflowContract;
use Flowra\Contracts\RegistryFilterContract;
use Flowra\Contracts\RegistryScopeContract;
use Flowra\Models\Registry;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Resolves registry-view conditions the same way CanEvaluateGuards resolves guards:
 * class-strings come out of the container, instances and closures pass through.
 *
 * Resolved conditions are then split by capability so row-level filtering happens in
 * SQL wherever the condition can express itself there, and only falls back to PHP for
 * closures (and for filters that genuinely cannot be a query).
 */
final class RegistryConditionResolver
{
    /**
     * @param  array<int, Closure|object|class-string>  $conditions
     * @return array{scopes: array<int, RegistryScopeContract>, filters: array<int, Closure|RegistryFilterContract>}
     */
    public static function partition(array $conditions): array
    {
        $scopes = [];
        $filters = [];

        foreach ($conditions as $condition) {
            $resolved = self::resolve($condition);

            $matched = false;

            if ($resolved instanceof RegistryScopeContract) {
                $scopes[] = $resolved;
                $matched = true;
            }

            if ($resolved instanceof RegistryFilterContract || $resolved instanceof Closure) {
                $filters[] = $resolved;
                $matched = true;
            }

            if (! $matched) {
                throw new InvalidArgumentException(sprintf(
                    'Registry view condition [%s] must be a Closure or implement %s or %s.',
                    get_debug_type($resolved),
                    RegistryScopeContract::class,
                    RegistryFilterContract::class
                ));
            }
        }

        return ['scopes' => $scopes, 'filters' => $filters];
    }

    /**
     * @param  array<int, RegistryScopeContract>  $scopes
     */
    public static function scope(array $scopes, Builder $query, HasWorkflowContract $owner, mixed $viewer = null): void
    {
        foreach ($scopes as $scope) {
            $scope->apply($query, $owner, $viewer);
        }
    }

    /**
     * A row is kept only when every condition returns a truthy value.
     *
     * @param  array<int, Closure|RegistryFilterContract>  $filters
     */
    public static function allows(array $filters, Registry $row, HasWorkflowContract $owner, mixed $viewer = null): bool
    {
        foreach ($filters as $filter) {
            $result = $filter instanceof RegistryFilterContract
                ? $filter->allows($row, $owner, $viewer)
                : $filter($row, $owner, $viewer);

            if (! $result) {
                return false;
            }
        }

        return true;
    }

    private static function resolve(object|string $condition): object
    {
        return is_string($condition) ? app($condition) : $condition;
    }
}
