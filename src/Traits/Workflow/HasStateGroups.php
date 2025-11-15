<?php

namespace Flowra\Traits\Workflow;

use BackedEnum;
use Flowra\DTOs\StateGroup;
use UnitEnum;

trait HasStateGroups
{
    private static array $stateGroups = [];
    private static array $stateGroupParents = [];

    protected static function bootStateGroups(string $statesClass): void
    {
        static::__fillStateGroups($statesClass);
    }

    public static function stateGroups(): array
    {
        static::bootIfNotBooted();

        return static::$stateGroups[static::class] ?? [];
    }

    public static function stateGroupFor(UnitEnum|string|null $state): ?array
    {
        if ($state === null) {
            return null;
        }

        static::bootIfNotBooted();

        $key = static::stateKey($state);

        return static::$stateGroups[static::class][$key] ?? null;
    }

    public static function stateGroupChildren(UnitEnum|string $state): array
    {
        $group = static::stateGroupFor($state);

        if (!$group) {
            return [];
        }

        return $group['children'] ?? [];
    }

    public static function stateParentGroup(UnitEnum|string $state): ?array
    {
        static::bootIfNotBooted();

        $key = static::stateKey($state);

        $parentKey = static::$stateGroupParents[static::class][$key]['key'] ?? null;

        if (!$parentKey) {
            return null;
        }

        return static::$stateGroups[static::class][$parentKey] ?? null;
    }

    public static function isGroupedState(UnitEnum|string $state): bool
    {
        return static::stateGroupFor($state) !== null;
    }

    public static function hasParentGroup(UnitEnum|string $state): bool
    {
        static::bootIfNotBooted();

        $key = static::stateKey($state);

        return isset(static::$stateGroupParents[static::class][$key]);
    }

    private static function __fillStateGroups(string $statesClass): void
    {
        $groups = [];

        if (method_exists($statesClass, 'groups')) {
            $groups = $statesClass::groups();
        }

        foreach ($groups as $group) {
            if ($group instanceof StateGroup) {
                $group = $group->toArray();
            }

            if (!isset($group['state'])) {
                continue;
            }

            $stateMeta = static::normalizedStateMeta($group['state'], $statesClass);
            $childrenMeta = array_map(
                fn(UnitEnum|string $child) => static::normalizedStateMeta($child),
                $group['children'] ?? []
            );

            static::$stateGroups[static::class][$stateMeta['key']] = [
                'state' => $stateMeta,
                'children' => $childrenMeta,
            ];

            foreach ($childrenMeta as $child) {
                static::$stateGroupParents[static::class][$child['key']] = $stateMeta;
            }
        }
    }

    private static function normalizedStateMeta(UnitEnum|string $state, ?string $defaultEnum = null): array
    {
        $meta = [
            'key' => static::stateKey($state),
            'enum' => $defaultEnum,
            'name' => null,
            'value' => null,
        ];

        if ($state instanceof UnitEnum) {
            $meta['enum'] = $state::class;
            $meta['name'] = $state->name;
            $meta['value'] = $state instanceof BackedEnum ? $state->value : $state->name;
        } else {
            $meta['value'] = (string) $state;
        }

        return $meta;
    }

    private static function stateKey(UnitEnum|string $state): string
    {
        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        if ($state instanceof UnitEnum) {
            return $state->name;
        }

        return (string) $state;
    }
}
