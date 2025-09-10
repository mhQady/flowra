<?php

namespace Flowra\Traits;

use Flowra\Contracts\ActionContract;
use Flowra\DTOs\Transition;

trait CanExecuteActions
{
    private function __executeActions(Transition $t): void
    {
        foreach ($t->actions() as $action) {

            $instance = $action;

            if (is_string($action)) {
                $instance = app($action);
            }

            $instance instanceof ActionContract ? $instance->execute($t) : $instance($t);
            
        }
    }
}