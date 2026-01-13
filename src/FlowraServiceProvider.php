<?php

namespace Flowra;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class FlowraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/flowra.php', 'flowra');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Flowra\Console\MakeWorkflow::class,
                \Flowra\Console\MakeGuard::class,
                \Flowra\Console\MakeAction::class,
                \Flowra\Console\ExportWorkflowDiagram::class,
//                \Flowra\Console\ImportWorkflowDiagram::class,
//                \Flowra\Console\ClearWorkflowCache::class,
//                \Flowra\Console\WarmWorkflowCache::class,
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

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'flowra');

        $this->publishes([
            __DIR__.'/../lang' => base_path('lang/vendor/flowra'),
        ], 'flowra-translations');

        AboutCommand::add('Flowra', static fn() => [
            'Version' => InstalledVersions::getPrettyVersion('mhqady/flowra')
        ]);
    }
}
