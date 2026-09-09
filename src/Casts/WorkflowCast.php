<?php

namespace Flowra\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;


class WorkflowCast implements CastsAttributes
{
    public function __construct(protected ?string $workflowClass)
    {
    }

    public function get(Model $model, string $key, $value, array $attributes)
    {
        return $model->hydrateWorkflow($this->workflowClass);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        // The workflow is a virtual attribute — nothing is persisted for it. Returning an
        // empty array keeps it out of the model's attributes; returning null/void would be
        // normalized by Eloquent to [$key => null] and leak a non-existent "{$key}" column
        // into the next UPDATE once the cast has been accessed.
        return [];
    }
}
