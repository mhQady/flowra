<?php

namespace Flowra\Concretes;

use Flowra\Contracts\HasWorkflowContract;
use Flowra\Models\{Registry, Status};
use Flowra\Traits\Support\Bootable;
use Flowra\Traits\Workflow\{HasStates, HasSubflow, HasTransitions};
use Illuminate\Database\Eloquent\Collection;
use Str;

/**
 * @todo
 * An inner flow allows a single state in your main workflow to trigger and manage another, separate workflow (the inner flow).
 * The parent workflow usually waits until the inner flow completes (reaches a terminal state), then resumes its own transitions.
 */
class BaseWorkflow
{
    use Bootable, HasStates, HasTransitions, HasSubflow;

    public function __construct(public readonly HasWorkflowContract $model)
    {
        static::bootIfNotBooted();

        $this->initializeTraits();
    }

    public static function transitionsSchema(): array
    {
        return [];
    }

    public function status(): ?Status
    {
        return Status::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this::class)
            ->first();
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

        $innerName = Str::snake($name);
        if (isset($this->subflows[$innerName]))
            return $this->subflows[$innerName];

        return null;
    }
}