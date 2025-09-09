<?php

namespace Flowra\Traits;

use Flowra\Contracts\GuardContract;
use Flowra\DTOs\GuardDecision;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\GuardDeniedException;

trait CanEvaluateGuards
{
    private function __evaluateGuards(Transition $t)
    {
        foreach ($t->guards() as $g) {

//            try {

            $instance = $g;

            if (is_string($g)) {
                $instance = app($g);
            }


            $res = $instance instanceof GuardContract ? $instance->allows($t) : $instance($t);

            if ($res === false) {
                throw new GuardDeniedException('Transition cannot be applied, Guard denied.');
            }


            if ($res instanceof GuardDecision && !$res->allowed) {
                return $res;
            }
//
//            } catch (\Throwable $e) {
//                throw new GuardDeniedException($e->message ?? 'Transition cannot be applied, Guard denied.');
//            }

        }
    }
}
