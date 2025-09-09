<?php

namespace Flowra\Contracts;

use Flowra\DTOs\Transition;

interface ActionContract
{
    public function execute(Transition $t): void;
}