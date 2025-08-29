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
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            // keep the DB file inside your package so it persists between runs:
            'database' => __DIR__.'/../src/database/testing.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }


    protected function defineDatabaseMigrations(): void
    {
        // Load your package migrations
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

    }
}
