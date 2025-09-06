<?php

namespace Flowra\DTOs;

use Flowra\Enums\TransitionTypesEnum;
use Flowra\Flows\BaseWorkflow;
use Throwable;
use UnitEnum;

class Transition
{
    public function __construct(
        public readonly string $key,
        public readonly UnitEnum $from,
        public readonly UnitEnum $to,
        public readonly BaseWorkflow $workflow,
        // guard: fn($flow) => $flow->model->owner_name && $flow->model->owner_national_id,
        // action: fn($flow) => event(new OwnerInfoEntered($flow->model)),
        public ?array $comment = null,
        public ?int $appliedBy = null,  // optional user id
        public int $type = TransitionTypesEnum::TRANSITION->value
    ) {

    }

    /**
     * @throws Throwable
     */
    public function apply(?array $comment = null): BaseWorkflow
    {
        return $this->workflow->apply($this, $comment);
    }

}