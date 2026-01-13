<?php

namespace Flowra\Concretes;

use Flowra\Contracts\HasWorkflowContract;
use Flowra\DTOs\BulkTransitionResult;
use Flowra\DTOs\Transition;
use Flowra\Models\{Registry, Status};
use Flowra\Services\BulkTransitionService;
use Flowra\Traits\Support\Bootable;
use Flowra\Traits\Workflow\{HasStates, HasTransitions};
use Illuminate\Database\Eloquent\Collection;
use Throwable;


class BaseWorkflow
{
    use Bootable, HasStates, HasTransitions;

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

    public function __get(string $name): ?Transition
    {
        if ($t = $this->accessCachedTransitionAsProperty($name)) {
            return $t;
        }

        return null;
    }

    /**
     * @throws Throwable
     */
    public static function applyMany(
        iterable $targets,
        string|Transition $transition,
        int|string|null $appliedBy = null,
        array $comments = [],
        bool $continueOnError = false,
        ?int $chunk = null
    ): BulkTransitionResult {
        return BulkTransitionService::for(static::class)
            ->apply($targets, $transition, $appliedBy, $comments, $continueOnError, $chunk);
    }
}
