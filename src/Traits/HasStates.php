<?php

namespace Flowra\Traits;

trait HasStates
{
    public string $statesClass;

    private function __bindStates(): void
    {
        $statesClass = static::class.'States';

        if (class_exists($statesClass))
            $this->statesClass = static::class.'States';

    }
}