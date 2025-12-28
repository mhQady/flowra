<?php

namespace Flowra\Traits\Workflow;

use Flowra\DTOs\Transition;
use Flowra\Support\WorkflowCache;

trait HasTransitions
{
    use CanApplyTransitions, CanApplyBulkTransitions;

    private array $transitions = [];

    public static function bootHasTransitions(): void
    {
        $transitions = [];

        foreach (static::transitionsSchema() as $t) {

            if (!$t instanceof Transition) {
                continue;
            }

            $transitions[$t->key] = $t;
        }

        WorkflowCache::rememberIfMissing(static::class, 'transitions',
            static fn() => $transitions);
    }

    public function initializeHasTransitions(): void
    {
        $this->transitions = WorkflowCache::get(static::class, 'transitions');
    }

    public function transitions(): array
    {
        return $this->transitions;
    }

    protected function accessCachedTransitionAsProperty(string $name): ?Transition
    {
        $name = str_replace([' ', '-'], '_', $name);
        $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        if (array_key_exists($name, $this->transitions)) {
            $t = $this->transitions[$name];
            $t->workflow($this);
            return $t;
        }

        return null;
    }
}
