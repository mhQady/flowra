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
        ?int $appliedBy = null,
    ) {
        parent::__construct($key, $from, $to, $appliedBy, TransitionTypesEnum::RESET->value);

        $this->workflow($workflow);
    }
}
