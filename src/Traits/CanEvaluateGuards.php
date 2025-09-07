<?php

namespace Flowra\Traits;
use Flowra\DTOs\Transition;
use Flowra\Contracts\GuardContract;
use Flowra\DTOs\GuardDecision;

trait CanEvaluateGuards
{
  private function __evaluateGuards(Transition $t): GuardDecision
  {
    // dd($t->guards());
    foreach ($t->guards() as $g) {
      try {

        $instance = $g;

        if (is_string($g))
          $instance = app($g);

        $res = $instance instanceof GuardContract ? $instance->allow($this, $t) : $instance($this, $t);

        if ($res === false)
          return GuardDecision::deny();


        if ($res instanceof GuardDecision && !$res->allowed)
          return $res;

      } catch (\Throwable $e) {
        return GuardDecision::deny($e->getMessage(), 'exception');
      }
    }

    return GuardDecision::allow();
  }
}