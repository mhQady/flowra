<?php

namespace Flowra\Contracts\Subflow;


interface StartStep
{
    public function start(string $transition): ExitStep;
}