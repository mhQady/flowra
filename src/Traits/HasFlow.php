<?php

namespace Flowra\Traits;

use Flowra\DTOs\Transition;
use Flowra\Flows\BaseFlow;
use Flowra\Models\Registry;
use Flowra\Models\Status;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Throwable;

trait HasFlow
{
    use LoadFlowDynamically;

    // TODO: define way to force user to implement registry function for each workflow
    public function statusesRegistry(): MorphMany
    {
        return $this->morphMany(Registry::class, 'owner');
    }

// TODO: define way to force user to implement status function for each workflow
    public function statuses()
    {
        return $this->morphMany(Status::class, 'owner');
    }

    /**
     * @throws Throwable
     */
    public function applyTransition(BaseFlow $flow, Transition $transition, ?array $comment = null): BaseFlow
    {
        return $flow->apply($transition, $comment);
    }
}
