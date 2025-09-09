<?php

namespace Flowra\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeGuard extends Command
{
    protected $signature = 'flowra:make-guard
        {name : Guard name, e.g. OnlyOwner}
        {--path=app/Workflows/Guards : Directory where the guard will be created}
        {--namespace=App\\Workflows\\Guards : Namespace for the generated class}
        {--force : Overwrite if the file already exists}';

    protected $description = 'Create a new Flowra guard class.';

    public function handle(Filesystem $files): int
    {
        // Normalize name → ensure it ends with "Guard"
        $raw = trim($this->argument('name'));
        $studly = Str::studly($raw);
        if (!Str::endsWith($studly, 'Guard')) {
            $studly .= 'Guard';
        }

        // Resolve paths & namespace
        $basePath = base_path(Str::finish($this->option('path'), '/'));       // e.g. app/FlowGuards/
        $namespace = rtrim($this->option('namespace'), '\\');                  // e.g. App\FlowGuards
        $filename = $basePath.$studly.'.php';
        $class = $studly;

        // Ensure directory
        if (!$files->isDirectory($basePath)) {
            $files->makeDirectory($basePath, 0777, true);
        }

        // Load stub (published first, then package fallback)
        $stub = $this->getStub('guard.stub');

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
        $this->info("✅ Guard generated: {$namespace}\\{$class}");
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

        // 2) package default stub (src/stubs/guard.stub)
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
