<?php

namespace Tests;

use Flowra\FlowraServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Register the package's service providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            FlowraServiceProvider::class,
        ];
    }

    /**
     * Define environment setup (optional).
     */
    protected function defineEnvironment($app): void
    {
        // Example: configure database if your package needs it
        // $app['config']->set('database.default', 'testing');
        // $app['config']->set('database.connections.testing', [
        //     'driver' => 'sqlite',
        //     'database' => ':memory:',
        //     'prefix' => '',
        // ]);
    }
}
