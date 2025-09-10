<?php

namespace Flowra\Traits\Workflow;

trait HasStates
{
    public string $statesClass;

    private function __bindStates(): void
    {
        if (isset($this->statesClass)) return;

        $statesClass = static::class.'States';

        if (class_exists($statesClass))
            $this->statesClass = static::class.'States';

    }
}