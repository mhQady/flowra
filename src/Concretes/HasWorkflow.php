<?php

namespace Flowra\Concretes;

use Flowra\Traits\HasWorkflowRelations;
use Flowra\Traits\HasWorkflowViewShortcuts;
use Flowra\Traits\WorkflowAware;

trait HasWorkflow
{
    use WorkflowAware, HasWorkflowRelations, HasWorkflowViewShortcuts;

    public static function appliedWorkflows(): array
    {
        return property_exists(static::class, 'workflows') ? static::$workflows : [];
    }
}
