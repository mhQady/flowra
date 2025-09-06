<?php

namespace Flowra\Traits;

use Flowra\DTOs\Transition;
use Illuminate\Support\Str;
use Throwable;

trait HasTransitions
{
    use CanApplyTransitions;

    /** Discover methods with this suffix */
    protected const string TRANSITION_SUFFIX = 'Transition';
    public array $transitions = [];

    private function __bootstrapTransitions(): void
    {
        foreach (get_class_methods(static::class) as $m) {

            //check valid function name
            if (!str_ends_with(ucfirst($m), 'Transition'))
                continue;

            $base = Str::of($m)->beforeLast(static::TRANSITION_SUFFIX)->snake()->toString();

            if ($base === '')
                continue;

            //check valid function value
            if (!\is_callable([$this, $m]))
                continue;

            try {
                $t = $this->{$m}();
            } catch (Throwable $e) {
                continue;
            }

            if (!$t instanceof Transition)
                continue;

//            $t->key ??= $key;
            $this->transitions[$base] = $t;
        }
    }

    protected function __accessCachedTransitionAsProperty(string $name): ?Transition
    {
        $name = Str::of($name)->beforeLast(static::TRANSITION_SUFFIX)->snake()->toString();

        if (key_exists($name, $this->transitions)) {
            return $this->transitions[$name];
        }

        return null;
    }
}