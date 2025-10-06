<?php

namespace Flowra\Traits\Workflow;

use Flowra\DTOs\Transition;
use Illuminate\Support\Str;

trait HasTransitions
{
    use CanApplyTransitions;

    private static array $transitions = [];

    public static function bootHasTransitions(): void
    {
        foreach (static::transitionsSchema() as $t) {
            if (!$t instanceof Transition)
                continue;

            static::$transitions[static::class][$t->key] = $t;
        }
    }

    public static function transitions(): array
    {
        static::bootIfNotBooted();

        if (!isset(static::$transitions[static::class]))
            return [];

        return static::$transitions[static::class];
    }

    protected function __accessCachedTransitionAsProperty(string $name): ?Transition
    {
        $name = Str::of($name)->snake()->toString();
        if (in_array($name, array_keys(static::$transitions[static::class]))) {
            $t = self::$transitions[static::class][$name];
            $t->workflow($this);
            return $t;
        }

        return null;
    }
}