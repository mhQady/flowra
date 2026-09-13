<?php

namespace Flowra\Traits\Workflow;

use BackedEnum;
use Flowra\DTOs\Phase;
use Flowra\Support\WorkflowCache;
use RuntimeException;
use UnitEnum;

trait HasPhases
{
    use HasStateGroupAliases;

    private array $phases = [];
    private array $phaseParents = [];
    protected static array $cachedPhases = [];
    protected static array $cachedPhaseParents = [];
    protected static array $cachedPhaseMap = [];

    protected static function bootHasPhases(): void
    {
        static::cachedPhases();
    }

    protected function initializeHasPhases(): void
    {
        [$this->phases, $this->phaseParents] = static::cachedPhases();
    }

    public static function phases(): array
    {
        return static::cachedPhases()[0];
    }

    /**
     * The phase declared under this key, or null when no phase is keyed by it.
     *
     * A member state is not a key — ask phaseForState() or stateParentPhase() which phase a
     * state sits in.
     */
    public static function phase(UnitEnum|string|null $phase): ?array
    {
        if ($phase === null) {
            return null;
        }

        $key = static::stateKey($phase);
        [$phases] = static::cachedPhases();

        return $phases[$key] ?? null;
    }

    public static function phaseChildren(UnitEnum|string $phase): array
    {
        $definition = static::phase($phase);

        if (!$definition) {
            return [];
        }

        return $definition['children'] ?? [];
    }

    public static function stateParentPhase(UnitEnum|string $state): ?array
    {
        $key = static::stateKey($state);
        [, $parents] = static::cachedPhases();

        $parentKey = $parents[$key]['key'] ?? null;

        if (!$parentKey) {
            return null;
        }

        [$phases] = static::cachedPhases();

        return $phases[$parentKey] ?? null;
    }

    /**
     * The phase map: every state value that belongs to a phase, pointing at that phase.
     *
     * This is what the registry read layer collapses on — a row is part of a phase when the
     * state it landed in (`to`) belongs to that phase. A phase's own key resolves to itself as
     * well, so a real parent state that contains sub-states counts as being inside its own
     * phase.
     *
     * Derived from the already-cached phases, so it needs no WorkflowCache key of its own.
     *
     * @return array<string, array{key: string, label: ?string}>
     */
    public static function statePhases(): array
    {
        $workflow = static::class;

        if (isset(static::$cachedPhaseMap[$workflow])) {
            return static::$cachedPhaseMap[$workflow];
        }

        [$phases] = static::cachedPhases();

        $map = [];

        foreach ($phases as $key => $definition) {
            $phase = [
                'key' => (string) $key,
                'label' => $definition['label'] ?? null,
            ];

            $map[(string) $key] = $phase;

            foreach ($definition['children'] ?? [] as $child) {
                $childKey = (string) ($child['key'] ?? $child['value'] ?? '');

                if ($childKey !== '') {
                    $map[$childKey] = $phase;
                }
            }
        }

        return static::$cachedPhaseMap[$workflow] = $map;
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

    public static function isPhase(UnitEnum|string $state): bool
    {
        return static::phase($state) !== null;
    }

    public static function hasParentPhase(UnitEnum|string $state): bool
    {
        $key = static::stateKey($state);
        [, $parents] = static::cachedPhases();

        return isset($parents[$key]);
    }

    /**
     * @return array{0: array<string, array>, 1: array<string, array>}
     */
    private static function cachedPhases(): array
    {
        $workflow = static::class;

        if (!isset(static::$cachedPhases[$workflow], static::$cachedPhaseParents[$workflow])) {
            [$phases, $phaseParents] = static::buildPhaseCache();

            static::$cachedPhases[$workflow] = $phases;
            static::$cachedPhaseParents[$workflow] = $phaseParents;
        }

        return [static::$cachedPhases[$workflow], static::$cachedPhaseParents[$workflow]];
    }

    /**
     * @return array{0: array<string, array>, 1: array<string, array>}
     */
    private static function buildPhaseCache(): array
    {
        $statesEnum = static::cacheStates()[0];
        $compiled = null;

        $phases = WorkflowCache::remember(
            static::class,
            'phases',
            static function () use ($statesEnum, &$compiled) {
                $compiled ??= static::compilePhases($statesEnum);
                return $compiled['phases'];
            }
        );

        $phaseParents = WorkflowCache::remember(
            static::class,
            'phaseParents',
            static function () use ($statesEnum, &$compiled) {
                $compiled ??= static::compilePhases($statesEnum);
                return $compiled['parents'];
            }
        );

        return [
            is_array($phases) ? $phases : [],
            is_array($phaseParents) ? $phaseParents : [],
        ];
    }

    /**
     * Read the phases declared on the states enum.
     *
     * An enum that declares no phases() is still read through groups(), the declaration's
     * deprecated name from before state groups became phases.
     *
     * @param  class-string<UnitEnum>  $statesEnum
     * @return array{phases: array<string, array>, parents: array<string, array>}
     */
    private static function compilePhases(string $statesEnum): array
    {
        $declared = [];
        $method = method_exists($statesEnum, 'phases') ? 'phases' : 'groups';

        if (method_exists($statesEnum, $method)) {
            $declared = $statesEnum::$method();
        }

        $phases = [];
        $phaseParents = [];

        foreach ($declared as $phase) {

            if (!$phase instanceof Phase) {
                throw new RuntimeException("{$method}() must return an array of Phase objects.");
            }

            $phase = $phase->toArray();

            $stateMeta = static::normalizedStateMeta($phase['state'], $statesEnum);

            $childrenMeta = array_map(
                static fn(UnitEnum|string $child) => static::normalizedStateMeta($child),
                $phase['children'] ?? []
            );


            $phases[$stateMeta['key']] = [
                'state' => $stateMeta,
                'children' => $childrenMeta,
                'label' => $phase['label'] ?? null,
            ];

            foreach ($childrenMeta as $child) {
                $phaseParents[$child['key']] = $stateMeta;
            }
        }

        return ['phases' => $phases, 'parents' => $phaseParents];
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
