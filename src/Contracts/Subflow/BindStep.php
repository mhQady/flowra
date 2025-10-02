<?php

namespace Flowra\Contracts\Subflow;

use UnitEnum;

interface BindStep
{
    public function bind(string|UnitEnum $boundState): ToStep;
}
