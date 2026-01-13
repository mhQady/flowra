<?php

namespace Flowra\Traits\Workflow;

use Flowra\DTOs\Transition;
use Flowra\Support\WorkflowCache;

trait HasTransitions
{
    use CanApplyTransitions;

    private array $transitions = [];
    protected static array $cachedTransitions = [];

    public static function bootHasTransitions(): void
    {
        static::cachedTransitions();
    }

    public function initializeHasTransitions(): void
    {
        $this->transitions = static::cloneTransitions();
    }

    public static function transitions(): array
    {
        return static::cloneTransitions();
    }

    protected static function cachedTransitions(): array
    {
        $workflow = static::class;

        if (isset(static::$cachedTransitions[$workflow])) {
            return static::$cachedTransitions[$workflow];
        }

        $transitions = WorkflowCache::remember($workflow, 'transitions',
            static function () {
                $transitions = [];

                foreach (static::transitionsSchema() as $t) {

                    if (!$t instanceof Transition) {
                        continue;
                    }

                    $transitions[$t->key] = $t;
                }

                return $transitions;
            }
        );

        return static::$cachedTransitions[$workflow] = is_array($transitions) ? $transitions : [];
    }

    private static function cloneTransitions(): array
    {
        $transitions = array_filter(
            static::cachedTransitions(),
            static fn($t) => $t instanceof Transition
        );

        return array_map(static fn(Transition $t) => clone $t, $transitions);
    }

    protected function accessCachedTransitionAsProperty(string $name): ?Transition
    {
        $name = str_replace([' ', '-'], '_', $name);
        $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        if ($this->transitions === []) {
            $this->transitions = static::cloneTransitions();
        }

        if (array_key_exists($name, $this->transitions)) {
            $t = $this->transitions[$name];
            $t->workflow($this);
            return $t;
        }

        return null;
    }
}
