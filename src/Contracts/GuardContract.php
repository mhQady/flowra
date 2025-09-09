<?php

namespace Flowra\Contracts;

use Flowra\DTOs\Transition;

interface GuardContract
{
    public function allows(Transition $transition): bool;
}
