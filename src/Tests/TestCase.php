<?php

namespace Flowra\Tests;

use Flowra\FlowraServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [FlowraServiceProvider::class];
    }
}

