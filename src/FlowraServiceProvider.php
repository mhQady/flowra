<?php

namespace Flowra;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class FlowraServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Flowra\Console\MakeWorkflow::class,
                \Flowra\Console\MakeGuard::class,
                \Flowra\Console\MakeAction::class,
                \Flowra\Console\ListWorkflow::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/config/flowra.php' => config_path('flowra.php'),
        ], 'flowra-config');

        $timestamp = date('Y_m_d_His');

        $this->publishes([
            __DIR__.'/database/migrations/create_flowra_tables.php' => database_path("migrations/{$timestamp}_create_flowra_tables.php"),
            __DIR__.'/database/migrations/create_contexts_table.php' => database_path("migrations/{$timestamp}_create_contexts_table.php"),
        ], 'flowra-migrations');

        $this->publishes([
            __DIR__.'/stubs' => base_path('stubs/flowra'),
        ], 'flowra-stubs');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'flowra');

        $this->publishes([
            __DIR__.'/../lang' => base_path('lang/vendor/flowra'),
        ], 'flowra-translations');

        AboutCommand::add('Flowra', fn() => [
            'Version' => InstalledVersions::getPrettyVersion('mhqady/flowra')
        ]);
    }
}
