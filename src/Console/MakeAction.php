<?php

namespace Flowra\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeAction extends Command
{
    protected $signature = 'flowra:make-action
        {name : Action name, e.g. NotifyUser}
        {--path=app/Workflows/Actions : Directory where the action will be created}
        {--namespace=App\\Workflows\\Actions : Namespace for the generated class}
        {--force : Overwrite if the file already exists}';

    protected $description = 'Create a new Flowra action class.';

    public function handle(Filesystem $files): int
    {
        // Normalize name → ensure it ends with "Action"
        $raw = trim($this->argument('name'));
        $studly = Str::studly($raw);
        if (!Str::endsWith($studly, 'Action')) {
            $studly .= 'Action';
        }

        // Resolve paths & namespace
        $basePath = base_path(Str::finish($this->option('path'), '/'));
        $namespace = rtrim($this->option('namespace'), '\\');
        $filename = $basePath.$studly.'.php';
        $class = $studly;

        if (!$files->isDirectory($basePath)) {
            $files->makeDirectory($basePath, 0777, true);
        }

        // Load stub (published first, then package fallback)
        $stub = $this->getStub('action.stub');

        // Render
        $rendered = strtr($stub, [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
        ]);

        // Write
        if ($files->exists($filename) && !$this->option('force')) {
            $this->warn("⏭  Skipped (exists): {$filename} (use --force to overwrite)");
            return self::SUCCESS;
        }

        $files->put($filename, $rendered);
        $this->info("✅ Action generated: {$namespace}\\{$class}");
        $this->line("   • Wrote: <info>{$filename}</info>");

        return self::SUCCESS;
    }

    private function getStub(string $name): string
    {
        $name = ltrim($name, '/\\');

        // 1) app-published stubs take precedence
        $appStub = base_path('stubs/flowra/'.$name);
        if (is_file($appStub)) {
            $data = file_get_contents($appStub);
            if ($data !== false) {
                return $data;
            }
        }

        // 2) package default stub (src/stubs/action.stub)
        $packageStub = dirname(__DIR__, 1).'/stubs/'.$name; // __DIR__ = src/Console
        if (is_file($packageStub)) {
            $data = file_get_contents($packageStub);
            if ($data !== false) {
                return $data;
            }
        }

        throw new \RuntimeException("Stub not found: {$name}\nLooked in:\n - {$appStub}\n - {$packageStub}");
    }
}
