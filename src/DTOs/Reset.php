<?php

namespace Flowra\DTOs;

use Flowra\Enums\TransitionTypesEnum;
use Flowra\Flows\BaseFlow;
use UnitEnum;

class Reset extends Transition
{
    public function __construct(
        string $key,
        UnitEnum $from,
        UnitEnum $to,
        BaseFlow $flow,
        // guard: fn($flow) => $flow->model->owner_name && $flow->model->owner_national_id,
        // action: fn($flow) => event(new OwnerInfoEntered($flow->model)),
        ?array $comment = null,
        ?int $appliedBy = null,  // optional user id
    )
    {
        parent::__construct($key, $from, $to, $flow, $comment, $appliedBy, TransitionTypesEnum::RESET->value);
    }
}