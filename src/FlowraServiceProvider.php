<?php

namespace Flowra;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class FlowraServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AboutCommand::add('Flowra', fn() => [
            'Version' => '0.1.2'
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Flowra\Console\GenerateWorkflow::class,
                \Flowra\Console\ListWorkflow::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/config/flowra.php' => config_path('flowra.php'),
        ], 'flowra-config');

        $timestamp = date('Y_m_d_His');

        $this->publishes([
            __DIR__.'/database/migrations/create_flowra_tables.php' => database_path("migrations/{$timestamp}_create_flowra_tables.php"),
        ], 'flowra-migrations');

        $this->publishes([
            __DIR__.'/stubs' => base_path('stubs/flowra'),
        ], 'flowra-stubs');
    }
}
