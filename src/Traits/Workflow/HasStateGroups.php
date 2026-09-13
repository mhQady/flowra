<?php

namespace Flowra\Traits\Workflow;

use BackedEnum;
use Flowra\DTOs\StateGroup;
use Flowra\Support\WorkflowCache;
use RuntimeException;
use UnitEnum;

trait HasStateGroups
{
    private array $stateGroups = [];
    private array $stateGroupParents = [];
    protected static array $cachedStateGroups = [];
    protected static array $cachedStateGroupParents = [];
    protected static array $cachedStatePhases = [];

    protected static function bootHasStateGroups(): void
    {
        static::cachedStateGroups();
    }

    protected function initializeHasStateGroups(): void
    {
        [$this->stateGroups, $this->stateGroupParents] = static::cachedStateGroups();
    }

    public static function stateGroups(): array
    {
        return static::cachedStateGroups()[0];
    }

    public static function stateGroupFor(UnitEnum|string|null $state): ?array
    {
        if ($state === null) {
            return null;
        }

        $key = static::stateKey($state);
        [$stateGroups] = static::cachedStateGroups();

        return $stateGroups[$key] ?? null;
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
        $key = static::stateKey($state);
        [, $parents] = static::cachedStateGroups();

        $parentKey = $parents[$key]['key'] ?? null;

        if (!$parentKey) {
            return null;
        }

        [$stateGroups] = static::cachedStateGroups();

        return $stateGroups[$parentKey] ?? null;
    }

    /**
     * The phase map: every state value that belongs to a group, pointing at that group.
     *
     * This is what the registry read layer collapses on — a row is part of a phase when the
     * state it landed in (`to`) belongs to that phase's group. A group's own key resolves to
     * itself as well, so a real parent state that contains sub-states counts as being inside
     * its own phase.
     *
     * Derived from the already-cached group maps, so it needs no WorkflowCache key of its own.
     *
     * @return array<string, array{key: string, label: ?string}>
     */
    public static function statePhases(): array
    {
        $workflow = static::class;

        if (isset(static::$cachedStatePhases[$workflow])) {
            return static::$cachedStatePhases[$workflow];
        }

        [$stateGroups] = static::cachedStateGroups();

        $phases = [];

        foreach ($stateGroups as $key => $group) {
            $phase = [
                'key' => (string) $key,
                'label' => $group['label'] ?? null,
            ];

            $phases[(string) $key] = $phase;

            foreach ($group['children'] ?? [] as $child) {
                $childKey = (string) ($child['key'] ?? $child['value'] ?? '');

                if ($childKey !== '') {
                    $phases[$childKey] = $phase;
                }
            }
        }

        return static::$cachedStatePhases[$workflow] = $phases;
    }

    /**
     * The phase a state belongs to, if any.
     *
     * @return array{key: string, label: ?string}|null
     */
    public static function phaseForState(UnitEnum|string|null $state): ?array
    {
        if ($state === null) {
            return null;
        }

        return static::statePhases()[static::stateKey($state)] ?? null;
    }

    public static function isGroupedState(UnitEnum|string $state): bool
    {
        return static::stateGroupFor($state) !== null;
    }

    public static function hasParentGroup(UnitEnum|string $state): bool
    {
        $key = static::stateKey($state);
        [, $parents] = static::cachedStateGroups();

        return isset($parents[$key]);
    }

    /**
     * @return array{0: array<string, array>, 1: array<string, array>}
     */
    private static function cachedStateGroups(): array
    {
        $workflow = static::class;

        if (!isset(static::$cachedStateGroups[$workflow], static::$cachedStateGroupParents[$workflow])) {
            [$stateGroups, $stateGroupParents] = static::buildStateGroupCache();

            static::$cachedStateGroups[$workflow] = $stateGroups;
            static::$cachedStateGroupParents[$workflow] = $stateGroupParents;
        }

        return [static::$cachedStateGroups[$workflow], static::$cachedStateGroupParents[$workflow]];
    }

    /**
     * @return array{0: array<string, array>, 1: array<string, array>}
     */
    private static function buildStateGroupCache(): array
    {
        $statesEnum = static::cacheStates()[0];
        $compiled = null;

        $stateGroups = WorkflowCache::remember(
            static::class,
            'stateGroups',
            static function () use ($statesEnum, &$compiled) {
                $compiled ??= static::compileStateGroups($statesEnum);
                return $compiled['groups'];
            }
        );

        $stateGroupParents = WorkflowCache::remember(
            static::class,
            'stateGroupParents',
            static function () use ($statesEnum, &$compiled) {
                $compiled ??= static::compileStateGroups($statesEnum);
                return $compiled['parents'];
            }
        );

        return [
            is_array($stateGroups) ? $stateGroups : [],
            is_array($stateGroupParents) ? $stateGroupParents : [],
        ];
    }

    /**
     * @param  class-string<UnitEnum>  $statesEnum
     * @return array{groups: array<string, array>, parents: array<string, array>}
     */
    private static function compileStateGroups(string $statesEnum): array
    {
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
                'label' => $group['label'] ?? null,
            ];

            foreach ($childrenMeta as $child) {
                $stateGroupParents[$child['key']] = $stateMeta;
            }
        }

        return ['groups' => $stateGroups, 'parents' => $stateGroupParents];
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
