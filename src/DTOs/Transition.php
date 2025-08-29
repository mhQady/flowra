<?php

namespace Flowra\DTOs;

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
        public array $comment = [],     // optional payload to store in history/status
        public ?int $appliedBy = null,  // optional user id
    )
    {

    }

    /**
     * @throws Throwable
     */
    public function apply(): BaseFlow
    {
        return $this->flow->apply($this);
    }

}