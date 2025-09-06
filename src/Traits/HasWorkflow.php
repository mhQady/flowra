<?php

namespace Flowra\Traits;

use Flowra\DTOs\Transition;
use Flowra\Flows\BaseWorkflow;
use Throwable;

trait HasWorkflow
{
    use LoadWorkflowDynamically, HasWorkflowRelations;

    /**
     * @throws Throwable
     */
    public function applyTransition(BaseWorkflow $flow, Transition $transition, ?array $comment = null): BaseWorkflow
    {
        return $flow->apply($transition, $comment);
    }
}
