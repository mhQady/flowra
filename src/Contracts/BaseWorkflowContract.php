<?php

namespace Flowra\Contracts;

use Flowra\DTOs\Transition;

interface BaseWorkflowContract
{
    /**
     * @return array|Transition[]
     */
    public static function transitionsSchema(): array;
}