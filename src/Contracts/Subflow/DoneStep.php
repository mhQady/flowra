<?php

namespace Flowra\Contracts\Subflow;


interface DoneStep
{
    public function done(): array;
}