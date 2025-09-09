<?php

namespace Flowra\Flows;

use Flowra\Contracts\HasWorkflowContract;
use Flowra\DTOs\Transition;
use Flowra\Models\Registry;
use Flowra\Models\Status;
use Flowra\Traits\HasStates;
use Flowra\Traits\HasTransitions;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class BaseWorkflow
{
    use HasStates, HasTransitions;

    public function __construct(public readonly HasWorkflowContract $model)
    {
        $this->__bindStates();

        $this->__bootstrapTransitions();
    }

    /** helper to construct a bound transition */
    protected function t(string $key, UnitEnum $from, UnitEnum $to, array $comment = []): Transition
    {
        return new Transition(key: $key, from: $from, to: $to, workflow: $this);
    }

    public function status(): ?Status
    {
        return Status::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this::class)
            ->first();
    }

    public function currentStatus(): ?UnitEnum
    {
        return $this->statesClass::tryFrom($this->status()?->to);
    }

    public function registry(): Collection
    {
        return Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this::class)
            ->get();
    }

    public function __get(string $name)
    {
        if ($t = $this->__accessCachedTransitionAsProperty($name))
            return $t;

        return null;
    }
}