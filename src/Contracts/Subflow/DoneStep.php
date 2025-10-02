<?php

namespace Flowra\Contracts\Subflow;


use Flowra\DTOs\Subflow;

interface DoneStep
{
    public function make(): Subflow;
}