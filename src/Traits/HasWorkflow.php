<?php

namespace Flowra\Traits;

use Flowra\DTOs\Transition;
use Flowra\Flows\BaseFlow;
use Throwable;

trait HasWorkflow
{
    use LoadFlowDynamically, HasWorkflowRelations;

    /**
     * @throws Throwable
     */
    public function applyTransition(BaseFlow $flow, Transition $transition, ?array $comment = null): BaseFlow
    {
        return $flow->apply($transition, $comment);
    }
}
