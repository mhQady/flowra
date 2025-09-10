<?php

namespace Flowra\Traits;

use Flowra\BaseWorkflow;
use Flowra\DTOs\Transition;
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
