<?php

namespace Flowra\DTOs;

use Flowra\Enums\TransitionTypesEnum;
use Flowra\Flows\BaseFlow;
use Throwable;
use UnitEnum;

class Transition
{
    public function __construct(
        public readonly string $key,
        public readonly UnitEnum $from,
        public readonly UnitEnum $to,
        public readonly BaseFlow $flow,
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
    public function apply(?array $comment = null): BaseFlow
    {
        return $this->flow->apply($this, $comment);
    }

}