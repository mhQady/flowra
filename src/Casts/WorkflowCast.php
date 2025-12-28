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
        //
    }
}
