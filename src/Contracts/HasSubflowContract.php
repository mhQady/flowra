<?php

namespace Flowra\Contracts;

interface HasSubflowContract
{
    public function defineSubflows(): array;
}