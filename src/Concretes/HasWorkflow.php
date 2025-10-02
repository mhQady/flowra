<?php

namespace Flowra\Concretes;

use Flowra\Traits\HasWorkflowRelations;
use Flowra\Traits\LoadWorkflowDynamically;

trait HasWorkflow
{
    use LoadWorkflowDynamically, HasWorkflowRelations;
}
