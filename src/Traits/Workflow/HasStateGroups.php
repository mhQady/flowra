<?php

namespace Flowra\Traits\Workflow;

use BackedEnum;
use Flowra\DTOs\StateGroup;
use Flowra\Support\WorkflowCache;
use http\Exception\RuntimeException;
use UnitEnum;

trait HasStateGroups
{
    private array $stateGroups = [];
    private array $stateGroupParents = [];

    protected static function bootHasStateGroups(): void
    {
        static::fillStateGroups();
    }

    protected function initializeHasStateGroups(): void
    {
        $this->stateGroups = WorkflowCache::get(static::class, 'stateGroups');
        $this->stateGroupParents = WorkflowCache::get(static::class, 'stateGroupParents');
    }

    public function stateGroups(): array
    {
//        static::bootIfNotBooted();

        return $this->stateGroups;
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

    private static function fillStateGroups(): void
    {
        $statesEnum = WorkflowCache::get(static::class, 'statesEnum');

        $groups = [];

        if (method_exists($statesEnum, 'groups')) {
            $groups = $statesEnum::groups();
        }

        $stateGroups = [];
        $stateGroupParents = [];

        foreach ($groups as $group) {

            if (!$group instanceof StateGroup) {
                throw new RuntimeException('groups() must return an array of StateGroup objects.');
            }

            $group = $group->toArray();

            $stateMeta = static::normalizedStateMeta($group['state'], $statesEnum);

            $childrenMeta = array_map(
                static fn(UnitEnum|string $child) => static::normalizedStateMeta($child),
                $group['children'] ?? []
            );


            $stateGroups[$stateMeta['key']] = [
                'state' => $stateMeta,
                'children' => $childrenMeta,
            ];

            foreach ($childrenMeta as $child) {
                $stateGroupParents[$child['key']] = $stateMeta;
            }
        }

        WorkflowCache::rememberIfMissing(static::class, 'stateGroups',
            static fn() => $stateGroups);

        WorkflowCache::rememberIfMissing(static::class, 'stateGroupParents',
            static fn() => $stateGroupParents);
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
