<?php

namespace Flowra\Concretes;

use Flowra\Traits\HasWorkflowRelations;
use Flowra\Traits\WorkflowAware;

trait HasWorkflow
{
    use WorkflowAware, HasWorkflowRelations;

    public static function appliedWorkflows(): array
    {
        return property_exists(static::class, 'workflows') ? static::$workflows : [];
    }
}
