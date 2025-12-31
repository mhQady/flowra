<?php

namespace Flowra\Traits\Workflow;

use Flowra\Contracts\GuardContract;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\GuardDeniedException;

trait CanEvaluateGuards
{
    private function evaluateGuards(Transition $t): void
    {
        foreach ($t->guards() as $g) {

            $instance = $g;

            if (is_string($g)) {
                $instance = app($g);
            }

            $res = $instance instanceof GuardContract ? $instance->allows($t) : $instance($t);

            if ($res === false) {
                throw new GuardDeniedException('Transition cannot be applied, Guard denied.');
            }

        }
    }
}
