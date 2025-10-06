<?php

namespace Flowra\Traits;

use Exception;
use Flowra\Casts\WorkflowCast;
use Flowra\Concretes\BaseWorkflow;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\App;
use Str;

trait WorkflowAware
{
    /**
     * Initialize the trait.
     * @throws Exception
     */
    public function initializeWorkflowAware(): void
    {
        $this->mergeCasts($this->workflowsCasts());
    }

    /**
     * Lazily make (and cache) an instance of the workflow class.
     * @throws BindingResolutionException
     */
    public function hydrateWorkflow(string $class): BaseWorkflow
    {
        return App::make($class, ['model' => $this]);
    }

    /**
     * Get the casts for the workflows.
     * @throws Exception
     */
    private function workflowsCasts(): array
    {
        $casts = [];

        foreach (static::appliedWorkflows() as $class) {

            if (!is_subclass_of($class, BaseWorkflow::class)) {
                throw new \Exception("Workflow class $class must extend BaseWorkflow");
            }

            $shortName = Str::of(class_basename($class))->camel()->toString();
            $casts [$shortName] = [WorkflowCast::class.":$class"];
        }

        return $casts;
    }
}