<?php

namespace Flowra;

use Flowra\Console\GenerateWorkflow;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class FlowraServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AboutCommand::add('Flowra', fn () => ['Version' => '1.0.0']);


        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateWorkflow::class,
            ]);
        }

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'flowra-migrations');

        // Publish config
        $this->publishes([
            __DIR__.'/../config/flowra.php' => config_path('flowra.php'),
        ], 'flowra-config');
    }
}