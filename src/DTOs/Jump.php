<?php

namespace Flowra\DTOs;

use Flowra\Concretes\BaseWorkflow;
use Flowra\Enums\TransitionTypesEnum;
use UnitEnum;

class Jump extends Transition
{
    public function __construct(
        string $key,
        UnitEnum $from,
        UnitEnum $to,
        BaseWorkflow $workflow,
        // guard: fn($flow) => $flow->model->owner_name && $flow->model->owner_national_id,
        // action: fn($flow) => event(new OwnerInfoEntered($flow->model)),
        ?array $comment = null,
        ?int $appliedBy = null,  // optional user id
    )
    {
        parent::__construct($key, $from, $to, $workflow, $comment, $appliedBy, TransitionTypesEnum::RESET->value);
    }
}