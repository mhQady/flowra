<?php

namespace Flowra\Contracts\Subflow;


use UnitEnum;

interface ExitStep
{
    public function exit(UnitEnum|string $exitState, string $outerTransition): DoneStep|ExitStep;
}
