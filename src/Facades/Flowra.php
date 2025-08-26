<?php

namespace Flowra\Facades;

use Illuminate\Support\Facades\Facade;

class Flowra extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'flowra';
    }
}
