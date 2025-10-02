<?php

namespace Flowra\Contracts\Subflow;


interface ToStep
{
    public function to(string $innerWorkflow): StartStep;
}