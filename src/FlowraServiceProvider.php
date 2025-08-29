<?php

namespace Flowra;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class FlowraServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AboutCommand::add('Flowra', fn () => ['Version' => '1.0.0']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Flowra\Console\GenerateWorkflow::class,
            ]);

        }

        // Publish config
        $this->publishes([
            __DIR__.'/config/flowra.php' => config_path('flowra.php'),
        ], 'flowra-config');

        $this->publishes([
            __DIR__.'/database/migrations/' => database_path('migrations'),
        ], 'flowra-migrations');
    }
}
