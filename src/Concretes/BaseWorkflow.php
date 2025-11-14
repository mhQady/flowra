<?php

namespace Flowra\Concretes;

use Flowra\Contracts\HasWorkflowContract;
use Flowra\Models\{Registry, Status};
use Flowra\Traits\Support\Bootable;
use Flowra\Traits\Workflow\{HasStates, HasSubflow, HasTransitions};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class BaseWorkflow
{
    use Bootable;
    use HasStates;
    use HasTransitions;
    use HasSubflow;

    public function __construct(public readonly HasWorkflowContract $model)
    {
        static::bootIfNotBooted();

        $this->initializeTraits();
    }

    /**
     * @return Status|null
     */
    public function status(): ?Status
    {
        return Status::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this::class)
            ->first();
    }

    /**
     * @return Collection
     */
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
        $name = Str::camel($name);

        if ($t = $this->resolveTransitionProperty($name)) {
            return $t;
        }

        if ($sub = $this->resolveSubflowProperty($name)) {
            return $sub;
        }

        //        $innerName = Str::snake($name);
        //        if (isset($this->subflows[$innerName]))
        //            return $this->subflows[$innerName];

        return null;
    }
}
